<?php
/**
 * Business names paid by card: the partner, never the customer, pays ASIC's fee.
 *
 * "Pay ASIC fee" asks BAPI for the current link on every click (BAPI renews an expired one) and
 * redirects; the URL is never stored, logged or emailed. Admin emails link to the admin-post
 * route, never to ASIC. Customers only ever see "In progress".
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Applications\Applications;
use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class AsicPayment {

	public const ACTION       = 'bapi_forms_pay_asic';
	public const ORDER_ACTION = self::ACTION;
	public const PRODUCT      = 'business_name';

	/** Seconds after the fee became due. */
	private const REMINDERS = array( DAY_IN_SECONDS, 3 * DAY_IN_SECONDS );

	/** Status check after the partner opened the payment page, to pick up the payment. */
	private const POLL_AFTER_PAYING = 10 * MINUTE_IN_SECONDS;

	/** Per-user admin notice after a click (text only, no URL). */
	public const NOTICE = 'bapi_forms_asic_notice_';

	/** Where the order action sends the admin after WooCommerce saved the order. */
	private string $redirect = '';

	public function __construct( private readonly Applications $applications ) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'login_first' ) );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ORDER_ACTION, array( $this, 'on_order_action' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function payable( array $app ): bool {
		return self::PRODUCT === ( $app['product'] ?? '' )
			&& 'released' === ( $app['state'] ?? '' )
			// Never ask the partner to pay ASIC for a client who got their money back.
			&& empty( $app['closed'] )
			&& ( 'awaiting_asic_payment' === ( $app['progress'] ?? '' ) || in_array( $app['asic'] ?? '', array( 'due', 'lapsed' ), true ) );
	}

	public static function payable_refs( \WC_Order $order ): array {
		if ( self::order_closed( $order ) ) {
			return array();
		}
		$refs = OrderBridge::item_refs( $order );
		$out  = array();
		foreach ( OrderBridge::apps( $order ) as $ref => $app ) {
			if ( in_array( (string) $ref, $refs, true ) && self::payable( $app ) ) {
				$out[] = (string) $ref;
			}
		}
		return $out;
	}

	private static function order_closed( \WC_Order $order ): bool {
		return $order->has_status( array( 'cancelled', 'refunded', 'trash' ) );
	}

	/** Without a nonce (emails) the route opens the order, where the nonce-protected button is. */
	public static function pay_url( int $order_id, string $ref, bool $nonce = true ): string {
		$args = array(
			'action' => self::ACTION,
			'order'  => $order_id,
			'ref'    => $ref,
		);
		if ( $nonce ) {
			$args['_wpnonce'] = wp_create_nonce( self::nonce_action( $order_id, $ref ) );
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	public static function nonce_action( int $order_id, string $ref ): string {
		return self::ACTION . '_' . $order_id . '_' . $ref;
	}

	public function login_first(): void {
		auth_redirect();
	}

	public function handle(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below, after the capability check.
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		$ref      = isset( $_GET['ref'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ) : '';
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to pay ASIC fees for orders.', 'businessapi-forms' ), '', array( 'response' => 403 ) );
		}
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || ! Applications::is_ref( $ref ) ) {
			wp_die( esc_html__( 'Order not found.', 'businessapi-forms' ), '', array( 'response' => 404 ) );
		}
		$back = $order->get_edit_order_url();

		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $order_id, $ref ) ) ) {
			// Email links carry no nonce: open the order, where the button is.
			self::notice(
				'info',
				/* translators: %s: short application label, e.g. Application #ABC123. */
				sprintf( __( 'To pay ASIC\'s fee for %s, click "Pay ASIC fee" in the BusinessAPI Forms box on this order.', 'businessapi-forms' ), StatusText::short_ref( $ref ) )
			);
			self::back( $back );
		}

		$app = OrderBridge::apps( $order )[ $ref ] ?? null;
		if ( ! is_array( $app ) || ! in_array( $ref, OrderBridge::item_refs( $order ), true ) || self::PRODUCT !== ( $app['product'] ?? '' ) || 'released' !== ( $app['state'] ?? '' ) ) {
			self::notice( 'error', __( 'There is no ASIC fee to pay for this application: only released business name applications have one.', 'businessapi-forms' ) );
			self::back( $back );
		}
		if ( ! empty( $app['closed'] ) || self::order_closed( $order ) ) {
			self::notice( 'error', __( 'This order was refunded or cancelled, so BusinessAPI Forms does not open the ASIC fee payment for it. If the business name should still be registered, contact Business API support quoting the application reference.', 'businessapi-forms' ) );
			self::back( $back );
		}

		$response = $this->applications->payment_link( $ref, (string) ( $app['env'] ?? '' ) );
		$url      = $response->ok() ? self::safe_url( $response->data['url'] ?? null ) : null;

		if ( null !== $url ) {
			$this->record_opened( $order_id, $ref, ! empty( $response->data['renewed'] ) );
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- ASIC's payment page, host checked in safe_url().
			wp_redirect( $url, 303 );
			exit;
		}

		$code  = $response->code();
		$short = StatusText::short_ref( $ref );
		if ( $response->ok() ) {
			/* translators: %s: application reference. */
			self::notice( 'error', sprintf( __( 'Business API returned a payment link BusinessAPI Forms does not recognise as ASIC\'s. Nothing was opened. Contact Business API support quoting application %s.', 'businessapi-forms' ), $ref ) );
		} elseif ( 409 === $response->status && 'no_payment_due' === $code ) {
			/* translators: %s: short application label. */
			self::notice( 'warning', sprintf( __( 'No ASIC fee is due for %s: it is already paid, the business name is registered, or ASIC refused it. The order notes update after the next status check (within a few minutes).', 'businessapi-forms' ), $short ) );
			OrderBridge::poll_soon( $order_id, $ref, MINUTE_IN_SECONDS );
		} elseif ( 409 === $response->status && 'payment_link_pending' === $code ) {
			/* translators: %s: short application label. */
			self::notice( 'warning', sprintf( __( 'ASIC is still issuing the payment link for %s. Try "Pay ASIC fee" again in a minute.', 'businessapi-forms' ), $short ) );
		} elseif ( 429 === $response->status ) {
			self::notice( 'warning', __( 'Too many payment link requests. Try again in a minute.', 'businessapi-forms' ) );
		} elseif ( $response->is_transient() || 'payment_link_failed' === $code ) {
			self::notice( 'warning', __( 'Business API or ASIC did not answer in time. Try "Pay ASIC fee" again in a minute.', 'businessapi-forms' ) );
		} else {
			self::notice(
				'error',
				sprintf(
					/* translators: 1: machine code or HTTP status, 2: application reference. */
					__( 'Business API refused the payment link request (%1$s). Check your Business API account (card on file, account status), or contact Business API support quoting application %2$s.', 'businessapi-forms' ),
					'' !== $code ? $code : 'HTTP ' . $response->status,
					$ref
				)
			);
		}
		self::back( $back );
	}

	/** ASIC's payment pages only: https on ASIC or its card processor. */
	public static function safe_url( mixed $url ): ?string {
		if ( ! is_string( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return null;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		/**
		 * Hosts ASIC's card payment page may be on (subdomains included).
		 *
		 * @param list<string> $hosts Allowed hosts.
		 */
		$hosts = (array) apply_filters( 'bapi_forms_asic_payment_hosts', array( 'asic.gov.au', 'ippayments.com.au' ) );
		foreach ( $hosts as $allowed ) {
			$allowed = strtolower( (string) $allowed );
			if ( '' !== $allowed && ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) ) {
				return $url;
			}
		}
		return null;
	}

	/** Counts as this cycle's first notice, so no "fee due" email arrives right after paying. */
	private function record_opened( int $order_id, string $ref, bool $renewed ): void {
		$user = wp_get_current_user();
		OrderBridge::with_lock(
			$order_id,
			function () use ( $order_id, $ref, $renewed, $user ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$apps = OrderBridge::apps( $order );
				if ( ! isset( $apps[ $ref ] ) ) {
					return;
				}
				$app                = $apps[ $ref ];
				$app['asic']        = 'due';
				$app['asic_due_at'] = $renewed || empty( $app['asic_due_at'] ) ? time() : (int) $app['asic_due_at'];
				$app['asic_mailed'] = max( 1, (int) ( $app['asic_mailed'] ?? 0 ) );
				unset( $app['asic_lapsed_mailed'] );
				$apps[ $ref ] = $app;
				$order->update_meta_data( OrderBridge::META, $apps );
				$order->add_order_note(
					sprintf(
						/* translators: 1: user display name, 2: application reference. */
						$renewed ? __( 'BusinessAPI Forms: %1$s got a new ASIC payment link for application %2$s (the previous link expired) and opened it.', 'businessapi-forms' ) : __( 'BusinessAPI Forms: %1$s opened the ASIC payment link for application %2$s.', 'businessapi-forms' ),
						$user->exists() ? $user->display_name : __( 'an administrator', 'businessapi-forms' ),
						$ref
					)
				);
				$order->save();
			},
			5
		);
		OrderBridge::poll_soon( $order_id, $ref, self::POLL_AFTER_PAYING );
	}

	/**
	 * @param string $type error|warning|info|success.
	 */
	private static function notice( string $type, string $text ): void {
		set_transient( self::NOTICE . get_current_user_id(), array( $type, $text ), 5 * MINUTE_IN_SECONDS );
	}

	private static function back( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	public function render_notice(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
			return;
		}
		delete_transient( $key );
		[ $type, $text ] = $notice;
		printf(
			'<div class="notice notice-%s is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_attr( in_array( $type, array( 'error', 'warning', 'info', 'success' ), true ) ? $type : 'info' ),
			esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
			esc_html( (string) $text )
		);
	}

	/**
	 * @param array<string, string> $actions Actions.
	 * @param \WC_Order|null        $order   Order (WC 8.6+).
	 */
	public function order_actions( $actions, $order = null ): array {
		$actions = (array) $actions;
		if ( ! $order instanceof \WC_Order ) {
			global $theorder;
			$order = $theorder instanceof \WC_Order ? $theorder : null;
		}
		$refs = $order && current_user_can( 'edit_shop_orders' ) ? self::payable_refs( $order ) : array();
		if ( $refs ) {
			// The action opens the first payable application.
			$first                         = OrderBridge::apps( $order )[ $refs[0] ] ?? array();
			$actions[ self::ORDER_ACTION ] = 'lapsed' === ( $first['asic'] ?? '' )
				? __( 'Get new payment link (BusinessAPI Forms)', 'businessapi-forms' )
				: __( 'Pay ASIC fee (BusinessAPI Forms)', 'businessapi-forms' );
		}
		return $actions;
	}

	/**
	 * Redirects to the pay route once WooCommerce has saved the order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function on_order_action( $order ): void {
		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'edit_shop_orders' ) || ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
			return;
		}
		$refs = self::payable_refs( $order );
		if ( ! $refs ) {
			return;
		}
		$this->redirect = self::pay_url( (int) $order->get_id(), $refs[0] );
		add_filter( 'redirect_post_location', array( $this, 'redirect_after_save' ), 99 );
		add_filter( 'woocommerce_redirect_order_location', array( $this, 'redirect_after_save' ), 99 );
	}

	/**
	 * @param string $location Default location.
	 */
	public function redirect_after_save( $location ): string {
		return '' !== $this->redirect ? $this->redirect : (string) $location;
	}

	/**
	 * @param string $screen_id     Screen or post type (HPOS and posts).
	 * @param mixed  $post_or_order WP_Post or WC_Order.
	 */
	public function add_meta_box( $screen_id, $post_or_order = null ): void {
		unset( $screen_id );
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order instanceof \WC_Order || ( ! OrderBridge::apps( $order ) && ! Prepaid::slots( $order ) ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$screen = wc_get_page_screen_id( 'shop-order' );
		add_meta_box( 'bapi-forms-applications', __( 'BusinessAPI Forms', 'businessapi-forms' ), array( $this, 'render_meta_box' ), $screen, 'side', 'high' );
	}

	/**
	 * @param mixed $post_or_order WP_Post or WC_Order.
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$refs   = OrderBridge::item_refs( $order );
		$labels = Plugin::instance()->products->all();
		echo '<ul class="bapi-forms-box">';
		PrepaidAdmin::render_unissued( $order );
		foreach ( OrderBridge::apps( $order ) as $ref => $app ) {
			if ( ! in_array( (string) $ref, $refs, true ) ) {
				continue;
			}
			$status = 'prepaid' === ( $app['state'] ?? '' ) ? __( 'Paid, waiting for your client', 'businessapi-forms' ) : str_replace( '_', ' ', (string) ( $app['status'] ?? '' ) );
			if ( 'lapsed' === ( $app['asic'] ?? '' ) ) {
				$status = __( 'Waiting for the ASIC fee payment. The payment link expired: get a new link', 'businessapi-forms' );
			} elseif ( 'awaiting_asic_payment' === ( $app['progress'] ?? '' ) ) {
				$status = __( 'Waiting for the ASIC fee payment', 'businessapi-forms' );
			}
			printf(
				'<li><strong>%s</strong><br><small>%s</small><br>%s',
				esc_html( (string) ( $labels[ $app['product'] ?? '' ]['label'] ?? __( 'Application', 'businessapi-forms' ) ) ),
				esc_html( StatusText::short_ref( (string) $ref ) ),
				esc_html( '' !== $status ? ucfirst( $status ) : __( 'Not released yet', 'businessapi-forms' ) )
			);
			if ( '' !== (string) ( $app['document'] ?? '' ) ) {
				/* translators: %s: ASIC document number. */
				echo '<br>' . esc_html( sprintf( __( 'ASIC document number %s', 'businessapi-forms' ), $app['document'] ) );
			}
			PrepaidAdmin::render_app_extra( $order, (string) $ref, $app );
			if ( self::payable( $app ) && ! self::order_closed( $order ) ) {
				printf(
					'<br><a class="button button-primary" href="%s">%s</a><br><small>%s</small>',
					esc_url( self::pay_url( (int) $order->get_id(), (string) $ref ) ),
					esc_html( self::button_label( $app ) ),
					esc_html(
						'lapsed' === ( $app['asic'] ?? '' )
							? __( 'Gets a new ASIC payment link, then opens ASIC\'s card payment page.', 'businessapi-forms' )
							: __( 'Opens ASIC\'s card payment page. You pay the fee; your client already paid it to you.', 'businessapi-forms' )
					)
				);
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function button_label( array $app ): string {
		return 'lapsed' === ( $app['asic'] ?? '' )
			? __( 'Get new payment link', 'businessapi-forms' )
			: __( 'Pay ASIC fee', 'businessapi-forms' );
	}

	/**
	 * Records the ASIC payment state and document number after a status read, and sends the
	 * deduplicated admin emails. Runs inside the order lock, before the order is saved.
	 *
	 * @param array<string, mixed> $app  Tracked application (updated copy returned).
	 * @param array<string, mixed> $data GET /status body (read in memory, never stored).
	 * @return array<string, mixed>
	 */
	public static function on_poll( \WC_Order $order, string $ref, array $app, array $data ): array {
		if ( self::PRODUCT !== ( $app['product'] ?? '' ) ) {
			return $app;
		}

		$bn       = is_array( $data['businessName'] ?? null ) ? $data['businessName'] : array();
		$document = $bn['documentNumber'] ?? null;
		if ( ! is_string( $document ) || '' === $document ) {
			foreach ( (array) ( $data['requests'] ?? array() ) as $request ) {
				if ( is_array( $request ) && 'bn' === ( $request['role'] ?? '' ) && is_string( $request['documentNumber'] ?? null ) ) {
					$document = $request['documentNumber'];
				}
			}
		}
		if ( is_string( $document ) && '' !== $document ) {
			$app['document'] = substr( (string) preg_replace( '/[^A-Za-z0-9-]/', '', $document ), 0, 32 );
		}

		$payment = $bn['payment'] ?? null;
		if ( ! in_array( $payment, array( 'due', 'lapsed' ), true ) ) {
			// Older BAPI without businessName.payment: the progress code says it is due.
			$payment = 'awaiting_asic_payment' === ( $app['progress'] ?? '' ) ? 'due' : '';
		}
		$app['asic'] = $payment;
		$now         = time();

		// Refunded or cancelled: state recorded, but no fee emails.
		if ( ! empty( $app['closed'] ) || self::order_closed( $order ) ) {
			return $app;
		}

		if ( 'due' === $payment ) {
			// Due again (a new link): a later lapse emails again.
			unset( $app['asic_lapsed_mailed'] );
			if ( empty( $app['asic_due_at'] ) ) {
				$app['asic_due_at'] = $now;
			}
			$sent = (int) ( $app['asic_mailed'] ?? 0 );
			if ( 0 === $sent ) {
				self::email( $order, $ref, 'due' );
				$app['asic_mailed'] = 1;
			} elseif ( $sent <= count( self::REMINDERS ) && $now - (int) $app['asic_due_at'] >= self::REMINDERS[ $sent - 1 ] ) {
				self::email( $order, $ref, 'reminder' );
				$app['asic_mailed'] = $sent + 1;
			}
		} elseif ( 'lapsed' === $payment ) {
			// A new cycle starts once a new link is requested.
			unset( $app['asic_due_at'], $app['asic_mailed'] );
			if ( empty( $app['asic_lapsed_mailed'] ) ) {
				self::email( $order, $ref, 'lapsed' );
				$app['asic_lapsed_mailed'] = $now;
			}
		}

		return $app;
	}

	/**
	 * @param string $kind due|reminder|lapsed.
	 */
	private static function email( \WC_Order $order, string $ref, string $kind ): void {
		$number = $order->get_order_number();
		$pay    = self::pay_url( (int) $order->get_id(), $ref, false );
		$short  = StatusText::short_ref( $ref );

		if ( 'lapsed' === $kind ) {
			/* translators: %s: order number. */
			$subject = sprintf( __( 'BusinessAPI Forms: get a new ASIC payment link for order #%s', 'businessapi-forms' ), $number );
			/* translators: 1: order number, 2: short application label, 3: application reference, 4: pay URL. */
			$body = __( "Order #%1\$s: waiting for the ASIC fee payment for the business name registration (%2\$s, application %3\$s). The payment link expired: get a new link. Nothing is lost: open the order and click \"Get new payment link\" (link below; log in as a shop manager), then pay ASIC's fee by card. ASIC registers the business name once it is paid.\n\n%4\$s\n\nYour client is not told about this and still sees \"In progress\".", 'businessapi-forms' );
		} else {
			$subject = 'reminder' === $kind
				/* translators: %s: order number. */
				? sprintf( __( 'BusinessAPI Forms: reminder, the ASIC fee for order #%s is still unpaid', 'businessapi-forms' ), $number )
				/* translators: %s: order number. */
				: sprintf( __( 'BusinessAPI Forms: pay the ASIC fee for order #%s', 'businessapi-forms' ), $number );
			/* translators: 1: order number, 2: short application label, 3: application reference, 4: pay URL. */
			$body = __( "Order #%1\$s: ASIC is waiting for its fee for the business name registration (%2\$s, application %3\$s). Pay it by card: open the order and click \"Pay ASIC fee\" (link below; log in as a shop manager). ASIC registers the business name once it is paid. Your client paid you this fee in the GST-free government line.\n\n%4\$s\n\nIf the payment link expires, the same button gets a new one.", 'businessapi-forms' );
		}

		// A sandbox order is never mistaken for a real fee to pay.
		if ( \BapiForms\Settings\Options::ENV_TEST === ( OrderBridge::apps( $order )[ $ref ]['env'] ?? '' ) ) {
			$subject = __( '[Sandbox test]', 'businessapi-forms' ) . ' ' . $subject;
		}

		wp_mail( (string) get_option( 'admin_email' ), $subject, sprintf( $body, $number, $short, $ref, $pay ) );
	}
}
