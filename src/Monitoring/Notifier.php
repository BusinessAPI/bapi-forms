<?php
/**
 * Monitoring notices from BAPI webhooks, with a daily check for anything a webhook missed.
 *
 * Only sent-notice markers (hashed keys) and the last event id per environment are stored, never
 * names or event content. A notice is marked sent only after the email went out.
 *
 * @package BapiForms
 */

namespace BapiForms\Monitoring;

use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Plugin;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Notifier {

	public const HOOK_DAILY = 'bapi_forms_monitoring_daily';
	private const CURSOR    = 'bapi_forms_monitoring_cursor';
	private const SENT_TTL  = 400 * DAY_IN_SECONDS;

	private ?Renewals $renewals = null;

	public function __construct( private readonly Api $api ) {}

	public function set_renewals( Renewals $renewals ): void {
		$this->renewals = $renewals;
	}

	public function register(): void {
		add_action( 'bapi_forms_webhook', array( $this, 'on_webhook' ), 10, 3 );
		add_action( self::HOOK_DAILY, array( $this, 'daily' ) );
		add_action( 'action_scheduler_init', array( $this, 'schedule' ) );
	}

	/** Re-added after a deactivation removed the group's actions. */
	public function schedule(): void {
		if ( ! as_has_scheduled_action( self::HOOK_DAILY, array(), OrderBridge::GROUP ) ) {
			$first = strtotime( 'tomorrow 07:00' );
			as_schedule_recurring_action( $first ? $first : time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_DAILY, array(), OrderBridge::GROUP );
		}
	}

	/**
	 * @param mixed $event       Event name.
	 * @param mixed $payload     Verified webhook body.
	 * @param mixed $environment test or live (default: the current one).
	 */
	public function on_webhook( $event, $payload, $environment = null ): void {
		$event = (string) $event;
		if ( ! str_starts_with( $event, 'monitoring.' ) || ! is_array( $payload ) ) {
			return;
		}
		$env    = in_array( $environment, array( Options::ENV_TEST, Options::ENV_LIVE ), true ) ? (string) $environment : Plugin::instance()->options->environment();
		$entity = is_array( $payload['entity'] ?? null ) ? $payload['entity'] : array();
		$id     = (int) ( $entity['id'] ?? 0 );
		if ( $id <= 0 || Plugin::instance()->options->environment() !== $env || ! Entities::orders( Entities::key( $env, $id ) ) ) {
			return; // Not one this site sold (the partner's own API entities, other sites).
		}
		// The receiver drops BAPI's reference from the payload, so ownership is checked live.
		$live = $this->api->entity( $id, $env );
		if ( ! $live->ok() ) {
			return; // The daily check catches up.
		}
		$entity = $live->data;
		if ( ! Entities::owner_order( Entities::key( $env, $id ), Entities::norm( (string) ( $entity['reference'] ?? '' ) ) ) ) {
			return; // Not a subscription this site's customer holds now.
		}

		switch ( $event ) {
			case 'monitoring.change_detected':
				$this->change( $env, $entity, (int) ( $payload['monitoringEventId'] ?? 0 ) );
				break;
			case 'monitoring.expiring':
				$this->expiring( $env, $entity, (int) ( $payload['daysRemaining'] ?? $entity['daysRemaining'] ?? 0 ) );
				break;
			case 'monitoring.expired':
				$this->expired( $env, $entity );
				break;
		}
	}

	/** Current environment only: a sandbox shop never mails about live entities, and the reverse. */
	public function daily(): void {
		$env = Plugin::instance()->options->environment();
		$ids = Entities::all( $env )[ $env ] ?? array();
		if ( ! Plugin::instance()->options->has_key( $env ) ) {
			return;
		}
		// Unpaid renewal orders' entities too (their entity may no longer be tracked otherwise).
		$unpaid = Renewals::unpaid_orders( null, $env );
		foreach ( $unpaid as $order ) {
			$ids[] = (int) Renewals::record( $order )['entity'];
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $ids ) {
			return;
		}
		$entities = $this->api->entities_by_ids( $ids, $env );
		if ( null !== $entities ) {
			$this->check_entities( $env, $entities, $unpaid );
		}
		$this->check_events( $env, $ids );
	}

	/**
	 * @param array<int, array<string, mixed>> $entities id => BAPI entity (read now, not stored).
	 * @param list<\WC_Order>                  $unpaid   Unpaid renewal orders in this environment.
	 */
	private function check_entities( string $env, array $entities, array $unpaid ): void {
		$before = gmdate( 'Y-m-d', time() + ( Renewals::WINDOW_DAYS + 1 ) * DAY_IN_SECONDS );
		foreach ( $entities as $id => $entity ) {
			$expires = (string) ( $entity['expiresAt'] ?? '' );
			if ( 'active' === ( $entity['status'] ?? '' ) && '' !== $expires && $expires < $before ) {
				$this->expiring( $env, $entity, (int) ( $entity['daysRemaining'] ?? 0 ) );
			}
		}
		$checked = array();
		foreach ( $unpaid as $order ) {
			$record = Renewals::record( $order );
			$id     = (int) $record['entity'];
			$key    = Entities::key( $env, $id );
			if ( isset( $checked[ $key ] ) ) {
				continue;
			}
			$checked[ $key ] = true;
			$entity          = $entities[ $id ] ?? null;
			Renewals::cancel_stale( $key, $entity );
			// Still unpaid and the entity has ended: the "monitoring has ended" email.
			if ( is_array( $entity ) && 'expired' === ( $entity['status'] ?? '' ) ) {
				$this->expired( $env, $entity );
			}
		}
	}

	private function check_events( string $env, array $ids ): void {
		$cursors = get_option( self::CURSOR, array() );
		$cursors = is_array( $cursors ) ? $cursors : array();
		$since   = (int) ( $cursors[ $env ] ?? 0 );

		if ( 0 === $since ) {
			// First run: start from the newest event, never mail the history.
			$first = $this->api->events_since( 0, $env, 1, 1 );
			if ( ! $first->ok() ) {
				return;
			}
			$total = (int) ( $first->data['meta']['total'] ?? 0 );
			if ( $total > 0 ) {
				$last  = $this->api->events_since( 0, $env, $total, 1 );
				$since = $last->ok() ? (int) ( $last->data['data'][0]['monitoringEventId'] ?? 0 ) : 0;
			}
			$cursors[ $env ] = max( 1, $since );
			update_option( self::CURSOR, $cursors, false );
			return;
		}

		for ( $page = 1; $page <= 10; $page++ ) {
			$response = $this->api->events_since( $since, $env, 1, 100 );
			if ( ! $response->ok() ) {
				break;
			}
			$events = (array) ( $response->data['data'] ?? array() );
			$failed = false;
			foreach ( $events as $event ) {
				$event_id = (int) ( $event['monitoringEventId'] ?? 0 );
				$entity   = (int) ( $event['entityId'] ?? 0 );
				if ( in_array( $entity, $ids, true ) && $event_id > 0 ) {
					$detail = $this->api->entity( $entity, $env );
					// Never move the cursor past an event that could not be handled.
					if ( ! $detail->ok() || ! $this->change( $env, $detail->data, $event_id ) ) {
						$failed = true;
						break;
					}
				}
				if ( $event_id > $since ) {
					$since = $event_id;
				}
			}
			if ( $failed || count( $events ) < 100 ) {
				break;
			}
		}
		$cursors[ $env ] = $since;
		update_option( self::CURSOR, $cursors, false );
	}

	/**
	 * @param array<string, mixed> $entity BAPI entity object.
	 * @return bool False when the email could not be sent (retry later).
	 */
	private function change( string $env, array $entity, int $event_id ): bool {
		$id  = (int) ( $entity['id'] ?? 0 );
		$key = 'change:' . $env . ':' . $id . ':' . $event_id;
		if ( $event_id <= 0 || $this->was_sent( $key ) ) {
			return true;
		}
		$order = Entities::owner_order( Entities::key( $env, $id ), Entities::norm( (string) ( $entity['reference'] ?? '' ) ) );
		if ( ! $order ) {
			return true; // No current owner here: nothing to send.
		}
		$name = Entities::display_name( $entity );
		$sent = $this->send(
			$order,
			/* translators: %s: ACN or ABN (or name). */
			sprintf( __( 'A change was detected for %s', 'businessapi-forms' ), Entities::subject_label( $entity ) ),
			__( 'A change was detected', 'businessapi-forms' ),
			array(
				/* translators: %s: company or business name. */
				sprintf( __( 'Our monitoring found a change on the public register for %s.', 'businessapi-forms' ), $name ),
				__( 'You can see what changed in your account.', 'businessapi-forms' ),
			),
			AccountTab::url(),
			__( 'View it in your account', 'businessapi-forms' )
		);
		if ( $sent ) {
			$this->mark_sent( $key );
		}
		return $sent;
	}

	/**
	 * @param array<string, mixed> $entity BAPI entity object.
	 */
	public function expiring( string $env, array $entity, int $days ): void {
		if ( 'active' !== ( $entity['status'] ?? '' ) || null === $this->renewals ) {
			return;
		}
		$this->renewals->ensure( $env, $entity );
		// Second reminder in the last week while still unpaid.
		$id     = (int) ( $entity['id'] ?? 0 );
		$period = (string) ( $entity['expiresAt'] ?? '' );
		$order  = Renewals::existing( Entities::key( $env, $id ), $period, true, Entities::norm( (string) ( $entity['reference'] ?? '' ) ) );
		$key    = 'expiring7:' . $env . ':' . $id . ':' . $period;
		if ( $days > 0 && $days <= 7 && $order && $order->needs_payment() && ! $this->was_sent( $key ) && $this->renewal_due( $order, $entity, true ) ) {
			$this->mark_sent( $key );
		}
	}

	/**
	 * @param array<string, mixed> $entity BAPI entity object.
	 */
	public function expired( string $env, array $entity ): void {
		$id     = (int) ( $entity['id'] ?? 0 );
		$period = (string) ( $entity['expiresAt'] ?? '' );
		$sent   = 'expired:' . $env . ':' . $id . ':' . $period;
		if ( 'expired' !== ( $entity['status'] ?? '' ) || $this->was_sent( $sent ) ) {
			return;
		}
		$owner = Entities::owner_order( Entities::key( $env, $id ), Entities::norm( (string) ( $entity['reference'] ?? '' ) ) );
		if ( ! $owner ) {
			return; // Refunded, cancelled or no longer this customer's subscription.
		}
		$renewal = null !== $this->renewals ? $this->renewals->ensure( $env, $entity, false ) : null;
		if ( $renewal && $renewal->is_paid() ) {
			return; // Paid; the renewal is being applied.
		}
		// A pay link only for an order that can still be paid (never a cancelled one).
		$renewal = $renewal && $renewal->needs_payment() ? $renewal : null;
		$order   = $renewal ?? $owner;
		$name    = Entities::display_name( $entity );
		$ok      = $this->send(
			$order,
			/* translators: %s: ACN or ABN (or name). */
			sprintf( __( 'Monitoring of %s has ended', 'businessapi-forms' ), Entities::subject_label( $entity ) ),
			__( 'Monitoring has ended', 'businessapi-forms' ),
			array(
				/* translators: %s: company or business name. */
				sprintf( __( 'Your 12 months of monitoring for %s have ended, so we no longer check it for changes.', 'businessapi-forms' ), $name ),
				$renewal ? __( 'You can start it again for another 12 months at any time.', 'businessapi-forms' ) : __( 'Contact us if you would like to start it again.', 'businessapi-forms' ),
			),
			$renewal ? $renewal->get_checkout_payment_url() : AccountTab::url(),
			$renewal ? __( 'Renew monitoring', 'businessapi-forms' ) : __( 'View your account', 'businessapi-forms' )
		);
		if ( $ok ) {
			$this->mark_sent( $sent );
		}
	}

	/**
	 * Email the pay link for a renewal order.
	 *
	 * @param array<string, mixed> $entity BAPI entity object.
	 */
	public function renewal_due( \WC_Order $order, array $entity, bool $reminder = false ): bool {
		if ( ! $order->needs_payment() ) {
			return false; // Paid, cancelled or otherwise not payable: never a pay link.
		}
		$name = Entities::display_name( $entity );
		$date = (string) ( $entity['expiresAt'] ?? '' );
		return $this->send(
			$order,
			/* translators: %s: ACN or ABN (or name). */
			sprintf( $reminder ? __( 'Reminder: monitoring of %s ends soon', 'businessapi-forms' ) : __( 'Renew monitoring of %s', 'businessapi-forms' ), Entities::subject_label( $entity ) ),
			__( 'Time to renew your monitoring', 'businessapi-forms' ),
			array(
				/* translators: 1: company or business name, 2: date. */
				sprintf( __( 'Your monitoring of %1$s ends on %2$s.', 'businessapi-forms' ), $name, self::date( $date ) ),
				/* translators: %s: price. */
				sprintf( __( 'Renew it for another 12 months for %s. The new 12 months start when the current ones end.', 'businessapi-forms' ), wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ),
			),
			$order->get_checkout_payment_url(),
			__( 'Renew monitoring', 'businessapi-forms' )
		);
	}

	/** Admin email about a renewal problem: no customer data beyond the order link. */
	public function admin_alert( \WC_Order $order, string $text ): void {
		$subject = sprintf(
			/* translators: %s: order number. */
			__( 'BusinessAPI Forms: monitoring renewal needs attention (order #%s)', 'businessapi-forms' ),
			$order->get_order_number()
		);
		wp_mail( (string) get_option( 'admin_email' ), $subject, $text . "\n\n" . $order->get_edit_order_url() );
	}

	/**
	 * @param list<string> $lines Plain sentences.
	 */
	private function send( \WC_Order $order, string $subject, string $heading, array $lines, string $url, string $button ): bool {
		$to = (string) $order->get_billing_email();
		if ( ! is_email( $to ) ) {
			return true; // Nobody to mail: nothing to retry.
		}
		$html = '';
		foreach ( $lines as $line ) {
			$html .= '<p>' . esc_html( $line ) . '</p>';
		}
		$html .= '<p><a href="' . esc_url( $url ) . '" style="font-weight:bold">' . esc_html( $button ) . '</a></p>';
		if ( Options::ENV_TEST === Plugin::instance()->options->environment() ) {
			$subject = __( '[Sandbox test]', 'businessapi-forms' ) . ' ' . $subject;
		}
		$mailer = WC()->mailer();
		return (bool) $mailer->send( $to, $subject, $mailer->wrap_message( $heading, $html ), "Content-Type: text/html\r\n" );
	}

	/** One expiring transient per notice instead of an ever-growing option. */
	private static function sent_key( string $key ): string {
		return 'bapi_forms_mon_sent_' . md5( $key );
	}

	private function was_sent( string $key ): bool {
		return false !== get_transient( self::sent_key( $key ) );
	}

	private function mark_sent( string $key ): void {
		set_transient( self::sent_key( $key ), 1, self::SENT_TTL );
	}

	public static function date( string $ymd ): string {
		$time   = strtotime( $ymd . ' 00:00:00' );
		$format = (string) get_option( 'date_format' );
		return $time ? date_i18n( '' !== $format ? $format : 'j F Y', $time ) : $ymd;
	}
}
