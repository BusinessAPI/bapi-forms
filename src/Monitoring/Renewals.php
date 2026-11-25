<?php
/**
 * Monitoring renewals without WooCommerce Subscriptions.
 *
 * One pending order per entity and period (the entity's current expiresAt), emailed to the
 * owner. Payment passes the same release guard as applications, then renews with BAPI using an
 * Idempotency-Key so a retry never buys a second year.
 *
 * @package BapiForms
 */

namespace BapiForms\Monitoring;

use BapiForms\Commerce\WooCommerce\Carriers;
use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class Renewals {

	public const PRODUCT     = 'entity_monitoring';
	public const META        = '_bapi_forms_monitoring_renewal';
	public const META_KEY    = '_bapi_forms_monitoring_renewal_key';
	public const HOOK_RENEW  = 'bapi_forms_monitoring_renew';
	public const CREATED_VIA = 'bapi-forms-monitoring';

	/** Days before the period ends that renewal orders are created. */
	public const WINDOW_DAYS = 30;

	public function __construct(
		private readonly Api $api,
		private readonly Notifier $notifier,
	) {}

	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'flag_payment' ), 5 );
		add_action( 'woocommerce_payment_complete', array( $this, 'queue' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'queue' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'queue' ) );
		add_action( self::HOOK_RENEW, array( $this, 'renew' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'customer_note' ), 6 );
		add_filter( 'woocommerce_valid_order_statuses_for_order_again', array( $this, 'no_order_again' ) );
	}

	public static function record( \WC_Order $order ): ?array {
		$record = $order->get_meta( self::META );
		return is_array( $record ) && isset( $record['entity'], $record['env'], $record['period'] ) ? $record : null;
	}

	/**
	 * The renewal order for the entity's current period, created (and the pay link emailed) when
	 * missing. Null when it cannot be renewed here.
	 *
	 * @param array<string, mixed> $entity    BAPI entity object (read live, not stored).
	 * @param bool                 $email     Email the pay link when the order is created.
	 * @param bool                 $on_demand The customer clicked Renew: an order they cancelled
	 *                                        does not count (automatic runs never recreate it).
	 */
	public function ensure( string $env, array $entity, bool $email = true, bool $on_demand = false ): ?\WC_Order {
		$id     = (int) ( $entity['id'] ?? 0 );
		$period = (string) ( $entity['expiresAt'] ?? '' );
		$status = (string) ( $entity['status'] ?? '' );
		// A stopped entity with paid time left is resumed for free instead.
		$renewable = in_array( $status, array( 'active', 'expired' ), true ) || ( 'stopped' === $status && $period <= gmdate( 'Y-m-d' ) );
		if ( $id <= 0 || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $period ) || ! $renewable ) {
			return null;
		}
		// A stray or early event never bills a year ahead: automatic runs from 30 days out, the
		// customer's own click from 60.
		$window = ( $on_demand ? 2 * self::WINDOW_DAYS : self::WINDOW_DAYS ) + 1;
		if ( $period > gmdate( 'Y-m-d', time() + $window * DAY_IN_SECONDS ) ) {
			return null;
		}

		$key       = Entities::key( $env, $id );
		$reference = Entities::norm( (string) ( $entity['reference'] ?? '' ) );
		$existing  = self::existing( $key, $period, ! $on_demand, $reference );
		if ( $existing ) {
			return $existing;
		}
		if ( Plugin::instance()->options->environment() !== $env ) {
			return null;
		}

		if ( ! self::sellable() ) {
			return null;
		}
		// Only for the owner of the current subscription.
		$source = Entities::owner_order( $key, $reference );
		if ( ! $source || ( (int) $source->get_customer_id() <= 0 && '' === (string) $source->get_billing_email() ) ) {
			return null;
		}

		$lock = 'bapi_forms_monitoring_renewal_' . md5( $key . '|' . $period );
		if ( ! \BapiForms\Lock::acquire( $lock, 5 * MINUTE_IN_SECONDS ) ) {
			return self::existing( $key, $period, ! $on_demand, $reference ); // Another request is creating it.
		}

		try {
			$order = self::existing( $key, $period, ! $on_demand, $reference );
			if ( $order ) {
				return $order;
			}
			$order = $this->create_order( $source, $env, $entity, $key, $period, $reference );
		} finally {
			\BapiForms\Lock::release( $lock );
		}

		if ( $order && $email ) {
			$this->notifier->renewal_due( $order, $entity );
		}
		return $order;
	}

	/** Newest renewal order for this entity and period. $closed false skips cancelled and refunded ones. */
	public static function existing( string $key, string $period, bool $closed = true, ?string $reference = null ): ?\WC_Order {
		$statuses = array_keys( wc_get_order_statuses() );
		if ( ! $closed ) {
			$statuses = array_values( array_diff( $statuses, array( 'wc-cancelled', 'wc-refunded' ) ) );
		}
		$orders = (array) wc_get_orders(
			array(
				'limit'      => 10,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'type'       => 'shop_order',
				'status'     => $statuses,
				'meta_key'   => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $key . ':' . $period, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			// An order made for an earlier holder of a reused entity never counts.
			$ref = Entities::norm( (string) ( self::record( $order )['ref'] ?? '' ) );
			if ( null === $reference || '' === $ref || Entities::norm( $reference ) === $ref ) {
				return $order;
			}
		}
		return null;
	}

	/** Days after its period that an unpaid renewal order is cancelled. */
	public const ABANDON_DAYS = 30;

	/**
	 * Unpaid renewal orders, oldest first.
	 *
	 * @param string|null $key Only this entity key.
	 * @param string|null $env Only this environment.
	 * @return list<\WC_Order>
	 */
	public static function unpaid_orders( ?string $key = null, ?string $env = null ): array {
		$args = array(
			'limit'   => 500,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'type'    => 'shop_order',
			'status'  => array( 'pending', 'failed', 'on-hold' ),
		);
		if ( null !== $key ) {
			$args['meta_key']   = Entities::META_ENTITY; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = $key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		} else {
			$args['meta_key']     = self::META; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_compare'] = 'EXISTS';
		}
		$out = array();
		foreach ( (array) wc_get_orders( $args ) as $order ) {
			$record = $order instanceof \WC_Order ? self::record( $order ) : null;
			if ( $record && ( null === $env || $env === $record['env'] ) ) {
				$out[] = $order;
			}
		}
		return $out;
	}

	/**
	 * Cancel unpaid renewal orders that can no longer be right: period already extended (paying
	 * would buy a year on top), holder changed, or left unpaid ABANDON_DAYS after the period.
	 *
	 * @param string                    $key         Entity key.
	 * @param array<string, mixed>|null $entity      Current BAPI entity (null: unknown).
	 * @param string|null               $current_ref The ref that now holds the entity.
	 * @return int Orders cancelled.
	 */
	public static function cancel_stale( string $key, ?array $entity = null, ?string $current_ref = null ): int {
		$cancelled = 0;
		$abandon   = gmdate( 'Y-m-d', time() - self::ABANDON_DAYS * DAY_IN_SECONDS );
		foreach ( self::unpaid_orders( $key ) as $order ) {
			$record = self::record( $order );
			if ( ! $record || $order->is_paid() ) {
				continue;
			}
			$ref    = Entities::norm( (string) ( $record['ref'] ?? '' ) );
			$expiry = is_array( $entity ) ? (string) ( $entity['expiresAt'] ?? '' ) : '';
			$why    = match ( true ) {
				'' !== $expiry && $expiry > (string) $record['period'] => __( 'the monitoring was already extended past the period this order was for', 'businessapi-forms' ),
				is_array( $entity ) && '' !== $ref && Entities::norm( (string) ( $entity['reference'] ?? '' ) ) !== $ref => __( 'the monitoring subscription it was for no longer belongs to this customer', 'businessapi-forms' ),
				null !== $current_ref && '' !== $ref && Entities::norm( $current_ref ) !== $ref => __( 'a newer application now holds this monitored entity', 'businessapi-forms' ),
				(string) $record['period'] < $abandon => __( 'it was not paid within 30 days after the monitoring ended', 'businessapi-forms' ),
				default => '',
			};
			if ( '' === $why ) {
				continue;
			}
			/* translators: %s: why. */
			$order->update_status( 'cancelled', sprintf( __( 'BusinessAPI Forms: renewal order cancelled automatically because %s.', 'businessapi-forms' ), $why ) );
			++$cancelled;
		}
		return $cancelled;
	}

	/** Switched on with a service fee above $0. */
	public static function sellable(): bool {
		return ! empty( Plugin::instance()->options->enabled_products()[ self::PRODUCT ] ) && Plugin::instance()->options->service_fee( self::PRODUCT ) > 0;
	}

	private function create_order( \WC_Order $source, string $env, array $entity, string $key, string $period, string $reference ): ?\WC_Order {
		// Fees are AUD amounts, as in the cart.
		if ( 'AUD' !== get_woocommerce_currency() ) {
			return null;
		}
		$order = wc_create_order(
			array(
				'customer_id' => (int) $source->get_customer_id(),
				'status'      => 'pending',
				'created_via' => self::CREATED_VIA,
			)
		);
		if ( is_wp_error( $order ) || ! $order instanceof \WC_Order ) {
			return null;
		}

		// WooCommerce's payment record, not form data.
		$order->set_address( $source->get_address( 'billing' ), 'billing' );
		$order->set_currency( 'AUD' );
		$order->set_prices_include_tax( wc_prices_include_tax() );

		if ( ! $this->add_lines( $order, $entity ) ) {
			$order->delete( true );
			return null;
		}

		$order->update_meta_data(
			self::META,
			array(
				'entity'   => (int) $entity['id'],
				'env'      => $env,
				'period'   => $period,
				'source'   => (int) $source->get_id(),
				'ref'      => $reference,
				'state'    => 'pending',
				'attempts' => 0,
			)
		);
		$order->update_meta_data( self::META_KEY, $key . ':' . $period );
		Entities::attach( $order, $env, (int) $entity['id'] );
		$order->calculate_totals();
		$order->add_order_note(
			sprintf(
				/* translators: 1: entity number label, 2: BAPI entity id, 3: date, 4: order number. */
				__( 'BusinessAPI Forms: monitoring renewal for %1$s (Business API entity %2$d). The current 12 months end on %3$s. Created from order #%4$s; the pay link was emailed to the customer. Payment renews it with Business API automatically.', 'businessapi-forms' ),
				'' !== Entities::number_label( $entity ) ? Entities::number_label( $entity ) : __( 'the entity', 'businessapi-forms' ),
				(int) $entity['id'],
				$period,
				$source->get_order_number()
			)
		);
		$order->save();

		$source->add_order_note(
			/* translators: 1: entity id, 2: order number. */
			sprintf( __( 'BusinessAPI Forms: renewal order #%2$s created for monitored entity %1$d.', 'businessapi-forms' ), (int) $entity['id'], $order->get_order_number() )
		);
		return $order;
	}

	/**
	 * The monitoring service fee line (monitoring has no government fee).
	 *
	 * @param array<string, mixed> $entity BAPI entity object.
	 */
	private function add_lines( \WC_Order $order, array $entity ): bool {
		$product_id = ( new Carriers( Plugin::instance()->products ) )->ensure( self::PRODUCT );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
		$price      = Plugin::instance()->options->service_fee( self::PRODUCT );
		if ( ! $product || $price <= 0 ) {
			return false;
		}
		// With prices including tax the line total excludes GST, as in the cart, so the renewal
		// costs the same as the first purchase. Base location rates, like WooCommerce's default.
		$net = $price;
		if ( wc_tax_enabled() && $order->get_prices_include_tax() && $product->is_taxable() ) {
			$taxes = \WC_Tax::calc_inclusive_tax( $price, \WC_Tax::get_base_tax_rates( $product->get_tax_class() ) );
			$net   = $price - array_sum( $taxes );
		}
		$item_id = $order->add_product(
			$product,
			1,
			array(
				'subtotal' => $net,
				'total'    => $net,
			)
		);
		$item    = $item_id ? $order->get_item( $item_id ) : null;
		if ( ! $item ) {
			return false;
		}
		$item->set_name( __( 'Entity monitoring renewal (12 months)', 'businessapi-forms' ) );
		$item->update_meta_data( OrderBridge::ITEM_CARRIER, self::PRODUCT );
		$label = Entities::number_label( $entity );
		if ( '' !== $label ) {
			$item->update_meta_data( __( 'For', 'businessapi-forms' ), $label );
		}
		$item->save();
		return true;
	}

	/**
	 * Same flag as applications, so release_refusal() accepts gateway orders without a transaction id.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function flag_payment( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && self::record( $order ) && ! $order->get_meta( OrderBridge::PAID_FLAG ) ) {
			$order->update_meta_data( OrderBridge::PAID_FLAG, time() );
			$order->save();
		}
	}

	/**
	 * @param int|string $order_id Order id.
	 */
	public function queue( $order_id ): void {
		$order  = wc_get_order( $order_id );
		$record = $order instanceof \WC_Order ? self::record( $order ) : null;
		if ( ! $record || 'renewed' === $record['state'] ) {
			return;
		}
		$args = array( (int) $order_id );
		if ( ! \BapiForms\Scheduler::pending( self::HOOK_RENEW, $args, OrderBridge::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_RENEW, $args, OrderBridge::GROUP );
		}
	}

	/** After re-activation: queue a paid renewal not yet renewed or blocked. True when queued. */
	public static function requeue( \WC_Order $order ): bool {
		$record = self::record( $order );
		if ( ! $record || in_array( $record['state'], array( 'renewed', 'blocked' ), true ) || ! $order->is_paid() ) {
			return false;
		}
		$args = array( (int) $order->get_id() );
		if ( ! \BapiForms\Scheduler::pending( self::HOOK_RENEW, $args, OrderBridge::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_RENEW, $args, OrderBridge::GROUP );
		}
		return true;
	}

	/**
	 * Safe to repeat.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function renew( $order_id ): void {
		$done = OrderBridge::with_lock(
			(int) $order_id,
			function () use ( $order_id ): void {
				$this->renew_locked( (int) $order_id );
			}
		);
		if ( ! $done ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_RENEW, array( (int) $order_id ), OrderBridge::GROUP );
		}
	}

	private function renew_locked( int $order_id ): void {
		$order  = wc_get_order( $order_id );
		$record = $order instanceof \WC_Order ? self::record( $order ) : null;
		if ( ! $record || in_array( $record['state'], array( 'renewed', 'blocked' ), true ) ) {
			return;
		}

		$refusal = OrderBridge::release_refusal( $order );
		if ( null !== $refusal ) {
			if ( empty( $record['refused'] ) ) {
				/* translators: %s: why, e.g. "the order is not paid". */
				$order->add_order_note( sprintf( __( 'BusinessAPI Forms: monitoring not renewed yet because %s.', 'businessapi-forms' ), $refusal ) );
				$record['refused'] = 1;
				$this->save( $order, $record );
			}
			return;
		}
		unset( $record['refused'] );

		$id  = (int) $record['entity'];
		$env = (string) $record['env'];

		$current = $this->api->entity( $id, $env );
		if ( 404 === $current->status ) {
			$this->blocked( $order, $record, 'not_found' );
			return;
		}
		if ( ! $current->ok() ) {
			$this->retry( $order, $record, 'http_' . $current->status );
			return;
		}
		// The subscription belongs to someone else now.
		if ( '' !== (string) ( $record['ref'] ?? '' ) && Entities::norm( (string) ( $current->data['reference'] ?? '' ) ) !== Entities::norm( (string) $record['ref'] ) ) {
			$this->blocked( $order, $record, 'owner_changed' );
			return;
		}
		if ( (string) ( $current->data['expiresAt'] ?? '' ) > (string) $record['period'] ) {
			if ( ! empty( $record['sent'] ) ) {
				// This order's own renewal went through; its answer was lost or still processing.
				$this->renewed( $order, $record, $current->data );
			} else {
				// Extended by something else: never buy a year on top.
				$this->blocked( $order, $record, 'period_changed' );
			}
			return;
		}

		// Marked before the call: from now on an extended period means this order did it.
		$record['sent'] = $record['sent'] ?? time();
		$this->save( $order, $record );

		$response = $this->api->renew( $id, $env, self::idempotency_key( $order_id, $record ) );
		$code     = $response->code();
		$row      = self::row_code( $response );

		// 202: charged, activation still finishing. A re-send within 24 hours reuses the key.
		if ( 202 === $response->status ) {
			$this->retry( $order, $record, 'processing' );
			return;
		}

		if ( $response->ok() ) {
			$entity = (array) ( $response->data['entities'][0] ?? array() );
			$this->renewed( $order, $record, $entity );
			return;
		}

		// Stopped with time left: resume (free), then renew on the next try.
		if ( 'stopped_with_time_remaining' === $row && empty( $record['resumed'] ) ) {
			$resumed           = $this->api->resume( $id, $env );
			$record['resumed'] = 1;
			$order->add_order_note( $resumed->ok() ? __( 'BusinessAPI Forms: the customer had stopped this monitoring; it was resumed so it can be renewed.', 'businessapi-forms' ) : __( 'BusinessAPI Forms: the customer had stopped this monitoring and it could not be resumed automatically.', 'businessapi-forms' ) );
			$this->retry( $order, $record, 'stopped', 0 );
			return;
		}

		$temporary = $response->is_transient()
			|| ( 409 === $response->status && in_array( $code, array( 'request_in_progress', 'order_in_progress', 'renewal_in_progress', '' ), true ) )
			|| 'renewal_in_progress' === $row
			|| 402 === $response->status; // The partner's card: retried while they fix it.
		if ( $temporary ) {
			if ( 402 === $response->status && empty( $record['card_mailed'] ) ) {
				$record['card_mailed'] = 1;
				$this->notifier->admin_alert( $order, __( 'Business API could not charge the card on your Business API account for a monitoring renewal. Update the card in your Business API dashboard; the renewal is retried automatically.', 'businessapi-forms' ) );
			}
			$this->retry( $order, $record, '' !== $code ? $code : 'http_' . $response->status );
			return;
		}

		$this->blocked( $order, $record, '' !== $row ? $row : ( '' !== $code ? $code : 'http_' . $response->status ) );
	}

	/** Stable per renewal order, so a retry never buys a second year. */
	private static function idempotency_key( int $order_id, array $record ): string {
		return 'bapi-forms-renew-' . substr( hash( 'sha256', home_url() ), 0, 12 ) . '-' . $order_id . '-' . $record['entity'] . '-' . $record['period'];
	}

	/** First row error code of a 422 answer: {errors: {"ids.0": [{code}]}}. */
	private static function row_code( \BapiForms\Api\Response $response ): string {
		foreach ( (array) ( $response->data['errors'] ?? array() ) as $list ) {
			foreach ( (array) $list as $error ) {
				if ( is_array( $error ) && is_string( $error['code'] ?? null ) ) {
					return sanitize_key( $error['code'] );
				}
			}
		}
		return '';
	}

	private function renewed( \WC_Order $order, array $record, array $entity ): void {
		$record['state'] = 'renewed';
		$record['until'] = sanitize_text_field( (string) ( $entity['expiresAt'] ?? '' ) );
		$this->save( $order, $record );
		$order->add_order_note(
			'' !== $record['until']
				/* translators: 1: entity id, 2: date. */
				? sprintf( __( 'BusinessAPI Forms: monitoring for entity %1$d renewed with Business API, now until %2$s.', 'businessapi-forms' ), (int) $record['entity'], $record['until'] )
				/* translators: %d: entity id. */
				: sprintf( __( 'BusinessAPI Forms: monitoring for entity %d renewed with Business API.', 'businessapi-forms' ), (int) $record['entity'] )
		);
		if ( ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed' );
		}
	}

	private function retry( \WC_Order $order, array $record, string $why, ?int $delay = null ): void {
		$attempt = (int) ( $record['attempts'] ?? 0 );
		if ( null === $delay && $attempt >= count( OrderBridge::RETRY_DELAYS ) ) {
			$this->blocked( $order, $record, 'gave_up_' . $why );
			return;
		}
		$record['attempts'] = $attempt + 1;
		$record['state']    = 'retrying';
		$this->save( $order, $record );
		$delay = $delay ?? OrderBridge::RETRY_DELAYS[ $attempt ];
		if ( 0 === $attempt && 'stopped' !== $why ) {
			/* translators: %s: machine code. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: the monitoring renewal could not be completed yet (%s). It is retried automatically.', 'businessapi-forms' ), $why ) );
		}
		as_schedule_single_action( time() + max( 30, $delay ), self::HOOK_RENEW, array( (int) $order->get_id() ), OrderBridge::GROUP );
	}

	private function blocked( \WC_Order $order, array $record, string $code ): void {
		$record['state'] = 'blocked';
		$record['code']  = $code;
		$this->save( $order, $record );
		$fix = match ( true ) {
			'owner_changed' === $code => __( 'This monitoring subscription no longer belongs to this customer in Business API, so it was not renewed. Refund the customer.', 'businessapi-forms' ),
			'period_changed' === $code => __( 'The monitoring was already extended past the period this order was for (another renewal or a change in Business API), so no second year was bought. Refund the customer.', 'businessapi-forms' ),
			in_array( $code, array( 'acn_not_eligible', 'abn_not_active', 'acn_not_found', 'abn_not_found' ), true ) => __( 'The company was deregistered or the ABN cancelled, so it cannot be monitored any more. Refund the customer.', 'businessapi-forms' ),
			'renewal_too_far_ahead' === $code => __( 'It is already renewed as far ahead as Business API allows (2 years). Refund the customer or renew later.', 'businessapi-forms' ),
			'not_found' === $code => __( 'Business API does not know this entity for your API key. Check the environment (sandbox or live) and contact Business API support.', 'businessapi-forms' ),
			default => __( 'Check the order, then contact Business API support quoting the order and entity id, or refund the customer.', 'businessapi-forms' ),
		};
		/* translators: 1: machine code, 2: what to do. */
		$note = sprintf( __( 'BusinessAPI Forms: the monitoring renewal was refused by Business API (%1$s). %2$s', 'businessapi-forms' ), $code, $fix );
		$order->add_order_note( $note );
		$this->notifier->admin_alert( $order, $note );
	}

	private function save( \WC_Order $order, array $record ): void {
		$order->update_meta_data( self::META, $record );
		$order->save();
	}

	/**
	 * @param \WC_Order|mixed $order Order.
	 */
	public function customer_note( $order ): void {
		$record = $order instanceof \WC_Order ? self::record( $order ) : null;
		if ( ! $record ) {
			return;
		}
		$status = match ( true ) {
			'renewed' === $record['state'] => __( 'Completed.', 'businessapi-forms' ),
			$order->has_status( array( 'cancelled', 'refunded' ) ) => __( 'Cancelled.', 'businessapi-forms' ),
			! $order->is_paid()             => __( 'Awaiting payment. Monitoring is renewed once payment is confirmed.', 'businessapi-forms' ),
			default                         => __( 'In progress.', 'businessapi-forms' ),
		};
		printf(
			'<section class="bapi-forms-monitoring-renewal"><h2>%s</h2><p>%s</p></section>',
			esc_html__( 'Monitoring renewal', 'businessapi-forms' ),
			esc_html( $status )
		);
	}

	/**
	 * A new renewal order is made per period, so never "Order again".
	 *
	 * @param array $statuses Statuses.
	 */
	public function no_order_again( $statuses ): array {
		global $wp;
		$order_id = isset( $wp->query_vars['view-order'] ) ? absint( $wp->query_vars['view-order'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		return $order instanceof \WC_Order && self::record( $order ) ? array() : (array) $statuses;
	}
}
