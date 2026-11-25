<?php
/**
 * Pay first, client side: the paid client fills in their application on the thank-you page, the
 * order page or from the emailed link.
 *
 * Links carry only ?bapi_start=REF on the order page (customers) or order received page (guests,
 * guarded by WooCommerce's email check). REST calls carry a signed proof, never the order key.
 *
 * @package BapiForms
 */

namespace BapiForms\Frontend;

use BapiForms\Applications\Applications;
use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Commerce\WooCommerce\StatusText;
use BapiForms\Commerce\WooCommerce\OrderLines;
use BapiForms\Commerce\WooCommerce\Prepaid;
use BapiForms\Monitoring\Entities;
use BapiForms\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class PrepaidStart {

	public const START_ARG = 'bapi_start';

	private const RATE_LIMIT  = 20;
	private const RATE_WINDOW = 10 * MINUTE_IN_SECONDS;
	/** /prepaid-ready has its own bucket per IP and order: a waiting page asks up to 8 times. */
	private const READY_LIMIT = 40;
	private const SHOWN       = 5;

	private const NO_START_STATUSES = array( 'cancelled', 'refunded', 'failed', 'trash', 'on-hold', 'pending', 'checkout-draft' );

	/** Order meta: the "form not available" admin email went out. */
	private const UNAVAILABLE_FLAG = '_bapi_forms_unavailable_mailed';

	/** The form was rendered on this page already (two hooks can ask). */
	private static bool $rendered = false;

	/** The ref whose form this page shows (its button is left out). */
	private static string $open_ref = '';

	public function __construct( private readonly Plugin $plugin, private readonly Shortcode $shortcode ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'start_form' ), 4 );
		add_action( 'woocommerce_thankyou', array( $this, 'start_form_thankyou' ), 20 );
		add_action( 'woocommerce_account_dashboard', array( $this, 'dashboard_list' ), 5 );
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'keep_start_arg' ), 10, 2 );
	}

	/** Link that opens the form of a prepaid application. */
	public static function start_url( \WC_Order $order, string $ref ): string {
		$base = (int) $order->get_customer_id() > 0 ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
		return add_query_arg( self::START_ARG, rawurlencode( strtoupper( $ref ) ), $base ) . '#bapi-forms-start';
	}

	/**
	 * Whether the client can fill in $ref now: the order still stands behind the application and
	 * its lines are not refunded. Ownership is checked separately (page_owner(), the proof).
	 */
	public static function order_allows_start( \WC_Order $order, string $ref ): bool {
		$ref = strtoupper( $ref );
		if ( ! Applications::is_ref( $ref ) || ! in_array( $ref, OrderBridge::item_refs( $order ), true ) ) {
			return false;
		}
		$app = OrderBridge::apps( $order )[ $ref ] ?? null;
		if ( ! is_array( $app ) || ! Prepaid::app_startable( $app ) || ! empty( $app['suspended'] ) || ! empty( $app['held'] ) || ! empty( $app['closed'] ) || ! empty( $app['refunded'] ) ) {
			return false;
		}
		if ( ! $order->is_paid() || $order->has_status( self::NO_START_STATUSES ) ) {
			return false;
		}
		return ! in_array( $ref, array_map( 'strtoupper', OrderLines::refunded_refs( $order ) ), true );
	}

	/**
	 * Paused by the shop: the customer is told we will be in touch rather than to contact us.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	private static function paused( \WC_Order $order, array $app ): bool {
		return ! empty( $app['held'] ) || ! empty( $app['suspended'] ) || $order->has_status( array( 'on-hold', 'pending', 'failed' ) );
	}

	/**
	 * Start or Continue button for "Your applications" (escaped HTML), or ''.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function start_button( \WC_Order $order, string $ref, array $app = array() ): string {
		if ( ! self::order_allows_start( $order, $ref ) || strtoupper( $ref ) === self::requested_ref() || strtoupper( $ref ) === self::$open_ref ) {
			return '';
		}
		return sprintf(
			' <a class="woocommerce-button button wp-element-button bapi-forms-start-button" href="%s">%s</a>',
			esc_url( self::start_url( $order, $ref ) ),
			esc_html( ! empty( $app['form']['started'] ) || 'released' === ( $app['state'] ?? '' ) ? __( 'Continue your application', 'businessapi-forms' ) : __( 'Start your application', 'businessapi-forms' ) )
		);
	}

	/**
	 * Guests: WooCommerce's email check posts back to the order received URL; keep ?bapi_start.
	 *
	 * @param string|mixed $url   Order received URL.
	 * @param mixed        $order Order.
	 */
	public function keep_start_arg( $url, $order = null ) {
		$ref = self::requested_ref();
		if ( '' === $ref || ! is_string( $url ) || ! $order instanceof \WC_Order || (int) $order->get_customer_id() > 0 ) {
			return $url;
		}
		return add_query_arg( self::START_ARG, rawurlencode( $ref ), $url );
	}

	private static function requested_ref(): string {
		$ref = isset( $_GET[ self::START_ARG ] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET[ self::START_ARG ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return Applications::is_ref( $ref ) ? $ref : '';
	}

	/** @param int|mixed $order_id Order id (woocommerce_thankyou). */
	public function start_form_thankyou( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( $order instanceof \WC_Order ) {
			$this->start_form( $order );
		}
	}

	/**
	 * The form for ?bapi_start=REF, or on the thank-you page the first application waiting for
	 * the client's details.
	 *
	 * @param \WC_Order|mixed $order Order.
	 */
	public function start_form( $order ): void {
		if ( self::$rendered || ! $order instanceof \WC_Order || ! Prepaid::slots( $order ) || ! $this->plugin->can_serve_forms() || ! MyAccount::page_owner( $order ) ) {
			return;
		}
		$asked    = self::requested_ref();
		$thankyou = function_exists( 'is_order_received_page' ) && is_order_received_page();
		if ( '' === $asked && ! $thankyou ) {
			return; // Order page: the buttons in "Your applications" open it.
		}
		$ref = '';
		if ( '' !== $asked ) {
			$ref = $asked;
		} else {
			foreach ( OrderBridge::apps( $order ) as $candidate => $app ) {
				if ( self::order_allows_start( $order, (string) $candidate ) && 'prepaid' === ( $app['state'] ?? '' ) ) {
					$ref = (string) $candidate;
					break;
				}
			}
		}
		self::$rendered = true;

		if ( '' === $ref ) {
			// Paid and still being created: wait for it here (JS polls /prepaid-ready).
			if ( $order->is_paid() && Prepaid::has_unissued( $order ) && ! Prepaid::has_creating( $order ) && ! $order->has_status( self::NO_START_STATUSES ) ) {
				// Blocked until the partner fixes something: no "getting ready", no email promise.
				echo '<section id="bapi-forms-start" class="bapi-forms-start"><h2>' . esc_html__( 'Your application', 'businessapi-forms' ) . '</h2><p>' . esc_html__( 'Thank you for your order. We will be in touch about your application.', 'businessapi-forms' ) . '</p></section>';
			} elseif ( $order->is_paid() && Prepaid::has_creating( $order ) && ! $order->has_status( self::NO_START_STATUSES ) ) {
				$this->shortcode->enqueue();
				printf(
					'<section id="bapi-forms-start" class="bapi-forms-start"><h2>%1$s</h2><div class="bapi-forms-form" data-pending-order="%2$d" data-proof="%3$s"><p class="bapi-forms-form__status" role="status">%4$s</p></div></section>',
					esc_html__( 'Your application', 'businessapi-forms' ),
					(int) $order->get_id(),
					esc_attr( MyAccount::proof( $order, '', null, 'ready' ) ),
					esc_html__( 'Getting your form ready...', 'businessapi-forms' )
				);
			}
			return;
		}
		if ( ! self::order_allows_start( $order, $ref ) ) {
			$app = OrderBridge::apps( $order )[ $ref ] ?? array();
			if ( 'released' === ( $app['state'] ?? '' ) || 'pending' === ( $app['state'] ?? '' ) ) {
				$msg = __( 'This application has already been sent. You can follow it on your order.', 'businessapi-forms' );
			} elseif ( self::paused( $order, $app ) ) {
				$msg = __( 'Thank you. We will be in touch about your application.', 'businessapi-forms' );
			} else {
				$msg = __( 'This application cannot be filled in online right now. If you think this is wrong, please contact us.', 'businessapi-forms' );
			}
			echo '<section id="bapi-forms-start" class="bapi-forms-start"><p>' . esc_html( $msg ) . '</p></section>';
			return;
		}

		$this->shortcode->enqueue();
		self::$open_ref = strtoupper( $ref );
		$product        = (string) ( OrderBridge::apps( $order )[ $ref ]['product'] ?? '' );
		printf(
			'<section id="bapi-forms-start" class="bapi-forms-start"><h2>%1$s</h2><p>%2$s</p><div class="bapi-forms-form" style="max-width:min(%7$dpx,100%%);box-sizing:border-box;" data-product="%3$s" data-start-ref="%4$s" data-order="%5$d" data-proof="%6$s"><p class="bapi-forms-form__status" role="status" hidden></p></div></section>',
			esc_html__( 'Your application', 'businessapi-forms' ),
			esc_html__( 'Fill in your details below. They are saved securely as you go: you can stop and come back with the link in your email.', 'businessapi-forms' ),
			esc_attr( $product ),
			esc_attr( $ref ),
			(int) $order->get_id(),
			esc_attr( MyAccount::proof( $order, $ref, null, 'start' ) ),
			(int) Shortcode::MAX_WIDTH
		);
	}

	public function routes(): void {
		$base = array(
			'order' => array(
				'type'     => 'integer',
				'required' => true,
			),
			'proof' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		$ref  = $base + array(
			'applicationRef' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		$done = $ref + array(
			'code' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
		);
		foreach ( array(
			'prepaid-session' => array( 'session', $ref ),
			'prepaid-done'    => array( 'done', $done ),
			'prepaid-ready'   => array( 'ready', $base ),
		) as $route => [ $callback, $args ] ) {
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

	/**
	 * Serving gate, rate limit and proof. One answer for every failure (order ids and refs
	 * cannot be probed); only a genuine proof that expired gets the reload message.
	 *
	 * @return \WC_Order|WP_Error
	 */
	private function checked( WP_REST_Request $request, string $ref, string $purpose ) {
		if ( ! $this->plugin->can_serve_forms() || ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'bapi_forms_unavailable', __( 'This form is not available.', 'businessapi-forms' ), array( 'status' => 404 ) );
		}
		$ip      = (string) apply_filters( 'bapi_forms_client_ip', $this->plugin->options->client_ip() );
		$limited = 'ready' === $purpose
			? RateLimiter::hit( 'prepaid-ready', RestController::rate_subject( $ip ) . '|' . (int) $request->get_param( 'order' ), self::READY_LIMIT, self::RATE_WINDOW )
			: RateLimiter::hit( 'prepaid', RestController::rate_subject( $ip ), self::RATE_LIMIT, self::RATE_WINDOW );
		if ( $limited ) {
			return new WP_Error( 'bapi_forms_rate_limited', __( 'Too many requests. Please wait a few minutes and try again.', 'businessapi-forms' ), array( 'status' => 429 ) );
		}
		$order = wc_get_order( (int) $request->get_param( 'order' ) );
		$proof = (string) $request->get_param( 'proof' );
		$valid = $order instanceof \WC_Order && ( '' === $ref || Applications::is_ref( $ref ) )
			&& ( '' === $ref || in_array( $ref, OrderBridge::item_refs( $order ), true ) )
			&& MyAccount::proof_valid( $order, $ref, $proof, $purpose );
		if ( ! $valid ) {
			if ( $order instanceof \WC_Order && MyAccount::proof_expired( $order, $ref, $proof, $purpose ) ) {
				return new WP_Error( 'bapi_forms_proof_expired', __( 'This page has been open a while. Reload the page to keep editing.', 'businessapi-forms' ), array( 'status' => 403 ) );
			}
			return new WP_Error( 'bapi_forms_forbidden', __( 'Please log in to the account you ordered with, or open the link from your order email.', 'businessapi-forms' ), array( 'status' => 403 ) );
		}
		return $order;
	}

	/** POST /prepaid-session {order, proof, applicationRef} -> {url, applicationRef}. */
	public function session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ref   = strtoupper( (string) $request->get_param( 'applicationRef' ) );
		$order = $this->checked( $request, $ref, 'start' );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( ! self::order_allows_start( $order, $ref ) ) {
			$message = self::paused( $order, OrderBridge::apps( $order )[ $ref ] ?? array() )
				? __( 'Thank you. We will be in touch about your application.', 'businessapi-forms' )
				: __( 'This application cannot be filled in online right now. If you think this is wrong, please contact us.', 'businessapi-forms' );
			return new WP_Error( 'bapi_forms_not_startable', $message, array( 'status' => 409 ) );
		}
		$app     = OrderBridge::apps( $order )[ $ref ];
		$type    = (string) $this->plugin->products->bapi_type( (string) $app['product'] );
		$options = array();
		$brand   = $this->plugin->options->branding_for_bapi();
		if ( $brand ) {
			$options['branding'] = $brand;
		}
		if ( 'monitoring' === $type ) {
			// The customer's other monitored entities (never this application itself).
			$owned                 = (int) $order->get_customer_id() > 0 ? Entities::owned_refs( (int) $order->get_customer_id(), (string) $app['env'] ) : array();
			$options['monitoring'] = array( 'ownedRefs' => array_values( array_diff( $owned, array( $ref ) ) ) );
		}
		$response = $this->plugin->applications->create_session( $type, RestController::site_origin(), $options, $ref, (string) $app['env'] );

		if ( ! $response->ok() ) {
			$code = $response->code();
			if ( 403 === $response->status && in_array( $code, array( 'product_not_live', 'wordpress_onboarding_required' ), true ) ) {
				$this->tell_unavailable( $order, $ref, $code );
				return new WP_Error( 'bapi_forms_not_available', __( 'This form is not available right now. We have been told and will be in touch.', 'businessapi-forms' ), array( 'status' => 503 ) );
			}
			if ( 409 === $response->status && 'application_on_hold' === $code ) {
				// Paused in BAPI (the plugin's flag may lag behind): no form, no alarm.
				return new WP_Error( 'bapi_forms_not_startable', __( 'Thank you. We will be in touch about your application.', 'businessapi-forms' ), array( 'status' => 409 ) );
			}
			return new WP_Error( 'bapi_forms_session', __( 'The form could not be loaded. Please try again shortly.', 'businessapi-forms' ), array( 'status' => 502 ) );
		}
		$url = (string) ( $response->data['url'] ?? '' );
		if ( '' === $url || ! str_starts_with( $url, RestController::bapi_origin() . '/' ) ) {
			return new WP_Error( 'bapi_forms_session', __( 'The form could not be loaded. Please try again shortly.', 'businessapi-forms' ), array( 'status' => 502 ) );
		}
		do_action(
			'bapi_forms_audit',
			'prepaid_form_opened',
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
	 * POST /prepaid-done {order, proof, applicationRef, code?}: the form was submitted (or reported
	 * already_monitored). Only moves the next status check forward; safe to repeat.
	 */
	public function done( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ref   = strtoupper( (string) $request->get_param( 'applicationRef' ) );
		$order = $this->checked( $request, $ref, 'start' );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$order_id = (int) $order->get_id();
		$app      = OrderBridge::apps( $order )[ $ref ] ?? array();
		if ( 'already_monitored' === (string) $request->get_param( 'code' ) ) {
			// The partner is emailed only when Business API confirms the duplicate.
			if ( $this->confirmed_duplicate( $order, $ref, $app ) ) {
				\BapiForms\Emails\PrepaidEmails::already_monitored( $order_id, $ref );
			}
			return new WP_REST_Response( array( 'ok' => true ) );
		}
		$flag = 'bapi_forms_pf_nudge_' . md5( $order_id . ':' . $ref );
		if ( false === get_transient( $flag ) && in_array( $app['state'] ?? '', array( 'prepaid', 'released' ), true ) ) {
			set_transient( $flag, 1, MINUTE_IN_SECONDS );
			OrderBridge::poll_soon( $order_id, $ref, 15 );
			do_action(
				'bapi_forms_audit',
				'prepaid_submitted',
				array(
					'orderId'     => $order_id,
					'ref'         => $ref,
					'environment' => (string) ( $app['env'] ?? '' ),
				)
			);
		}
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Whether Business API says this prepaid monitoring application is for an entity the customer
	 * already monitors (/summary compared with their other monitoring refs).
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	private function confirmed_duplicate( \WC_Order $order, string $ref, array $app ): bool {
		$customer = (int) $order->get_customer_id();
		$env      = (string) ( $app['env'] ?? '' );
		if ( $customer <= 0 || 'prepaid' !== ( $app['state'] ?? '' ) || 'monitoring' !== $this->plugin->products->bapi_type( (string) ( $app['product'] ?? '' ) ) ) {
			return false;
		}
		$owned = array_values( array_diff( Entities::owned_refs( $customer, $env ), array( $ref ) ) );
		if ( ! $owned ) {
			return false;
		}
		$response = $this->plugin->applications->summary( $ref, $env, $owned );
		$same     = $response->ok() ? ( $response->data['monitoring']['sameEntity'] ?? array() ) : array();
		return is_array( $same ) && array() !== $same;
	}

	/** POST /prepaid-ready {order, proof}: {ready, url?} once the order created an application. */
	public function ready( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order = $this->checked( $request, '', 'ready' );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		foreach ( OrderBridge::apps( $order ) as $ref => $app ) {
			if ( 'prepaid' === ( $app['state'] ?? '' ) && self::order_allows_start( $order, (string) $ref ) ) {
				$out = new WP_REST_Response(
					array(
						'ready' => true,
						'url'   => esc_url_raw( self::start_url( $order, (string) $ref ) ),
					)
				);
				$out->header( 'Cache-Control', 'no-store' );
				return $out;
			}
		}
		$out = new WP_REST_Response( array( 'ready' => false ) );
		$out->header( 'Cache-Control', 'no-store' );
		return $out;
	}

	/** Live eligibility or onboarding lost after payment: one admin email per order. */
	private function tell_unavailable( \WC_Order $order, string $ref, string $code ): void {
		$order_id = (int) $order->get_id();
		OrderBridge::with_lock(
			$order_id,
			function () use ( $order_id, $ref, $code ): void {
				$fresh = wc_get_order( $order_id );
				if ( ! $fresh instanceof \WC_Order || '' !== (string) $fresh->get_meta( self::UNAVAILABLE_FLAG ) ) {
					return;
				}
				$fresh->update_meta_data( self::UNAVAILABLE_FLAG, (string) time() );
				$fresh->add_order_note(
					sprintf(
						/* translators: 1: application reference, 2: reason. */
						__( 'BusinessAPI Forms: your client could not open the form for application %1$s: %2$s', 'businessapi-forms' ),
						$ref,
						OrderBridge::fix_for( $code, 403 )
					)
				);
				$fresh->save();
				wp_mail(
					(string) get_option( 'admin_email' ),
					/* translators: %s: order number. */
					sprintf( __( 'BusinessAPI Forms: your client cannot open their form (order #%s)', 'businessapi-forms' ), $fresh->get_order_number() ),
					sprintf(
						/* translators: 1: order number, 2: application reference, 3: what to fix, 4: order admin URL. */
						__( "Your client paid for order #%1\$s but could not open the form for application %2\$s.\n\n%3\$s\n\nOnce fixed, your client can use the link in their email again. If it cannot be fixed, refund the order.\n%4\$s", 'businessapi-forms' ),
						$fresh->get_order_number(),
						$ref,
						OrderBridge::fix_for( $code, 403 ),
						$fresh->get_edit_order_url()
					)
				);
			},
			5
		);
	}

	/** My Account dashboard: up to SHOWN applications waiting for the client. */
	public function dashboard_list(): void {
		$user = get_current_user_id();
		if ( $user <= 0 || ! function_exists( 'wc_get_orders' ) || ! $this->plugin->can_serve_forms() ) {
			return;
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => $user,
				'limit'       => 20,
				'status'      => array( 'wc-processing', 'wc-completed' ),
				'meta_key'    => Prepaid::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$rows   = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( OrderBridge::apps( $order ) as $ref => $app ) {
				if ( count( $rows ) >= self::SHOWN ) {
					break 2;
				}
				if ( self::order_allows_start( $order, (string) $ref ) ) {
					$rows[] = array( $order, (string) $ref, $app );
				}
			}
		}
		if ( ! $rows ) {
			return;
		}
		$labels = $this->plugin->products->all();
		echo '<section class="bapi-forms-start-list"><h2>' . esc_html__( 'Start your application', 'businessapi-forms' ) . '</h2><ul>';
		foreach ( $rows as [ $order, $ref, $app ] ) {
			$fresh = 'released' !== ( $app['state'] ?? '' ) && empty( $app['form']['started'] );
			$state = StatusText::customer_label( $app, $order, $ref ); // Same words as the order page.
			printf(
				'<li><strong>%1$s</strong> (%2$s, %3$s): %4$s <a class="woocommerce-button button wp-element-button" href="%5$s">%6$s</a></li>',
				esc_html( (string) ( $labels[ $app['product'] ?? '' ]['label'] ?? __( 'Application', 'businessapi-forms' ) ) ),
				/* translators: %s: order number. */
				esc_html( sprintf( __( 'order #%s', 'businessapi-forms' ), $order->get_order_number() ) ),
				esc_html( StatusText::short_ref( $ref ) ),
				esc_html( $state ),
				esc_url( self::start_url( $order, $ref ) ),
				esc_html( $fresh ? __( 'Start', 'businessapi-forms' ) : __( 'Continue', 'businessapi-forms' ) )
			);
		}
		echo '</ul></section>';
	}
}
