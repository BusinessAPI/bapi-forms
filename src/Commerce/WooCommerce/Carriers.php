<?php
/**
 * Hidden "carrier" products that put applications in the WooCommerce cart.
 *
 * One cart line per application: the service fee with add-ons merged in, quantity 1, GST
 * applies. The government fee is a GST-free cart fee rebuilt from the lines on every calculation,
 * so customers cannot remove or edit it and coupons never touch it. Carriers are hidden from the
 * storefront and only enter the cart through the plugin, with an application ref. Pay-first lines
 * have no ref yet and are keyed "PF{SLOT}".
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Products\ExpectedLines;
use BapiForms\Products\ProductRegistry;

defined( 'ABSPATH' ) || exit;

final class Carriers {

	public const OPTION = 'bapi_forms_carriers';
	public const META   = '_bapi_forms_carrier';
	/** Cart line format, stored on each cart line and order item. */
	public const VERSION = 2;
	/** Cart fee id prefix: "bapi-gov-{REF}". */
	public const FEE_PREFIX = 'bapi-gov-';

	/** Set only while the plugin itself adds a carrier. */
	private static bool $adding = false;

	/** Set while the plugin itself removes a ref's lines (no cascade). */
	private static bool $removing = false;

	/** WC session key for the block cart's "application removed" notice. */
	private const SESSION_NOTICE = 'bapi_forms_cart_notice';

	public const STORE_API_NAMESPACE = 'bapi-forms';

	/** On "Return to the form" links: the form reopens on "Continue to checkout". */
	public const RETURN_ARG = 'bapi_forms_return';

	public function __construct( private readonly ProductRegistry $products ) {}

	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'block_direct_add' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_prices' ), 20 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'prepare_item' ), 20 );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'prepare_item' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fees' ), 1000 );

		// Never check out a BAPI line at $0 or a BAPI order with a $0 total.
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'store_api_cart_errors' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validation' ), 10, 2 );

		// No storefront presence: no product page, not indexed, not in the sitemap.
		add_action( 'template_redirect', array( $this, 'hide_product_page' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'sitemap_args' ), 10, 2 );

		// Quantity 1; removing the line removes the application.
		add_filter( 'woocommerce_cart_item_permalink', array( $this, 'item_permalink' ), 10, 2 );
		add_action( 'woocommerce_remove_cart_item', array( $this, 'on_remove' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'on_restore' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'on_quantity' ), 10, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( $this, 'quantity_editable' ), 10, 3 );

		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );

		// Block cart: the removal notice travels in the Store API cart response.
		add_action( 'init', array( $this, 'register_store_api_data' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_cart_notice' ) );
	}

	/** Whether a cart item is an application line in the current format. */
	public static function is_line( mixed $item ): bool {
		return is_array( $item ) && ! empty( $item['bapi_carrier'] ) && self::VERSION === (int) ( $item['bapi_v'] ?? 0 );
	}

	/**
	 * Government fee recorded on a line, or null when it has none.
	 *
	 * @param array<string, mixed> $item Cart item.
	 * @return array{kind: string, label: string, amount: float}|null
	 */
	public static function government_of( array $item ): ?array {
		$gov = $item['bapi_gov'] ?? null;
		if ( ! self::is_line( $item ) || ! is_array( $gov ) || ! is_numeric( $gov['amount'] ?? null ) || (float) $gov['amount'] <= 0 ) {
			return null;
		}
		return array(
			'kind'   => sanitize_key( (string) ( $gov['kind'] ?? '' ) ),
			'label'  => sanitize_text_field( (string) ( $gov['label'] ?? '' ) ),
			'amount' => round( (float) $gov['amount'], 2 ),
		);
	}

	/**
	 * @param array<string, mixed> $item Cart item.
	 * @return list<string>
	 */
	public static function addons_of( array $item ): array {
		return array_values( array_map( 'sanitize_key', array_filter( (array) ( $item['bapi_addons'] ?? array() ), 'is_string' ) ) );
	}

	/** Cart fee id of an application's government fee (key: see line_key()). */
	public static function fee_id( string $ref ): string {
		return self::FEE_PREFIX . strtoupper( $ref );
	}

	/** Whether a cart item is a pay-first line (bought before its form). */
	public static function is_pay_first( mixed $item ): bool {
		return is_array( $item ) && ! empty( $item['bapi_carrier'] ) && \BapiForms\Settings\Options::FLOW_PAY_FIRST === ( $item['bapi_flow'] ?? '' ) && Prepaid::is_slot( (string) ( $item['bapi_slot'] ?? '' ) );
	}

	/**
	 * Key for a line's fee and checks: the application ref, "PF{SLOT}" for pay first, or ''.
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	public static function line_key( array $item ): string {
		if ( self::is_pay_first( $item ) ) {
			return Prepaid::FEE_PART . strtoupper( (string) $item['bapi_slot'] );
		}
		$ref = (string) ( $item['bapi_application_ref'] ?? '' );
		return \BapiForms\Applications\Applications::is_ref( $ref ) ? $ref : '';
	}

	/**
	 * Fee name, e.g. "ASIC fee #A1B2C3 (GST-free, at cost)". Kept short so the block cart's totals
	 * row keeps its amount on the same line.
	 *
	 * @param array{label: string} $gov Government fee.
	 */
	public static function fee_name( array $gov, string $ref ): string {
		/* translators: 1: fee name, e.g. "ASIC fee", 2: last 6 characters of the application reference. */
		return sprintf( __( '%1$s #%2$s (GST-free, at cost)', 'businessapi-forms' ), $gov['label'], strtoupper( substr( $ref, -6 ) ) );
	}

	/**
	 * Fee name of a pay-first line: "ASIC fee, Business name registration (GST-free, at cost)".
	 *
	 * @param array{label: string}  $gov  Government fee.
	 * @param array<string, mixed> $item Cart item.
	 */
	public static function pay_first_fee_name( array $gov, array $item ): string {
		$product = (string) ( $item['bapi_product'] ?? '' );
		$label   = (string) ( \BapiForms\Plugin::instance()->products->all()[ $product ]['label'] ?? '' );
		/* translators: 1: fee name, e.g. "ASIC fee", 2: product name. */
		return sprintf( __( '%1$s, %2$s (GST-free, at cost)', 'businessapi-forms' ), $gov['label'], $label );
	}

	/**
	 * "Business name registration, application #7ZC7G6", for cart errors.
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	private static function app_label( array $item ): string {
		$product = (string) ( $item['bapi_product'] ?? $item['bapi_carrier'] ?? '' );
		$name    = self::is_line( $item )
			? ExpectedLines::line_name( $product, self::addons_of( $item ) )
			: (string) ( \BapiForms\Plugin::instance()->products->all()[ $product ]['label'] ?? __( 'Application', 'businessapi-forms' ) );
		if ( self::is_pay_first( $item ) ) {
			return $name; // No application yet: the product is enough.
		}
		/* translators: 1: product name, 2: last 6 characters of the application reference. */
		return sprintf( __( '%1$s, application #%2$s', 'businessapi-forms' ), $name, strtoupper( substr( (string) ( $item['bapi_application_ref'] ?? '' ), -6 ) ) );
	}

	/**
	 * Error naming one application, with a link to its form page when known (HTML for classic
	 * notices, plain text for the Store API).
	 *
	 * @param array<string, mixed> $item Cart item.
	 * @param string               $what What is wrong.
	 */
	private static function app_problem( array $item, string $what, bool $html ): string {
		$ref  = (string) ( $item['bapi_application_ref'] ?? '' );
		$page = '' !== $ref ? \BapiForms\Frontend\RestController::form_page_for( $ref ) : '';
		$page = '' !== $page && wp_validate_redirect( $page, '' ) ? $page : '';
		$text = self::app_label( $item ) . ': ' . $what . ' ';
		if ( self::is_pay_first( $item ) ) {
			return $text . __( 'Remove it from your cart, then add it again.', 'businessapi-forms' );
		}
		if ( '' === $page ) {
			return $text . __( 'Remove it from your cart, then fill in the form again.', 'businessapi-forms' );
		}
		$page = add_query_arg( self::RETURN_ARG, sanitize_key( (string) ( $item['bapi_product'] ?? '' ) ), $page );
		return $html
			? esc_html( $text . __( 'Remove it from your cart, then', 'businessapi-forms' ) ) . ' <a href="' . esc_url( $page ) . '">' . esc_html__( 'open its form again', 'businessapi-forms' ) . '</a>.'
			/* translators: %s: form page URL. */
			: $text . sprintf( __( 'Remove it from your cart, then open its form again: %s', 'businessapi-forms' ), esc_url_raw( $page ) );
	}

	/**
	 * Line detail: "For: Application #A1B2C3" and the government fee ("Includes: ..." where the
	 * line price includes it, "Plus: ..." in the block cart). No applicant data.
	 *
	 * @param array $data Item data rows.
	 * @param array $item Cart item.
	 */
	public function item_data( $data, $item ): array {
		$data = (array) $data;
		$key  = is_array( $item ) && ! empty( $item['bapi_carrier'] ) ? self::line_key( $item ) : '';
		if ( '' === $key ) {
			return $data;
		}
		if ( ! self::is_pay_first( $item ) ) {
			$data[] = array(
				'key'   => __( 'For', 'businessapi-forms' ),
				'value' => StatusText::short_ref( $key ),
			);
		}
		$gov = self::government_of( $item );
		// Classic cart, checkout and mini-cart show the fee inside the line (FeeDisplay).
		$folded = null !== $gov ? FeeDisplay::cart_breakdown( $item ) : null;
		if ( null !== $folded ) {
			$data[] = array(
				'key'   => __( 'Includes', 'businessapi-forms' ),
				'value' => $folded,
			);
		} elseif ( null !== $gov ) {
			$data[] = array(
				'key'   => __( 'Plus', 'businessapi-forms' ),
				/* translators: 1: fee name, e.g. "ASIC fee", 2: amount. */
				'value' => sprintf( __( '%1$s %2$s (GST-free, at cost)', 'businessapi-forms' ), $gov['label'], Money::plain( $gov['amount'] ) ),
			);
		}
		return $data;
	}

	/** Store API cart extension data: the removal notice, taken once from the WC session. */
	public function register_store_api_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::STORE_API_NAMESPACE,
				'data_callback'   => array( $this, 'store_api_data' ),
				'schema_callback' => static fn () => array(
					'notice' => array(
						'description' => 'Notice after an application was removed from the cart.',
						'type'        => array( 'object', 'null' ),
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
				),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/** @return array{notice: array{id: string, text: string}|null} */
	public function store_api_data(): array {
		$session = WC()->session ? WC()->session : null;
		$notice  = $session ? $session->get( self::SESSION_NOTICE ) : null;
		if ( is_array( $notice ) && isset( $notice['id'], $notice['text'] ) ) {
			$session->set( self::SESSION_NOTICE, null );
			return array(
				'notice' => array(
					'id'   => (string) $notice['id'],
					'text' => (string) $notice['text'],
				),
			);
		}
		return array( 'notice' => null );
	}

	/** Show the Store API notice in the block cart's notice area. */
	public function enqueue_cart_notice(): void {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() || ! function_exists( 'has_block' ) || ! has_block( 'woocommerce/cart' ) ) {
			return;
		}
		wp_enqueue_script( 'bapi-forms-cart', BAPI_FORMS_URL . 'assets/js/bapi-forms-cart.js', array( 'wp-data', 'wc-blocks-data-store' ), BAPI_FORMS_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function lines_for( \WC_Cart $cart, string $ref ): array {
		return array_filter( $cart->get_cart(), fn ( $item ) => is_array( $item ) && ( $item['bapi_application_ref'] ?? '' ) === $ref && ! empty( $item['bapi_carrier'] ) );
	}

	/** Remove every line of an application (no cascade, no refusal). */
	public static function remove_ref( \WC_Cart $cart, string $ref ): void {
		self::$removing = true;
		try {
			foreach ( array_keys( self::lines_for( $cart, $ref ) ) as $key ) {
				$cart->remove_cart_item( $key );
			}
		} finally {
			self::$removing = false;
		}
	}

	/**
	 * Whether the cart holds exactly the expected single line for an application.
	 *
	 * @param list<string>                                   $addons     Expected add-on slugs.
	 * @param array{kind: string, label: string, amount: float}|null $government Expected government fee.
	 */
	public static function ref_complete( \WC_Cart $cart, string $ref, string $product, array $addons, ?array $government ): bool {
		$lines = self::lines_for( $cart, $ref );
		if ( 1 !== count( $lines ) ) {
			return false;
		}
		$item = reset( $lines );
		if ( ! self::is_line( $item ) || 1 !== (int) ( $item['quantity'] ?? 0 ) || (string) $item['bapi_carrier'] !== $product || (string) ( $item['bapi_product'] ?? '' ) !== $product ) {
			return false;
		}
		$have = self::addons_of( $item );
		$want = array_values( $addons );
		sort( $have );
		sort( $want );
		if ( $have !== $want ) {
			return false;
		}
		$gov = self::government_of( $item );
		if ( null === $government || null === $gov ) {
			return null === $government && null === $gov;
		}
		return abs( $gov['amount'] - (float) $government['amount'] ) < Money::EPSILON && $gov['kind'] === $government['kind'];
	}

	/**
	 * Carriers have no product page: the line links back to its form page instead.
	 *
	 * @param string $permalink Product permalink.
	 * @param mixed  $item      Cart item.
	 */
	public function item_permalink( $permalink, $item ): string {
		if ( ! is_array( $item ) || empty( $item['bapi_carrier'] ) ) {
			return (string) $permalink;
		}
		if ( self::is_pay_first( $item ) ) {
			return (string) $permalink; // The partner's own Registration product page.
		}
		$page = \BapiForms\Frontend\RestController::form_page_for( (string) ( $item['bapi_application_ref'] ?? '' ) );
		return '' !== $page && wp_validate_redirect( $page, '' ) ? esc_url_raw( $page ) : '';
	}

	/**
	 * Removing a line removes the whole application.
	 *
	 * @param string   $key  Cart item key.
	 * @param \WC_Cart $cart Cart.
	 */
	public function on_remove( $key, $cart ): void {
		if ( self::$removing || ! $cart instanceof \WC_Cart ) {
			return;
		}
		$item = $cart->get_cart()[ $key ] ?? null;
		$ref  = is_array( $item ) ? (string) ( $item['bapi_application_ref'] ?? '' ) : '';
		if ( '' === $ref || empty( $item['bapi_carrier'] ) ) {
			return;
		}
		self::$removing = true;
		try {
			foreach ( array_keys( self::lines_for( $cart, $ref ) ) as $other ) {
				if ( $other !== $key ) {
					$cart->remove_cart_item( $other );
				}
			}
		} finally {
			self::$removing = false;
		}

		// The application stays in BAPI, so the form page can reopen it on "Continue to checkout".
		$page = \BapiForms\Frontend\RestController::release_to_form( $ref );
		$text = __( 'The application was removed from your cart, together with its government fee. Your details are saved.', 'businessapi-forms' );
		if ( '' !== $page ) {
			$product = sanitize_key( (string) ( $item['bapi_product'] ?? '' ) );
			$text   .= ' <a href="' . esc_url( add_query_arg( self::RETURN_ARG, $product, $page ) ) . '">' . esc_html__( 'Continue to checkout with this application', 'businessapi-forms' ) . '</a>';
		} else {
			$text .= ' ' . esc_html__( 'Open the form page again to check out.', 'businessapi-forms' );
		}
		$text = wp_kses( $text, array( 'a' => array( 'href' => array() ) ) );

		if ( self::is_store_api() ) {
			// A session notice would only show on the next page, so it goes in this response.
			$session = WC()->session ? WC()->session : null;
			if ( $session ) {
				$session->set(
					self::SESSION_NOTICE,
					array(
						'id'   => wp_generate_uuid4(),
						'text' => $text,
					)
				);
			}
		} else {
			wc_add_notice( $text, 'notice' );
		}
	}

	/**
	 * Classic cart "Undo": bring the whole application back.
	 *
	 * @param string   $key  Cart item key.
	 * @param \WC_Cart $cart Cart.
	 */
	public function on_restore( $key, $cart ): void {
		if ( self::$removing || ! $cart instanceof \WC_Cart ) {
			return;
		}
		$ref = (string) ( $cart->cart_contents[ $key ]['bapi_application_ref'] ?? '' );
		if ( '' === $ref ) {
			return;
		}
		\BapiForms\Frontend\RestController::back_in_cart( $ref );
		self::$removing = true;
		try {
			foreach ( $cart->removed_cart_contents as $other => $item ) {
				if ( $other !== $key && ( $item['bapi_application_ref'] ?? '' ) === $ref ) {
					$cart->restore_cart_item( $other );
				}
			}
		} finally {
			self::$removing = false;
		}
	}

	/**
	 * Application lines stay at quantity 1.
	 *
	 * @param string   $key      Cart item key.
	 * @param int      $quantity New quantity.
	 * @param int      $old      Old quantity.
	 * @param \WC_Cart $cart     Cart.
	 */
	public function on_quantity( $key, $quantity, $old, $cart ): void {
		if ( ! $cart instanceof \WC_Cart || 1 === (int) $quantity || empty( $cart->cart_contents[ $key ]['bapi_carrier'] ) ) {
			return;
		}
		if ( self::is_store_api() ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'bapi_forms_locked_line', esc_html__( 'The quantity of an application cannot be changed.', 'businessapi-forms' ), 409 );
		}
		$cart->cart_contents[ $key ]['quantity'] = 1;
	}

	/**
	 * Block cart: no quantity selector on application lines.
	 *
	 * @param bool        $editable  Editable.
	 * @param \WC_Product $product   Product.
	 * @param array|null  $cart_item Cart item.
	 */
	public function quantity_editable( $editable, $product, $cart_item = null ): bool {
		return is_array( $cart_item ) && ! empty( $cart_item['bapi_carrier'] ) ? false : (bool) $editable;
	}

	private static function is_store_api(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' );
	}

	/**
	 * Add a carrier with the flag set, so block_direct_add() lets it through.
	 *
	 * @param array<string, mixed> $data Cart item data.
	 * @return string|false Cart item key.
	 */
	public function add( \WC_Cart $cart, int $product_id, array $data ) {
		self::$adding = true;
		try {
			return $cart->add_to_cart( $product_id, 1, 0, array(), $data );
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			self::$adding = false;
		}
	}

	/** Carrier product id for a product slug, created when missing. */
	public function ensure( string $slug ): int {
		$ids = get_option( self::OPTION, array() );
		$ids = is_array( $ids ) ? $ids : array();

		$tax_status = 'taxable';
		$label      = $this->products->all()[ $slug ]['label'] ?? null;
		if ( null === $label ) {
			return 0;
		}

		$product = isset( $ids[ $slug ] ) ? wc_get_product( (int) $ids[ $slug ] ) : null;
		// Private after a deactivation: publish it again (purchasable), never a duplicate.
		if ( $product && in_array( $product->get_status(), array( 'publish', 'private' ), true ) && $slug === $product->get_meta( self::META ) ) {
			if ( 'publish' !== $product->get_status() || 'hidden' !== $product->get_catalog_visibility() || $tax_status !== $product->get_tax_status() || '' !== $product->get_tax_class() || $label !== $product->get_name() || '' !== $product->get_short_description() ) {
				$product->set_status( 'publish' );
				$product->set_catalog_visibility( 'hidden' );
				$product->set_tax_status( $tax_status );
				$product->set_tax_class( '' );
				$product->set_name( $label );
				$product->set_short_description( '' ); // Shown to customers in the block cart.
				$product->save();
			}
			return $product->get_id();
		}

		$product = new \WC_Product_Simple();
		$product->set_name( $label );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_reviews_allowed( false );
		$product->set_regular_price( '0' );
		// No description: the block cart shows it to customers.
		$product->update_meta_data( self::META, $slug );
		// Service fees carry GST (standard class); government fees are GST-free cart fees.
		$product->set_tax_status( $tax_status );
		$product->set_tax_class( '' );
		$id = (int) $product->save();

		if ( $id > 0 ) {
			$ids[ $slug ] = $id;
			update_option( self::OPTION, $ids, false );
		}

		return $id;
	}

	/**
	 * Carriers only enter the cart through add(), never ?add-to-cart, the Store API or "order again".
	 *
	 * @param bool $passed     Validation result.
	 * @param int  $product_id Product id.
	 */
	public function block_direct_add( $passed, $product_id ): bool {
		if ( $passed && ! self::$adding && '' !== (string) get_post_meta( (int) $product_id, self::META, true ) ) {
			wc_add_notice( __( 'This item is added automatically when you complete the application form.', 'businessapi-forms' ), 'error' );
			return false;
		}
		return (bool) $passed;
	}

	/** Line price: service fee plus merged add-on fees. */
	private static function line_price( array $item ): float {
		$fees  = \BapiForms\Plugin::instance()->options;
		$price = $fees->service_fee( (string) $item['bapi_carrier'] );
		foreach ( self::addons_of( $item ) as $addon ) {
			$price += $fees->service_fee( $addon );
		}
		return round( $price, 2 );
	}

	/**
	 * Whether every service fee on a line (product and merged add-ons) is above 0.
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	private static function priced( array $item ): bool {
		foreach ( array_merge( array( (string) $item['bapi_carrier'] ), self::addons_of( $item ) ) as $slug ) {
			if ( \BapiForms\Plugin::instance()->options->service_fee( $slug ) <= 0 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Set the line's title and price on its per-item product object.
	 *
	 * @param array<string, mixed> $item Cart item.
	 * @return array<string, mixed>
	 */
	public function prepare_item( $item ) {
		if ( ! is_array( $item ) || empty( $item['bapi_carrier'] ) || ! isset( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
			return $item;
		}
		// Pay first: the partner names their Registration product (and its options) themselves.
		if ( self::is_line( $item ) && ! self::is_pay_first( $item ) ) {
			$item['data']->set_name( ExpectedLines::line_name( (string) $item['bapi_carrier'], self::addons_of( $item ) ) );
		}
		$item['data']->set_price( max( 0.0, self::line_price( $item ) ) );
		return $item;
	}

	/**
	 * Line prices from the Products tab service fees.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function set_prices( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			$this->prepare_item( $item );
		}
	}

	/**
	 * One GST-free fee per application from the amount on its line. Never stored, so it cannot be
	 * removed, edited or discounted and it goes with its line.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function add_fees( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$seen = array();
		foreach ( $cart->get_cart() as $item ) {
			$gov = is_array( $item ) ? self::government_of( $item ) : null;
			$ref = is_array( $item ) ? self::line_key( $item ) : '';
			if ( null === $gov || '' === $ref || isset( $seen[ $ref ] ) ) {
				continue;
			}
			$seen[ $ref ] = true;
			$cart->fees_api()->add_fee(
				array(
					'id'        => self::fee_id( $ref ),
					'name'      => self::is_pay_first( $item ) ? self::pay_first_fee_name( $gov, $item ) : self::fee_name( $gov, $ref ),
					'amount'    => $gov['amount'],
					'taxable'   => false,
					'tax_class' => '',
				)
			);
		}
	}

	/** Why the cart cannot be checked out, or null. */
	public static function problem( ?\WC_Cart $cart, bool $html = true ): ?string {
		if ( ! $cart instanceof \WC_Cart ) {
			return null;
		}

		$refs = array();
		$env  = \BapiForms\Plugin::instance()->options->environment();
		foreach ( $cart->get_cart() as $cart_key => $item ) {
			if ( ! is_array( $item ) || empty( $item['bapi_carrier'] ) ) {
				continue;
			}
			$ref = self::line_key( $item );
			// Not in the current format, a second line for a ref, or no ref.
			if ( ! self::is_line( $item ) || '' === $ref || isset( $refs[ $ref ] ) ) {
				return self::app_problem( $item, __( 'its items in your cart changed.', 'businessapi-forms' ), $html );
			}
			$problem = self::line_problem( $cart, (string) $cart_key, $item, $env, $html );
			if ( null !== $problem ) {
				return $problem;
			}
			$refs[ $ref ] = $item;
		}
		if ( ! $refs ) {
			return null;
		}

		// Applications are only ever paid in Australian dollars (fees are AUD amounts).
		if ( 'AUD' !== get_woocommerce_currency() ) {
			return __( 'Applications can only be paid in Australian dollars (AUD). Please contact us.', 'businessapi-forms' );
		}

		// Fees are not kept in the session: only checked once the cart was calculated in this request.
		$fees       = did_action( 'woocommerce_cart_calculate_fees' ) ? $cart->get_fees() : null;
		$government = 0.0;
		foreach ( $refs as $ref => $item ) {
			$ref         = (string) $ref;
			$gov         = self::government_of( $item );
			$government += null !== $gov ? $gov['amount'] : 0.0;
			$problem     = self::paid_problem( $ref, $item, $gov, $fees, $html );
			if ( null !== $problem ) {
				return $problem;
			}
		}

		$total = (float) $cart->get_total( 'edit' );
		if ( $total <= 0 ) {
			return __( 'Orders with an application cannot have a total of $0. Remove the discount and try again.', 'businessapi-forms' );
		}
		// Negative fees, gift cards or credit applied in the cart must never eat into government fees.
		if ( null !== $fees && $total + Money::EPSILON < $government ) {
			return __( 'Discounts cannot be applied to government fees. Remove the coupon and try again.', 'businessapi-forms' );
		}

		return null;
	}

	/**
	 * One application line on its own: still sold this way, priced, right fee and environment.
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	private static function line_problem( \WC_Cart $cart, string $cart_key, array $item, string $env, bool $html ): ?string {
		// Pay first: the product must still be sold that way (setting changed or switched off).
		if ( self::is_pay_first( $item ) ) {
			$product = (string) ( $item['bapi_product'] ?? '' );
			$plugin  = \BapiForms\Plugin::instance();
			if ( \BapiForms\Settings\Options::FLOW_PAY_FIRST !== $plugin->options->flow( $product ) || ! $plugin->products->is_enabled( $product ) ) {
				return self::app_label( $item ) . ': ' . __( 'this item can no longer be bought this way. Please remove it from your cart.', 'businessapi-forms' );
			}
			// Same gate as add to cart: key for the environment, product active (live eligibility).
			$gate = PayFirstCart::problem( $product );
			if ( null !== $gate ) {
				return self::app_label( $item ) . ': ' . $gate;
			}
			// The government fee changed since it was added (e.g. 1 July).
			$changed = PayFirstCart::refresh_government( $cart, (string) $cart_key, $item );
			if ( null !== $changed ) {
				return self::app_label( $item ) . ': ' . $changed;
			}
		}
		if ( ! self::priced( $item ) ) {
			return self::app_label( $item ) . ': ' . __( 'it cannot be checked out yet because pricing is not configured. Please contact us.', 'businessapi-forms' );
		}
		// A product with a government fee must carry one, and one without must not.
		if ( \BapiForms\Plugin::instance()->prices->has_government_fee( (string) ( $item['bapi_product'] ?? '' ) ) !== ( null !== self::government_of( $item ) ) ) {
			return self::app_problem( $item, __( 'its government fee in your cart changed.', 'businessapi-forms' ), $html );
		}
		// Added in the other environment. Pay-first lines have no application yet and are restamped.
		if ( ( $item['bapi_env'] ?? '' ) !== $env && ! self::is_pay_first( $item ) ) {
			return self::app_problem( $item, __( 'our form settings changed since it was added to your cart.', 'businessapi-forms' ), $html );
		}
		return null;
	}

	/**
	 * One application's quantity, government fee line and total as calculated.
	 *
	 * @param array<string, mixed>       $item Cart item.
	 * @param array{amount: float}|null  $gov  Its government fee.
	 * @param array<string, object>|null $fees Cart fees, null before the fees were calculated.
	 */
	private static function paid_problem( string $ref, array $item, ?array $gov, ?array $fees, bool $html ): ?string {
		if ( 1 !== (int) ( $item['quantity'] ?? 0 ) ) {
			return self::app_problem( $item, __( 'its items in your cart changed.', 'businessapi-forms' ), $html );
		}
		if ( null !== $gov ) {
			if ( null !== $fees ) {
				$fee = $fees[ self::fee_id( $ref ) ] ?? null;
				if ( ! is_object( $fee ) || ! empty( $fee->taxable ) || abs( (float) $fee->amount - $gov['amount'] ) > Money::EPSILON ) {
					return self::app_problem( $item, __( 'its government fee in your cart changed.', 'businessapi-forms' ), $html );
				}
				if ( isset( $fee->total ) && (float) $fee->total + Money::EPSILON < $gov['amount'] ) {
					return self::app_label( $item ) . ': ' . __( 'discounts cannot be applied to government fees. Remove the coupon and try again.', 'businessapi-forms' );
				}
			}
		}
		// Each application must total more than $0 on its own, not just the cart.
		$line = isset( $item['line_total'] ) ? (float) $item['line_total'] + (float) ( $item['line_tax'] ?? 0 ) : self::line_price( $item );
		if ( $line + ( null !== $gov ? $gov['amount'] : 0.0 ) <= 0 ) {
			return self::app_label( $item ) . ': ' . __( 'an application cannot be checked out for $0. Remove the discount and try again.', 'businessapi-forms' );
		}
		// One order per application, also at order creation.
		if ( ! self::is_pay_first( $item ) && OrderBridge::ref_on_other_order( $ref, self::current_order_id() ) ) {
			return self::app_label( $item ) . ': ' . __( 'this application is already on an order. Remove it from your cart and start the form again.', 'businessapi-forms' );
		}
		return null;
	}

	/** The checkout draft / order being paid, excluded from the one-order-per-ref check. */
	private static function current_order_id(): int {
		$session = WC()->session ? WC()->session : null;
		if ( ! $session ) {
			return 0;
		}
		$draft = (int) $session->get( 'store_api_draft_order' );
		return $draft > 0 ? $draft : (int) $session->get( 'order_awaiting_payment' );
	}

	/** Classic cart and checkout. */
	public function check_cart_items(): void {
		$problem = self::problem( WC()->cart );
		if ( null !== $problem ) {
			wc_add_notice( $problem, 'error' );
		}
	}

	/**
	 * Block cart and checkout.
	 *
	 * @param \WP_Error $errors Errors.
	 * @param \WC_Cart  $cart   Cart.
	 */
	public function store_api_cart_errors( $errors, $cart ): void {
		$problem = self::problem( $cart instanceof \WC_Cart ? $cart : null, false );
		if ( null !== $problem && $errors instanceof \WP_Error ) {
			$errors->add( 'bapi_forms_pricing', $problem );
		}
	}

	/**
	 * Classic checkout submit.
	 *
	 * @param array     $data   Posted data.
	 * @param \WP_Error $errors Errors.
	 */
	public function checkout_validation( $data, $errors ): void {
		$problem = self::problem( WC()->cart );
		if ( null !== $problem && $errors instanceof \WP_Error ) {
			$errors->add( 'bapi_forms_pricing', $problem );
		}
	}

	/** Whether a post is a carrier product. */
	public static function is_carrier( int $post_id ): bool {
		return $post_id > 0 && in_array( $post_id, array_map( 'intval', (array) get_option( self::OPTION, array() ) ), true );
	}

	/** Carrier product pages 404 for visitors. */
	public function hide_product_page(): void {
		if ( is_singular( 'product' ) && self::is_carrier( (int) get_queried_object_id() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * @param array<string, bool|string> $robots Robots directives.
	 */
	public function robots( $robots ): array {
		$robots = (array) $robots;
		if ( is_singular( 'product' ) && self::is_carrier( (int) get_queried_object_id() ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * @param array<string, mixed> $args      WP_Query args.
	 * @param string               $post_type Post type.
	 */
	public function sitemap_args( $args, $post_type ): array {
		$args = (array) $args;
		if ( 'product' === $post_type ) {
			$ids = array_map( 'intval', (array) get_option( self::OPTION, array() ) );
			if ( $ids ) {
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- a handful of carrier ids.
				$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $ids );
			}
		}
		return $args;
	}
}
