<?php
/**
 * Activation and deactivation. Deactivating hides carrier products and drops our scheduled
 * actions (order meta stays); re-activating resumes releases, polls, pay-first orders and paid
 * renewals in background batches.
 *
 * @package BapiForms
 */

namespace BapiForms;

use BapiForms\Commerce\WooCommerce\Carriers;
use BapiForms\Commerce\WooCommerce\OrderBridge;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	public const RESUME_FLAG = 'bapi_forms_resume_jobs';

	public static function activate(): void {
		update_option( self::RESUME_FLAG, 1, false );
	}

	public static function deactivate(): void {
		self::hide_carriers();
		\BapiForms\Commerce\WooCommerce\PayFirstProducts::hide_all();
		// WP-Cron fallback of the account status refresh, every args variant.
		wp_unschedule_hook( \BapiForms\Account\Capabilities::HOOK_REFRESH );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			try {
				as_unschedule_all_actions( '', array(), OrderBridge::GROUP );
			} catch ( \Throwable $e ) {
				// Action Scheduler not ready (WooCommerce deactivated first): nothing to remove.
				unset( $e );
			}
		}
	}

	/** Set every carrier product private (works without WooCommerce loaded). */
	public static function hide_carriers(): void {
		foreach ( array_map( 'intval', (array) get_option( Carriers::OPTION, array() ) ) as $id ) {
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'private',
					)
				);
			}
		}
	}

	public const HOOK_RESUME = self::RESUME_FLAG;
	private const BATCH      = 50;

	/**
	 * After re-activation, queue the first resume batch in the background (never in the
	 * visitor's request). Runs on every request while the flag is set; one batch at a time.
	 */
	public static function resume_jobs(): void {
		if ( ! get_option( self::RESUME_FLAG ) || ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK_RESUME, null, OrderBridge::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_RESUME, array( 1 ), OrderBridge::GROUP );
		}
	}

	/**
	 * One batch of orders that track applications, oldest first so new orders cannot shift pages.
	 * Pages: 1, 2... then "p1"... (pay-first slots) then "r1"... (renewals).
	 *
	 * @param int|string $page Page (1-based), with an optional pass prefix.
	 */
	public static function resume_batch( OrderBridge $bridge, $page ): void {
		if ( ! get_option( self::RESUME_FLAG ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		if ( is_string( $page ) && str_starts_with( $page, 'p' ) ) {
			self::resume_slots( $bridge, max( 1, (int) substr( $page, 1 ) ) );
			return;
		}
		if ( is_string( $page ) && str_starts_with( $page, 'r' ) ) {
			self::resume_renewals( max( 1, (int) substr( $page, 1 ) ) );
			return;
		}
		$page = max( 1, (int) $page );
		$ids  = (array) wc_get_orders(
			array(
				'limit'        => self::BATCH,
				'page'         => $page,
				'return'       => 'ids',
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'meta_key'     => OrderBridge::ORDER_REF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$bridge->resume_order( $order );
			}
		}
		if ( count( $ids ) >= self::BATCH ) {
			as_enqueue_async_action( self::HOOK_RESUME, array( $page + 1 ), OrderBridge::GROUP );
			return;
		}
		as_enqueue_async_action( self::HOOK_RESUME, array( 'p1' ), OrderBridge::GROUP );
	}

	/** Second pass: pay-first orders with no application ref yet (the first pass did the rest). */
	private static function resume_slots( OrderBridge $bridge, int $page ): void {
		$ids = (array) wc_get_orders(
			array(
				'limit'        => self::BATCH,
				'page'         => $page,
				'return'       => 'ids',
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'meta_key'     => \BapiForms\Commerce\WooCommerce\Prepaid::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order && '' === (string) $order->get_meta( OrderBridge::ORDER_REF ) ) {
				$bridge->resume_order( $order );
			}
		}
		if ( count( $ids ) >= self::BATCH ) {
			as_enqueue_async_action( self::HOOK_RESUME, array( 'p' . ( $page + 1 ) ), OrderBridge::GROUP );
			return;
		}
		as_enqueue_async_action( self::HOOK_RESUME, array( 'r1' ), OrderBridge::GROUP );
	}

	/** Third pass: paid monitoring renewals not renewed yet. Removes the flag after the last batch. */
	private static function resume_renewals( int $page ): void {
		$ids = (array) wc_get_orders(
			array(
				'limit'        => self::BATCH,
				'page'         => $page,
				'return'       => 'ids',
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'status'       => function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' ),
				'meta_key'     => \BapiForms\Monitoring\Renewals::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				\BapiForms\Monitoring\Renewals::requeue( $order );
			}
		}
		if ( count( $ids ) >= self::BATCH ) {
			as_enqueue_async_action( self::HOOK_RESUME, array( 'r' . ( $page + 1 ) ), OrderBridge::GROUP );
			return;
		}
		delete_option( self::RESUME_FLAG );
	}
}
