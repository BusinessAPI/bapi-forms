<?php
/**
 * Pay first: one visible "Registration" product in the shop per product set to pay first.
 *
 * The partner owns name, text, images, categories, slug and SEO. The plugin owns price, tax,
 * stock and variations, and writes them again on every sync and product screen save. Back to
 * form first or switched off: draft. Switched on again: published, never duplicated.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Plugin;
use BapiForms\Products\PriceList;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class PayFirstProducts {

	public const OPTION = 'bapi_forms_pay_first_products';
	public const META   = '_bapi_forms_pay_first';
	public const CHOICE = '_bapi_forms_choice';
	/** Signature of the settings last synced. */
	public const SYNCED = 'bapi_forms_pay_first_synced';

	private const LOCK = 'bapi_forms_pay_first_sync_lock';

	/** Keeps the product screen guard out of sync()'s own writes. */
	private static bool $syncing = false;

	public function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_sync' ) );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 20, 2 );
		add_action( 'admin_notices', array( $this, 'product_screen_notice' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'keep_owned' ), 99 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'keep_owned_variation' ), 99, 2 );
		// A duplicate or older copy carrying our meta is never sold as a plain product.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'not_orphan' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'not_orphan' ), 20, 2 );
	}

	/**
	 * @param bool              $purchasable Purchasable.
	 * @param \WC_Product|mixed $product     Product.
	 */
	public function not_orphan( $purchasable, $product ): bool {
		if ( ! $purchasable || ! $product instanceof \WC_Product ) {
			return (bool) $purchasable;
		}
		$id     = (int) $product->get_id();
		$parent = (int) $product->get_parent_id();
		$ours   = '' !== (string) get_post_meta( $id, self::META, true ) || ( $parent > 0 && '' !== (string) get_post_meta( $parent, self::META, true ) );
		return ! $ours || '' !== self::slug_of( $id );
	}

	/** Slug of the Registration product this product (or its parent) is, or ''. */
	public static function slug_of( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}
		$slug = (string) get_post_meta( $product_id, self::META, true );
		if ( '' === $slug ) {
			$parent = (int) wp_get_post_parent_id( $product_id );
			$slug   = $parent > 0 ? (string) get_post_meta( $parent, self::META, true ) : '';
		}
		$ids = self::ids();
		$own = '' !== $slug && isset( $ids[ $slug ] ) && ( $product_id === (int) $ids[ $slug ] || (int) wp_get_post_parent_id( $product_id ) === (int) $ids[ $slug ] );
		return $own ? sanitize_key( $slug ) : '';
	}

	/** @return array<string, int> */
	public static function ids(): array {
		$ids = get_option( self::OPTION, array() );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/** The slug's Registration product, or null. */
	public static function product_for( string $slug ): ?\WC_Product {
		$id      = self::ids()[ $slug ] ?? 0;
		$product = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		return $product instanceof \WC_Product && $slug === (string) $product->get_meta( self::META ) ? $product : null;
	}

	private static function wanted( string $slug ): bool {
		$plugin = Plugin::instance();
		return Options::FLOW_PAY_FIRST === $plugin->options->flow( $slug ) && $plugin->products->is_enabled( $slug ) && $plugin->commerce->has_active();
	}

	private static function signature(): string {
		$options = Plugin::instance()->options;
		return md5(
			(string) wp_json_encode(
				array(
					BAPI_FORMS_VERSION,
					$options->all()['flows'] ?? array(),
					$options->enabled_products(),
					$options->pricing(),
					array_map( 'intval', self::ids() ),
				)
			)
		);
	}

	public static function maybe_sync(): void {
		// Not on admin-ajax: a full page load does it.
		if ( wp_doing_ajax() || ! function_exists( 'wc_get_product' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::signature() !== (string) get_option( self::SYNCED, '' ) ) {
			self::sync();
		}
	}

	/**
	 * Brings every Registration product in line with the settings. One sync at a time, so two
	 * admin tabs never create two products; a busy lock leaves it to the next admin page load.
	 *
	 * @param int $wait Seconds to wait for a sync already running.
	 * @return array<string, int> Product ids by slug (only the ones on sale).
	 */
	public static function sync( int $wait = 0 ): array {
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\WC_Product_Variable' ) ) {
			return array();
		}
		if ( ! \BapiForms\Lock::acquire( self::LOCK, 2 * MINUTE_IN_SECONDS, $wait ) ) {
			delete_option( self::SYNCED );
			return array();
		}
		// Taken now, so settings saved during the run sync again.
		$signature     = self::signature();
		$plugin        = Plugin::instance();
		$out           = array();
		self::$syncing = true;
		try {
			foreach ( $plugin->products->all() as $slug => $product ) {
				$slug = (string) $slug;
				if ( ! empty( $product['requires'] ) || empty( $product['type'] ) ) {
					continue;
				}
				if ( self::wanted( $slug ) ) {
					$id = self::ensure( $slug );
					if ( $id > 0 ) {
						$out[ $slug ] = $id;
					}
				} else {
					self::park( $slug, 'draft' );
				}
			}
			update_option( self::SYNCED, $signature, false );
		} finally {
			self::$syncing = false;
			\BapiForms\Lock::release( self::LOCK );
		}
		return $out;
	}

	/** A product made earlier for this slug but lost track of (non-trashed first), or 0. */
	private static function find_existing( string $slug ): int {
		foreach ( array( array( 'publish', 'draft', 'private', 'pending', 'future' ), array( 'trash' ) ) as $statuses ) {
			$found = get_posts(
				array(
					'post_type'   => 'product',
					'post_status' => $statuses,
					'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			if ( $found ) {
				return (int) $found[0];
			}
		}
		return 0;
	}

	private static function park( string $slug, string $status ): void {
		$id = self::ids()[ $slug ] ?? 0;
		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $status,
				)
			);
		}
	}

	/** Deactivation and uninstall. Works without WooCommerce loaded. */
	public static function hide_all(): void {
		foreach ( self::ids() as $id ) {
			if ( $id > 0 && in_array( get_post_status( $id ), array( 'publish', 'draft' ), true ) ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'private',
					)
				);
			}
		}
		delete_option( self::SYNCED );
	}

	/**
	 * Variations a slug's product offers, or array() for a simple product.
	 *
	 * @return array<string, array{label: string, addons: list<string>}>
	 */
	public static function choices( string $slug ): array {
		$plugin = Plugin::instance();
		if ( 'company_registration' === $slug && $plugin->products->is_enabled( 'abn_addon' ) ) {
			return array(
				'addons='    => array(
					'label'  => __( 'Without ABN', 'businessapi-forms' ),
					'addons' => array(),
				),
				'addons=abn' => array(
					'label'  => __( 'With ABN', 'businessapi-forms' ),
					'addons' => array( 'abn_addon' ),
				),
			);
		}
		if ( 'business_name' === $slug ) {
			return array(
				'term=1' => array(
					'label'  => __( '1 year', 'businessapi-forms' ),
					'addons' => array(),
				),
				'term=3' => array(
					'label'  => __( '3 years', 'businessapi-forms' ),
					'addons' => array(),
				),
			);
		}
		return array();
	}

	public static function attribute_label( string $slug ): string {
		return 'business_name' === $slug ? __( 'Registration period', 'businessapi-forms' ) : __( 'ABN', 'businessapi-forms' );
	}

	/** The product's service fee plus its add-ons' fees. */
	private static function price_of( string $slug, array $addons ): string {
		$fees  = Plugin::instance()->options;
		$price = $fees->service_fee( $slug );
		foreach ( $addons as $addon ) {
			$price += $fees->service_fee( (string) $addon );
		}
		return wc_format_decimal( max( 0.0, round( $price, 2 ) ), 2 );
	}

	/** Creates or updates the slug's product (published). Its id, or 0. */
	private static function ensure( string $slug ): int {
		$label   = (string) ( Plugin::instance()->products->all()[ $slug ]['label'] ?? '' );
		$choices = self::choices( $slug );
		$ids     = self::ids();
		$id      = $ids[ $slug ] ?? 0;
		$status  = $id > 0 ? get_post_status( $id ) : false;
		$fresh   = false;

		if ( ! $status || 'trash' === $status || (string) get_post_meta( $id, self::META, true ) !== $slug ) {
			// Adopt the product made earlier rather than create a second one.
			$existing = self::find_existing( $slug );
			if ( $existing > 0 ) {
				if ( 'trash' === get_post_status( $existing ) ) {
					wp_untrash_post( $existing );
				}
				$id           = $existing;
				$status       = get_post_status( $id );
				$ids          = self::ids();
				$ids[ $slug ] = $id;
				update_option( self::OPTION, $ids, false );
			}
		}

		if ( ! $status || 'trash' === $status || (string) get_post_meta( $id, self::META, true ) !== $slug ) {
			// Never take over a product the plugin did not make.
			$product = $choices ? new \WC_Product_Variable() : new \WC_Product_Simple();
			$product->set_name( $label );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_reviews_allowed( false );
			$product->set_short_description( __( 'Pay now, then fill in your application online. Your details are saved securely as you go.', 'businessapi-forms' ) );
			$product->update_meta_data( self::META, $slug );
			$fresh = true;
		} else {
			$type = $choices ? 'variable' : 'simple';
			if ( self::type_of( $id ) !== $type ) {
				wp_set_object_terms( $id, $type, 'product_type' );
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $id );
				}
				\WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
			}
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product ) {
				return 0;
			}
			if ( 'publish' !== $product->get_status() ) {
				$product->set_status( 'publish' );
			}
		}

		self::apply_owned( $product, $slug );
		if ( $choices && $product instanceof \WC_Product_Variable ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( self::attribute_label( $slug ) );
			$attribute->set_options( array_values( array_map( static fn ( $c ) => $c['label'], $choices ) ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$product->set_attributes( array( $attribute ) );
			$product->set_regular_price( '' );
		} else {
			$product->set_attributes( array() );
			$product->set_regular_price( self::price_of( $slug, array() ) );
			$product->set_sale_price( '' );
		}
		$id = (int) $product->save();
		if ( $id <= 0 ) {
			return 0;
		}
		if ( $fresh ) {
			$ids          = self::ids();
			$ids[ $slug ] = $id;
			update_option( self::OPTION, $ids, false );
		}
		if ( $choices && $product instanceof \WC_Product_Variable ) {
			self::sync_variations( $product, $slug, $choices );
		}
		return $id;
	}

	private static function apply_owned( \WC_Product $product, string $slug ): void {
		$product->set_virtual( true );
		$product->set_downloadable( false );
		$product->set_tax_status( 'taxable' );
		$product->set_tax_class( '' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		if ( ! $product instanceof \WC_Product_Variation ) {
			$product->set_sold_individually( true );
		}
		unset( $slug );
	}

	/**
	 * One variation per choice, matched by _bapi_forms_choice so ids stay stable. Others go private.
	 *
	 * @param array<string, array{label: string, addons: list<string>}> $choices Choices.
	 */
	private static function sync_variations( \WC_Product_Variable $product, string $slug, array $choices ): void {
		$attr  = sanitize_title( self::attribute_label( $slug ) );
		$found = array();
		foreach ( $product->get_children() as $child_id ) {
			$choice = (string) get_post_meta( (int) $child_id, self::CHOICE, true );
			if ( isset( $choices[ $choice ] ) && ! isset( $found[ $choice ] ) ) {
				$found[ $choice ] = (int) $child_id;
			} elseif ( 'private' !== get_post_status( (int) $child_id ) ) {
				wp_update_post(
					array(
						'ID'          => (int) $child_id,
						'post_status' => 'private',
					)
				);
			}
		}
		$order = 0;
		foreach ( $choices as $choice => $row ) {
			$variation = isset( $found[ $choice ] ) ? wc_get_product( $found[ $choice ] ) : null;
			if ( ! $variation instanceof \WC_Product_Variation ) {
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
			}
			$variation->set_attributes( array( $attr => $row['label'] ) );
			$variation->set_status( 'publish' );
			$variation->set_menu_order( $order++ );
			$variation->set_regular_price( self::price_of( $slug, $row['addons'] ) );
			$variation->set_sale_price( '' );
			self::apply_owned( $variation, $slug );
			$variation->update_meta_data( self::CHOICE, $choice );
			$variation->save();
		}
		\WC_Product_Variable::sync( $product->get_id() );
	}

	/**
	 * Product screen save: put back what the plugin owns.
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function keep_owned( $product ): void {
		if ( self::$syncing || ! $product instanceof \WC_Product ) {
			return;
		}
		$slug = (string) $product->get_meta( self::META );
		if ( '' === $slug || (int) $product->get_id() !== ( self::ids()[ $slug ] ?? 0 ) ) {
			return;
		}
		self::apply_owned( $product, $slug );
		if ( ! self::choices( $slug ) ) {
			$product->set_regular_price( self::price_of( $slug, array() ) );
			$product->set_sale_price( '' );
		}
		// Variations and the attribute are rewritten by the next sync.
		delete_option( self::SYNCED );
	}

	/**
	 * @param \WC_Product_Variation $variation Variation being saved.
	 * @param int                   $i         Row.
	 */
	public function keep_owned_variation( $variation, $i = 0 ): void {
		unset( $i );
		if ( self::$syncing || ! $variation instanceof \WC_Product_Variation ) {
			return;
		}
		$slug = self::slug_of( (int) $variation->get_parent_id() );
		if ( '' === $slug ) {
			return;
		}
		self::apply_owned( $variation, $slug );
		$choices = self::choices( $slug );
		$choice  = (string) $variation->get_meta( self::CHOICE );
		if ( isset( $choices[ $choice ] ) ) {
			$variation->set_regular_price( self::price_of( $slug, $choices[ $choice ]['addons'] ) );
			$variation->set_sale_price( '' );
			$variation->set_date_on_sale_from( '' );
			$variation->set_date_on_sale_to( '' );
		}
		delete_option( self::SYNCED );
	}

	public function product_screen_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === self::slug_of( $id ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
			esc_html__( 'Price and options are managed in BusinessAPI Forms > Products. You can change the name, descriptions, images, categories and SEO here. Do not delete this product: switching back to form first hides it.', 'businessapi-forms' ),
			esc_url( \BapiForms\Admin\SettingsPage::url( array( 'tab' => 'products' ) ) ),
			esc_html__( 'Open BusinessAPI Forms > Products', 'businessapi-forms' )
		);
	}

	/**
	 * Adds "+ ASIC fee $636.00 (GST-free, at cost)". Cached schedule only: no API call while rendering.
	 *
	 * @param string            $html    Price HTML.
	 * @param \WC_Product|mixed $product Product.
	 */
	public function price_html( $html, $product ) {
		if ( ! $product instanceof \WC_Product || '' === (string) $html ) {
			return $html;
		}
		$slug = self::slug_of( (int) $product->get_id() );
		if ( '' === $slug || ! Plugin::instance()->prices->has_government_fee( $slug ) ) {
			return $html;
		}
		$list = Plugin::instance()->prices->cached();
		if ( null === $list ) {
			return $html;
		}
		$amounts = array();
		if ( $product instanceof \WC_Product_Variation ) {
			$choice  = Prepaid::parse_choice( $slug, (string) $product->get_meta( self::CHOICE ) );
			$fee     = null !== $choice ? PriceList::fee_in( $list, $slug, Prepaid::pricing( $slug, $choice['fixed'] ) ) : null;
			$amounts = null !== $fee ? array( $fee ) : array();
		} elseif ( 'business_name' === $slug ) {
			foreach ( PriceList::BN_TERMS as $term ) {
				$fee = PriceList::fee_in( $list, $slug, array( 'term' => $term ) );
				if ( null !== $fee ) {
					$amounts[] = $fee;
				}
			}
		} else {
			$fee     = PriceList::fee_in( $list, $slug, Prepaid::pricing( $slug, array() ) );
			$amounts = null !== $fee ? array( $fee ) : array();
		}
		if ( ! $amounts ) {
			return $html;
		}
		$text = implode( ' / ', array_map( array( Money::class, 'plain' ), array_unique( $amounts ) ) );
		/* translators: 1: fee name, e.g. "ASIC fee", 2: amount(s). */
		$note = sprintf( __( '+ %1$s %2$s (GST-free, at cost)', 'businessapi-forms' ), PriceList::government_label( $slug ), $text );
		return $html . ' <small class="bapi-forms-gov-price">' . esc_html( $note ) . '</small>';
	}

	/** Without loading the product. */
	private static function type_of( int $id ): string {
		$terms = get_the_terms( $id, 'product_type' );
		return is_array( $terms ) && isset( $terms[0]->slug ) ? (string) $terms[0]->slug : 'simple';
	}
}
