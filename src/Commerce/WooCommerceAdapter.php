<?php
/**
 * WooCommerce commerce adapter: cart, checkout and the order bridge.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce;

use BapiForms\Commerce\WooCommerce\Carriers;
use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class WooCommerceAdapter implements Adapter {

	/**
	 * Keep in step with "WC requires at least" in businessapi-forms.php and readme.txt. HPOS queries and
	 * the block checkout compatibility model are stable from 8.2.
	 */
	public const MIN_VERSION = '8.2';

	private ?Carriers $carriers = null;

	public function id(): string {
		return 'woocommerce';
	}

	public function label(): string {
		return 'WooCommerce';
	}

	public function is_available(): bool {
		return class_exists( 'WooCommerce' ) && ! self::too_old();
	}

	/** Active but older than MIN_VERSION: forms are not served. */
	public static function too_old(): bool {
		return class_exists( 'WooCommerce' ) && version_compare( self::version(), self::MIN_VERSION, '<' );
	}

	public static function version(): string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '0';
	}

	public function register(): void {
		$plugin = Plugin::instance();

		$this->carriers()->register();
		( new \BapiForms\Commerce\WooCommerce\CarrierVisibility() )->register();
		$bridge = new OrderBridge( $plugin->applications, $plugin->options );
		$bridge->register();
		( new \BapiForms\Commerce\WooCommerce\OrderLines() )->register();
		( new \BapiForms\Commerce\WooCommerce\FeeDisplay() )->register();
		( new \BapiForms\Commerce\WooCommerce\AsicPayment( $plugin->applications ) )->register();
		( new \BapiForms\Monitoring\Monitoring( $plugin ) )->register();
		$downloads = new \BapiForms\Documents\Downloads( $plugin );
		$downloads->register();
		( new \BapiForms\Emails\Emails( $plugin, $downloads ) )->register();
		( new \BapiForms\Commerce\WooCommerce\PayFirstProducts() )->register();
		( new \BapiForms\Commerce\WooCommerce\PayFirstCart() )->register();
		( new \BapiForms\Commerce\WooCommerce\PrepaidAdmin() )->register();
		( new \BapiForms\Emails\PrepaidEmails() )->register();
		// After a re-activation: requeue the releases and polls that deactivation removed.
		add_action( 'init', static fn () => \BapiForms\Lifecycle::resume_jobs(), 20 );
		add_action( \BapiForms\Lifecycle::HOOK_RESUME, static fn ( $page = 1 ) => \BapiForms\Lifecycle::resume_batch( $bridge, $page ) );
	}

	public function start_checkout( string $application_ref, string $product, array $addon_products, array $government = array() ): string|\WP_Error {
		if ( null === WC()->cart ) {
			wc_load_cart(); // REST requests do not load the cart by default.
		}

		$cart = WC()->cart;
		if ( ! $cart instanceof \WC_Cart ) {
			return new \WP_Error( 'bapi_forms_cart', __( 'The cart is not available.', 'businessapi-forms' ), array( 'status' => 500 ) );
		}

		$addons = array_values( array_diff( array_unique( $addon_products ), array( $product ) ) );
		$gov    = isset( $government['amount'] ) && is_numeric( $government['amount'] ) && (float) $government['amount'] > 0
			? array(
				'kind'   => sanitize_key( (string) ( $government['kind'] ?? $product ) ),
				'label'  => sanitize_text_field( (string) ( $government['label'] ?? \BapiForms\Products\PriceList::government_label( $product ) ) ),
				'amount' => round( (float) $government['amount'], 2 ),
			)
			: null;

		if ( Carriers::lines_for( $cart, $application_ref ) ) {
			if ( Carriers::ref_complete( $cart, $application_ref, $product, $addons, $gov ) ) {
				return wc_get_checkout_url(); // Already in the cart (double submit, back button).
			}
			Carriers::remove_ref( $cart, $application_ref ); // Incomplete or stale: rebuild it.
		}

		// One line per application, add-ons merged in. The government fee is a cart fee (Carriers::add_fees()).
		$line = array(
			'bapi_application_ref' => $application_ref,
			'bapi_product'         => $product,
			'bapi_carrier'         => $product,
			'bapi_fee'             => 'service',
			'bapi_v'               => Carriers::VERSION,
			'bapi_addons'          => $addons,
			// Release and cancel go to the environment the application lives in.
			'bapi_env'             => Plugin::instance()->options->environment(),
		);
		// No null values: WooCommerce trims every scalar when it builds the cart id.
		if ( null !== $gov ) {
			$line['bapi_gov'] = $gov;
		}
		$product_id = $this->carriers()->ensure( $product );
		$key        = $product_id > 0 ? $this->carriers()->add( $cart, $product_id, $line ) : false;

		if ( ! $key ) {
			Carriers::remove_ref( $cart, $application_ref );
			return new \WP_Error( 'bapi_forms_cart', __( 'Could not add the application to the cart.', 'businessapi-forms' ), array( 'status' => 500 ) );
		}

		$cart->calculate_totals();

		return wc_get_checkout_url();
	}

	public function refs_in_cart( string $product, string $env ): array {
		if ( null === WC()->cart ) {
			wc_load_cart();
		}
		$cart = WC()->cart;
		if ( ! $cart instanceof \WC_Cart ) {
			return array();
		}
		$refs = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( is_array( $item ) && ! empty( $item['bapi_carrier'] ) && ( $item['bapi_product'] ?? '' ) === $product && ( $item['bapi_env'] ?? '' ) === $env ) {
				$refs[] = strtoupper( (string) ( $item['bapi_application_ref'] ?? '' ) );
			}
		}
		return array_values( array_unique( array_filter( $refs ) ) );
	}

	private function carriers(): Carriers {
		if ( null === $this->carriers ) {
			$this->carriers = new Carriers( Plugin::instance()->products );
		}
		return $this->carriers;
	}
}
