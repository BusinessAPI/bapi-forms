<?php
/**
 * Public REST routes used by the form embed script.
 *
 * GET  admin-ajax.php?action=bapi_forms_nonce                     -> {nonce, restNonce, visitor}
 * POST /wp-json/bapi-forms/v1/session  {product, applicationRef?}  -> {url, applicationRef}
 * POST /wp-json/bapi-forms/v1/cart     {applicationRef, product}   -> {redirect}; 503 = try again
 *
 * The nonce is only a CSRF speed bump (anyone can fetch one). The real controls are the rate
 * limits, the serving gate, binding refs to the visitor's WooCommerce session, and Business
 * API's own checks. The site-wide hourly cap counts only applications that reached checkout,
 * so scripted empty drafts cannot lock a site out.
 *
 * @package BapiForms
 */

namespace BapiForms\Frontend;

use BapiForms\Monitoring\Entities;
use BapiForms\Applications\Applications;
use BapiForms\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class RestController {

	public const NAMESPACE    = 'bapi-forms/v1';
	public const NONCE_ACTION = 'bapi_forms';
	public const NONCE_HEADER = 'X-Bapi-Forms-Nonce';

	private const RATE_WINDOW = 10 * MINUTE_IN_SECONDS;

	/** New applications per hour per IPv4 address or IPv6 /64 (filter bapi_forms_new_application_limit). */
	private const NEW_PER_SUBJECT = 10;

	/** New applications per hour per IPv6 /48 (one network holds 65,536 /64s). */
	private const NEW_PER_48 = 30;

	/** WC session key: [{ref, product, env}] issued to this visitor (opaque refs only). */
	public const SESSION_REFS = 'bapi_forms_refs';
	private const MAX_REFS    = 10;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_ajax_bapi_forms_nonce', array( $this, 'nonce' ) );
		add_action( 'wp_ajax_nopriv_bapi_forms_nonce', array( $this, 'nonce' ) );
		// Once a ref is on an order it is no longer the visitor's open application.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'forget_ordered_refs' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'forget_ordered_refs' ) );
	}

	/** Nonces come from this uncached endpoint so full-page caching never serves a stale one. */
	public function nonce(): void {
		nocache_headers();

		wp_send_json(
			array(
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'visitor'   => self::visitor_id(),
			)
		);
	}

	/**
	 * Opaque id of who is using this browser now (HMAC of the user and login session, or the
	 * guest's WooCommerce session), so the embed script can drop a tab's saved ref or a
	 * back/forward-cached form that belongs to someone else. Changes on logout, not on nonce ticks.
	 */
	public static function visitor_id(): string {
		$user = get_current_user_id();
		if ( $user > 0 ) {
			$raw = 'u|' . $user . '|' . wp_get_session_token();
		} else {
			$session = function_exists( 'WC' ) ? WC()->session : null;
			$id      = $session instanceof \WC_Session && ( ! method_exists( $session, 'has_session' ) || $session->has_session() ) ? (string) $session->get_customer_id() : '';
			$raw     = 'g|' . $id;
		}
		return substr( hash_hmac( 'sha256', 'bapi_forms_visitor|' . $raw, wp_salt( 'nonce' ) ), 0, 32 );
	}

	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'session' ),
				'permission_callback' => array( self::class, 'check_nonce' ),
				'args'                => array(
					'product'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'applicationRef' => array(
						'type'     => 'string',
						'required' => false,
					),
					// Only reopen applicationRef, never start a new application (My Account link).
					'resumeOnly'     => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cart',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cart' ),
				'permission_callback' => array( self::class, 'check_nonce' ),
				'args'                => array(
					'product'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'applicationRef' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * permission_callback of every visitor route: the page's nonce in the X-Bapi-Forms-Nonce header.
	 * Own nonce, because core only checks "wp_rest" for logged-in users.
	 */
	public static function check_nonce( WP_REST_Request $request ): bool|WP_Error {
		$nonce = (string) $request->get_header( self::NONCE_HEADER );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error( 'bapi_forms_nonce', __( 'Your session has expired. Please refresh the page.', 'businessapi-forms' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product = (string) $request->get_param( 'product' );

		$problem = $this->gate( $product, 'session', 20 );
		if ( null !== $problem ) {
			return $problem;
		}

		// Resume the tab's application, only when this visitor's /session issued it.
		$asked  = strtoupper( (string) $request->get_param( 'applicationRef' ) );
		$issued = Applications::is_ref( $asked ) ? $this->issued( $asked ) : null;
		// Or a draft this logged-in customer started (My Account "Continue your application").
		if ( null === $issued && Applications::is_ref( $asked ) ) {
			$issued = MyAccount::user_draft( $asked );
		}
		$resume      = null !== $issued && ! $issued['carted'] && $issued['product'] === $product && $issued['env'] === $this->plugin->options->environment() ? $asked : null;
		$resume_only = (bool) $request->get_param( 'resumeOnly' );

		// Once on an order the order owns it; amendments go through the order page.
		if ( null !== $resume && class_exists( '\BapiForms\Commerce\WooCommerce\OrderBridge' ) && function_exists( 'wc_get_orders' )
			&& \BapiForms\Commerce\WooCommerce\OrderBridge::ref_on_other_order( $resume ) ) {
			$this->forget_ref( $resume );
			MyAccount::unbind_draft( $resume );
			$resume = null;
			if ( $resume_only ) {
				return new WP_Error( 'bapi_forms_ref_ordered', __( 'This application is already on an order. You can follow it from your order.', 'businessapi-forms' ), array( 'status' => 409 ) );
			}
		}
		if ( $resume_only && null === $resume ) {
			return self::resume_unavailable();
		}

		// An application the visitor took out of the cart (Carriers::on_remove) reopens once on
		// its form page, on the "Continue to checkout" screen, instead of starting a new one.
		if ( null === $resume ) {
			$resume = $this->take_returned( $product );
		}

		// Pay first: new applications start from the shop. Older drafts still resume above.
		if ( null === $resume && \BapiForms\Settings\Options::FLOW_PAY_FIRST === $this->plugin->options->flow( $product ) ) {
			return self::pay_first_refusal( $product );
		}

		// Caps on new applications (resuming one does not count).
		if ( null === $resume ) {
			$limited = $this->new_application_limited();
			if ( null !== $limited ) {
				return $limited;
			}
		}

		$options  = array(
			'addons'      => $this->plugin->products->active_addons( $product ),
			'asicNotices' => $this->plugin->options->asic_notices_for_bapi(),
			// Always form first here: pay-first applications are created by the order (OrderBridge).
			'flow'        => \BapiForms\Settings\Options::FLOW_FORM_FIRST,
		);
		$branding = $this->plugin->options->branding_for_bapi();
		if ( $branding ) {
			$options['branding'] = $branding;
		}

		$type = (string) $this->plugin->products->bapi_type( $product );
		if ( 'monitoring' === $type ) {
			// So the form can say "you already monitor this". Always sent: an empty list replaces an older one.
			$options['monitoring'] = array( 'ownedRefs' => Entities::owned_refs( get_current_user_id(), $this->plugin->options->environment() ) );
		}
		$response = $this->plugin->applications->create_session( $type, self::site_origin(), $options, $resume );
		if ( null !== $resume && ! $response->ok() ) {
			// Expired, cancelled or lodged: forget it and start a new application (never for a
			// resume-only request: the visitor clicks Start for a new one).
			$this->forget_ref( $resume );
			MyAccount::unbind_draft( $resume );
			if ( $resume_only ) {
				return self::resume_unavailable();
			}
			if ( \BapiForms\Settings\Options::FLOW_PAY_FIRST === $this->plugin->options->flow( $product ) ) {
				return self::pay_first_refusal( $product );
			}
			$limited = $this->new_application_limited();
			if ( null !== $limited ) {
				return $limited;
			}
			$response = $this->plugin->applications->create_session( $type, self::site_origin(), $options );
		}

		$url = $response->ok() ? (string) ( $response->data['url'] ?? '' ) : '';
		$ref = $response->ok() ? strtoupper( (string) ( $response->data['applicationRef'] ?? '' ) ) : '';

		// Only ever frame Business API itself.
		if ( '' === $url || ! str_starts_with( $url, self::bapi_origin() . '/' ) ) {
			return new WP_Error( 'bapi_forms_session', __( 'The form could not be loaded. Please try again shortly.', 'businessapi-forms' ), array( 'status' => 502 ) );
		}

		if ( Applications::is_ref( $ref ) && ! $this->remember_ref( $ref, $product ) ) {
			return new WP_Error( 'bapi_forms_session', __( 'The form could not be loaded. Please enable cookies and try again.', 'businessapi-forms' ), array( 'status' => 500 ) );
		}
		// Logged-in customers can also continue it later from My Account (opaque ref only).
		if ( Applications::is_ref( $ref ) ) {
			MyAccount::bind_draft( $ref, $product, $this->plugin->options->environment(), self::form_page( $request ) );
		}

		$out = new WP_REST_Response(
			array(
				'url'            => esc_url_raw( $url ),
				'applicationRef' => Applications::is_ref( $ref ) ? $ref : null,
				// Now that the visitor has a WooCommerce session: the id the tab keeps with the ref.
				'visitor'        => self::visitor_id(),
			)
		);
		$out->header( 'Cache-Control', 'no-store' );
		return $out;
	}

	/** 409 bapi_forms_pay_first: this product is bought first; the answer carries its shop page. */
	private static function pay_first_refusal( string $product ): WP_Error {
		$shop = class_exists( '\BapiForms\Commerce\WooCommerce\PayFirstProducts' ) ? \BapiForms\Commerce\WooCommerce\PayFirstProducts::product_for( $product ) : null;
		return new WP_Error(
			'bapi_forms_pay_first',
			__( 'Please buy this first. You fill in the form right after payment.', 'businessapi-forms' ),
			array(
				'status'     => 409,
				'productUrl' => null !== $shop && 'publish' === $shop->get_status() ? esc_url_raw( $shop->get_permalink() ) : null,
			)
		);
	}

	public function cart( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product = (string) $request->get_param( 'product' );
		$ref     = strtoupper( (string) $request->get_param( 'applicationRef' ) );

		$problem = $this->gate( $product, 'cart', 30 );
		if ( null !== $problem ) {
			return $problem;
		}

		if ( ! Applications::is_ref( $ref ) ) {
			return new WP_Error( 'bapi_forms_ref', __( 'Invalid application reference.', 'businessapi-forms' ), array( 'status' => 400 ) );
		}

		// Only refs this visitor's own /session calls issued.
		$issued = $this->issued( $ref );
		if ( null === $issued || $issued['product'] !== $product ) {
			return new WP_Error( 'bapi_forms_ref', __( 'This application was not started in this browser. Please start the form again.', 'businessapi-forms' ), array( 'status' => 403 ) );
		}

		// The cart line records the current environment for release; never mix them.
		if ( $issued['env'] !== $this->plugin->options->environment() ) {
			return new WP_Error( 'bapi_forms_ref', __( 'The form settings changed. Please start the form again.', 'businessapi-forms' ), array( 'status' => 409 ) );
		}

		// A ref goes on one order only.
		if ( class_exists( '\BapiForms\Commerce\WooCommerce\OrderBridge' ) && function_exists( 'wc_get_orders' )
			&& \BapiForms\Commerce\WooCommerce\OrderBridge::ref_on_other_order( $ref ) ) {
			return new WP_Error( 'bapi_forms_ref_ordered', __( 'This application is already on an order.', 'businessapi-forms' ), array( 'status' => 409 ) );
		}

		// Monitoring: never the same entity twice in a cart or one the customer already monitors.
		// The form checks too; this is the backstop (e.g. two tabs).
		$monitoring = 'monitoring' === $this->plugin->products->bapi_type( $product );
		$in_cart    = array();
		$owned      = array();
		if ( $monitoring ) {
			$adapter_now         = $this->plugin->commerce->primary();
			$in_cart             = null !== $adapter_now ? array_values( array_diff( $adapter_now->refs_in_cart( $product, $issued['env'] ), array( $ref ) ) ) : array();
			[ $in_cart, $owned ] = self::monitoring_compare( get_current_user_id(), $issued['env'], $in_cart );
		}

		// Confirms the ref belongs to this partner and is ready to pay for. No applicant data.
		$response = $this->plugin->applications->summary( $ref, $issued['env'], array_merge( $in_cart, $owned ) );
		if ( ! $response->ok() ) {
			// BAPI down, timed out, rate limited or busy: the browser may try again.
			if ( $response->is_transient() ) {
				return new WP_Error( 'bapi_forms_retry', __( 'We could not reach our registration service. Your application is saved, please try again in a moment.', 'businessapi-forms' ), array( 'status' => 503 ) );
			}
			return new WP_Error( 'bapi_forms_ref', __( 'This application is not ready for checkout.', 'businessapi-forms' ), array( 'status' => 409 ) );
		}
		$type   = (string) ( $response->data['type'] ?? '' );
		$status = (string) ( $response->data['status'] ?? '' );
		if ( Applications::STATUS_AWAITING_RELEASE !== $status || $type !== $this->plugin->products->bapi_type( $product ) ) {
			return new WP_Error( 'bapi_forms_ref', __( 'This application is not ready for checkout.', 'businessapi-forms' ), array( 'status' => 409 ) );
		}
		if ( $monitoring ) {
			$duplicate = self::monitoring_duplicate( (array) ( $response->data['monitoring']['sameEntity'] ?? array() ), $in_cart, $owned );
			if ( null !== $duplicate ) {
				return $duplicate;
			}
		}
		$inputs = $this->plugin->expected->inputs( $response->data, $product );
		if ( is_wp_error( $inputs ) ) {
			return $inputs; // 409: an add-on this site cannot price is never dropped.
		}

		$adapter = $this->plugin->commerce->primary();
		if ( null === $adapter ) {
			return new WP_Error( 'bapi_forms_unavailable', __( 'This form is not available.', 'businessapi-forms' ), array( 'status' => 404 ) );
		}

		// Add-ons come from Business API, not the browser. The government fee is read fresh (not
		// the cached schedule), so the fee charged at checkout is the fee checked at release.
		$expected = $this->plugin->expected->expected( $product, $inputs['addons'], $inputs['pricing'], true, $issued['env'] );
		if ( is_wp_error( $expected ) ) {
			return $expected; // 503 (retry) when the schedule could not be read, 409 when it has no fee.
		}
		$redirect = $adapter->start_checkout( $ref, $product, $expected['addons'], $expected['government'] ?? array() );
		if ( is_wp_error( $redirect ) ) {
			return $redirect;
		}

		// Reached checkout: counts towards the site-wide cap, once per application.
		if ( ! $issued['counted'] ) {
			RateLimiter::hit( 'site_progressed', 'site', PHP_INT_MAX, HOUR_IN_SECONDS );
		}

		// /session no longer resumes it unless it is taken out of the cart (release_to_form()).
		self::set_ref_flags(
			$ref,
			array(
				'carted'   => true,
				'counted'  => true,
				'returned' => false,
				'page'     => self::form_page( $request ),
			)
		);
		// Logged-in customers: My Account no longer offers it as a draft on another device.
		MyAccount::mark_carted( $ref, true );

		return new WP_REST_Response( array( 'redirect' => esc_url_raw( $redirect ) ) );
	}

	/**
	 * Refs a monitoring duplicate check compares against: [in_cart (incl. the customer's unpaid
	 * orders), owned (paid)]. Guests only have the cart.
	 *
	 * @param list<string> $other_cart_refs Other monitoring refs in this cart.
	 * @return array{0: list<string>, 1: list<string>}
	 */
	public static function monitoring_compare( int $user_id, string $env, array $other_cart_refs ): array {
		$owned   = Entities::owned_refs( $user_id, $env );
		$in_cart = array_values( array_unique( array_merge( $other_cart_refs, Entities::owned_refs( $user_id, $env, false ) ) ) );
		return array( $in_cart, $owned );
	}

	/**
	 * Refusal for a monitoring application whose entity is already in the cart or monitored by
	 * this customer, else null. Same rules as the hosted form: in-cart matches block only while
	 * `open` or `active`; an ended subscription can be ordered again.
	 *
	 * @param array<int, mixed> $same    BAPI /summary monitoring.sameEntity: [{ref, state}].
	 * @param list<string>      $in_cart Other refs in this cart and on the customer's unpaid orders.
	 * @param list<string>      $owned   The logged-in customer's own refs (Entities::owned_refs).
	 */
	public static function monitoring_duplicate( array $same, array $in_cart, array $owned ): ?WP_Error {
		foreach ( $same as $row ) {
			$ref   = Entities::norm( (string) ( is_array( $row ) ? ( $row['ref'] ?? '' ) : '' ) );
			$state = is_array( $row ) ? (string) ( $row['state'] ?? '' ) : '';
			if ( '' !== $ref && in_array( $state, array( 'open', 'active' ), true ) && in_array( $ref, array_map( array( Entities::class, 'norm' ), $in_cart ), true ) ) {
				return new WP_Error( 'bapi_forms_duplicate', __( 'This company or business is already in your cart or on an order waiting for payment. Each one only needs to be ordered once.', 'businessapi-forms' ), array( 'status' => 409 ) );
			}
			if ( '' !== $ref && in_array( $ref, array_map( array( Entities::class, 'norm' ), $owned ), true ) ) {
				if ( 'active' === $state ) {
					return new WP_Error( 'bapi_forms_duplicate', __( "You're already monitoring this company or business. You can see it in My Account > Monitoring.", 'businessapi-forms' ), array( 'status' => 409 ) );
				}
				if ( 'stopped' === $state ) {
					return new WP_Error( 'bapi_forms_duplicate', __( 'You already have monitoring for this company or business, but it is stopped. You can resume it free of charge in My Account > Monitoring.', 'businessapi-forms' ), array( 'status' => 409 ) );
				}
			}
		}
		return null;
	}

	/** Serving gate, product check and rate limit shared by both routes. */
	private function gate( string $product, string $bucket, int $limit ): ?WP_Error {
		if ( ! $this->plugin->can_serve_forms() || ! $this->plugin->products->is_enabled( $product ) || null === $this->plugin->products->bapi_type( $product ) ) {
			return new WP_Error( 'bapi_forms_unavailable', __( 'This form is not available.', 'businessapi-forms' ), array( 'status' => 404 ) );
		}

		if ( $this->rate_limited( $bucket, $limit ) ) {
			return new WP_Error( 'bapi_forms_rate_limited', __( 'Too many requests. Please wait a few minutes and try again.', 'businessapi-forms' ), array( 'status' => 429 ) );
		}

		// Checked here rather than while the page renders, so a slow Business API cannot slow the page.
		if ( ! $this->plugin->products->is_active( $product ) ) {
			return new WP_Error( 'bapi_forms_not_available_now', __( 'Sorry, this form is not available right now. Please try again later or contact us.', 'businessapi-forms' ), array( 'status' => 503 ) );
		}

		return null;
	}

	/**
	 * Count one new application against this visitor's network, and check the site-wide cap of
	 * applications that reached checkout this hour. Null when it may start.
	 */
	private function new_application_limited(): ?WP_Error {
		$cap = $this->plugin->options->session_cap();
		if ( $cap > 0 && RateLimiter::count( 'site_progressed', 'site', HOUR_IN_SECONDS ) >= $cap ) {
			return new WP_Error( 'bapi_forms_rate_limited', __( 'Our form is very busy right now. Please try again in a little while.', 'businessapi-forms' ), array( 'status' => 429 ) );
		}

		$ip      = $this->client_ip();
		$subject = self::rate_subject( $ip );
		$net48   = self::rate_subject_48( $ip );
		/**
		 * Filters how many new applications one network may start per hour.
		 *
		 * @param int    $limit Applications allowed.
		 * @param string $scope "ip" (IPv4 address or IPv6 /64) or "ip48" (IPv6 /48).
		 */
		$per_ip = (int) apply_filters( 'bapi_forms_new_application_limit', self::NEW_PER_SUBJECT, 'ip' );
		$per_48 = (int) apply_filters( 'bapi_forms_new_application_limit', self::NEW_PER_48, 'ip48' );
		// Both buckets count every attempt, so a busy /48 cannot be dodged by rotating /64s.
		$over = RateLimiter::hit( 'session_new', $subject, $per_ip, HOUR_IN_SECONDS );
		if ( null !== $net48 && RateLimiter::hit( 'session_new48', $net48, $per_48, HOUR_IN_SECONDS ) ) {
			$over = true;
		}
		if ( $over ) {
			return new WP_Error( 'bapi_forms_rate_limited', __( 'You have started several applications in the last hour. Please continue one you already started, or try again a little later.', 'businessapi-forms' ), array( 'status' => 429 ) );
		}
		return null;
	}

	private function client_ip(): string {
		/**
		 * Filters the client IP used for rate limiting. Prefer the "Trusted proxy header"
		 * setting; this filter is for unusual setups.
		 *
		 * @param string $ip REMOTE_ADDR, or the trusted proxy header when enabled.
		 */
		return (string) apply_filters( 'bapi_forms_client_ip', $this->plugin->options->client_ip() );
	}

	private function rate_limited( string $bucket, int $limit ): bool {
		/**
		 * Filters the per-client request limit per 10 minutes.
		 *
		 * @param int    $limit  Requests allowed.
		 * @param string $bucket "session" or "cart".
		 */
		$limit = (int) apply_filters( 'bapi_forms_rate_limit', $limit, $bucket );

		return RateLimiter::hit( $bucket, self::rate_subject( $this->client_ip() ), $limit, self::RATE_WINDOW );
	}

	/** The /48 network of a (non IPv4-mapped) IPv6 address, or null for IPv4. */
	public static function rate_subject_48( string $ip ): ?string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return null;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) || str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return null;
		}
		return inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) ) . '/48';
	}

	/** The IPv4 address, or the IPv6 /64 (a home usually gets a whole /64, trivial to rotate within). */
	public static function rate_subject( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}
		if ( str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) ); // IPv4-mapped: the IPv4 address.
		}
		return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	private static function wc_session(): ?\WC_Session {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		if ( null === WC()->session && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart(); // REST requests do not load the session by default.
		}
		return WC()->session instanceof \WC_Session ? WC()->session : null;
	}

	/** @return list<array{ref: string, product: string, env: string}> */
	private static function refs(): array {
		$session = self::wc_session();
		$refs    = $session ? $session->get( self::SESSION_REFS ) : array();
		return is_array( $refs ) ? array_values( array_filter( $refs, 'is_array' ) ) : array();
	}

	private function remember_ref( string $ref, string $product ): bool {
		$session = self::wc_session();
		if ( ! $session ) {
			return false;
		}
		if ( method_exists( $session, 'set_customer_session_cookie' ) && ! $session->has_session() ) {
			$session->set_customer_session_cookie( true );
		}
		$refs   = array_values( array_filter( self::refs(), fn ( $r ) => ( $r['ref'] ?? '' ) !== $ref ) );
		$refs[] = array(
			'ref'     => $ref,
			'product' => $product,
			'env'     => $this->plugin->options->environment(),
		);
		$session->set( self::SESSION_REFS, array_slice( $refs, -self::MAX_REFS ) );
		return true;
	}

	private function forget_ref( string $ref ): void {
		$session = self::wc_session();
		if ( $session ) {
			$session->set( self::SESSION_REFS, array_values( array_filter( self::refs(), fn ( $r ) => ( $r['ref'] ?? '' ) !== $ref ) ) );
		}
	}

	/** @return array{ref: string, product: string, env: string, carted: bool, counted: bool}|null */
	private function issued( string $ref ): ?array {
		foreach ( self::refs() as $row ) {
			if ( ( $row['ref'] ?? '' ) === $ref ) {
				return array(
					'ref'     => $ref,
					'product' => (string) ( $row['product'] ?? '' ),
					'env'     => 'live' === ( $row['env'] ?? '' ) ? 'live' : 'test',
					'carted'  => ! empty( $row['carted'] ),
					'counted' => ! empty( $row['counted'] ),
				);
			}
		}
		return null;
	}

	/**
	 * Merge flags into this visitor's row for a ref: carted, returned (reopened once by
	 * /session), page.
	 *
	 * @param array<string, mixed> $flags Flags.
	 */
	private static function set_ref_flags( string $ref, array $flags ): void {
		$session = self::wc_session();
		if ( $session ) {
			$session->set(
				self::SESSION_REFS,
				array_map( fn ( $r ) => ( $r['ref'] ?? '' ) === $ref ? array_merge( $r, $flags ) : $r, self::refs() )
			);
		}
	}

	/**
	 * Taken out of the cart: its form page reopens it instead of starting a new one. Nothing is
	 * cancelled, so the classic cart's "Undo" still works. Returns the form page URL, or ''.
	 */
	public static function release_to_form( string $ref ): string {
		foreach ( self::refs() as $row ) {
			if ( ( $row['ref'] ?? '' ) === $ref ) {
				self::set_ref_flags(
					$ref,
					array(
						'carted'   => false,
						'returned' => true,
					)
				);
				MyAccount::mark_carted( $ref, false );
				return (string) ( $row['page'] ?? '' );
			}
		}
		return '';
	}

	/** The form page this visitor started the application on, or '' (no state change). */
	public static function form_page_for( string $ref ): string {
		foreach ( self::refs() as $row ) {
			if ( ( $row['ref'] ?? '' ) === $ref ) {
				return (string) ( $row['page'] ?? '' );
			}
		}
		return '';
	}

	/** Classic cart "Undo": the application is back in the cart. */
	public static function back_in_cart( string $ref ): void {
		self::set_ref_flags(
			$ref,
			array(
				'carted'   => true,
				'returned' => false,
			)
		);
		MyAccount::mark_carted( $ref, true );
	}

	/** A returned ref for this product in the current environment, taken once (or null). */
	private function take_returned( string $product ): ?string {
		$env = $this->plugin->options->environment();
		foreach ( array_reverse( self::refs() ) as $row ) {
			$ref = (string) ( $row['ref'] ?? '' );
			if ( ! empty( $row['returned'] ) && empty( $row['carted'] ) && ( $row['product'] ?? '' ) === $product && ( 'live' === ( $row['env'] ?? '' ) ? 'live' : 'test' ) === $env && Applications::is_ref( $ref ) ) {
				self::set_ref_flags( $ref, array( 'returned' => false ) );
				return $ref;
			}
		}
		return null;
	}

	private static function resume_unavailable(): WP_Error {
		return new WP_Error( 'bapi_forms_resume_unavailable', __( 'This application can no longer be continued. You can start a new one.', 'businessapi-forms' ), array( 'status' => 409 ) );
	}

	/** The same-site form page without query or fragment (nothing typed into a URL is kept), or ''. */
	private static function form_page( WP_REST_Request $request ): string {
		$referer = (string) $request->get_header( 'referer' );
		if ( '' === $referer || self::origin( $referer ) !== self::site_origin() ) {
			return '';
		}
		$path = (string) wp_parse_url( $referer, PHP_URL_PATH );
		return esc_url_raw( self::site_origin() . ( '' !== $path ? $path : '/' ) );
	}

	/**
	 * Refs that made it onto an order are no longer resumable.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function forget_ordered_refs( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$ref = (string) $item->get_meta( \BapiForms\Commerce\WooCommerce\OrderBridge::ITEM_REF );
			if ( '' !== $ref ) {
				$this->forget_ref( $ref );
				MyAccount::unbind_draft( $ref, (int) $order->get_customer_id() );
			}
		}
	}

	/** Origin of this site, for frame-ancestors and postMessage. */
	public static function site_origin(): string {
		return self::origin( home_url() );
	}

	/** Origin of Business API, the only origin allowed to post messages to the page. */
	public static function bapi_origin(): string {
		return self::origin( BAPI_FORMS_API_BASE );
	}

	private static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return strtolower( ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) ) . $port;
	}
}
