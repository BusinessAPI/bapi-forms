<?php
/**
 * Pay first emails: start link and reminders to the client, a "paid but not filled in" email to
 * the partner after 30 days (nothing is cancelled or refunded automatically), the next step block
 * in order emails, and the duplicate monitoring alert.
 *
 * One scheduled action per slot at a time; step 0..n-1 are client reminders, -1 the partner email.
 *
 * @package BapiForms
 */

namespace BapiForms\Emails;

use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Commerce\WooCommerce\StatusText;
use BapiForms\Commerce\WooCommerce\Prepaid;
use BapiForms\Frontend\PrepaidStart;
use BapiForms\Plugin;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class PrepaidEmails {

	public const HOOK  = 'bapi_forms_prepaid_reminder';
	public const GROUP = \BapiForms\Scheduler::GROUP;

	/** Order emails that get the "Next step" block. */
	private const ORDER_EMAILS = array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order' );

	public function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 3 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'order_email_block' ), 5, 4 );
	}

	/** Client reminders, in days after the application was created. */
	private const REMINDER_DAYS = array( 2, 7, 21 );
	/** The partner's "paid but not filled in" email, in days after creation. */
	private const PARTNER_DAYS = 30;

	/** The "paid but not filled in" email's recipient setting, else the site admin email. */
	private static function partner_recipient(): string {
		$settings  = get_option( 'woocommerce_bapi_forms_prepaid_unused_settings', array() );
		$recipient = is_array( $settings ) ? trim( (string) ( $settings['recipient'] ?? '' ) ) : '';
		return '' !== $recipient ? $recipient : (string) get_option( 'admin_email' );
	}

	/**
	 * Schedule the next reminder, or the partner email, of a prepaid slot.
	 *
	 * @param array<string, mixed> $row Slot record.
	 */
	public static function schedule( int $order_id, string $slot, array $row ): void {
		if ( 'prepaid' !== ( $row['state'] ?? '' ) ) {
			return;
		}
		$issued = (int) ( $row['issued_at'] ?? 0 ) > 0 ? (int) $row['issued_at'] : time();
		$days   = self::REMINDER_DAYS;
		$sent   = (int) ( $row['reminders'] ?? 0 );
		if ( $sent < count( $days ) ) {
			$step = $sent;
			$at   = $issued + $days[ $sent ] * DAY_IN_SECONDS;
		} elseif ( empty( $row['partner_nudged'] ) ) {
			$step = -1;
			$at   = $issued + self::PARTNER_DAYS * DAY_IN_SECONDS;
		} else {
			self::unschedule( $order_id, $slot );
			return;
		}
		self::unschedule( $order_id, $slot );
		as_schedule_single_action( max( time() + 30, $at ), self::HOOK, array( $order_id, $slot, $step ), self::GROUP );
	}

	/** Only this order and slot, never the group. */
	public static function unschedule( int $order_id, string $slot ): void {
		if ( ! Prepaid::is_slot( $slot ) ) {
			return;
		}
		$last = count( self::REMINDER_DAYS );
		for ( $step = -1; $step < $last; $step++ ) {
			as_unschedule_all_actions( self::HOOK, array( $order_id, $slot, $step ), self::GROUP );
		}
	}

	/**
	 * Send the step's email while the application still waits for the client, then schedule the next.
	 *
	 * @param int|string $order_id Order id.
	 * @param string     $slot     Slot id.
	 * @param int|string $step     Step.
	 */
	public static function run( $order_id, $slot, $step ): void {
		$order_id = (int) $order_id;
		$slot     = (string) $slot;
		$step     = (int) $step;
		$next     = null;
		$done     = OrderBridge::with_lock(
			$order_id,
			function () use ( $order_id, $slot, $step, &$next ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$slots = Prepaid::slots( $order );
				$row   = $slots[ $slot ] ?? null;
				$ref   = is_array( $row ) ? (string) ( $row['ref'] ?? '' ) : '';
				$app   = OrderBridge::apps( $order )[ $ref ] ?? null;
				if ( ! is_array( $row ) || 'prepaid' !== ( $row['state'] ?? '' ) || ! is_array( $app ) || 'prepaid' !== ( $app['state'] ?? '' ) ) {
					return;
				}
				// The client may have just finished it.
				$summary = Plugin::instance()->applications->summary( $ref, (string) $app['env'] );
				if ( $summary->ok() && 'draft' !== (string) ( $summary->data['status'] ?? 'draft' ) ) {
					as_enqueue_async_action( OrderBridge::HOOK_POLL, array( $order_id, $ref ), OrderBridge::GROUP );
					return;
				}
				// A failed order paid again resumes them (OrderBridge sync_holds()).
				if ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) || ! empty( $app['refunded'] ) || ! empty( $app['closed'] ) ) {
					return;
				}
				// Paused: try the same step again tomorrow.
				if ( ! PrepaidStart::order_allows_start( $order, $ref ) ) {
					as_unschedule_all_actions( self::HOOK, array( $order_id, $slot, $step ), self::GROUP );
					as_schedule_single_action( time() + DAY_IN_SECONDS, self::HOOK, array( $order_id, $slot, $step ), self::GROUP );
					return;
				}
				$reminders = (int) ( $row['reminders'] ?? 0 );
				if ( $step >= 0 && $reminders === $step ) {
					self::send_start( $order, $slot, true );
					$row['reminders'] = $step + 1;
				} elseif ( -1 === $step && empty( $row['partner_nudged'] ) ) {
					self::send_unused( $order, $slot, $row );
					$row['partner_nudged'] = time();
				} else {
					return;
				}
				$slots[ $slot ] = $row;
				$order->update_meta_data( Prepaid::META, $slots );
				$order->save();
				$next = $row;
			},
			30
		);
		if ( ! $done ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK, array( $order_id, $slot, $step ), self::GROUP );
			return;
		}
		if ( is_array( $next ) ) {
			self::schedule( $order_id, $slot, $next );
		}
	}

	/** Runs under the order lock. True when sent. */
	public static function send_start( \WC_Order $order, string $slot, bool $reminder ): bool {
		$row = Prepaid::slots( $order )[ $slot ] ?? null;
		$ref = is_array( $row ) ? (string) ( $row['ref'] ?? '' ) : '';
		if ( '' === $ref || ! PrepaidStart::order_allows_start( $order, $ref ) ) {
			return false;
		}
		$emails = WC()->mailer()->get_emails();
		$email  = $emails['BapiForms_StartApplicationEmail'] ?? null;
		if ( ! $email instanceof StartApplicationEmail ) {
			return false;
		}
		$product = (string) ( $row['product'] ?? '' );
		$term    = (int) ( $row['fixed']['term'] ?? 0 );
		$sent    = $email->trigger(
			$order,
			array(
				'product'   => \BapiForms\Products\ExpectedLines::line_name( $product, (array) ( $row['addons'] ?? array() ) ),
				'short_ref' => StatusText::short_ref( $ref ),
				'start_url' => PrepaidStart::start_url( $order, $ref ),
				'sandbox'   => Options::ENV_TEST === ( $row['env'] ?? '' ),
				'reminder'  => $reminder,
				/* translators: %d: number of years. */
				'fixed'     => $term > 0 ? sprintf( _n( 'Registration period: %d year (paid).', 'Registration period: %d years (paid).', $term, 'businessapi-forms' ), $term ) : '',
			)
		);
		if ( $sent ) {
			$order->add_order_note(
				$reminder
					/* translators: %s: application reference. */
					? sprintf( __( 'BusinessAPI Forms: reminder to fill in application %s sent to the customer.', 'businessapi-forms' ), $ref )
					/* translators: %s: application reference. */
					: sprintf( __( 'BusinessAPI Forms: "start your application" email sent to the customer for application %s.', 'businessapi-forms' ), $ref )
			);
			if ( ! $reminder ) {
				$order->save();
			}
		}
		return $sent;
	}

	/**
	 * @param array<string, mixed> $row Slot record.
	 */
	private static function send_unused( \WC_Order $order, string $slot, array $row ): void {
		$ref  = (string) ( $row['ref'] ?? '' );
		$days = max( 1, (int) floor( ( time() - (int) ( $row['issued_at'] ?? time() ) ) / DAY_IN_SECONDS ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: application reference, 2: number of days. */
				__( 'BusinessAPI Forms: your client has not filled in application %1$s, paid %2$d days ago. Contact them, or refund the order (that cancels the application). Nothing happens automatically.', 'businessapi-forms' ),
				$ref,
				$days
			)
		);
		$emails = WC()->mailer()->get_emails();
		$email  = $emails['BapiForms_PrepaidUnusedEmail'] ?? null;
		if ( $email instanceof PrepaidUnusedEmail ) {
			$email->trigger(
				$order,
				array(
					'product'       => \BapiForms\Products\ExpectedLines::line_name( (string) ( $row['product'] ?? '' ), (array) ( $row['addons'] ?? array() ) ),
					'short_ref'     => StatusText::short_ref( $ref ),
					'ref'           => $ref,
					'days'          => $days,
					'reminder_days' => array_slice( self::REMINDER_DAYS, 0, (int) ( $row['reminders'] ?? 0 ) ),
					'started'       => ! empty( OrderBridge::apps( $order )[ $ref ]['form']['started'] ),
					'order_url'     => $order->get_edit_order_url(),
					'sandbox'       => Options::ENV_TEST === ( $row['env'] ?? '' ),
				)
			);
		}
		unset( $slot );
	}

	/** The form said the entity is already monitored: email the partner once per application. */
	public static function already_monitored( int $order_id, string $ref ): void {
		OrderBridge::with_lock(
			$order_id,
			function () use ( $order_id, $ref ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$slot  = Prepaid::slot_of_ref( $order, $ref );
				$slots = Prepaid::slots( $order );
				if ( '' === $slot || ! empty( $slots[ $slot ]['duplicate_mailed'] ) ) {
					return;
				}
				$slots[ $slot ]['duplicate_mailed'] = time();
				$order->update_meta_data( Prepaid::META, $slots );
				$order->add_order_note(
					sprintf(
						/* translators: %s: application reference. */
						__( 'BusinessAPI Forms: your client paid for monitoring (application %s), but the entity they entered is already monitored on your account. Consider refunding the order.', 'businessapi-forms' ),
						$ref
					)
				);
				$order->save();
				wp_mail(
					self::partner_recipient(),
					/* translators: %s: order number. */
					sprintf( __( 'BusinessAPI Forms: duplicate monitoring paid on order #%s', 'businessapi-forms' ), $order->get_order_number() ),
					sprintf(
						/* translators: 1: order number, 2: application reference, 3: order admin URL. */
						__( "Your client paid for entity monitoring on order #%1\$s (application %2\$s), but the entity they entered is already monitored. The form told them to contact you for a refund.\n\nConsider refunding the order (that cancels the application).\n%3\$s", 'businessapi-forms' ),
						$order->get_order_number(),
						$ref,
						$order->get_edit_order_url()
					)
				);
			},
			10
		);
	}

	/**
	 * "Next step: fill in your application" in the customer's order emails.
	 *
	 * @param \WC_Order|mixed $order         Order.
	 * @param bool|mixed      $sent_to_admin Admin copy.
	 * @param bool|mixed      $plain_text    Plain text.
	 * @param \WC_Email|mixed $email         Email.
	 */
	public static function order_email_block( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $order instanceof \WC_Order || $sent_to_admin || ! $email instanceof \WC_Email || ! in_array( $email->id, self::ORDER_EMAILS, true ) || ! Prepaid::slots( $order ) ) {
			return;
		}
		$lines = array();
		foreach ( OrderBridge::apps( $order ) as $ref => $app ) {
			if ( PrepaidStart::order_allows_start( $order, (string) $ref ) && 'prepaid' === ( $app['state'] ?? '' ) ) {
				$lines[] = array(
					StatusText::short_ref( (string) $ref ),
					PrepaidStart::start_url( $order, (string) $ref ),
				);
			}
		}
		$waiting = Prepaid::has_unissued( $order );
		$blocked = $waiting && $order->is_paid() && ! Prepaid::has_creating( $order );
		if ( ! $lines && ! $waiting ) {
			return;
		}
		$heading = __( 'Next step: fill in your application', 'businessapi-forms' );
		$wait    = $blocked
			? __( 'We will be in touch about your application.', 'businessapi-forms' )
			: ( $order->is_paid()
			? __( 'We are getting your form ready and will email you the link shortly.', 'businessapi-forms' )
			: __( 'Once your payment is confirmed we will email you a link to fill in your application.', 'businessapi-forms' ) );
		if ( $plain_text ) {
			echo "\n" . esc_html( ApplicationEmail::plain( $heading ) ) . "\n";
			foreach ( $lines as [ $label, $url ] ) {
				echo esc_html( ApplicationEmail::plain( $label ) ) . ': ' . esc_url( $url ) . "\n";
			}
			if ( ! $lines ) {
				echo esc_html( ApplicationEmail::plain( $wait ) ) . "\n";
			}
			echo "\n";
			return;
		}
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		if ( $lines ) {
			echo '<p>' . esc_html__( 'Use the link below to fill in your details. They are saved as you go, so you can stop and come back with the same link.', 'businessapi-forms' ) . '</p>';
			foreach ( $lines as [ $label, $url ] ) {
				printf( '<p><a href="%1$s">%2$s</a> (%3$s)</p>', esc_url( $url ), esc_html__( 'Start your application', 'businessapi-forms' ), esc_html( $label ) );
			}
		} else {
			echo '<p>' . esc_html( $wait ) . '</p>';
		}
	}
}
