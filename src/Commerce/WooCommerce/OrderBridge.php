<?php
/**
 * Links WooCommerce orders to BAPI applications: release on payment, cancel on cancellation,
 * refund or trash, then poll status. All order meta changes run under a per-order lock.
 *
 * Release guard: a paid order above $0 that covers the government fees; never cash on delivery;
 * cheque and bank transfer only when the partner allows it; online gateways only with a
 * transaction id or a payment_complete call (a status set by hand is not a payment).
 *
 * One live order per ref: a new checkout for a ref still on an unpaid order takes it over.
 *
 * Order meta holds references and outcomes only, never form fields or BAPI payloads:
 *
 *   _bapi_forms_applications = [ REF => [product, env, state (pending|blocked|released|cancelled),
 *     status, requests, acn, abn, attempts, reason, cancel_attempts, issues, progress, agency,
 *     request, polls, updated, document, asic, asic_due_at, asic_mailed, asic_lapsed_mailed] ]
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Api\Response;
use BapiForms\Applications\Applications;
use BapiForms\Plugin;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class OrderBridge {

	use PrepaidLifecycle;

	public const META         = '_bapi_forms_applications';
	public const ITEM_REF     = '_bapi_forms_application_ref';
	public const ITEM_CARRIER = '_bapi_forms_carrier';
	/** One plain meta row per ref, so a ref can be looked up across orders. */
	public const ORDER_REF = '_bapi_forms_ref';

	/** Payment methods that mark an order paid without money having arrived. */
	public const OFFLINE_METHODS = array( 'cod', 'cheque', 'bacs' );
	/** Offline methods a partner may allow, once they have marked the order paid. */
	public const OVERRIDABLE_METHODS = array( 'cheque', 'bacs' );

	public const GROUP        = \BapiForms\Scheduler::GROUP;
	public const HOOK_POLL    = 'bapi_forms_poll';
	public const HOOK_RETRY   = 'bapi_forms_retry_release';
	public const HOOK_RELEASE = 'bapi_forms_release_order';
	public const HOOK_CANCEL  = 'bapi_forms_cancel_order';
	/** Cancel unreleased applications a partial refund covered in full. */
	public const HOOK_CANCEL_REFUNDED = 'bapi_forms_cancel_refunded';
	public const HOOK_RETRY_NOW       = 'bapi_forms_retry_now';
	/** Retry of suspend_order() while the order is busy. */
	public const HOOK_SUSPEND = 'bapi_forms_prepaid_suspend';
	/** Pause or resume after a partial refund, deleted refund or order item edit. */
	public const HOOK_RECHECK = 'bapi_forms_recheck_holds';
	/** Highest item id when the order was paid: later lines were not paid for. */
	public const PAID_ITEMS = '_bapi_forms_paid_max_item';
	/** A Registration line added after payment, already noted. */
	public const ITEM_IGNORED = '_bapi_forms_pf_ignored';
	public const ORDER_ACTION = self::HOOK_RETRY;
	/** woocommerce_payment_complete ran for this order. */
	public const PAID_FLAG = '_bapi_forms_payment_complete';
	/** Id of the newer order its applications moved to. */
	public const MOVED_FLAG = '_bapi_forms_moved_to';
	/** Why the release guard last refused the order (deduplicates its note). */
	public const REFUSED_FLAG = '_bapi_forms_release_refused';
	/** Agency progress codes accepted from GET /status; anything else is ignored. */
	public const PROGRESS = array( 'with_agency', 'manual_review', 'awaiting_asic_payment', 'sandbox_validated' );
	/** Refs of a paid order that cannot be released, already reported. */
	private const UNRELEASABLE_FLAG = '_bapi_forms_unreleasable';
	private const LOCK_SECONDS      = 180; // Refreshed before each application (refresh_lock()).
	/** Lock row name, followed by the order id. */
	public const LOCK_PREFIX = 'bapi_forms_lock_';
	private const MAX_POLLS  = 65; // About two months at the daily rate.
	/** Status poll backoff; also the re-checks while BAPI is still lodging a cancelled one. */
	private const BACKOFF = array( 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS );
	/** Release (and monitoring renewal) retry delays, about 7 days in all, then blocked. */
	public const RETRY_DELAYS = array(
		10 * MINUTE_IN_SECONDS,
		30 * MINUTE_IN_SECONDS,
		2 * HOUR_IN_SECONDS,
		6 * HOUR_IN_SECONDS,
		12 * HOUR_IN_SECONDS,
		DAY_IN_SECONDS,
		DAY_IN_SECONDS,
		DAY_IN_SECONDS,
		DAY_IN_SECONDS,
		DAY_IN_SECONDS,
		DAY_IN_SECONDS,
	);
	/** Cancel retries after a 5xx or timeout. */
	private const CANCEL_DELAYS = array( 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS );
	/** Prepaid applications never expire in BAPI, so their cancel is retried for about a week. */
	private const PREPAID_CANCEL_DELAYS = array( ...self::CANCEL_DELAYS, 6 * HOUR_IN_SECONDS, 12 * HOUR_IN_SECONDS, DAY_IN_SECONDS, DAY_IN_SECONDS, DAY_IN_SECONDS, DAY_IN_SECONDS, DAY_IN_SECONDS, DAY_IN_SECONDS );
	/** Consecutive /summary 404s before a prepaid application is blocked. */
	private const MAX_MISSING = 5;

	public function __construct(
		private readonly Applications $applications,
		private readonly Options $options,
	) {}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copy_ref_to_item' ), 10, 4 );
		// Block checkout reuses a draft order: forget refs whose lines were dropped from it.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'prune_refs' ), 5 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'prune_refs' ), 5 );
		// Classic checkout makes a new order when the cart changed: each ref lives on one order only.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'claim_refs' ), 6 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'claim_refs' ), 6 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'moved_not_payable' ), 10, 2 );
		// Lines rebuilt from a cart (classic reuse, block checkout draft): the order is live again.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'clear_moved' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'clear_moved' ) );

		// Release runs in the background, never inside the checkout or admin request.
		add_action( 'woocommerce_payment_complete', array( $this, 'flag_payment_complete' ), 5 );
		// Pay first: which lines were on the order when it was paid.
		add_action( 'woocommerce_payment_complete', array( $this, 'remember_paid_items' ), 4 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'remember_paid_items' ), 4 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'remember_paid_items' ), 4 );
		add_action( 'woocommerce_payment_complete', array( $this, 'queue_release' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'queue_release' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'queue_release' ) );
		add_action( self::HOOK_RELEASE, array( $this, 'release_order' ) );
		add_action( self::HOOK_RETRY, array( $this, 'retry_release' ) );

		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ORDER_ACTION, array( $this, 'on_retry_action' ) );
		add_action( self::HOOK_RETRY_NOW, array( $this, 'retry_scheduled' ) );

		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_order' ) );
		// Not on failed: WooCommerce customers retry a failed payment from "Pay for order", so the
		// application must survive it. Unreleased applications expire in BAPI if never paid.
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'cancel_order' ) );
		add_action( 'woocommerce_before_trash_order', array( $this, 'cancel_order' ) );
		add_action( 'wp_trash_post', array( $this, 'cancel_trashed_post' ) );
		add_action( self::HOOK_CANCEL, array( $this, 'cancel_scheduled' ) );
		// A partial refund that covers a whole application (its line and its government fee line)
		// cancels that application if it was not released. A partial refund of only part of it
		// (e.g. part of the government fee) cancels nothing, but the release guard and the price
		// check count amounts net of refunds, so an unreleased application is then blocked.
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'cancel_refunded' ) );
		add_action( self::HOOK_CANCEL_REFUNDED, array( $this, 'cancel_refunded' ) );

		add_action( self::HOOK_POLL, array( $this, 'poll' ), 10, 2 );

		// On hold (dispute), failed or back to pending after payment pauses its applications;
		// the release run resumes them.
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'suspend_order' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'suspend_order' ) );
		add_action( 'woocommerce_order_status_pending', array( $this, 'suspend_order' ) );
		add_action( self::HOOK_SUSPEND, array( $this, 'suspend_order' ), 10, 2 );
		// Applications the order no longer pays for are paused, and resumed once it does again.
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'queue_recheck' ), 20 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'queue_recheck' ), 10, 2 );
		add_action( 'woocommerce_saved_order_items', array( $this, 'queue_recheck' ), 10, 2 );
		add_action( self::HOOK_RECHECK, array( $this, 'recheck_holds' ) );

		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_item_meta' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'admin_item_ref' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'no_order_again' ), 1 );
		add_action( 'woocommerce_order_details_after_order_table', array( StatusText::class, 'customer_status' ), 5 );
	}

	/**
	 * Copy the ref to the order item and start tracking the application.
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart key.
	 * @param array                  $values        Cart item.
	 * @param \WC_Order              $order         Order.
	 */
	public function copy_ref_to_item( $item, $cart_item_key, $values, $order ): void {
		$ref = (string) ( $values['bapi_application_ref'] ?? '' );
		if ( ! Applications::is_ref( $ref ) ) {
			return;
		}

		$item->update_meta_data( self::ITEM_REF, $ref );
		$item->update_meta_data( self::ITEM_CARRIER, sanitize_key( (string) ( $values['bapi_carrier'] ?? '' ) ) );
		// Monitoring orders carry a marker, so a customer's monitoring refs are read from those orders only.
		if ( \BapiForms\Monitoring\Renewals::PRODUCT === sanitize_key( (string) ( $values['bapi_product'] ?? '' ) ) ) {
			\BapiForms\Monitoring\Entities::mark( $order );
		}
		// WooCommerce resumed this order for a new checkout: it is the live order again.
		$order->delete_meta_data( self::MOVED_FLAG );

		// The environment stored on the cart line, not today's setting.
		$env = (string) ( $values['bapi_env'] ?? '' );
		$env = in_array( $env, array( Options::ENV_TEST, Options::ENV_LIVE ), true ) ? $env : $this->options->environment();

		$apps = self::apps( $order );
		if ( ! isset( $apps[ $ref ] ) ) {
			if ( ! in_array( $ref, array_map( fn ( $meta ) => (string) ( $meta->value ?? '' ), (array) $order->get_meta( self::ORDER_REF, false, 'edit' ) ), true ) ) {
				$order->add_meta_data( self::ORDER_REF, $ref );
			}
			$apps[ $ref ] = array(
				'product'  => sanitize_key( (string) ( $values['bapi_product'] ?? '' ) ),
				'env'      => $env,
				'state'    => 'pending',
				'status'   => Applications::STATUS_AWAITING_RELEASE,
				'requests' => array(),
				'acn'      => '',
				'abn'      => '',
				'attempts' => 0,
				'polls'    => 0,
				'updated'  => time(),
			);
			$order->update_meta_data( self::META, $apps );
		}
	}

	/**
	 * Block checkout drafts keep the meta of removed lines: drop pending refs no longer on the order.
	 *
	 * @param \WC_Order|mixed $order Order.
	 */
	public function prune_refs( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$keep    = self::item_refs( $order );
		$apps    = self::apps( $order );
		$changed = false;
		foreach ( $apps as $ref => $app ) {
			if ( ! in_array( (string) $ref, $keep, true ) && 'pending' === ( $app['state'] ?? '' ) ) {
				unset( $apps[ $ref ] );
				$changed = true;
			}
		}
		foreach ( $order->get_meta( self::ORDER_REF, false, 'edit' ) as $meta ) {
			$value = (string) ( $meta->value ?? '' );
			if ( ! isset( $apps[ $value ] ) ) {
				$order->delete_meta_data_by_mid( $meta->id );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->update_meta_data( self::META, $apps );
			$order->save();
		}
	}

	/**
	 * Move refs from an older unpaid order to this new one, so cancelling the old order can never
	 * cancel them. The old order is cancelled when everything on it moved, otherwise it can no
	 * longer be paid. A block checkout draft only loses the ref: WooCommerce reuses drafts for
	 * the next cart, so a draft is never flagged.
	 *
	 * @param \WC_Order|mixed $order New order.
	 */
	public function claim_refs( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$new_id = (int) $order->get_id();
		$cancel = array();
		foreach ( array_keys( self::apps( $order ) ) as $ref ) {
			$ref = (string) $ref;
			foreach ( self::orders_with_ref( $ref, array( 'wc-pending', 'wc-failed', 'wc-checkout-draft' ) ) as $old_id ) {
				if ( $old_id === $new_id ) {
					continue;
				}
				// Lock busy: the cancel guard (ref_tracked_elsewhere()) still protects the new order.
				self::with_lock(
					$old_id,
					function () use ( $old_id, $ref, $order, &$cancel ): void {
						if ( $this->move_ref( $old_id, $ref, $order ) ) {
							$cancel[ $old_id ] = true;
						}
					},
					10
				);
			}
		}
		// Outside the lock: the cancel hook takes it again.
		foreach ( array_keys( $cancel ) as $old_id ) {
			$old = wc_get_order( $old_id );
			if ( $old instanceof \WC_Order && ! $old->is_paid() && $old->has_status( array( 'pending', 'failed' ) ) ) {
				$old->update_status(
					'cancelled',
					sprintf(
						/* translators: %s: order number. */
						__( 'BusinessAPI Forms: cancelled because everything on it moved to order #%s (your client checked out again).', 'businessapi-forms' ),
						$order->get_order_number()
					)
				);
			}
		}
	}

	/**
	 * @param int       $old_id    Older unpaid order.
	 * @param string    $ref       Application reference.
	 * @param \WC_Order $new_order Order the ref moves to.
	 * @return bool True when nothing is left on the old order and it should be cancelled.
	 */
	private function move_ref( int $old_id, string $ref, \WC_Order $new_order ): bool {
		$old  = wc_get_order( $old_id );
		$apps = $old instanceof \WC_Order ? self::apps( $old ) : array();
		if ( ! $old instanceof \WC_Order || $old->is_paid() || ! in_array( $apps[ $ref ]['state'] ?? '', array( 'pending', 'blocked' ), true ) ) {
			return false;
		}
		unset( $apps[ $ref ] );
		$old->update_meta_data( self::META, $apps );
		self::forget_ref( $old, $ref );
		if ( $old->has_status( 'checkout-draft' ) ) {
			$old->save();
			return false;
		}
		if ( self::all_lines_moved( $old, $apps ) ) {
			$old->save();
			return true;
		}
		$old->update_meta_data( self::MOVED_FLAG, (int) $new_order->get_id() );
		$old->add_order_note(
			sprintf(
				/* translators: 1: application reference, 2: order number. */
				__( 'BusinessAPI Forms: application %1$s moved to order #%2$s (your client checked out again). This order no longer releases or cancels it and can no longer be paid.', 'businessapi-forms' ),
				$ref,
				$new_order->get_order_number()
			)
		);
		$old->save();
		return false;
	}

	/**
	 * Every product line of the order is an application line it no longer tracks, and it has no
	 * pay-first lines.
	 *
	 * @param array<string, array<string, mixed>> $apps Applications still tracked on the order.
	 */
	private static function all_lines_moved( \WC_Order $order, array $apps ): bool {
		if ( Prepaid::slots( $order ) ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			$ref = (string) $item->get_meta( self::ITEM_REF );
			if ( '' === $ref || isset( $apps[ $ref ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Remove the order's lookup row for a ref. */
	private static function forget_ref( \WC_Order $order, string $ref ): void {
		foreach ( $order->get_meta( self::ORDER_REF, false, 'edit' ) as $meta ) {
			if ( (string) ( $meta->value ?? '' ) === $ref ) {
				$order->delete_meta_data_by_mid( $meta->id );
			}
		}
	}

	/**
	 * An order whose applications moved to a newer order cannot be paid: pending and failed are
	 * not payable statuses for it. Never applied to checkout drafts (they are never flagged).
	 *
	 * @param list<string>    $statuses Statuses that need payment.
	 * @param \WC_Order|mixed $order    Order.
	 */
	public function moved_not_payable( $statuses, $order = null ): array {
		$statuses = (array) $statuses;
		if ( $order instanceof \WC_Order && $order->get_meta( self::MOVED_FLAG ) ) {
			return array_values( array_diff( $statuses, array( 'pending', 'failed' ) ) );
		}
		return $statuses;
	}

	/**
	 * The order's lines were rebuilt from the cart at checkout: whatever moved off it before no
	 * longer applies.
	 *
	 * @param \WC_Order|mixed $order Order.
	 */
	public function clear_moved( $order ): void {
		if ( $order instanceof \WC_Order && $order->get_meta( self::MOVED_FLAG ) ) {
			$order->delete_meta_data( self::MOVED_FLAG );
		}
	}

	/**
	 * The gateway reported the payment: an online order without a transaction id can still release.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function flag_payment_complete( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && ( self::apps( $order ) || Prepaid::slots( $order ) ) && ! $order->get_meta( self::PAID_FLAG ) ) {
			$order->update_meta_data( self::PAID_FLAG, time() );
			$order->save();
		}
	}

	/**
	 * Queue the release, one per order at a time.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function queue_release( $order_id ): void {
		$args = array( (int) $order_id );
		if ( (int) $order_id <= 0 ) {
			return;
		}
		if ( ! \BapiForms\Scheduler::pending( self::HOOK_RELEASE, $args, self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_RELEASE, $args, self::GROUP );
		}
	}

	/** Why an order must not release its applications, or null when it may. */
	public static function release_refusal( \WC_Order $order ): ?string {
		if ( ! $order->is_paid() ) {
			return __( 'the order is not paid', 'businessapi-forms' );
		}
		if ( (float) $order->get_total( 'edit' ) <= 0 ) {
			return __( 'the order total is $0', 'businessapi-forms' );
		}
		// Fees are AUD amounts: an order paid in another currency never releases.
		if ( 'AUD' !== strtoupper( (string) $order->get_currency() ) ) {
			/* translators: %s: currency code. */
			return sprintf( __( 'it was paid in %s; applications can only be paid in Australian dollars (AUD)', 'businessapi-forms' ), (string) $order->get_currency() );
		}
		// Gift cards, store credit, negative fees or refunds must never leave government fees unpaid.
		$government = OrderLines::government_paid( $order );
		$net_total  = (float) $order->get_total( 'edit' ) - (float) $order->get_total_refunded();
		if ( $net_total + Money::EPSILON < $government ) {
			return sprintf(
				/* translators: 1: order total, 2: government fees. */
				__( 'the order total (%1$s) is less than the government fees on it (%2$s), for example because of a gift card, store credit, a negative fee or a refund. Government fees must be paid in full: collect the difference or refund the order', 'businessapi-forms' ),
				Money::plain( $net_total ),
				Money::plain( $government )
			);
		}
		$method = (string) $order->get_payment_method();
		if ( in_array( $method, self::OFFLINE_METHODS, true ) ) {
			$allowed = in_array( $method, self::OVERRIDABLE_METHODS, true ) && Plugin::instance()->options->release_offline();
			/**
			 * Overrides the "Release bank transfer and cheque orders when I mark them paid" setting
			 * for one order. Applies once the order is marked paid (processing / completed). Cash
			 * on delivery is never released, whatever this returns.
			 *
			 * @param bool      $allow  The setting (default off).
			 * @param string    $method Payment method id (cheque or bacs).
			 * @param \WC_Order $order  Order.
			 */
			$allowed = in_array( $method, self::OVERRIDABLE_METHODS, true )
				&& (bool) apply_filters( 'bapi_forms_release_offline_payment', $allowed, $method, $order );
			if ( ! $allowed ) {
				return 'cod' === $method
					? __( 'it was paid by cash on delivery, which is never released', 'businessapi-forms' )
					/* translators: %s: payment method id. */
					: sprintf( __( 'it was paid by %s, an offline payment method. To release bank transfer and cheque orders once you mark them paid, switch this on in BusinessAPI Forms > Products, then click "Retry release" on this order', 'businessapi-forms' ), $method );
			}
		} elseif ( '' === (string) $order->get_transaction_id( 'edit' ) && ! $order->get_meta( self::PAID_FLAG ) ) {
			// WooCommerce sets a paid date on any change to Processing, even by hand, so only a
			// transaction id or the gateway's payment_complete call shows money arrived.
			/**
			 * Allows releasing an online-gateway order that has no transaction id and was not
			 * completed by the gateway (status set to Processing or Completed by hand).
			 *
			 * @param bool      $allow Default false.
			 * @param \WC_Order $order Order.
			 */
			if ( ! (bool) apply_filters( 'bapi_forms_release_without_transaction_id', false, $order ) ) {
				return __( 'no online payment was recorded on it (no transaction id, and the payment gateway did not report the payment; setting the status by hand is not a payment). If you have confirmed the payment, enter its Transaction ID on the order (Billing, Payment method) and save, then click "Retry release" in Order actions', 'businessapi-forms' );
			}
		}
		return null;
	}

	/**
	 * Release every pending application on a paid order. Idempotent.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function release_order( $order_id ): void {
		$done = self::with_lock(
			(int) $order_id,
			function () use ( $order_id ): void {
				$this->release_locked( $order_id );
			}
		);
		if ( ! $done ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_RELEASE, array( (int) $order_id ), self::GROUP );
		}
	}

	/**
	 * @param int|string $order_id Order id.
	 */
	private function release_locked( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$refusal = self::release_refusal( $order );
		if ( null !== $refusal ) {
			if ( ( self::apps( $order ) || Prepaid::has_unissued( $order ) ) && $refusal !== $order->get_meta( self::REFUSED_FLAG ) ) {
				/* translators: %s: reason. */
				$order->add_order_note( sprintf( __( 'BusinessAPI Forms: applications were not released because %s.', 'businessapi-forms' ), $refusal ) );
				$order->update_meta_data( self::REFUSED_FLAG, $refusal );
				$order->save();
			}
			return;
		}

		// The guard passes now: forget an earlier refusal, so a later one is noted again.
		if ( '' !== (string) $order->get_meta( self::REFUSED_FLAG ) ) {
			$order->delete_meta_data( self::REFUSED_FLAG );
			$order->save();
		}

		$this->report_unreleasable( $order );

		// Resume paused applications whose cause is gone first; pausing is left to the status,
		// refund and item edit hooks.
		$holds = $this->sync_holds( $order, null, false );

		$apps    = self::apps( $order );
		$paid    = self::item_refs( $order );
		$changed = false;
		$retry   = null; // Smallest retry delay needed, in seconds.

		foreach ( $apps as $ref => $app ) {
			$ref = (string) $ref;
			// Only refs still on the order's line items (block checkout drafts can drop items).
			if ( 'pending' !== $app['state'] || ! in_array( $ref, $paid, true ) ) {
				continue;
			}
			self::refresh_lock( (int) $order->get_id() );
			$changed = true;

			$delay        = $this->release_one( $order, $ref, $app );
			$retry        = null === $delay ? $retry : min( $retry ?? PHP_INT_MAX, $delay );
			$apps[ $ref ] = $app;
		}

		if ( $changed ) {
			$order->update_meta_data( self::META, $apps );
			$order->save();
		}

		// Pay first: create the prepaid applications of this paid order.
		$this->authorise_slots( $order, $retry );

		if ( $holds['again'] ) {
			$retry = min( $retry ?? PHP_INT_MAX, 5 * MINUTE_IN_SECONDS );
		}
		if ( null !== $retry && ! \BapiForms\Scheduler::pending( self::HOOK_RETRY, array( (int) $order->get_id() ), self::GROUP ) ) {
			as_schedule_single_action( time() + $retry, self::HOOK_RETRY, array( (int) $order->get_id() ), self::GROUP );
		} elseif ( null === $retry && ! self::slot_issuing( $order ) ) {
			// Nothing to retry: drop the watchdog authorise_slots() set.
			as_unschedule_all_actions( self::HOOK_RETRY, array( (int) $order->get_id() ), self::GROUP );
		}
	}

	/**
	 * Price check, then release, of one pending application. Updates $app.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 * @return int|null Seconds until a retry is needed, null for none.
	 */
	private function release_one( \WC_Order $order, string $ref, array &$app ): ?int {
		// Backstop: what was paid must match what the application needs today.
		$summary   = null;
		$overpaid  = 0.0;
		$shortfall = 0.0;
		$data      = null;
		$check     = $this->price_check( $order, $ref, $app, $summary, $overpaid, $shortfall, $data );
		// Pay first, still a draft in Business API: it waits for the client's form again.
		if ( 'pay_first' === ( $app['flow'] ?? '' ) && is_array( $data ) && 'draft' === sanitize_key( (string) ( $data['status'] ?? '' ) ) ) {
			$this->back_to_prepaid( $order, $ref, $app );
			return null;
		}
		if ( $summary instanceof Response ) {
			// BAPI refused to return the application: same handling as a refused release.
			return $this->refused( $order, $ref, $app, $summary, __( 'reading it from Business API', 'businessapi-forms' ) );
		}
		if ( 'retry' === $check ) {
			return $this->failed( $order, $ref, $app, __( 'its price could not be checked with Business API', 'businessapi-forms' ) );
		}
		if ( null !== $check ) {
			$this->block(
				$order,
				$ref,
				$app,
				'price_check',
				sprintf(
				/* translators: 1: application reference, 2: reason. */
					__( 'BusinessAPI Forms: price check failed, application %1$s not released: %2$s. Correct the order or refund it. Once fixed, click "Retry release" in Order actions.', 'businessapi-forms' ),
					$ref,
					$check
				),
				$check
			);
			return null;
		}

		// Pay first: the government fee went up after the client paid. Lodged anyway, partner absorbs it.
		if ( $shortfall > Money::EPSILON && empty( $app['fee_rise_noted'] ) ) {
			$app['fee_rise_noted'] = true;
			$order->add_order_note( self::fee_rise_text( $ref, $shortfall ) );
		}

		// The fee went down between cart and release: release, and tell the partner once.
		if ( $overpaid > Money::EPSILON && empty( $app['overpaid_noted'] ) ) {
			$app['overpaid_noted'] = true;
			$order->add_order_note(
				sprintf(
				/* translators: 1: application reference, 2: amount. */
					__( 'BusinessAPI Forms: application %1$s: the government fee paid is %2$s more than the current fee. Released anyway; consider refunding the difference to your client.', 'businessapi-forms' ),
					$ref,
					Money::plain( $overpaid )
				)
			);
		}

		$response = $this->applications->release( $ref, $app['env'] );
		$status   = self::status_of( $response, '' );

		if ( $response->ok() && 202 !== $response->status && in_array( $status, Applications::LODGED, true ) ) {
			$this->mark_released( $order, $ref, $app, $status );
			/* translators: %s: opaque application reference. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: application %s released for lodgement.', 'businessapi-forms' ), $ref ) );
		} elseif ( $response->ok() && in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
			$this->block_gone( $order, $ref, $app, $status );
		} elseif ( $response->ok() ) {
			// 202 or status "lodging": another worker is lodging it right now. Ask again later.
			if ( 0 === (int) $app['attempts'] ) {
				/* translators: %s: opaque application reference. */
				$order->add_order_note( sprintf( __( 'BusinessAPI Forms: Business API is still lodging application %s. Checking again shortly.', 'businessapi-forms' ), $ref ) );
			}
			return $this->failed( $order, $ref, $app, __( 'Business API was still lodging it', 'businessapi-forms' ), false );
		} else {
			return $this->refused( $order, $ref, $app, $response, __( 'releasing it', 'businessapi-forms' ) );
		}
		return null;
	}

	/** Whether a pay-first line of the order is still being created (state issuing). */
	private static function slot_issuing( \WC_Order $order ): bool {
		foreach ( Prepaid::slots( $order ) as $row ) {
			if ( 'issuing' === ( $row['state'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Handle a failed release call: temporary answers are retried with backoff, anything else
	 * blocks the application with what to fix. Returns the retry delay, or null.
	 *
	 * @param array<string, mixed> $app  Tracked application (updated).
	 * @param string               $what What failed, e.g. "releasing it".
	 */
	private function refused( \WC_Order $order, string $ref, array &$app, Response $response, string $what ): ?int {
		$code = self::safe_code( $response );

		if ( 'lodgement_conflict' === $code && 'ppsr_search' === ( $app['product'] ?? '' ) ) {
			// V2's 5 minute duplicate guard on PPSR searches: it clears by itself.
			if ( 0 === (int) $app['attempts'] ) {
				$order->add_order_note(
					sprintf(
					/* translators: %s: application reference. */
						__( 'BusinessAPI Forms: application %s not released yet. The same PPSR search was made on your Business API account in the last 5 minutes. Retrying automatically.', 'businessapi-forms' ),
						$ref
					)
				);
			}
			return $this->failed( $order, $ref, $app, __( 'the same PPSR search was made on your Business API account in the last 5 minutes (lodgement_conflict)', 'businessapi-forms' ), false );
		}

		if ( $response->is_transient() ) {
			return $this->failed(
				$order,
				$ref,
				$app,
				'lodgement_conflict' === $code
					? __( 'Business API is already lodging something for the same entity (lodgement_conflict)', 'businessapi-forms' )
					: ( Response::NO_KEY === $code
					? __( 'no API key is saved for this environment. Add it on BusinessAPI Forms > Connection; retries continue', 'businessapi-forms' )
					/* translators: 1: what failed, 2: HTTP status (0 = no answer). */
					: sprintf( __( '%1$s failed (HTTP %2$d)', 'businessapi-forms' ), $what, $response->status ) )
			);
		}

		// A 409 without a code: not releasable. Read its status to say why.
		if ( 409 === $response->status && 'HTTP 409' === $code ) {
			$summary = $this->applications->summary( $ref, $app['env'] );
			$status  = $summary->ok() ? self::status_of( $summary, '' ) : '';
			if ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
				$this->block_gone( $order, $ref, $app, $status );
				return null;
			}
		}

		// Block with a safe code only, never BAPI's message, which can quote the application.
		$fix = self::fix_for( $code, $response->status );
		$this->block(
			$order,
			$ref,
			$app,
			$code,
			sprintf(
			/* translators: 1: opaque application reference, 2: error code, 3: what to fix. */
				__( 'BusinessAPI Forms: application %1$s was not released: Business API refused it (%2$s). %3$s', 'businessapi-forms' ),
				$ref,
				$code,
				$fix
			),
			sprintf(
			/* translators: 1: error code, 2: what to fix. */
				__( 'Business API refused the release (%1$s). %2$s', 'businessapi-forms' ),
				$code,
				$fix
			)
		);
		return null;
	}

	/**
	 * Block an application that is cancelled or expired in BAPI.
	 *
	 * @param array<string, mixed> $app Tracked application (updated).
	 */
	private function block_gone( \WC_Order $order, string $ref, array &$app, string $status ): void {
		$this->block(
			$order,
			$ref,
			$app,
			$status,
			sprintf(
			/* translators: 1: opaque application reference, 2: status. */
				__( 'BusinessAPI Forms: application %1$s was not released: it is %2$s in Business API, so it cannot be lodged. Refund the order, or ask your client to fill in the form again.', 'businessapi-forms' ),
				$ref,
				$status
			),
			sprintf(
			/* translators: %s: status. */
				__( 'the application is %s in Business API', 'businessapi-forms' ),
				$status
			)
		);
	}

	/**
	 * A paid order with applications it can no longer release (cancelled or moved): one note and
	 * one admin email per set of refs.
	 */
	private function report_unreleasable( \WC_Order $order ): void {
		$apps = self::apps( $order );
		$gone = array();
		foreach ( self::item_refs( $order ) as $ref ) {
			// Cancelled because its lines were refunded: nothing to report.
			if ( ! isset( $apps[ $ref ] ) || ( 'cancelled' === ( $apps[ $ref ]['state'] ?? '' ) && empty( $apps[ $ref ]['refunded'] ) ) ) {
				$gone[] = $ref;
			}
		}
		sort( $gone );
		$key = implode( ',', $gone );
		if ( array() === $gone || $key === (string) $order->get_meta( self::UNRELEASABLE_FLAG ) ) {
			return;
		}
		foreach ( $gone as $ref ) {
			$moved = ! isset( $apps[ $ref ] );
			$why   = $moved
				? __( 'it was moved to a newer order when your client checked out again, so this order cannot release it', 'businessapi-forms' )
				: __( 'the application was cancelled before the payment arrived', 'businessapi-forms' );
			$order->add_order_note(
				sprintf(
					/* translators: 1: application reference, 2: reason. */
					__( 'BusinessAPI Forms: this order was paid, but application %1$s will not be released: %2$s. Refund the order, or ask your client to fill in the form again.', 'businessapi-forms' ),
					$ref,
					$why
				)
			);
			$this->alert_admin( $order, $ref, $why );
		}
		$order->update_meta_data( self::UNRELEASABLE_FLAG, $key );
		$order->save();
	}

	/**
	 * A release that may work later: the delay before the next try, or null once retries are used
	 * up (then blocked and the admin emailed).
	 *
	 * @param array<string, mixed> $app  Tracked application (updated).
	 * @param bool                 $note Add the "retrying" note on the first failure.
	 */
	private function failed( \WC_Order $order, string $ref, array &$app, string $why, bool $note = true ): ?int {
		$app['attempts'] = (int) $app['attempts'] + 1;
		$app['updated']  = time();
		$delay           = self::RETRY_DELAYS[ $app['attempts'] - 1 ] ?? null;

		if ( null === $delay ) {
			$this->block(
				$order,
				$ref,
				$app,
				'retries_exhausted',
				sprintf(
				/* translators: 1: application reference, 2: reason. */
					__( 'BusinessAPI Forms: gave up releasing application %1$s after about 7 days of retries (last try: %2$s). Check BusinessAPI Forms > Connection (API key, website and server IP registered) and the Business API status, then click "Retry release" in Order actions.', 'businessapi-forms' ),
					$ref,
					$why
				),
				sprintf(
				/* translators: %s: reason. */
					__( 'it could not be released after about 7 days of retries (last try: %s)', 'businessapi-forms' ),
					$why
				)
			);
			return null;
		}

		if ( $note && 1 === $app['attempts'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: application reference, 2: reason. */
					__( 'BusinessAPI Forms: application %1$s not released yet: %2$s. Retrying automatically for up to about 7 days.', 'businessapi-forms' ),
					$ref,
					$why
				)
			);
		}
		return $delay;
	}

	/**
	 * Block an application until the partner fixes the cause and clicks "Retry release".
	 *
	 * @param array<string, mixed> $app Tracked application (updated).
	 */
	private function block( \WC_Order $order, string $ref, array &$app, string $code, string $note, string $reason ): void {
		$app['state']   = 'blocked';
		$app['reason']  = sanitize_key( $code );
		$app['updated'] = time();
		$order->add_order_note( $note );
		$this->alert_admin( $order, $ref, $reason );
	}

	/**
	 * @param array<string, mixed> $app Tracked application (updated).
	 */
	private function mark_released( \WC_Order $order, string $ref, array &$app, string $status ): void {
		$app['state'] = 'released';
		// Always recorded as lodged: one that already finished (sandbox, fast PPSR) is picked up
		// by the first poll, so notes and customer emails take the normal path.
		$app['status']   = 'lodged';
		$app['attempts'] = 0;
		$app['updated']  = time();
		// Released (lodging or lodged): any pause is over, so a later "fix and resubmit" can start.
		unset( $app['reason'], $app['held'], $app['suspended'], $app['hold_attempts'] );
		if ( in_array( $status, array( 'completed', 'action_needed' ), true ) ) {
			$args = array( (int) $order->get_id(), $ref );
			if ( ! \BapiForms\Scheduler::pending( self::HOOK_POLL, $args, self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK_POLL, $args, self::GROUP );
				return;
			}
		}
		$this->schedule_poll( (int) $order->get_id(), $ref, 0 );
	}

	/** What the partner must fix for a refused release, ending with the retry step. */
	public static function fix_for( string $code, int $status ): string {
		$retry = __( 'Then click "Retry release" in Order actions on this order.', 'businessapi-forms' );
		$fix   = match ( $code ) {
			Response::NO_KEY                => __( 'No API key is saved for the environment this application was made in (sandbox or live). Add it on BusinessAPI Forms > Connection.', 'businessapi-forms' ),
			'ip_not_allowed'                => __( 'Business API does not accept this server\'s IP address. Go to BusinessAPI Forms > Connection, click "Register this site" and have your account owner confirm the email.', 'businessapi-forms' ),
			'wordpress_onboarding_required' => __( 'Your "Forms on my WordPress site" onboarding is not approved for live yet. Finish it on the Whitelabel card of your Business API dashboard and wait for approval.', 'businessapi-forms' ),
			'product_not_live'              => __( 'Your Business API account cannot lodge this product live (card billing, card on file, ASIC or ATO onboarding). BusinessAPI Forms > Products says what is missing for each product; fix it in your Business API dashboard.', 'businessapi-forms' ),
			'account_screening'             => __( 'Business API\'s account checks stopped it: for example unpaid requests, government onboarding not finished, or a locked account. Check your Business API dashboard or contact Business API support.', 'businessapi-forms' ),
			'payment_method_required', 'payment_required'
				=> __( 'Your Business API account needs a valid card. Add one in your Business API dashboard (Billing).', 'businessapi-forms' ),
			'payment_method_expired'        => __( 'The card on your Business API account has expired. Update it in your Business API dashboard (Billing).', 'businessapi-forms' ),
			'sandbox_only'                  => __( 'Your Business API account is a sandbox account and cannot lodge live. Upgrade it in your Business API dashboard.', 'businessapi-forms' ),
			'lodgement_forbidden'           => __( 'Business API refused this account for lodgement (for example the account is locked or has no usable API key). Check your Business API dashboard or contact Business API support.', 'businessapi-forms' ),
			'application_on_hold'           => __( 'The application is paused because the order does not cover it any more (see the "paused" note on this order). Correct the order, or collect the difference; it resumes by itself.', 'businessapi-forms' ),
			'prepaid_cap_reached'           => __( 'Your Business API account has too many prepaid applications waiting for a client\'s details. Cancel or refund unused ones, or ask Business API support to raise the limit.', 'businessapi-forms' ),
			'lodgement_refused'             => __( 'Business API refused the lodgement. Check the request in your Business API dashboard or ask Business API support, quoting the reference.', 'businessapi-forms' ),
			default                         => match ( true ) {
				400 === $status => __( 'Business API says the application is incomplete or invalid. Check it in your Business API dashboard or ask Business API support, quoting the reference.', 'businessapi-forms' ),
				401 === $status => __( 'Business API did not accept the API key. Check the key on BusinessAPI Forms > Connection.', 'businessapi-forms' ),
				402 === $status => __( 'Business API needs a valid card on your account. Check Billing in your Business API dashboard.', 'businessapi-forms' ),
				403 === $status => __( 'Business API refused this account or key. Check the API key on BusinessAPI Forms > Connection and that your account is not locked.', 'businessapi-forms' ),
				404 === $status => __( 'Business API cannot find this application with the current API key. Check that the key on BusinessAPI Forms > Connection belongs to the account and environment (sandbox or live) the application was made with.', 'businessapi-forms' ),
				409 === $status => __( 'Business API says the application cannot be released in its current state. Check its status in your Business API dashboard or ask Business API support, quoting the reference.', 'businessapi-forms' ),
				default         => __( 'Check the application with Business API support, quoting the reference.', 'businessapi-forms' ),
			},
		};
		return $fix . ' ' . $retry;
	}

	/**
	 * Release-time price check: add-ons and government fee worked out again from the BAPI summary
	 * and a fresh fee schedule, compared with the order lines net of refunds.
	 *
	 * @param array<string, mixed> $app     Tracked application.
	 * @param Response|null        $refused Set to BAPI's answer when it did not return the summary
	 *                                      (handled like a refused release, see refused()).
	 * @param float                $overpaid Set to the government fee paid above the current fee
	 *                                       when the check passes (0 otherwise).
	 * @return string|null Reason for blocking, 'retry' when the fees could not be read, null when
	 *                     fine (or when $refused is set).
	 */
	private function price_check( \WC_Order $order, string $ref, array $app, ?Response &$refused = null, float &$overpaid = 0.0, float &$shortfall = 0.0, ?array &$data = null ): ?string {
		$product  = (string) ( $app['product'] ?? '' );
		$response = $this->applications->summary( $ref, $app['env'] );
		if ( ! $response->ok() ) {
			$refused = $response;
			return null;
		}
		$data   = is_array( $response->data ) ? $response->data : array();
		$inputs = Plugin::instance()->expected->inputs( $response->data, $product );
		if ( is_wp_error( $inputs ) ) {
			return $inputs->get_error_message(); // An add-on this site cannot price: never released unpaid.
		}

		$expected = Plugin::instance()->expected->expected( $product, $inputs['addons'], $inputs['pricing'], true, (string) $app['env'] );
		if ( is_wp_error( $expected ) ) {
			return 503 === (int) ( $expected->get_error_data()['status'] ?? 0 ) ? 'retry' : $expected->get_error_message();
		}

		// Pay first: a fee rise after payment is lodged anyway; the caller notes the difference.
		if ( 'pay_first' === ( $app['flow'] ?? '' ) && null !== $expected['government'] ) {
			$paid = OrderLines::government_paid( $order, $ref );
			// Only a rise of the fee itself: a refund of part of the fee paid is never absorbed.
			$refunded = OrderLines::government_charged( $order, $ref ) - $paid > Money::EPSILON;
			if ( ! $refunded && $paid > Money::EPSILON && $paid + Money::EPSILON < (float) $expected['government']['amount'] ) {
				$shortfall                        = round( (float) $expected['government']['amount'] - $paid, 2 );
				$expected['government']['amount'] = $paid;
			}
		}

		// One line + its government fee line (or, for orders placed before, one line per carrier).
		$check = OrderLines::check( $order, $ref, $expected );
		if ( null === $check && null !== $expected['government'] ) {
			$overpaid = max( 0.0, round( OrderLines::government_paid( $order, $ref ) - (float) $expected['government']['amount'], 2 ) );
		}
		return $check;
	}

	/** BAPI's machine code when it sent one, else "HTTP 4xx". */
	private static function safe_code( Response $response ): string {
		$code = $response->code();
		return '' !== $code ? $code : 'HTTP ' . $response->status;
	}

	/** Email the site admin about an order that was not released. */
	private function alert_admin( \WC_Order $order, string $ref, string $reason ): void {
		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: order number. */
			sprintf( __( 'BusinessAPI Forms: order #%s was not released', 'businessapi-forms' ), $order->get_order_number() ),
			sprintf(
				/* translators: 1: order number, 2: application reference, 3: reason, 4: order admin URL. */
				__( "Order #%1\$s was paid, but application %2\$s was not released for lodgement because %3\$s.\n\nOpen the order, fix the cause (the order notes say what to do), then choose \"Retry release\" in Order actions. If it cannot be fixed, refund the order.\n%4\$s", 'businessapi-forms' ),
				$order->get_order_number(),
				$ref,
				rtrim( $reason, '.' ),
				$order->get_edit_order_url()
			)
		);
	}

	/**
	 * @param int|string $order_id Order id.
	 */
	public function retry_release( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->release_order( $order_id ); // Guarded inside.
		}
	}

	/**
	 * "Retry release" order action, for paid orders with a blocked or unreleased application.
	 *
	 * @param array<string, string> $actions Actions.
	 * @param \WC_Order|null        $order   Order (WC 8.6+).
	 */
	public function order_actions( $actions, $order = null ): array {
		$actions = (array) $actions;
		if ( ! $order instanceof \WC_Order ) {
			global $theorder;
			$order = $theorder instanceof \WC_Order ? $theorder : null;
		}
		if ( $order && current_user_can( 'edit_shop_orders' ) && $order->is_paid() && self::retryable( $order ) ) {
			$actions[ self::ORDER_ACTION ] = __( 'Retry release (BusinessAPI Forms)', 'businessapi-forms' );
		}
		return $actions;
	}

	/** Whether an order has an application on its lines that is blocked or not released yet. */
	public static function retryable( \WC_Order $order ): bool {
		$refs = self::item_refs( $order );
		foreach ( self::apps( $order ) as $ref => $app ) {
			if ( in_array( (string) $ref, $refs, true ) && in_array( $app['state'] ?? '', array( 'pending', 'blocked' ), true ) ) {
				return true;
			}
		}
		// Pay-first lines whose application is not created yet.
		return Prepaid::has_unissued( $order );
	}

	/**
	 * WooCommerce checks nonce and capability when saving the order; checked again here.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function on_retry_action( $order ): void {
		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'edit_shop_orders' ) || ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
			return;
		}
		$this->retry_now( $order );
	}

	/**
	 * Unblock blocked or waiting applications and queue the release again (queued instead when
	 * another task holds the order).
	 */
	public function retry_now( \WC_Order $order, bool $queue_if_busy = true ): bool {
		$order_id = (int) $order->get_id();
		$done     = self::with_lock(
			$order_id,
			function () use ( $order_id ): void {
				// Fresh copy: the one passed in may predate the task that held the lock.
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$refs = self::item_refs( $order );
				$apps = self::apps( $order );
				foreach ( $apps as $ref => $app ) {
					if ( in_array( (string) $ref, $refs, true ) && in_array( $app['state'] ?? '', array( 'pending', 'blocked' ), true ) ) {
						$app['state']    = 'pending';
						$app['attempts'] = 0;
						$app['updated']  = time();
						unset( $app['reason'] );
						$apps[ $ref ] = $app;
					}
				}
				$order->update_meta_data( self::META, $apps );
				// Pay first: same Idempotency-Key, so an existing application is returned, never a second.
				$slots = Prepaid::slots( $order );
				foreach ( $slots as $slot => $row ) {
					if ( in_array( $row['state'] ?? '', array( 'blocked', 'issuing' ), true ) && '' === (string) ( $row['ref'] ?? '' ) ) {
						$row['state']    = 'awaiting_payment';
						$row['attempts'] = 0;
						$row['updated']  = time();
						unset( $row['reason'] );
						$slots[ $slot ] = $row;
					}
				}
				if ( $slots ) {
					$order->update_meta_data( Prepaid::META, $slots );
				}
				$order->delete_meta_data( self::REFUSED_FLAG );
				$user = wp_get_current_user();
				$order->add_order_note(
					$user->exists()
						/* translators: %s: user display name. */
						? sprintf( __( 'BusinessAPI Forms: retry release requested by %s.', 'businessapi-forms' ), $user->display_name )
						: __( 'BusinessAPI Forms: retry release requested.', 'businessapi-forms' )
				);
				$order->save();
			},
			10
		);
		if ( $done ) {
			as_unschedule_all_actions( self::HOOK_RETRY, array( $order_id ), self::GROUP );
			$this->queue_release( $order_id );
		} elseif ( $queue_if_busy ) {
			if ( ! \BapiForms\Scheduler::pending( self::HOOK_RETRY_NOW, array( $order_id ), self::GROUP ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_RETRY_NOW, array( $order_id ), self::GROUP );
			}
			$user = wp_get_current_user();
			$order->add_order_note(
				$user->exists()
					/* translators: %s: user display name. */
					? sprintf( __( 'BusinessAPI Forms: retry queued by %s. Another BusinessAPI Forms task is running on this order; the retry runs within a few minutes.', 'businessapi-forms' ), $user->display_name )
					: __( 'BusinessAPI Forms: retry queued. Another BusinessAPI Forms task is running on this order; the retry runs within a few minutes.', 'businessapi-forms' )
			);
		}
		return $done;
	}

	/**
	 * Queued "Retry release": try again, and once more a minute later while the order stays busy.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function retry_scheduled( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! self::retryable( $order ) ) {
			return;
		}
		if ( ! $this->retry_now( $order, false ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_RETRY_NOW, array( (int) $order_id ), self::GROUP );
		}
	}

	/**
	 * Cancel applications not released yet. Released ones are with ASIC/ATO already.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function cancel_order( $order_id ): void {
		// Waits for a running release; if it still cannot get the lock, tries again shortly.
		$done = self::with_lock(
			(int) $order_id,
			function () use ( $order_id ): void {
				$this->cancel_locked( $order_id );
			},
			10
		);
		if ( ! $done ) {
			$this->schedule_cancel( (int) $order_id, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Scheduled cancel, only while the order is still not paid, so a re-opened order is never cancelled.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function cancel_scheduled( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			return;
		}
		$this->cancel_order( $order_id );
	}

	private function schedule_cancel( int $order_id, int $delay ): void {
		if ( $order_id > 0 && ! \BapiForms\Scheduler::pending( self::HOOK_CANCEL, array( $order_id ), self::GROUP ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK_CANCEL, array( $order_id ), self::GROUP );
		}
	}

	/**
	 * Partial refund: cancel unreleased applications whose lines were refunded in full.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function cancel_refunded( $order_id ): void {
		$done = self::with_lock(
			(int) $order_id,
			function () use ( $order_id ): void {
				$this->cancel_locked( $order_id, true );
			},
			10
		);
		if ( ! $done && ! \BapiForms\Scheduler::pending( self::HOOK_CANCEL_REFUNDED, array( (int) $order_id ), self::GROUP ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_CANCEL_REFUNDED, array( (int) $order_id ), self::GROUP );
		}
	}

	/**
	 * Legacy (posts) order storage: trashing an order.
	 *
	 * @param int $post_id Post id.
	 */
	public function cancel_trashed_post( $post_id ): void {
		if ( 'shop_order' === get_post_type( (int) $post_id ) ) {
			$this->cancel_order( (int) $post_id );
		}
	}

	/**
	 * @param int|string $order_id      Order id.
	 * @param bool       $refunded_only Only the refs a partial refund covered in full.
	 */
	private function cancel_locked( $order_id, bool $refunded_only = false ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$apps = self::apps( $order );
		// Only refs on the order's lines (and, after a partial refund, only the refunded ones).
		$refs    = $refunded_only ? OrderLines::refunded_refs( $order ) : self::item_refs( $order );
		$changed = false;
		$again   = null;

		foreach ( $apps as $ref => $app ) {
			$ref = (string) $ref;
			if ( ! in_array( $ref, $refs, true ) ) {
				continue;
			}
			if ( in_array( $app['state'], array( 'pending', 'blocked', 'prepaid' ), true ) ) {
				// Another live order owns this ref now: never cancel it from here.
				$owner = self::ref_tracked_elsewhere( $ref, (int) $order->get_id() );
				if ( $owner > 0 ) {
					unset( $apps[ $ref ] );
					$this->disown( $order, $ref, $owner );
					$changed = true;
					continue;
				}
				self::refresh_lock( (int) $order->get_id() );
				$delay        = $this->cancel_one( $order, $ref, $app, $refunded_only );
				$again        = null === $delay ? $again : min( $again ?? PHP_INT_MAX, $delay );
				$apps[ $ref ] = $app;
				$changed      = true;
			} elseif ( 'released' === $app['state'] && empty( $app['closed'] ) ) {
				// Refunded or cancelled after release: cannot be pulled back, but no longer amendable.
				self::refresh_lock( (int) $order->get_id() );
				$this->close_released( $order, $ref, $app );
				$apps[ $ref ] = $app;
				$changed      = true;
			}
		}

		if ( $changed ) {
			$order->update_meta_data( self::META, $apps );
			$order->save();
		}
		// Pay first: lines whose application was never created are simply cancelled here.
		$this->cancel_unissued( $order, $refunded_only );
		if ( null !== $again && $refunded_only ) {
			if ( ! \BapiForms\Scheduler::pending( self::HOOK_CANCEL_REFUNDED, array( (int) $order->get_id() ), self::GROUP ) ) {
				as_schedule_single_action( time() + $again, self::HOOK_CANCEL_REFUNDED, array( (int) $order->get_id() ), self::GROUP );
			}
		} elseif ( null !== $again ) {
			$this->schedule_cancel( (int) $order->get_id(), $again );
		}
	}

	/** Another live order holds $ref now: only this order's lookup row goes, never the application. */
	private function disown( \WC_Order $order, string $ref, int $owner ): void {
		foreach ( $order->get_meta( self::ORDER_REF, false, 'edit' ) as $meta ) {
			if ( (string) ( $meta->value ?? '' ) === $ref ) {
				$order->delete_meta_data_by_mid( $meta->id );
			}
		}
		$owner_order = wc_get_order( $owner );
		$order->add_order_note(
			sprintf(
				/* translators: 1: application reference, 2: order number. */
				__( 'BusinessAPI Forms: application %1$s was not cancelled: order #%2$s holds it now. Only this order\'s link to it was removed.', 'businessapi-forms' ),
				$ref,
				$owner_order instanceof \WC_Order ? $owner_order->get_order_number() : (string) $owner
			)
		);
	}

	/**
	 * Cancel one unreleased application in Business API. Updates $app.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 * @return int|null Seconds until it is tried or checked again, null for none.
	 */
	private function cancel_one( \WC_Order $order, string $ref, array &$app, bool $refunded_only ): ?int {
		$again = null;
		if ( $refunded_only ) {
			$app['refunded'] = true; // Tells it apart from "cancelled before payment".
		}
		$response = $this->applications->cancel( $ref, $app['env'] );
		if ( $response->ok() ) {
			$app['state']  = 'cancelled';
			$app['status'] = self::status_of( $response, 'cancelled' );
			/* translators: %s: opaque application reference. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: application %s cancelled.', 'businessapi-forms' ), $ref ) );
			$this->slot_state( $order, (string) ( $app['slot'] ?? '' ), 'cancelled' );
		} elseif ( 409 === $response->status ) {
			$again = $this->cancel_refused( $order, $ref, $app );
			if ( in_array( $app['state'], array( 'pending', 'blocked', 'prepaid' ), true ) && null === $again ) {
				$this->hold_uncancelled( $order, $ref, $app );
			}
		} elseif ( $response->is_transient() ) {
			// It could still be lodged while the cancel is retried: pause it first.
			$this->hold_uncancelled( $order, $ref, $app );
			$app['cancel_attempts'] = (int) ( $app['cancel_attempts'] ?? 0 ) + 1;
			$delays                 = self::never_expires( $app ) ? self::PREPAID_CANCEL_DELAYS : self::CANCEL_DELAYS;
			$delay                  = $delays[ $app['cancel_attempts'] - 1 ] ?? null;
			$again                  = $delay;
			if ( 1 === $app['cancel_attempts'] || null === $delay ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: application reference, 2: HTTP status, 3: what happens next. */
						__( 'BusinessAPI Forms: cancelling application %1$s failed (HTTP %2$d). %3$s', 'businessapi-forms' ),
						$ref,
						$response->status,
						null !== $delay ? __( 'Retrying automatically.', 'businessapi-forms' ) : __( 'Gave up.', 'businessapi-forms' ) . ' ' . self::cancel_fallback( $app )
					)
				);
			}
			if ( null === $delay ) {
				$this->alert_cancel_failed( $order, $ref, $app );
			}
		} else {
			$this->hold_uncancelled( $order, $ref, $app );
			/* translators: 1: application reference, 2: HTTP status, 3: what happens to it. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: cancelling application %1$s failed (HTTP %2$d). %3$s', 'businessapi-forms' ), $ref, $response->status, self::cancel_fallback( $app ) ) );
			$this->alert_cancel_failed( $order, $ref, $app );
		}
		$app['updated'] = time();
		return $again;
	}

	/**
	 * Released already: it cannot be pulled back, but Business API stops offering amendments.
	 *
	 * @param array<string, mixed> $app Tracked application (updated).
	 */
	private function close_released( \WC_Order $order, string $ref, array &$app ): void {
		$closed           = $this->applications->close( $ref, $app['env'] );
		$app['closed']    = true;
		$app['amendable'] = false;
		$app['updated']   = time();
		$order->add_order_note(
			$closed->ok()
				/* translators: %s: opaque application reference. */
				? sprintf( __( 'BusinessAPI Forms: application %s was already released for lodgement and was not cancelled. It can no longer be amended.', 'businessapi-forms' ), $ref )
				/* translators: 1: opaque application reference, 2: HTTP status. */
				: sprintf( __( 'BusinessAPI Forms: application %1$s was already released for lodgement and was not cancelled. Your client can no longer amend it here; Business API could not be told (HTTP %2$d).', 'businessapi-forms' ), $ref, $closed->status )
		);
	}

	/**
	 * BAPI refused a cancel (409), usually because a release that timed out here went through.
	 * Lodged means released and polled; still lodging is checked again later.
	 *
	 * @param array<string, mixed> $app Tracked application (updated).
	 * @return int|null Delay before checking again (still lodging), or null.
	 */
	private function cancel_refused( \WC_Order $order, string $ref, array &$app ): ?int {
		$summary = $this->applications->summary( $ref, $app['env'] );
		$status  = $summary->ok() ? self::status_of( $summary, '' ) : '';

		if ( in_array( $status, Applications::LODGED, true ) ) {
			$this->mark_released( $order, $ref, $app, $status );
			$this->slot_state( $order, (string) ( $app['slot'] ?? '' ), 'released' );
			unset( $app['lodging_checks'], $app['held'], $app['suspended'] );
			$order->add_order_note(
				sprintf(
					/* translators: 1: opaque application reference, 2: status. */
					__( 'BusinessAPI Forms: application %1$s could not be cancelled: Business API had already lodged it (status "%2$s"; an earlier release went through after a timeout). It is now tracked as released. Government fees may already be payable: check before refunding.', 'businessapi-forms' ),
					$ref,
					str_replace( '_', ' ', $status )
				)
			);
			return null;
		}
		if ( Applications::STATUS_LODGING === $status ) {
			$app['lodging_checks'] = (int) ( $app['lodging_checks'] ?? 0 ) + 1;
			$delay                 = self::BACKOFF[ $app['lodging_checks'] - 1 ] ?? null;
			if ( 1 === $app['lodging_checks'] ) {
				/* translators: %s: opaque application reference. */
				$order->add_order_note( sprintf( __( 'BusinessAPI Forms: application %s could not be cancelled yet: Business API is lodging it right now (an earlier release went through after a timeout). Checking again shortly: it is cancelled if the lodgement does not go ahead, otherwise tracked as released. Government fees may become payable: check before refunding.', 'businessapi-forms' ), $ref ) );
			} elseif ( null === $delay ) {
				/* translators: %s: opaque application reference. */
				$order->add_order_note( sprintf( __( 'BusinessAPI Forms: application %s is still being lodged in Business API after about a day; stopped checking. Check it in your Business API dashboard or ask Business API support, quoting the reference.', 'businessapi-forms' ), $ref ) );
			}
			return $delay;
		}
		if ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
			$app['state']  = 'cancelled';
			$app['status'] = $status;
			$this->slot_state( $order, (string) ( $app['slot'] ?? '' ), 'cancelled' );
			/* translators: 1: opaque application reference, 2: status. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: application %1$s is already %2$s in Business API.', 'businessapi-forms' ), $ref, $status ) );
			return null;
		}
		/* translators: 1: opaque application reference, 2: what happens to it. */
		$order->add_order_note( sprintf( __( 'BusinessAPI Forms: Business API refused to cancel application %1$s (HTTP 409). %2$s', 'businessapi-forms' ), $ref, self::cancel_fallback( $app ) ) );
		return null;
	}

	/**
	 * Never expires in Business API: pay first (prepaid), or a release was already attempted.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	private static function never_expires( array $app ): bool {
		return 'pay_first' === ( $app['flow'] ?? '' ) || 'prepaid' === ( $app['state'] ?? '' ) || (int) ( $app['attempts'] ?? 0 ) > 0 || 'blocked' === ( $app['state'] ?? '' );
	}

	/**
	 * A cancel failed or is being retried: pause the application so it cannot be lodged meanwhile.
	 *
	 * @param array<string, mixed> $app Tracked application (updated).
	 */
	private function hold_uncancelled( \WC_Order $order, string $ref, array &$app ): void {
		if ( ! empty( $app['held'] ) || 'cancelled' === ( $app['state'] ?? '' ) ) {
			return;
		}
		$response = $this->applications->hold( $ref, true, (string) $app['env'] );
		if ( $response->ok() ) {
			$app['held'] = true;
			/* translators: %s: application reference. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: application %s paused while it cannot be cancelled: Business API will not lodge it, even if your client finishes the form.', 'businessapi-forms' ), $ref ) );
		}
	}

	/**
	 * What becomes of an application that could not be cancelled from here.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	/**
	 * Email the admin when a cancel was given up on an application that never expires in BAPI.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	private function alert_cancel_failed( \WC_Order $order, string $ref, array $app ): void {
		if ( ! self::never_expires( $app ) && empty( $app['held'] ) ) {
			return;
		}
		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: order number. */
			sprintf( __( 'BusinessAPI Forms: an application on order #%s could not be cancelled', 'businessapi-forms' ), $order->get_order_number() ),
			sprintf(
				/* translators: 1: application reference, 2: order number, 3: what to do, 4: order admin URL. */
				__( "Order #%2\$s was refunded or cancelled, but application %1\$s could not be cancelled in Business API.\n\n%3\$s\n%4\$s", 'businessapi-forms' ),
				$ref,
				$order->get_order_number(),
				self::cancel_fallback( $app ),
				$order->get_edit_order_url()
			)
		);
	}

	private static function cancel_fallback( array $app = array() ): string {
		if ( ! empty( $app['held'] ) ) {
			return __( 'It is paused in Business API, so it will not be lodged even if your client finishes the form, but it stays open there: ask Business API support to cancel it, quoting the reference.', 'businessapi-forms' );
		}
		if ( self::never_expires( $app ) ) {
			return __( 'It does not expire in Business API (paid, or a release was already attempted) and may still be lodged: ask Business API support to cancel it, quoting the reference.', 'businessapi-forms' );
		}
		return __( 'Unreleased applications expire in Business API, but one whose release was already attempted stays open: if so, ask Business API support to cancel it, quoting the reference.', 'businessapi-forms' );
	}

	/**
	 * Read status from BAPI, record outcomes, reschedule.
	 *
	 * @param int|string $order_id Order id.
	 * @param string     $ref      Application reference.
	 */
	public function poll( $order_id, $ref ): void {
		$done = self::with_lock(
			(int) $order_id,
			function () use ( $order_id, $ref ): void {
				$this->poll_locked( $order_id, (string) $ref );
			}
		);
		if ( ! $done ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_POLL, array( (int) $order_id, (string) $ref ), self::GROUP );
		}
	}

	/**
	 * @param int|string $order_id Order id.
	 */
	private function poll_locked( $order_id, string $ref ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$apps = self::apps( $order );
		$app  = $apps[ $ref ] ?? null;
		if ( is_array( $app ) && 'prepaid' === ( $app['state'] ?? '' ) ) {
			$this->poll_prepaid( $order, $ref, $app );
			return;
		}
		if ( null === $app || 'released' !== $app['state'] ) {
			return;
		}

		$response = $this->applications->status( $ref, $app['env'] );
		++$app['polls'];

		if ( $response->ok() ) {
			$previous          = $app['status'];
			$previous_progress = (string) ( $app['progress'] ?? '' );
			$previous_asic     = (string) ( $app['asic'] ?? '' );
			$app['status']     = self::status_of( $response, $previous );
			$app               = self::read_status( $app, $response->data );
			// Business names: ASIC fee state (due / lapsed), document number, admin emails.
			$app = AsicPayment::on_poll( $order, $ref, $app, $response->data );

			/**
			 * Lets a product add references from the status answer to the tracked application
			 * (e.g. entity monitoring's entity id). References and outcomes only, never form data.
			 *
			 * @param array     $app    Tracked application.
			 * @param \WC_Order $order  Order (saved after this filter).
			 * @param string    $ref    Application reference.
			 * @param array     $status BAPI GET /applications/{ref}/status answer (not stored).
			 */
			$app = (array) apply_filters( 'bapi_forms_polled_application', $app, $order, $ref, $response->data );

			// A note on every change of status, progress or ASIC fee state, never on an unchanged poll.
			if ( $previous !== $app['status'] || $previous_progress !== $app['progress'] || (string) ( $app['asic'] ?? '' ) !== $previous_asic ) {
				$order->add_order_note( StatusText::status_note( $ref, $app, $previous !== $app['status'] ) );

				/**
				 * The tracked application changed (customer emails: Emails\Emails). Runs under the
				 * order lock; the order is saved right after, so listeners may set order meta.
				 *
				 * @param \WC_Order $order    Order.
				 * @param string    $ref      Application reference.
				 * @param array     $app      Tracked application after the change.
				 * @param string    $previous Previous status.
				 */
				do_action( 'bapi_forms_application_status_changed', $order, $ref, $app, $previous );
			}
		}

		$app['updated'] = time();
		$apps[ $ref ]   = $app;
		$order->update_meta_data( self::META, $apps );
		$order->save();

		if ( in_array( $app['status'], Applications::TERMINAL, true ) ) {
			return;
		}

		// While the ASIC fee is due, check every 6 hours at most and never give up.
		$cap = 'awaiting_asic_payment' === ( $app['progress'] ?? '' ) ? 6 * HOUR_IN_SECONDS : 0;

		if ( 0 === $cap && $app['polls'] >= self::MAX_POLLS ) {
			/* translators: %s: opaque application reference. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: stopped checking application %s automatically. Ask Business API support about it, quoting the reference.', 'businessapi-forms' ), $ref ) );
			return;
		}

		$this->schedule_poll( (int) $order->get_id(), $ref, $app['polls'], $cap );
	}

	/**
	 * Progress, request ids, ACN/ABN, agency and correction count from a status answer.
	 * References and outcomes only: never the agency's messages.
	 *
	 * @param array<string, mixed> $app  Tracked application.
	 * @param array<string, mixed> $data BAPI GET /applications/{ref}/status answer.
	 * @return array<string, mixed>
	 */
	private static function read_status( array $app, array $data ): array {
		// Optional (older BAPI versions do not send it): one of PROGRESS, or nothing.
		$progress        = $data['progress'] ?? null;
		$app['progress'] = is_string( $progress ) && in_array( $progress, self::PROGRESS, true ) ? $progress : '';

		// The first unfinished request (e.g. the ABN after the company), else the last one.
		$current = null;
		$last    = null;
		foreach ( (array) ( $data['requests'] ?? array() ) as $request ) {
			if ( ! is_array( $request ) ) {
				continue;
			}
			$last = $request;
			if ( null === $current && ! in_array( (string) ( $request['status'] ?? '' ), array( 'finished', 'validation ok' ), true ) ) {
				$current = $request;
			}
			if ( isset( $request['id'] ) && is_scalar( $request['id'] ) && ! in_array( (string) $request['id'], $app['requests'], true ) ) {
				$app['requests'][] = sanitize_text_field( (string) $request['id'] );
			}
			foreach ( array( 'acn', 'abn' ) as $number ) {
				if ( ! empty( $request[ $number ] ) && is_scalar( $request[ $number ] ) ) {
					$app[ $number ] = preg_replace( '/\D/', '', (string) $request[ $number ] );
				}
			}
		}

		// Every request id of an amend chain, and whether the customer can correct it now.
		foreach ( (array) ( $data['history'] ?? array() ) as $request ) {
			if ( is_array( $request ) && isset( $request['id'] ) && is_scalar( $request['id'] ) && ! in_array( (string) $request['id'], $app['requests'], true ) ) {
				$app['requests'][] = sanitize_text_field( (string) $request['id'] );
			}
		}
		$app['amendable'] = 'action_needed' === $app['status'] && ! empty( $data['amendable'] );
		// Refused by BAPI before it reached the agency: the form stays editable and resubmits.
		$app['editable'] = 'action_needed' === $app['status'] && ! $app['amendable'] && ! empty( $data['lodgementErrors'] );

		$current        = $current ?? $last;
		$app['agency']  = is_array( $current ) ? sanitize_key( (string) ( $current['role'] ?? '' ) ) : '';
		$app['request'] = is_array( $current ) && isset( $current['id'] ) && is_scalar( $current['id'] ) ? sanitize_text_field( (string) $current['id'] ) : '';

		// Corrections asked for: keep only how many (the messages can quote the application).
		$app['issues'] = 'action_needed' === $app['status'] ? count( (array) ( $data['actionNeeded']['errors'] ?? array() ) ) : 0;
		return $app;
	}

	/** Move this application's next status check to within $delay seconds (never the group's). */
	public static function poll_soon( int $order_id, string $ref, int $delay ): void {
		$args = array( $order_id, $ref );
		$next = as_next_scheduled_action( self::HOOK_POLL, $args, self::GROUP );
		if ( is_int( $next ) && $next <= time() + $delay ) {
			return; // Already due sooner.
		}
		if ( false !== $next ) {
			as_unschedule_action( self::HOOK_POLL, $args, self::GROUP );
		}
		as_schedule_single_action( time() + $delay, self::HOOK_POLL, $args, self::GROUP );
	}

	private function schedule_poll( int $order_id, string $ref, int $polls, int $cap = 0, int $delay_override = 0 ): void {

		$args = array( $order_id, $ref );
		// Pending only: the poll that is running right now must not stop it scheduling the next one.
		if ( \BapiForms\Scheduler::pending( self::HOOK_POLL, $args, self::GROUP ) ) {
			return;
		}

		$delay = self::BACKOFF[ min( $polls, count( self::BACKOFF ) - 1 ) ];
		if ( $cap > 0 ) {
			$delay = min( $delay, $cap );
		}
		if ( $delay_override > 0 ) {
			$delay = $delay_override;
		}
		as_schedule_single_action( time() + $delay, self::HOOK_POLL, $args, self::GROUP );
	}

	/** After re-activation: queue releases and resume polls that deactivation removed. */
	public function resume_order( \WC_Order $order ): void {
		$order_id = (int) $order->get_id();
		// Refunded or cancelled while the plugin was inactive: cancel what is still live.
		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			if ( self::has_cancellable( $order ) ) {
				$this->schedule_cancel( $order_id, MINUTE_IN_SECONDS );
			}
		}
		// On hold, failed or back to pending payment after payment: pause what could still lodge.
		if ( $order->has_status( self::$pause_statuses ) ) {
			if ( ( $order->get_date_paid( 'edit' ) || $order->get_meta( self::PAID_FLAG ) ) && ! \BapiForms\Scheduler::pending( self::HOOK_SUSPEND, array( $order_id ), self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK_SUSPEND, array( $order_id ), self::GROUP );
			}
		}
		// Pay first: lines not authorised yet on a paid order, reminders of prepaid applications.
		if ( $order->is_paid() && Prepaid::has_unissued( $order ) ) {
			$this->queue_release( (int) $order->get_id() );
		}
		foreach ( $order->is_paid() ? Prepaid::slots( $order ) : array() as $slot => $row ) {
			if ( 'prepaid' === ( $row['state'] ?? '' ) ) {
				\BapiForms\Emails\PrepaidEmails::schedule( (int) $order->get_id(), $slot, $row );
			}
		}
		foreach ( self::apps( $order ) as $ref => $app ) {
			if ( 'prepaid' === ( $app['state'] ?? '' ) ) {
				$this->schedule_poll( (int) $order->get_id(), (string) $ref, 0, 0, HOUR_IN_SECONDS );
			} elseif ( 'pending' === ( $app['state'] ?? '' ) && $order->is_paid() ) {
				$this->queue_release( (int) $order->get_id() );
			} elseif ( 'released' === ( $app['state'] ?? '' ) && ! in_array( $app['status'] ?? '', Applications::TERMINAL, true ) ) {
				$this->schedule_poll( (int) $order->get_id(), (string) $ref, (int) ( $app['polls'] ?? 0 ) );
			}
		}
		$this->resume_completed_emails( $order );
	}

	/** Whether an order still has an application (or pay-first line) a cancel would act on. */
	private static function has_cancellable( \WC_Order $order ): bool {
		$refs = self::item_refs( $order );
		foreach ( self::apps( $order ) as $ref => $app ) {
			if ( ! in_array( (string) $ref, $refs, true ) ) {
				continue;
			}
			if ( in_array( $app['state'] ?? '', array( 'pending', 'blocked', 'prepaid' ), true ) || ( 'released' === ( $app['state'] ?? '' ) && empty( $app['closed'] ) ) ) {
				return true;
			}
		}
		foreach ( Prepaid::slots( $order ) as $row ) {
			if ( in_array( $row['state'] ?? '', Prepaid::UNISSUED, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Requeue a "completed" email that was waiting for documents when actions were removed. */
	private function resume_completed_emails( \WC_Order $order ): void {
		$sent = $order->get_meta( \BapiForms\Emails\Emails::META );
		foreach ( is_array( $sent ) ? $sent : array() as $ref => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['completed_waiting'] ) || ! empty( $entry['completed'] ) || 'completed' !== ( self::apps( $order )[ (string) $ref ]['status'] ?? '' ) ) {
				continue;
			}
			$pending = false;
			for ( $attempt = 0; $attempt <= 10 && ! $pending; $attempt++ ) {
				$pending = \BapiForms\Scheduler::pending( \BapiForms\Emails\Emails::HOOK_RETRY, array( (int) $order->get_id(), (string) $ref, $attempt ), \BapiForms\Emails\Emails::GROUP );
			}
			if ( ! $pending ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, \BapiForms\Emails\Emails::HOOK_RETRY, array( (int) $order->get_id(), (string) $ref, 1 ), \BapiForms\Emails\Emails::GROUP );
			}
		}
	}

	private static function status_of( Response $response, string $fallback ): string {
		$status = $response->data['status'] ?? null;
		return is_string( $status ) && '' !== $status ? sanitize_key( $status ) : $fallback;
	}

	/**
	 * Hide raw item meta on the admin order screen.
	 *
	 * @param list<string> $keys Hidden keys.
	 */
	public function hidden_item_meta( $keys ): array {
		return array_merge( (array) $keys, array( self::ITEM_REF, self::ITEM_CARRIER ) );
	}

	/**
	 * Admin order screen: "Application reference" under an application line.
	 *
	 * @param int                    $item_id Item id.
	 * @param \WC_Order_Item|mixed   $item    Item.
	 */
	public function admin_item_ref( $item_id, $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		$ref = (string) $item->get_meta( self::ITEM_REF );
		if ( Applications::is_ref( $ref ) ) {
			printf( '<p class="bapi-forms-ref"><small>%s <code>%s</code></small></p>', esc_html__( 'Application reference:', 'businessapi-forms' ), esc_html( $ref ) );
		}
	}

	/**
	 * No "Order again" for orders with applications: each application is paid once.
	 *
	 * @param \WC_Order|mixed $order Order.
	 */
	public function no_order_again( $order ): void {
		if ( $order instanceof \WC_Order && ( self::apps( $order ) || Prepaid::slots( $order ) ) ) {
			remove_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' );
		}
	}

	/**
	 * Application refs on the order's line items.
	 *
	 * @return list<string>
	 */
	public static function item_refs( \WC_Order $order ): array {
		$refs = array();
		foreach ( $order->get_items() as $item ) {
			$ref = (string) $item->get_meta( self::ITEM_REF );
			if ( '' !== $ref ) {
				$refs[] = $ref;
			}
		}
		return array_values( array_unique( $refs ) );
	}

	/**
	 * Run $callback holding the order's lock, so release, cancel and poll never overwrite each
	 * other's meta. Waits up to $wait seconds.
	 *
	 * @return bool False when the lock could not be taken.
	 */
	public static function with_lock( int $order_id, callable $callback, int $wait = 0 ): bool {
		return $order_id > 0 && \BapiForms\Lock::run( self::LOCK_PREFIX . $order_id, self::LOCK_SECONDS, $callback, $wait );
	}

	/** Extend the lock before the next slow BAPI call. */
	private static function refresh_lock( int $order_id ): void {
		\BapiForms\Lock::refresh( self::LOCK_PREFIX . $order_id, self::LOCK_SECONDS );
	}

	/**
	 * Orders other than $exclude_id that hold $ref and are not cancelled, refunded, trashed or a
	 * block-checkout draft. A failed order still counts: its application is kept for a retry.
	 */
	public static function ref_on_other_order( string $ref, int $exclude_id = 0 ): bool {
		return array() !== array_diff( self::orders_with_ref( $ref, self::live_statuses() ), array( $exclude_id ) );
	}

	/**
	 * Another live order (see ref_on_other_order()) that still tracks $ref as not cancelled: it
	 * owns the application. Returns its id, or 0.
	 */
	public static function ref_tracked_elsewhere( string $ref, int $exclude_id ): int {
		foreach ( self::orders_with_ref( $ref, self::live_statuses() ) as $id ) {
			if ( $id === $exclude_id ) {
				continue;
			}
			$other = wc_get_order( $id );
			$state = $other instanceof \WC_Order ? ( self::apps( $other )[ $ref ]['state'] ?? '' ) : '';
			if ( '' !== $state && 'cancelled' !== $state ) {
				return $id;
			}
		}
		return 0;
	}

	/** Order statuses that can still hold an application: all but cancelled, refunded, draft. */
	private static function live_statuses(): array {
		return array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-refunded', 'wc-checkout-draft' ) ) );
	}

	/**
	 * Ids of orders with a lookup row for $ref, in $statuses (all statuses when empty; trashed
	 * orders excluded).
	 *
	 * @param list<string> $statuses Order statuses (wc- prefixed).
	 * @return list<int>
	 */
	public static function orders_with_ref( string $ref, array $statuses = array() ): array {
		$ids = wc_get_orders(
			array(
				'limit'      => 10,
				'return'     => 'ids',
				'status'     => $statuses ? $statuses : array_keys( wc_get_order_statuses() ),
				// meta_key / meta_value, not meta_query: the posts order store drops meta_query.
				'meta_key'   => self::ORDER_REF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $ref, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function apps( \WC_Order $order ): array {
		$apps = $order->get_meta( self::META );
		return is_array( $apps ) ? $apps : array();
	}
}
