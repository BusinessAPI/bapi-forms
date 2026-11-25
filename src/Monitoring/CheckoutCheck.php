<?php
/**
 * Monitoring duplicate check at checkout submit, for logged-in customers.
 *
 * Catches a second tab's order or a guest who logs in at checkout. BAPI does not re-check
 * ownership at release, so this is the last guard; it fails open when BAPI is unreachable.
 *
 * @package BapiForms
 */

namespace BapiForms\Monitoring;

use BapiForms\Frontend\RestController;
use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class CheckoutCheck {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'classic' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'store_api' ), 20, 2 );
	}

	/**
	 * @param array     $data   Posted data.
	 * @param \WP_Error $errors Errors.
	 */
	public function classic( $data, $errors ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}
		$problem = $this->problem( WC()->cart, get_current_user_id() );
		if ( null !== $problem ) {
			$errors->add( 'bapi_forms_duplicate', $problem );
		}
	}

	/**
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the entity is a duplicate.
	 */
	public function store_api( $order, $request ): void {
		unset( $order, $request );
		$problem = $this->problem( WC()->cart, get_current_user_id() );
		if ( null !== $problem && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'bapi_forms_duplicate', esc_html( $problem ), 409 );
		}
	}

	/**
	 * Refusal message for the first monitoring line that duplicates another line, a waiting order
	 * or an existing subscription, else null. Guests were already checked at /cart.
	 */
	public function problem( ?\WC_Cart $cart, int $user_id ): ?string {
		if ( ! $cart instanceof \WC_Cart || $user_id <= 0 ) {
			return null;
		}
		$lines = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( ! is_array( $item ) || empty( $item['bapi_carrier'] ) ) {
				continue;
			}
			$product = (string) ( $item['bapi_product'] ?? '' );
			$ref     = strtoupper( (string) ( $item['bapi_application_ref'] ?? '' ) );
			$env     = (string) ( $item['bapi_env'] ?? '' );
			if ( '' === $ref || '' === $env || 'monitoring' !== $this->plugin->products->bapi_type( $product ) ) {
				continue;
			}
			$lines[ $env ][] = $ref;
		}
		foreach ( $lines as $env => $refs ) {
			$refs = array_values( array_unique( $refs ) );
			foreach ( $refs as $ref ) {
				[ $in_cart, $owned ] = RestController::monitoring_compare( $user_id, (string) $env, array_values( array_diff( $refs, array( $ref ) ) ) );
				if ( array() === $in_cart && array() === $owned ) {
					continue; // Nothing to compare with.
				}
				$response = $this->plugin->applications->summary( $ref, (string) $env, array_merge( $in_cart, $owned ) );
				if ( ! $response->ok() ) {
					continue; // Fail open: never lose a sale while BAPI is away.
				}
				$duplicate = RestController::monitoring_duplicate( (array) ( $response->data['monitoring']['sameEntity'] ?? array() ), $in_cart, $owned );
				if ( null !== $duplicate ) {
					return $duplicate->get_error_message();
				}
			}
		}
		return null;
	}
}
