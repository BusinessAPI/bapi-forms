<?php
/**
 * admin-ajax fallback for the form's REST routes, for sites that block the REST API for visitors.
 *
 * Runs the same REST route internally (rest_do_request), so the nonce check, arguments, rate
 * limits and serving gate are identical. Only the plugin's own form routes are reachable.
 *
 * @package BapiForms
 */

namespace BapiForms\Frontend;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class AjaxBridge {

	public const ACTION = 'bapi_forms_call';

	/** Form routes the script may reach through admin-ajax (all POST). */
	private const ROUTES = array( 'session', 'cart', 'amend-session', 'amended', 'prepaid-session', 'prepaid-done', 'prepaid-ready' );

	/** The routes take a slug and refs only. */
	private const MAX_BODY = 8192;

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	public static function url(): string {
		return add_query_arg( 'action', self::ACTION, admin_url( 'admin-ajax.php' ) );
	}

	public function handle(): void {
		nocache_headers();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the route's own permission callback checks the nonce.
		$route = isset( $_GET['route'] ) ? sanitize_key( wp_unslash( $_GET['route'] ) ) : '';
		if ( ! in_array( $route, self::ROUTES, true ) || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			wp_send_json(
				array(
					'code'    => 'bapi_forms_unavailable',
					'message' => __( 'This form is not available.', 'businessapi-forms' ),
				),
				404
			);
		}

		$raw  = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_BODY + 1 );
		$body = strlen( $raw ) <= self::MAX_BODY ? json_decode( $raw, true ) : null;

		$request = new WP_REST_Request( 'POST', '/' . RestController::NAMESPACE . '/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( is_array( $body ) ? $body : array() ) );
		if ( isset( $_SERVER['HTTP_X_BAPI_FORMS_NONCE'] ) ) {
			$request->set_header( RestController::NONCE_HEADER, sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BAPI_FORMS_NONCE'] ) ) );
		}
		// RestController::form_page() reads the form page from it.
		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$request->set_header( 'Referer', esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
		}

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		wp_send_json( $data, $response->get_status() );
	}
}
