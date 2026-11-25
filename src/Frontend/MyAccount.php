<?php
/**
 * Customer self-service: amend a refused application, and continue an unfinished one.
 *
 * Amend opens the order page with ?bapi_amend=REF, shown only to the order's owner as WooCommerce
 * checks it. The page carries a short-lived signed proof (HMAC, nothing stored) that the REST
 * routes take instead of the order key. Resubmitting is free and lodges straight away.
 *
 * Logged-in customers' drafts are kept as opaque refs in user meta and listed on the dashboard.
 *
 * @package BapiForms
 */

namespace BapiForms\Frontend;

use BapiForms\Applications\Applications;
use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Commerce\WooCommerce\StatusText;
use BapiForms\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class MyAccount {

	/** Query arg on the order page that opens the amend form for one application. */
	public const AMEND_ARG = 'bapi_amend';

	/** Query args on a form page that reopen a customer's draft. */
	public const RESUME_ARG         = 'bapi_resume';
	public const RESUME_PRODUCT_ARG = 'bapi_product';

	/** User meta: [{ref, product, env, page, at}] drafts this customer started (opaque refs only). */
	public const USER_DRAFTS = '_bapi_forms_drafts';

	private const MAX_DRAFTS    = 10;
	private const SHOWN_DRAFTS  = 5;
	private const RATE_WINDOW   = 10 * MINUTE_IN_SECONDS;
	private const AMEND_LIMIT   = 20;
	private const FIRST_POLL_IN = MINUTE_IN_SECONDS;

	/** Lifetime of the signed amend proof on the order page. */
	public const PROOF_TTL = 2 * HOUR_IN_SECONDS;

	/** /amended moves the next status check forward at most once per order and ref in this window. */
	private const NUDGE_WINDOW = 5 * MINUTE_IN_SECONDS;

	/** ...and resets the poll backoff at most this many times per application. */
	private const MAX_NUDGES = 5;

	/** My Account "Continue your application": BAPI summary status cached per ref (status only). */
	private const SUMMARY_TTL = 5 * MINUTE_IN_SECONDS;

	/** Owner decision: refunded, cancelled, failed or disputed orders cannot be amended. */
	private const NO_AMEND_STATUSES = array( 'cancelled', 'refunded', 'failed', 'trash', 'on-hold', 'pending', 'checkout-draft' );

	/** Products with an amend flow in BAPI. */
	public const AMENDABLE_PRODUCTS = array( 'company_registration', 'abn_registration', 'business_name' );

	public function __construct( private readonly Plugin $plugin, private readonly Shortcode $shortcode ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'amend_form' ), 6 );
		add_action( 'woocommerce_account_dashboard', array( $this, 'drafts_list' ) );
		// WooCommerce's guest email check posts back to the order received URL: keep ?bapi_amend.
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'keep_amend_arg' ), 10, 2 );
	}

	/**
	 * @param string|mixed $url   Order received URL.
	 * @param mixed        $order Order.
	 */
	public function keep_amend_arg( $url, $order = null ) {
		$ref = self::requested_ref();
		if ( '' === $ref || ! is_string( $url ) || ! $order instanceof \WC_Order || (int) $order->get_customer_id() > 0 ) {
			return $url;
		}
		return add_query_arg( self::AMEND_ARG, rawurlencode( $ref ), $url );
	}

	public function routes(): void {
		$args = array(
			'order'          => array(
				'type'     => 'integer',
				'required' => true,
			),
			// Signed by amend_form() for this order and ref (see proof()).
			'proof'          => array(
				'type'     => 'string',
				'required' => true,
			),
			'applicationRef' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		foreach ( array(
			'amend-session' => 'amend_session',
			'amended'       => 'amended',
		) as $route => $callback ) {
			register_rest_route(
				RestController::NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( RestController::class, 'check_nonce' ),
					'args'                => $args,
				)
			);
		}
	}

	/** Same CSRF speed bump as the form routes; ownership is checked per order below. */
	/** Link that opens the amend form: My Account order view, or the order received page for guests. */
	public static function amend_url( \WC_Order $order, string $ref ): string {
		$base = $order->get_customer_id() > 0 ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
		return add_query_arg( self::AMEND_ARG, rawurlencode( strtoupper( $ref ) ), $base ) . '#bapi-forms-amend';
	}

	/** Whether $ref can be amended now. Ownership is checked separately. */
	public static function can_amend( \WC_Order $order, string $ref ): bool {
		$ref = strtoupper( $ref );
		if ( ! Applications::is_ref( $ref ) || ! in_array( $ref, OrderBridge::item_refs( $order ), true ) ) {
			return false;
		}
		$app = OrderBridge::apps( $order )[ $ref ] ?? null;
		return is_array( $app )
			&& 'released' === ( $app['state'] ?? '' )
			&& 'action_needed' === ( $app['status'] ?? '' )
			&& ! empty( $app['amendable'] )
			&& in_array( (string) ( $app['product'] ?? '' ), self::AMENDABLE_PRODUCTS, true )
			&& self::order_allows_amend( $order, $ref, $app );
	}

	/**
	 * The order still stands behind the application (paid, not refunded or disputed, its lines not
	 * refunded, not closed in Business API).
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function order_allows_amend( \WC_Order $order, string $ref, array $app ): bool {
		if ( ! empty( $app['closed'] ) || ! empty( $app['refunded'] ) || ! $order->is_paid() || $order->has_status( self::NO_AMEND_STATUSES ) ) {
			return false;
		}
		$refunded = class_exists( \BapiForms\Commerce\WooCommerce\OrderLines::class ) ? \BapiForms\Commerce\WooCommerce\OrderLines::refunded_refs( $order ) : array();
		return ! in_array( strtoupper( $ref ), array_map( 'strtoupper', $refunded ), true );
	}

	/**
	 * "Amend" button for "Your applications" (escaped HTML), or ''.
	 *
	 * @param array<string, mixed> $app Tracked application (unused, kept for callers).
	 */
	public static function amend_button( \WC_Order $order, string $ref, array $app = array() ): string {
		unset( $app );
		if ( ! self::can_amend( $order, $ref ) || self::requested_ref() === strtoupper( $ref ) ) {
			return '';
		}
		return sprintf(
			' <a class="woocommerce-button button wp-element-button bapi-forms-amend-button" href="%s">%s</a>',
			esc_url( self::amend_url( $order, $ref ) ),
			esc_html__( 'Amend', 'businessapi-forms' )
		);
	}

	/**
	 * The order page with ?bapi_amend=REF: the amend form under "Your applications".
	 *
	 * @param \WC_Order|mixed $order Order.
	 */
	public function amend_form( $order ): void {
		$ref = self::requested_ref();
		if ( ! $order instanceof \WC_Order || '' === $ref || ! $this->plugin->can_serve_forms() ) {
			return;
		}
		if ( ! self::page_owner( $order ) ) {
			return;
		}
		if ( ! self::can_amend( $order, $ref ) ) {
			echo '<section id="bapi-forms-amend" class="bapi-forms-amend"><p>' . esc_html__( 'This application cannot be amended online right now. If it still needs changes, please contact us.', 'businessapi-forms' ) . '</p></section>';
			return;
		}

		$this->shortcode->enqueue();
		$product = (string) ( OrderBridge::apps( $order )[ $ref ]['product'] ?? '' );

		printf(
			'<section id="bapi-forms-amend" class="bapi-forms-amend"><h2>%1$s</h2><p>%2$s</p><div class="bapi-forms-form" style="max-width:min(%7$dpx,100%%);box-sizing:border-box;" data-product="%3$s" data-amend-ref="%4$s" data-order="%5$d" data-proof="%6$s"><p class="bapi-forms-form__status" role="status" hidden></p></div></section>',
			esc_html__( 'Amend your application', 'businessapi-forms' ),
			esc_html__( 'The government agency asked for corrections. Change the details marked in the form and resubmit. There is no extra charge.', 'businessapi-forms' ),
			esc_attr( $product ),
			esc_attr( $ref ),
			(int) $order->get_id(),
			esc_attr( self::proof( $order, $ref ) ),
			(int) Shortcode::MAX_WIDTH
		);
	}

	/** POST /amend-session {order, proof, applicationRef} -> {url, applicationRef}. */
	public function amend_session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$checked = $this->checked( $request );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		[ $order, $ref, $app ] = $checked;

		if ( ! self::can_amend( $order, $ref ) ) {
			return new WP_Error( 'bapi_forms_not_amendable', __( 'This application cannot be amended online right now. If it still needs changes, please contact us.', 'businessapi-forms' ), array( 'status' => 409 ) );
		}

		$type = (string) $this->plugin->products->bapi_type( (string) $app['product'] );
		// Amend mode is asked for explicitly: BAPI never opens an amendment for a plain session.
		$options = array( 'mode' => 'amend' );
		$brand   = $this->plugin->options->branding_for_bapi();
		if ( $brand ) {
			$options['branding'] = $brand;
		}
		$response = $this->plugin->applications->create_session( $type, RestController::site_origin(), $options, $ref, (string) $app['env'] );

		if ( ! $response->ok() ) {
			$code = $response->code();
			if ( 'already_resubmitted' === $code ) {
				$this->poll_soon( $order, $ref );
				return new WP_Error( 'bapi_forms_already_resubmitted', __( 'Your corrections have already been sent. We will let you know when the application is finished.', 'businessapi-forms' ), array( 'status' => 409 ) );
			}
			if ( 409 === $response->status ) {
				return new WP_Error( 'bapi_forms_not_amendable', __( 'This application cannot be amended online. We will contact you about the next steps.', 'businessapi-forms' ), array( 'status' => 409 ) );
			}
			return new WP_Error( 'bapi_forms_session', __( 'The form could not be loaded. Please try again shortly.', 'businessapi-forms' ), array( 'status' => 502 ) );
		}

		$url = (string) ( $response->data['url'] ?? '' );
		if ( '' === $url || ! str_starts_with( $url, RestController::bapi_origin() . '/' ) ) {
			return new WP_Error( 'bapi_forms_session', __( 'The form could not be loaded. Please try again shortly.', 'businessapi-forms' ), array( 'status' => 502 ) );
		}

		do_action(
			'bapi_forms_audit',
			'amend_started',
			array(
				'orderId'     => (int) $order->get_id(),
				'ref'         => $ref,
				'environment' => (string) $app['env'],
			)
		);

		$out = new WP_REST_Response(
			array(
				'url'            => esc_url_raw( $url ),
				'applicationRef' => $ref,
			)
		);
		$out->header( 'Cache-Control', 'no-store' );
		return $out;
	}

	/**
	 * POST /amended {order, proof, applicationRef}: the correction was resubmitted. Only moves the
	 * next status check forward, throttled; Business API stays the source of truth.
	 */
	public function amended( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$checked = $this->checked( $request );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		[ $order, $ref, $app ] = $checked;
		$acted                 = 'released' === ( $app['state'] ?? '' )
			&& in_array( (string) ( $app['status'] ?? '' ), array( 'action_needed', 'lodged' ), true )
			&& ! in_array( (string) ( $app['status'] ?? '' ), Applications::TERMINAL, true )
			&& $this->poll_soon( $order, $ref );
		if ( $acted ) {
			do_action(
				'bapi_forms_audit',
				'resubmitted',
				array(
					'orderId'     => (int) $order->get_id(),
					'ref'         => $ref,
					'environment' => (string) $app['env'],
				)
			);
		}
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Serving gate, rate limit, order and ownership checks shared by both routes.
	 *
	 * @return array{0: \WC_Order, 1: string, 2: array<string, mixed>}|WP_Error
	 */
	private function checked( WP_REST_Request $request ): array|WP_Error {
		if ( ! $this->plugin->can_serve_forms() || ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'bapi_forms_unavailable', __( 'This form is not available.', 'businessapi-forms' ), array( 'status' => 404 ) );
		}
		$ip = (string) apply_filters( 'bapi_forms_client_ip', $this->plugin->options->client_ip() );
		if ( RateLimiter::hit( 'amend', RestController::rate_subject( $ip ), self::AMEND_LIMIT, self::RATE_WINDOW ) ) {
			return new WP_Error( 'bapi_forms_rate_limited', __( 'Too many requests. Please wait a few minutes and try again.', 'businessapi-forms' ), array( 'status' => 429 ) );
		}

		$ref   = strtoupper( (string) $request->get_param( 'applicationRef' ) );
		$order = wc_get_order( (int) $request->get_param( 'order' ) );
		$app   = $order instanceof \WC_Order ? ( OrderBridge::apps( $order )[ $ref ] ?? null ) : null;
		// One answer for every failure, so order ids and refs cannot be probed. Only a genuine
		// proof that expired gets its own answer.
		$proof = (string) $request->get_param( 'proof' );
		if ( ! Applications::is_ref( $ref ) || ! $order instanceof \WC_Order || ! is_array( $app )
			|| ! in_array( $ref, OrderBridge::item_refs( $order ), true )
			|| ! self::proof_valid( $order, $ref, $proof ) ) {
			if ( $order instanceof \WC_Order && Applications::is_ref( $ref ) && self::proof_expired( $order, $ref, $proof ) ) {
				return new WP_Error( 'bapi_forms_proof_expired', __( 'This page has been open a while. Reload the page to keep editing.', 'businessapi-forms' ), array( 'status' => 403 ) );
			}
			return new WP_Error( 'bapi_forms_forbidden', __( 'Please log in to the account you ordered with, or open the link from your order email.', 'businessapi-forms' ), array( 'status' => 403 ) );
		}
		return array( $order, $ref, $app );
	}

	/**
	 * The visitor owns the order as WooCommerce checks it: the logged-in customer, or for guest
	 * orders the order key in the URL with WooCommerce's email verification passed.
	 */
	public static function page_owner( \WC_Order $order ): bool {
		$customer = (int) $order->get_customer_id();
		if ( $customer > 0 ) {
			return get_current_user_id() === $customer;
		}
		$key = self::page_order_key();
		if ( '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return false;
		}
		if ( class_exists( \Automattic\WooCommerce\Internal\Utilities\Users::class ) ) {
			// The same answer WooCommerce gave before rendering this page: the email posted from its
			// verify form, with the classic shortcode's nonce (check_submission) or the Order
			// Confirmation block's (_wpnonce), both for the action wc_verify_email.
			$supplied = null;
			foreach ( array( 'check_submission', '_wpnonce' ) as $field ) {
				$nonce = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( '' !== $nonce && wp_verify_nonce( $nonce, 'wc_verify_email' ) && isset( $_POST['email'] ) && is_string( $_POST['email'] ) ) {
					$supplied = sanitize_email( wp_unslash( $_POST['email'] ) );
					break;
				}
			}
			if ( \Automattic\WooCommerce\Internal\Utilities\Users::should_user_verify_order_email( $order->get_id(), $supplied, 'order-received' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Short-lived "expiry.hmac" proof that page_owner() passed, so REST routes never need the
	 * order key. $purpose keeps proofs apart: amend, start or ready (pay first, $ref '').
	 */
	public static function proof( \WC_Order $order, string $ref, ?int $expires = null, string $purpose = 'amend' ): string {
		$expires = $expires ?? time() + self::PROOF_TTL;
		return $expires . '.' . hash_hmac( 'sha256', self::proof_data( $order, $ref, $expires, $purpose ), wp_salt( 'auth' ) );
	}

	/** A proof() for this order and ref that has not expired, from the order's customer if it has one. */
	public static function proof_valid( \WC_Order $order, string $ref, string $proof, string $purpose = 'amend' ): bool {
		if ( ! preg_match( '/^(\d{10})\.([0-9a-f]{64})$/', $proof, $m ) ) {
			return false;
		}
		$expires = (int) $m[1];
		if ( $expires < time() || $expires > time() + self::PROOF_TTL + MINUTE_IN_SECONDS ) {
			return false;
		}
		$customer = (int) $order->get_customer_id();
		if ( $customer > 0 && get_current_user_id() !== $customer ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', self::proof_data( $order, $ref, $expires, $purpose ), wp_salt( 'auth' ) ), $m[2] );
	}

	/** A genuine proof() whose time is up. */
	public static function proof_expired( \WC_Order $order, string $ref, string $proof, string $purpose = 'amend' ): bool {
		if ( ! preg_match( '/^(\d{10})\.([0-9a-f]{64})$/', $proof, $m ) || (int) $m[1] >= time() ) {
			return false;
		}
		$customer = (int) $order->get_customer_id();
		if ( $customer > 0 && get_current_user_id() !== $customer ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', self::proof_data( $order, $ref, (int) $m[1], $purpose ), wp_salt( 'auth' ) ), $m[2] );
	}

	private static function proof_data( \WC_Order $order, string $ref, int $expires, string $purpose = 'amend' ): string {
		return implode( '|', array( 'bapi_forms_' . sanitize_key( $purpose ), get_current_blog_id(), (int) $order->get_id(), strtoupper( $ref ), (int) $order->get_customer_id(), $expires ) );
	}

	/**
	 * Move the next status check for $ref on $order forward (first one in a minute), at most once
	 * per NUDGE_WINDOW, and reset the poll backoff at most MAX_NUDGES times per application.
	 *
	 * @return bool False when throttled (nothing changed).
	 */
	private function poll_soon( \WC_Order $order, string $ref ): bool {
		$order_id = (int) $order->get_id();
		$flag     = 'bapi_forms_nudge_' . md5( $order_id . ':' . $ref );
		if ( false !== get_transient( $flag ) ) {
			return false;
		}
		set_transient( $flag, 1, self::NUDGE_WINDOW );

		OrderBridge::with_lock(
			$order_id,
			function () use ( $order_id, $ref ): void {
				$fresh = wc_get_order( $order_id );
				if ( ! $fresh instanceof \WC_Order ) {
					return;
				}
				$apps = OrderBridge::apps( $fresh );
				if ( ! isset( $apps[ $ref ] ) ) {
					return;
				}
				$nudges = (int) ( $apps[ $ref ]['nudges'] ?? 0 );
				if ( $nudges >= self::MAX_NUDGES ) {
					return; // The normal backoff carries on.
				}
				$apps[ $ref ]['nudges'] = $nudges + 1;
				$apps[ $ref ]['polls']  = 0;
				$fresh->update_meta_data( OrderBridge::META, $apps );
				$fresh->save();
			},
			5
		);
		OrderBridge::poll_soon( $order_id, $ref, self::FIRST_POLL_IN );
		return true;
	}

	private static function requested_ref(): string {
		$ref = isset( $_GET[ self::AMEND_ARG ] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET[ self::AMEND_ARG ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return Applications::is_ref( $ref ) ? $ref : '';
	}

	/** The order key on the order received page (?key=wc_order_...), or ''. */
	private static function page_order_key(): string {
		return isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Bind a draft to the logged-in customer. Guests' drafts stay in their browser session. */
	public static function bind_draft( string $ref, string $product, string $env, string $page ): void {
		$user = get_current_user_id();
		if ( $user <= 0 || ! Applications::is_ref( $ref ) ) {
			return;
		}
		$drafts   = array_values( array_filter( self::drafts( $user ), fn ( $d ) => ( $d['ref'] ?? '' ) !== $ref ) );
		$previous = self::user_draft( $ref );
		$drafts[] = array(
			'ref'     => $ref,
			'product' => sanitize_key( $product ),
			'env'     => 'live' === $env ? 'live' : 'test',
			'page'    => self::clean_page( '' !== $page ? $page : (string) ( $previous['page'] ?? '' ) ),
			'at'      => time(),
		);
		update_user_meta( $user, self::USER_DRAFTS, array_slice( $drafts, -self::MAX_DRAFTS ) );
	}

	/** A same-site form page as scheme, host and path only (no query or fragment), or ''. */
	private static function clean_page( string $page ): string {
		$parts = '' !== $page ? wp_parse_url( $page ) : false;
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$home = wp_parse_url( home_url() );
		if ( strtolower( (string) $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return esc_url_raw( ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $port . ( $parts['path'] ?? '/' ) );
	}

	/** Forget a draft for $user (default: the current user). */
	public static function unbind_draft( string $ref, int $user = 0 ): void {
		$user = $user > 0 ? $user : get_current_user_id();
		if ( $user <= 0 ) {
			return;
		}
		$drafts = self::drafts( $user );
		$kept   = array_values( array_filter( $drafts, fn ( $d ) => ( $d['ref'] ?? '' ) !== $ref ) );
		if ( count( $kept ) !== count( $drafts ) ) {
			update_user_meta( $user, self::USER_DRAFTS, $kept );
		}
	}

	/**
	 * The current customer's draft $ref in RestController's "issued" shape, or null.
	 *
	 * @return array{ref: string, product: string, env: string, carted: bool, page: string}|null
	 */
	public static function user_draft( string $ref ): ?array {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return null;
		}
		foreach ( self::drafts( $user ) as $row ) {
			if ( ( $row['ref'] ?? '' ) === $ref ) {
				return array(
					'ref'     => $ref,
					'product' => (string) ( $row['product'] ?? '' ),
					'env'     => 'live' === ( $row['env'] ?? '' ) ? 'live' : 'test',
					// In a cart (any device): never reopened as a draft until taken out again.
					'carted'  => ! empty( $row['carted'] ),
					'page'    => (string) ( $row['page'] ?? '' ),
				);
			}
		}
		return null;
	}

	/** Mark the current customer's draft as in the cart or out of it again. */
	public static function mark_carted( string $ref, bool $carted ): void {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return;
		}
		$drafts  = self::drafts( $user );
		$changed = false;
		foreach ( $drafts as $i => $row ) {
			if ( ( $row['ref'] ?? '' ) === $ref && ! empty( $row['carted'] ) !== $carted ) {
				$drafts[ $i ]['carted'] = $carted;
				$changed                = true;
			}
		}
		if ( $changed ) {
			update_user_meta( $user, self::USER_DRAFTS, $drafts );
		}
	}

	/** @return list<array<string, mixed>> */
	private static function drafts( int $user ): array {
		$drafts = get_user_meta( $user, self::USER_DRAFTS, true );
		return is_array( $drafts ) ? array_values( array_filter( $drafts, 'is_array' ) ) : array();
	}

	/**
	 * Business API status of a draft, cached so the dashboard does not call it on every visit.
	 * 'gone' when it no longer exists, null when unreachable.
	 */
	private function draft_status( string $ref, string $env ): ?string {
		$key    = 'bapi_forms_sum_' . md5( $env . ':' . $ref );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$summary = $this->plugin->applications->summary( $ref, $env );
		if ( ! $summary->ok() ) {
			if ( 404 !== $summary->status ) {
				return null;
			}
			$status = 'gone';
		} else {
			$status = sanitize_key( (string) ( $summary->data['status'] ?? '' ) );
		}
		if ( '' !== $status ) {
			set_transient( $key, $status, self::SUMMARY_TTL );
		}
		return $status;
	}

	/** My Account dashboard: unfinished drafts in the current environment. Finished or gone ones are forgotten. */
	public function drafts_list(): void {
		$user = get_current_user_id();
		if ( $user <= 0 || ! $this->plugin->can_serve_forms() ) {
			return;
		}
		$env    = $this->plugin->options->environment();
		$labels = $this->plugin->products->all();
		$rows   = array();
		foreach ( array_reverse( self::drafts( $user ) ) as $draft ) {
			if ( count( $rows ) >= self::SHOWN_DRAFTS ) {
				break;
			}
			$ref  = (string) ( $draft['ref'] ?? '' );
			$page = (string) ( $draft['page'] ?? '' );
			// In a cart: checkout carries on from the cart, not from here.
			if ( ! empty( $draft['carted'] ) || ( $draft['env'] ?? '' ) !== $env || ! Applications::is_ref( $ref ) || '' === $page || ! $this->plugin->products->is_enabled( (string) ( $draft['product'] ?? '' ) ) ) {
				continue;
			}
			if ( class_exists( OrderBridge::class ) && OrderBridge::ref_on_other_order( $ref ) ) {
				self::unbind_draft( $ref, $user );
				continue;
			}
			$status = $this->draft_status( $ref, $env );
			if ( null === $status ) {
				continue; // BAPI unreachable: try again on the next visit.
			}
			if ( Applications::STATUS_AWAITING_RELEASE === $status ) {
				continue; // Completed and waiting for checkout (cart or form page), not a draft to edit.
			}
			if ( 'draft' !== $status ) {
				self::unbind_draft( $ref, $user );
				continue;
			}
			$rows[] = array( $draft, $ref, $page );
		}
		if ( ! $rows ) {
			return;
		}

		echo '<section class="bapi-forms-drafts"><h2>' . esc_html__( 'Continue your application', 'businessapi-forms' ) . '</h2><ul>';
		foreach ( $rows as [ $draft, $ref, $page ] ) {
			$url = add_query_arg(
				array(
					self::RESUME_ARG         => rawurlencode( $ref ),
					self::RESUME_PRODUCT_ARG => rawurlencode( (string) $draft['product'] ),
				),
				$page
			);
			printf(
				'<li><strong>%1$s (%2$s)</strong> <a class="woocommerce-button button wp-element-button" href="%3$s">%4$s</a></li>',
				esc_html( (string) ( $labels[ $draft['product'] ]['label'] ?? __( 'Application', 'businessapi-forms' ) ) ),
				esc_html( StatusText::short_ref( $ref ) ),
				esc_url( $url ),
				esc_html__( 'Continue your application', 'businessapi-forms' )
			);
		}
		echo '</ul></section>';
	}
}
