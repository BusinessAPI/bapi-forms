<?php
/**
 * Pay-first rows in the order's BusinessAPI Forms box: state, form progress, resend and copy link.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Emails\PrepaidEmails;
use BapiForms\Frontend\PrepaidStart;

defined( 'ABSPATH' ) || exit;

final class PrepaidAdmin {

	public const ACTION = 'bapi_forms_resend_start';

	/** Resends per order and hour. */
	private const RESEND_LIMIT = 3;

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'resend' ) );
	}

	/** State of a slot (and its application) in plain words, for the partner. */
	public static function state_text( array $row, ?array $app ): string {
		$state = (string) ( $row['state'] ?? '' );
		if ( 'prepaid' === $state && is_array( $app ) ) {
			if ( ! empty( $app['suspended'] ) || ! empty( $app['held'] ) ) {
				return __( 'Paid, paused: Business API will not lodge it until the order covers it again (see the order notes). It resumes by itself.', 'businessapi-forms' );
			}
			$form = (array) ( $app['form'] ?? array() );
			if ( empty( $form['started'] ) ) {
				$progress = __( 'not started', 'businessapi-forms' );
			} elseif ( (int) ( $form['index'] ?? 0 ) > 0 && (int) ( $form['total'] ?? 0 ) > 0 ) {
				/* translators: 1: step number, 2: number of steps. */
				$progress = sprintf( __( 'step %1$d of %2$d', 'businessapi-forms' ), (int) $form['index'], (int) $form['total'] );
			} else {
				$progress = __( 'started', 'businessapi-forms' );
			}
			if ( (int) ( $form['saved'] ?? 0 ) > 0 ) {
				/* translators: 1: progress, 2: date. */
				$progress = sprintf( __( '%1$s, last saved %2$s', 'businessapi-forms' ), $progress, wp_date( 'j M', (int) $form['saved'] ) );
			}
			/* translators: %s: form progress, e.g. "step 3 of 6, last saved 2 Dec". */
			return sprintf( __( 'Paid, waiting for your client\'s form (%s)', 'businessapi-forms' ), $progress );
		}
		return match ( $state ) {
			'awaiting_payment' => __( 'Waiting for payment (or for the release rules) before the application is created', 'businessapi-forms' ),
			'issuing'          => __( 'Creating the application (retrying automatically)', 'businessapi-forms' ),
			'blocked'          => __( 'Application not created: see the order notes, fix it, then "Retry release"', 'businessapi-forms' ),
			'cancelled'        => __( 'Cancelled', 'businessapi-forms' ),
			default            => '',
		};
	}

	/** Pay-first lines without an application yet, as box rows. */
	public static function render_unissued( \WC_Order $order ): void {
		$lines = Prepaid::item_slots( $order );
		foreach ( Prepaid::slots( $order ) as $slot => $row ) {
			if ( ! in_array( $slot, $lines, true ) || '' !== (string) ( $row['ref'] ?? '' ) ) {
				continue;
			}
			printf(
				'<li><strong>%s</strong><br><small>%s</small><br>%s</li>',
				esc_html( \BapiForms\Products\ExpectedLines::line_name( (string) ( $row['product'] ?? '' ), (array) ( $row['addons'] ?? array() ) ) ),
				esc_html( Prepaid::slot_label( $slot ) ),
				esc_html( self::state_text( $row, null ) )
			);
		}
	}

	/**
	 * Progress, resend and copy link under a prepaid application in the box.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function render_app_extra( \WC_Order $order, string $ref, array $app ): void {
		if ( 'pay_first' !== ( $app['flow'] ?? '' ) ) {
			return;
		}
		$slot = (string) ( $app['slot'] ?? Prepaid::slot_of_ref( $order, $ref ) );
		$row  = Prepaid::slots( $order )[ $slot ] ?? array();
		if ( 'prepaid' === ( $app['state'] ?? '' ) ) {
			echo '<br>' . esc_html( self::state_text( $row + array( 'state' => 'prepaid' ), $app ) );
		}
		if ( ! PrepaidStart::order_allows_start( $order, $ref ) ) {
			return;
		}
		$link   = PrepaidStart::start_url( $order, $ref );
		$resend = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'order'  => (int) $order->get_id(),
					'ref'    => rawurlencode( $ref ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . (int) $order->get_id() . '_' . $ref
		);
		printf(
			'<br><a class="button" href="%1$s">%2$s</a> <button type="button" class="button bapi-forms-copy-link" data-link="%3$s" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.dataset.link).then(()=>{this.textContent=%4$s;})">%5$s</button><br><small>%6$s</small>',
			esc_url( $resend ),
			esc_html__( 'Resend application link', 'businessapi-forms' ),
			esc_attr( $link ),
			esc_attr( (string) wp_json_encode( __( 'Copied', 'businessapi-forms' ) ) ),
			esc_html__( 'Copy client link', 'businessapi-forms' ),
			esc_html( (int) $order->get_customer_id() > 0 ? __( 'Your client logs in to open it.', 'businessapi-forms' ) : __( 'Guest link: it carries the order key, share it only with your client.', 'businessapi-forms' ) )
		);
	}

	/** admin-post.php?action=bapi_forms_resend_start&order=ID&ref=REF&_wpnonce=... */
	public function resend(): void {
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		$ref      = isset( $_GET['ref'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		check_admin_referer( self::ACTION . '_' . $order_id . '_' . $ref );
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'businessapi-forms' ), 403 );
		}
		$back = $order->get_edit_order_url();
		$key  = 'bapi_forms_resend_' . $order_id;
		$hits = (int) get_transient( $key );
		if ( $hits >= self::RESEND_LIMIT ) {
			self::notice( 'warning', __( 'The application link was already resent several times in the last hour. Try again later.', 'businessapi-forms' ) );
			wp_safe_redirect( $back );
			exit;
		}
		$sent = false;
		OrderBridge::with_lock(
			$order_id,
			function () use ( $order_id, $ref, &$sent ): void {
				$fresh = wc_get_order( $order_id );
				$slot  = $fresh instanceof \WC_Order ? Prepaid::slot_of_ref( $fresh, $ref ) : '';
				if ( '' === $slot || ! PrepaidStart::order_allows_start( $fresh, $ref ) ) {
					return;
				}
				$sent = PrepaidEmails::send_start( $fresh, $slot, false );
			},
			10
		);
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		self::notice( $sent ? 'success' : 'error', $sent ? __( 'Application link sent to your client.', 'businessapi-forms' ) : __( 'The application link could not be sent (the email may be switched off under WooCommerce > Settings > Emails, or the application can no longer be filled in).', 'businessapi-forms' ) );
		wp_safe_redirect( $back );
		exit;
	}

	private static function notice( string $type, string $text ): void {
		set_transient( AsicPayment::NOTICE . get_current_user_id(), array( $type, $text ), 5 * MINUTE_IN_SECONDS );
	}
}
