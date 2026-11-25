<?php
/**
 * Entity monitoring after the first purchase: entity id, My Account tab, renewals and emails.
 *
 * @package BapiForms
 */

namespace BapiForms\Monitoring;

use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class Monitoring {

	public const HOOK_STOP = 'bapi_forms_monitoring_stop_refunded';

	private ?Api $api = null;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		$api       = new Api( $this->plugin->client );
		$this->api = $api;
		$notifier  = new Notifier( $api );
		$renewals  = new Renewals( $api, $notifier );
		$notifier->set_renewals( $renewals );

		$notifier->register();
		$renewals->register();
		( new AccountTab( $api, $renewals ) )->register();
		( new CheckoutCheck( $this->plugin ) )->register();

		add_filter( 'bapi_forms_polled_application', array( $this, 'on_poll' ), 10, 4 );

		// Refunded or cancelled: stop monitoring so the customer gets no alerts they did not pay for.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'queue_stop' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'queue_stop' ) );
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'queue_stop' ) );
		add_action( self::HOOK_STOP, array( $this, 'stop_refunded' ) );
	}

	/**
	 * @param int|string $order_id Order id.
	 */
	public function queue_stop( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || Renewals::record( $order ) || ! self::monitored( $order ) ) {
			return;
		}
		if ( ! \BapiForms\Scheduler::pending( self::HOOK_STOP, array( (int) $order_id ), \BapiForms\Scheduler::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_STOP, array( (int) $order_id ), \BapiForms\Scheduler::GROUP );
		}
	}

	/**
	 * Stop what this refunded or cancelled order still monitors, but only entities whose current
	 * BAPI reference is still this order's application (never a later holder's).
	 *
	 * @param int|string $order_id Order id.
	 */
	public function stop_refunded( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || null === $this->api || $order->is_paid() ) {
			return; // Paid again since (e.g. status changed back): nothing to stop.
		}
		foreach ( self::monitored( $order ) as $ref => [ $env, $id ] ) {
			$current = $this->api->entity( $id, $env );
			if ( ! $current->ok() || Entities::norm( (string) ( $current->data['reference'] ?? '' ) ) !== Entities::norm( (string) $ref ) ) {
				continue;
			}
			if ( ! in_array( (string) ( $current->data['status'] ?? '' ), array( 'active', 'processing' ), true ) ) {
				continue;
			}
			$stopped = $this->api->stop( $id, $env );
			$order->add_order_note(
				$stopped->ok()
					/* translators: %d: BAPI entity id. */
					? sprintf( __( 'BusinessAPI Forms: the order was refunded or cancelled, so monitoring of Business API entity %d was stopped. Business API does not refund the partner for the time left.', 'businessapi-forms' ), $id )
					/* translators: %d: BAPI entity id. */
					: sprintf( __( 'BusinessAPI Forms: the order was refunded or cancelled, but monitoring of Business API entity %d could not be stopped. Stop it in the Business API dashboard.', 'businessapi-forms' ), $id )
			);
		}
	}

	/**
	 * Monitoring subscriptions an order bought: ref => [env, entity id].
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	private static function monitored( \WC_Order $order ): array {
		$out = array();
		foreach ( \BapiForms\Commerce\WooCommerce\OrderBridge::apps( $order ) as $ref => $app ) {
			$id = (int) ( $app['monitoring_entity'] ?? 0 );
			if ( $id > 0 && Renewals::PRODUCT === ( $app['product'] ?? '' ) ) {
				$out[ (string) $ref ] = array( (string) ( $app['env'] ?? '' ), $id );
			}
		}
		return $out;
	}

	/**
	 * Record the entity id a monitoring application's status names.
	 *
	 * @param array     $app    Tracked application.
	 * @param \WC_Order $order  Order (saved by the poll after this).
	 * @param string    $ref    Application reference.
	 * @param array     $status Status answer.
	 */
	public function on_poll( $app, $order, $ref, $status ): array {
		$app = (array) $app;
		$id  = is_array( $status ) ? (int) ( $status['monitoring']['entityId'] ?? 0 ) : 0;
		if ( $id <= 0 || ! $order instanceof \WC_Order || Renewals::PRODUCT !== ( $app['product'] ?? '' ) ) {
			return $app;
		}
		$env = (string) ( $app['env'] ?? $this->plugin->options->environment() );
		$new = (int) ( $app['monitoring_entity'] ?? 0 ) !== $id;
		if ( $new ) {
			$app['monitoring_entity'] = $id;
		}
		if ( Entities::attach( $order, $env, $id ) ) {
			$new = true;
			/* translators: 1: application reference, 2: BAPI entity id. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: application %1$s is monitoring Business API entity %2$d. The customer sees it under My Account > Monitoring.', 'businessapi-forms' ), (string) $ref, $id ) );
			// Unpaid renewal orders made for an older holder of this entity must never be paid.
			Renewals::cancel_stale( Entities::key( $env, $id ), null, (string) $ref );
		}
		// Refunded or cancelled before the entity id was known: stop it now, a minute later so the
		// poll has saved the id first.
		if ( $new && ! $order->is_paid() && $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) && ! Renewals::record( $order ) ) {
			$args = array( (int) $order->get_id() );
			if ( ! \BapiForms\Scheduler::pending( self::HOOK_STOP, $args, \BapiForms\Scheduler::GROUP ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_STOP, $args, \BapiForms\Scheduler::GROUP );
			}
		}
		return $app;
	}
}
