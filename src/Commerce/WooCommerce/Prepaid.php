<?php
/**
 * Pay-first lines ("slots") of an order.
 *
 * A pay-first line is bought before the form exists, so it carries a random slot id instead of an
 * application ref until the paid order creates its prepaid application. Order meta holds only
 * references, states, counters and timestamps, never form data:
 *
 *   _bapi_forms_prepaid = [ SLOT => [product, env, addons, fixed, state (awaiting_payment | issuing
 *     | prepaid | blocked | released | cancelled), ref, attempts, reason, issued_at, reminders,
 *     partner_nudged, suspended, duplicate_mailed, updated, idem, gov_due, cap_mailed] ]
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Applications\Applications;
use BapiForms\Plugin;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Prepaid {

	public const META = '_bapi_forms_prepaid';

	public const ITEM_FLOW = '_bapi_forms_flow';
	/** On the order line and its government fee line. */
	public const ITEM_SLOT = '_bapi_forms_slot';
	/** E.g. "term=3", '' when none. */
	public const ITEM_FIXED = '_bapi_forms_fixed';

	/** Cart fee id "bapi-gov-PF{SLOT}". */
	public const FEE_PART = 'PF';

	/** Slot states before an application exists. */
	public const UNISSUED = array( 'awaiting_payment', 'issuing', 'blocked' );

	public static function is_slot( string $slot ): bool {
		return 1 === preg_match( '/^[0-9a-f]{20}$/', $slot );
	}

	public static function new_slot(): string {
		return bin2hex( random_bytes( 10 ) );
	}

	/** "pay-first line #A1B2C3", for notes before the slot has a ref. */
	public static function slot_label( string $slot ): string {
		/* translators: %s: last 6 characters of the pay-first line id. */
		return sprintf( __( 'pay-first line #%s', 'businessapi-forms' ), strtoupper( substr( $slot, -6 ) ) );
	}

	/**
	 * Idempotency-Key for creating a slot's application. Stored on the slot at the first attempt
	 * and reused, so a later change of the site URL never creates a second application.
	 */
	public static function idempotency_key( int $order_id, string $slot, string $env ): string {
		return 'wp:' . substr( hash( 'sha256', home_url() ), 0, 16 ) . ':' . $order_id . ':' . $slot . ':' . ( Options::ENV_LIVE === $env ? 'live' : 'test' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function slots( \WC_Order $order ): array {
		$slots = $order->get_meta( self::META );
		$out   = array();
		foreach ( is_array( $slots ) ? $slots : array() as $slot => $row ) {
			if ( is_array( $row ) && self::is_slot( (string) $slot ) ) {
				$out[ (string) $slot ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Slot ids on the order's product lines.
	 *
	 * @return list<string>
	 */
	public static function item_slots( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$slot = (string) $item->get_meta( self::ITEM_SLOT );
			if ( self::is_slot( $slot ) ) {
				$out[] = $slot;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Slot of a pay-first application ref on this order, or ''. */
	public static function slot_of_ref( \WC_Order $order, string $ref ): string {
		foreach ( self::slots( $order ) as $slot => $row ) {
			if ( strtoupper( (string) ( $row['ref'] ?? '' ) ) === strtoupper( $ref ) ) {
				return $slot;
			}
		}
		return '';
	}

	/**
	 * Fixed choice as a line value ("term=3" for business names).
	 *
	 * @param array<string, mixed> $fixed Fixed options.
	 */
	public static function fixed_string( array $fixed ): string {
		return isset( $fixed['term'] ) ? 'term=' . (int) $fixed['term'] : '';
	}

	/** @return array{term?: int} */
	public static function parse_fixed( string $value ): array {
		return 1 === preg_match( '/^term=(1|3)$/', $value, $m ) ? array( 'term' => (int) $m[1] ) : array();
	}

	/**
	 * Parse a variation's _bapi_forms_choice ("addons=abn", "addons=", "term=1", "term=3"); null
	 * when not valid for the product.
	 *
	 * @return array{addons: list<string>, fixed: array{term?: int}}|null
	 */
	public static function parse_choice( string $product, string $choice ): ?array {
		if ( 'company_registration' === $product && 1 === preg_match( '/^addons=(abn)?$/', $choice, $m ) ) {
			return array(
				'addons' => ! empty( $m[1] ) ? array( 'abn_addon' ) : array(),
				'fixed'  => array(),
			);
		}
		if ( 'business_name' === $product && 1 === preg_match( '/^term=(1|3)$/', $choice, $m ) ) {
			return array(
				'addons' => array(),
				'fixed'  => array( 'term' => (int) $m[1] ),
			);
		}
		return null;
	}

	/**
	 * BAPI add-on keys (options.addons) of add-on slugs.
	 *
	 * @param list<string> $addons Add-on product slugs.
	 * @return list<string>
	 */
	public static function addon_keys( array $addons ): array {
		$all = Plugin::instance()->products->all();
		$out = array();
		foreach ( $addons as $slug ) {
			$key = $all[ (string) $slug ]['addon'] ?? null;
			if ( is_string( $key ) && '' !== $key ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Government fee pricing codes for ExpectedLines::expected(). Proprietary is the only company
	 * type the form registers.
	 *
	 * @param array<string, mixed> $fixed Fixed options.
	 * @return array{company?: string, term?: int}
	 */
	public static function pricing( string $product, array $fixed ): array {
		if ( 'company_registration' === $product ) {
			return array( 'company' => \BapiForms\Products\PriceList::V1_COMPANY_KEY );
		}
		if ( 'business_name' === $product ) {
			return array( 'term' => in_array( (int) ( $fixed['term'] ?? 0 ), \BapiForms\Products\PriceList::BN_TERMS, true ) ? (int) $fixed['term'] : 3 );
		}
		return array();
	}

	/**
	 * Whether the client can still fill in this pay-first application: prepaid, or refused by
	 * Business API before it reached the agency (no Amend, not PPSR, not a lapsed ASIC payment).
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function app_startable( array $app ): bool {
		if ( 'pay_first' !== ( $app['flow'] ?? '' ) ) {
			return false;
		}
		if ( 'prepaid' === ( $app['state'] ?? '' ) ) {
			return true;
		}
		return 'released' === ( $app['state'] ?? '' )
			&& 'action_needed' === ( $app['status'] ?? '' )
			&& empty( $app['amendable'] )
			&& ! empty( $app['editable'] )
			&& 'ppsr_search' !== ( $app['product'] ?? '' )
			&& 'lapsed' !== ( $app['asic'] ?? '' );
	}

	/** Whether the order has pay-first lines whose application does not exist yet. */
	public static function has_unissued( \WC_Order $order ): bool {
		$lines = self::item_slots( $order );
		foreach ( self::slots( $order ) as $slot => $row ) {
			if ( in_array( $slot, $lines, true ) && in_array( $row['state'] ?? '', self::UNISSUED, true ) && '' === (string) ( $row['ref'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Pay-first lines whose application is being created right now (not blocked). */
	public static function has_creating( \WC_Order $order ): bool {
		$lines = self::item_slots( $order );
		foreach ( self::slots( $order ) as $slot => $row ) {
			if ( in_array( $slot, $lines, true ) && in_array( $row['state'] ?? '', array( 'awaiting_payment', 'issuing' ), true ) && '' === (string) ( $row['ref'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_ref( string $ref ): bool {
		return Applications::is_ref( $ref );
	}
}
