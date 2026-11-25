<?php
/**
 * Pay-first Registration products in the cart and at checkout.
 *
 * Cart item data holds no applicant data: flow, product, add-ons, fixed choice (BN term), the
 * government fee (omitted when none), environment and a random slot id. Carriers prices the line
 * and charges the government fee as a cart fee; checkout copies the slot to the order line.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Plugin;
use BapiForms\Products\PriceList;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class PayFirstCart {

	public const BUY_NOW   = 'bapi_forms_buy_now';
	public const SHORTCODE = 'bapi_buy';

	/** "Prices updated" was shown in this request. */
	private static bool $noticed = false;

	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 20, 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 20, 4 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'buy_now_redirect' ), 20, 2 );
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'buy_now_button' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'restamp' ), 5 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copy_slot_to_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'prune_slots' ), 5 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'prune_slots' ), 5 );
		add_shortcode( self::SHORTCODE, array( $this, 'buy_shortcode' ) );
	}

	/** Why a slug cannot be bought right now, or null. */
	public static function problem( string $slug ): ?string {
		$plugin = Plugin::instance();
		if ( ! $plugin->commerce->has_active() || ! $plugin->options->has_key() || Options::FLOW_PAY_FIRST !== $plugin->options->flow( $slug ) || ! $plugin->products->is_enabled( $slug ) ) {
			return __( 'Sorry, this is not available right now. Please contact us.', 'businessapi-forms' );
		}
		if ( ! $plugin->products->is_active( $slug ) ) {
			return __( 'Sorry, this is not available right now. Please try again later or contact us.', 'businessapi-forms' );
		}
		return null;
	}

	/**
	 * Add-ons and fixed options of the chosen variation, or null when it is not one of the choices.
	 *
	 * @return array{addons: list<string>, fixed: array{term?: int}}|null
	 */
	public static function choice_of( string $slug, int $product_id, int $variation_id ): ?array {
		$choices = PayFirstProducts::choices( $slug );
		if ( ! $choices ) {
			return array(
				'addons' => array(),
				'fixed'  => array(),
			);
		}
		if ( $variation_id <= 0 || (int) wp_get_post_parent_id( $variation_id ) !== $product_id || 'publish' !== get_post_status( $variation_id ) ) {
			return null;
		}
		$choice = (string) get_post_meta( $variation_id, PayFirstProducts::CHOICE, true );
		return isset( $choices[ $choice ] ) ? Prepaid::parse_choice( $slug, $choice ) : null;
	}

	/**
	 * Government fee from the cached schedule: null when the product has none, false when it
	 * cannot be read now.
	 *
	 * @param array{term?: int} $fixed Fixed options.
	 * @return array{kind: string, label: string, amount: float}|null|false
	 */
	public static function government( string $slug, array $fixed, ?string $env = null ) {
		$prices = Plugin::instance()->prices;
		if ( ! $prices->has_government_fee( $slug ) ) {
			return null;
		}
		$list = $prices->get( false, $env );
		$fee  = null !== $list ? PriceList::fee_in( $list, $slug, Prepaid::pricing( $slug, $fixed ) ) : null;
		if ( null === $fee ) {
			return false;
		}
		return array(
			'kind'   => $slug,
			'label'  => PriceList::government_label( $slug ),
			'amount' => round( $fee, 2 ),
		);
	}

	/**
	 * At checkout: if the government fee changed (cached schedule, confirmed by a fresh read), the
	 * line takes the new fee and the returned message asks the customer to check the total.
	 * Unreadable: the cart keeps its fee; the price check at payment still guards.
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	public static function refresh_government( \WC_Cart $cart, string $key, array $item ): ?string {
		$slug  = (string) ( $item['bapi_product'] ?? '' );
		$fixed = (array) ( $item['bapi_fixed'] ?? array() );
		$env   = (string) ( $item['bapi_env'] ?? '' );
		$env   = in_array( $env, array( Options::ENV_TEST, Options::ENV_LIVE ), true ) ? $env : null;
		$have  = Carriers::government_of( $item );
		$now   = self::government( $slug, $fixed, $env );
		$same  = ( null === $now && null === $have ) || ( is_array( $now ) && null !== $have && abs( $now['amount'] - $have['amount'] ) < Money::EPSILON );
		if ( false === $now || $same ) {
			return null;
		}
		$prices = Plugin::instance()->prices;
		$list   = $prices->has_government_fee( $slug ) ? $prices->get( true, $env ) : null;
		$fee    = null !== $list ? PriceList::fee_in( $list, $slug, Prepaid::pricing( $slug, $fixed ) ) : null;
		if ( null === $fee || ( null !== $have && abs( round( $fee, 2 ) - $have['amount'] ) < Money::EPSILON ) ) {
			return null; // The cache was stale, or nothing could be read now.
		}
		if ( ! isset( $cart->cart_contents[ $key ] ) ) {
			return null;
		}
		$cart->cart_contents[ $key ]['bapi_gov'] = array(
			'kind'   => $slug,
			'label'  => PriceList::government_label( $slug ),
			'amount' => round( $fee, 2 ),
		);
		$cart->calculate_totals();
		/* translators: %s: new government fee. */
		return sprintf( __( 'the government fee is now %s. Your cart was updated: please check the total and place your order again.', 'businessapi-forms' ), Money::plain( round( $fee, 2 ) ) );
	}

	/**
	 * @param bool        $passed       Validation result.
	 * @param int         $product_id   Product id.
	 * @param int         $quantity     Quantity.
	 * @param int         $variation_id Variation id.
	 * @param array|mixed $variations   Variation attributes.
	 */
	public function validate( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array() ): bool {
		unset( $quantity, $variations );
		$slug = PayFirstProducts::slug_of( (int) $product_id );
		if ( '' === $slug || ! $passed ) {
			return (bool) $passed;
		}
		$problem = self::problem( $slug );
		if ( null === $problem && null === self::choice_of( $slug, (int) $product_id, (int) $variation_id ) ) {
			$problem = __( 'Please choose an option first.', 'businessapi-forms' );
		}
		if ( null === $problem ) {
			$choice = self::choice_of( $slug, (int) $product_id, (int) $variation_id );
			if ( false === self::government( $slug, (array) ( $choice['fixed'] ?? array() ) ) ) {
				$problem = __( 'The government fee could not be loaded. Please try again in a few minutes.', 'businessapi-forms' );
			}
		}
		if ( null !== $problem ) {
			wc_add_notice( $problem, 'error' );
			return false;
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $data         Cart item data.
	 * @param int                  $product_id   Product id.
	 * @param int                  $variation_id Variation id.
	 * @param int                  $quantity     Quantity.
	 * @return array<string, mixed>
	 */
	public function cart_item_data( $data, $product_id, $variation_id = 0, $quantity = 1 ) {
		unset( $quantity );
		$data = (array) $data;
		$slug = PayFirstProducts::slug_of( (int) $product_id );
		if ( '' === $slug ) {
			return $data;
		}
		$choice = self::choice_of( $slug, (int) $product_id, (int) $variation_id );
		$gov    = null !== $choice ? self::government( $slug, $choice['fixed'] ) : false;
		// Validation refused these already; never put a half-made line in the cart.
		if ( null === $choice || false === $gov ) {
			return $data;
		}
		$line = array(
			'bapi_flow'    => Options::FLOW_PAY_FIRST,
			'bapi_product' => $slug,
			'bapi_carrier' => $slug,
			'bapi_fee'     => 'service',
			'bapi_v'       => Carriers::VERSION,
			'bapi_addons'  => $choice['addons'],
			'bapi_fixed'   => $choice['fixed'],
			'bapi_env'     => Plugin::instance()->options->environment(),
			'bapi_slot'    => Prepaid::new_slot(),
		);
		// No null values: WooCommerce trims every scalar when it builds the cart id.
		if ( null !== $gov ) {
			$line['bapi_gov'] = $gov;
		}
		return array_merge( $data, $line );
	}

	/**
	 * Buy now: straight to checkout after the add.
	 *
	 * @param string|false     $url     Redirect URL.
	 * @param \WC_Product|null $product Product added.
	 * @return string|false
	 */
	public function buy_now_redirect( $url, $product = null ) {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce's add to cart form has no nonce; this only picks the redirect.
		// POST from the product form, GET from the formaction URL.
		$buy = isset( $_POST[ self::BUY_NOW ] ) ? sanitize_key( wp_unslash( $_POST[ self::BUY_NOW ] ) ) : ( isset( $_GET[ self::BUY_NOW ] ) ? sanitize_key( wp_unslash( $_GET[ self::BUY_NOW ] ) ) : '' );
		// phpcs:enable
		if ( '1' !== $buy || ! $product instanceof \WC_Product || '' === PayFirstProducts::slug_of( (int) $product->get_id() ) ) {
			return $url;
		}
		return wc_get_checkout_url();
	}

	/** "Buy now" next to Add to cart on a Registration product page. */
	public function buy_now_button(): void {
		global $product;
		if ( ! $product instanceof \WC_Product || '' === PayFirstProducts::slug_of( (int) $product->get_id() ) ) {
			return;
		}
		if ( ! $product->is_type( 'variable' ) ) {
			// The flag goes in formaction, not a field, so the regular Add to cart never redirects.
			printf(
				'<button type="submit" name="add-to-cart" value="%1$d" formaction="%2$s" class="button alt wp-element-button bapi-forms-buy-now" style="margin-left:8px;">%3$s</button>',
				(int) $product->get_id(),
				esc_url( add_query_arg( self::BUY_NOW, '1', $product->get_permalink() ) ),
				esc_html__( 'Buy now', 'businessapi-forms' )
			);
			return;
		}
		printf(
			'<button type="submit" name="%1$s" value="1" class="button alt wp-element-button bapi-forms-buy-now" style="margin-left:8px;">%2$s</button>',
			esc_attr( self::BUY_NOW ),
			esc_html__( 'Buy now', 'businessapi-forms' )
		);
	}

	/**
	 * Lines added before the partner switched sandbox/live: restamp and re-read the government fee.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function restamp( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$env = Plugin::instance()->options->environment();
		foreach ( $cart->cart_contents as $key => $item ) {
			if ( ! Carriers::is_pay_first( $item ) || ( $item['bapi_env'] ?? '' ) === $env ) {
				continue;
			}
			$gov                                     = self::government( (string) $item['bapi_product'], (array) ( $item['bapi_fixed'] ?? array() ), $env );
			$cart->cart_contents[ $key ]['bapi_env'] = $env;
			// Unreadable: no fee, so checkout refuses the line until it can be read.
			if ( is_array( $gov ) ) {
				$cart->cart_contents[ $key ]['bapi_gov'] = $gov;
			} else {
				unset( $cart->cart_contents[ $key ]['bapi_gov'] );
			}
			if ( ! self::$noticed && ! wc_has_notice( __( 'Prices in your cart were updated.', 'businessapi-forms' ), 'notice' ) ) {
				wc_add_notice( __( 'Prices in your cart were updated.', 'businessapi-forms' ), 'notice' );
				self::$noticed = true;
			}
		}
	}

	/**
	 * Copy the slot to the order line and track it on the order.
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart key.
	 * @param array                  $values        Cart item.
	 * @param \WC_Order              $order         Order.
	 */
	public function copy_slot_to_item( $item, $cart_item_key, $values, $order ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || ! $order instanceof \WC_Order || ! Carriers::is_pay_first( $values ) ) {
			return;
		}
		$slot    = (string) $values['bapi_slot'];
		$product = sanitize_key( (string) ( $values['bapi_product'] ?? '' ) );
		$addons  = Carriers::addons_of( $values );
		$fixed   = Prepaid::parse_fixed( Prepaid::fixed_string( (array) ( $values['bapi_fixed'] ?? array() ) ) );
		$env     = (string) ( $values['bapi_env'] ?? '' );
		$env     = in_array( $env, array( Options::ENV_TEST, Options::ENV_LIVE ), true ) ? $env : Plugin::instance()->options->environment();

		$item->update_meta_data( OrderBridge::ITEM_CARRIER, $product );
		$item->update_meta_data( OrderLines::ITEM_VERSION, Carriers::VERSION );
		$item->update_meta_data( OrderLines::ITEM_ADDONS, implode( ',', $addons ) );
		$item->update_meta_data( Prepaid::ITEM_FLOW, Options::FLOW_PAY_FIRST );
		$item->update_meta_data( Prepaid::ITEM_SLOT, $slot );
		$item->update_meta_data( Prepaid::ITEM_FIXED, Prepaid::fixed_string( $fixed ) );

		$slots = Prepaid::slots( $order );
		if ( ! isset( $slots[ $slot ] ) ) {
			$slots[ $slot ] = self::new_record( $product, $env, $addons, $fixed );
			$order->update_meta_data( Prepaid::META, $slots );
		}
		if ( \BapiForms\Monitoring\Renewals::PRODUCT === $product ) {
			\BapiForms\Monitoring\Entities::mark( $order );
		}
	}

	/**
	 * @param list<string>      $addons Add-on slugs.
	 * @param array{term?: int} $fixed  Fixed options.
	 * @return array<string, mixed>
	 */
	public static function new_record( string $product, string $env, array $addons, array $fixed ): array {
		return array(
			'product'   => $product,
			'env'       => $env,
			'addons'    => array_values( $addons ),
			'fixed'     => $fixed,
			'state'     => 'awaiting_payment',
			'ref'       => '',
			'attempts'  => 0,
			'reminders' => 0,
			'updated'   => time(),
		);
	}

	/**
	 * Block checkout reuses draft orders: forget unpaid slots whose lines were removed.
	 *
	 * @param \WC_Order|mixed $order Order.
	 */
	public function prune_slots( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$keep    = Prepaid::item_slots( $order );
		$slots   = Prepaid::slots( $order );
		$changed = false;
		foreach ( $slots as $slot => $row ) {
			if ( ! in_array( $slot, $keep, true ) && 'awaiting_payment' === ( $row['state'] ?? '' ) ) {
				unset( $slots[ $slot ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->update_meta_data( Prepaid::META, $slots );
			$order->save();
		}
	}

	/**
	 * [bapi_buy product="business_name" choice="term=3" label="Register now"]: add to cart and go
	 * to checkout. A variable product without a choice links to its page instead.
	 *
	 * @param array|string $atts Attributes.
	 */
	public function buy_shortcode( $atts ): string {
		$atts    = shortcode_atts(
			array(
				'product' => '',
				'choice'  => '',
				'label'   => '',
			),
			(array) $atts,
			self::SHORTCODE
		);
		$slug    = sanitize_key( (string) $atts['product'] );
		$product = PayFirstProducts::product_for( $slug );
		$problem = null === $product || 'publish' !== $product->get_status() ? __( 'This product is not set to "Pay first" (BusinessAPI Forms > Products), or is switched off.', 'businessapi-forms' ) : null;
		if ( null !== $problem ) {
			return current_user_can( 'manage_options' )
				? sprintf( '<div class="bapi-forms-form-admin-notice" style="padding:12px;border:1px dashed #d63638;"><strong>BusinessAPI Forms:</strong> %s</div>', esc_html( $problem ) )
				: '';
		}
		$label   = '' !== trim( (string) $atts['label'] ) ? sanitize_text_field( (string) $atts['label'] ) : __( 'Buy now', 'businessapi-forms' );
		$choices = PayFirstProducts::choices( $slug );
		$choice  = sanitize_text_field( (string) $atts['choice'] );
		$fields  = array( 'add-to-cart' => (int) $product->get_id() );
		if ( $choices ) {
			$variation = 0;
			foreach ( $product->get_children() as $child ) {
				if ( '' !== $choice && (string) get_post_meta( (int) $child, PayFirstProducts::CHOICE, true ) === $choice && 'publish' === get_post_status( (int) $child ) ) {
					$variation = (int) $child;
				}
			}
			if ( 0 === $variation || ! isset( $choices[ $choice ] ) ) {
				return sprintf( '<a class="button wp-element-button bapi-forms-buy" href="%1$s">%2$s</a>', esc_url( $product->get_permalink() ), esc_html( $label ) );
			}
			$fields['variation_id'] = $variation;
			$fields[ 'attribute_' . sanitize_title( PayFirstProducts::attribute_label( $slug ) ) ] = $choices[ $choice ]['label'];
		}
		$fields[ self::BUY_NOW ] = 1;
		$hidden                  = '';
		foreach ( $fields as $name => $value ) {
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}
		return sprintf(
			'<form class="bapi-forms-buy" method="post" action="%1$s" style="display:inline-block;margin:0;">%2$s<button type="submit" class="button wp-element-button">%3$s</button></form>',
			esc_url( $product->get_permalink() ),
			$hidden, // Escaped above.
			esc_html( $label )
		);
	}
}
