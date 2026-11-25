<?php
/**
 * An application's lines on an order: one product line plus one GST-free government fee line.
 * Pay-first lines carry their slot id from checkout and the ref once the application exists.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Applications\Applications;

defined( 'ABSPATH' ) || exit;

final class OrderLines {

	/** Fee lines: "government". */
	public const ITEM_FEE = '_bapi_forms_fee';
	/** Fee lines: the product whose government fee it is. */
	public const ITEM_GOV_KIND = '_bapi_forms_gov_kind';
	/** Product lines: add-on slugs merged into the line (comma separated). */
	public const ITEM_ADDONS = '_bapi_forms_addons';
	/** Product lines: cart line format (Carriers::VERSION). */
	public const ITEM_VERSION = '_bapi_forms_cart_v';
	/** The "For: Application #XXXXXX" row, added on display only. */
	public const FOR_KEY = 'bapi_forms_for';

	public function register(): void {
		// After OrderBridge::copy_ref_to_item() (10), which starts tracking the application.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copy_line_meta' ), 20, 3 );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'copy_fee_meta' ), 10, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_item_meta' ) );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'formatted_meta' ), 10, 2 );
	}

	/**
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart key.
	 * @param array                  $values        Cart item.
	 */
	public function copy_line_meta( $item, $cart_item_key, $values ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || ! Carriers::is_line( $values ) || ! Applications::is_ref( (string) ( $values['bapi_application_ref'] ?? '' ) ) ) {
			return;
		}
		$item->update_meta_data( self::ITEM_VERSION, Carriers::VERSION );
		$item->update_meta_data( self::ITEM_ADDONS, implode( ',', Carriers::addons_of( $values ) ) );
	}

	/**
	 * Link a government fee line ("bapi-gov-{REF}") to an application the order already tracks.
	 *
	 * @param \WC_Order_Item_Fee $item    Fee item.
	 * @param string             $fee_key Cart fee id.
	 * @param object             $fee     Cart fee.
	 * @param \WC_Order          $order   Order.
	 */
	public function copy_fee_meta( $item, $fee_key, $fee, $order ): void {
		$fee_key = (string) $fee_key;
		if ( ! $item instanceof \WC_Order_Item_Fee || ! $order instanceof \WC_Order || ! str_starts_with( $fee_key, Carriers::FEE_PREFIX ) ) {
			return;
		}
		$wanted = strtoupper( substr( $fee_key, strlen( Carriers::FEE_PREFIX ) ) );
		// Pay first: "bapi-gov-PF{SLOT}".
		if ( str_starts_with( $wanted, Prepaid::FEE_PART ) ) {
			$slot = strtolower( substr( $wanted, strlen( Prepaid::FEE_PART ) ) );
			$row  = Prepaid::slots( $order )[ $slot ] ?? null;
			if ( is_array( $row ) ) {
				$item->update_meta_data( Prepaid::ITEM_SLOT, $slot );
				$item->update_meta_data( self::ITEM_FEE, 'government' );
				$item->update_meta_data( self::ITEM_GOV_KIND, sanitize_key( (string) ( $row['product'] ?? '' ) ) );
			}
			return;
		}
		foreach ( OrderBridge::apps( $order ) as $ref => $app ) {
			if ( strtoupper( (string) $ref ) === $wanted ) {
				$item->update_meta_data( OrderBridge::ITEM_REF, (string) $ref );
				$item->update_meta_data( self::ITEM_FEE, 'government' );
				$item->update_meta_data( self::ITEM_GOV_KIND, sanitize_key( (string) ( $app['product'] ?? '' ) ) );
				return;
			}
		}
	}

	/**
	 * @param list<string> $keys Hidden keys.
	 */
	public function hidden_item_meta( $keys ): array {
		// "For" (formatted_meta()) too: the admin item editor posts every row it lists back as real
		// meta, so saving an order would store it; the admin screen shows the ref on its own.
		return array_merge( (array) $keys, array( OrderBridge::ITEM_REF, OrderBridge::ITEM_CARRIER, self::ITEM_FEE, self::ITEM_GOV_KIND, self::ITEM_ADDONS, self::ITEM_VERSION, self::FOR_KEY, Prepaid::ITEM_FLOW, Prepaid::ITEM_SLOT, Prepaid::ITEM_FIXED ) );
	}

	/**
	 * @param array<string, object> $meta Formatted meta.
	 * @param \WC_Order_Item|mixed  $item Item.
	 */
	public function formatted_meta( $meta, $item ): array {
		// Drop a "For" row stored by an older admin save; the one below is always current.
		$meta = array_filter( (array) $meta, static fn( $row ): bool => ! is_object( $row ) || self::FOR_KEY !== ( $row->key ?? '' ) );
		if ( $item instanceof \WC_Order_Item_Product ) {
			$ref = (string) $item->get_meta( OrderBridge::ITEM_REF );
			if ( Applications::is_ref( $ref ) ) {
				$meta[ self::FOR_KEY ] = (object) array(
					'key'           => self::FOR_KEY,
					'value'         => StatusText::short_ref( $ref ),
					'display_key'   => __( 'For', 'businessapi-forms' ),
					'display_value' => esc_html( StatusText::short_ref( $ref ) ),
				);
			}
		}
		return $meta;
	}

	/** A government fee line, linked to a ref or a pay-first slot. */
	private static function is_gov_fee( \WC_Order_Item $item ): bool {
		return $item instanceof \WC_Order_Item_Fee && 'government' === (string) $item->get_meta( self::ITEM_FEE )
			&& ( '' !== (string) $item->get_meta( OrderBridge::ITEM_REF ) || Prepaid::is_slot( (string) $item->get_meta( Prepaid::ITEM_SLOT ) ) );
	}

	/** Paid incl. tax, net of refunds. */
	private static function net( \WC_Order $order, \WC_Order_Item $item ): float {
		$type = $item instanceof \WC_Order_Item_Fee ? 'fee' : 'line_item';
		$paid = (float) $item->get_total() + (float) $item->get_total_tax();
		$back = abs( (float) $order->get_total_refunded_for_item( $item->get_id(), $type ) );
		foreach ( array_keys( (array) ( $item->get_taxes()['total'] ?? array() ) ) as $rate_id ) {
			$back += abs( (float) $order->get_tax_refunded_for_item( $item->get_id(), $rate_id, $type ) );
		}
		return round( $paid - $back, 2 );
	}

	/** Paid ex tax, net of refunds. */
	private static function net_ex_tax( \WC_Order $order, \WC_Order_Item $item ): float {
		$type = $item instanceof \WC_Order_Item_Fee ? 'fee' : 'line_item';
		return round( (float) $item->get_total() - abs( (float) $order->get_total_refunded_for_item( $item->get_id(), $type ) ), 2 );
	}

	/** Government fees paid, net of refunds; one application's when $ref is given. */
	public static function government_paid( \WC_Order $order, ?string $ref = null, string $slot = '' ): float {
		$sum = 0.0;
		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			$mine = '' !== $slot ? (string) $item->get_meta( Prepaid::ITEM_SLOT ) === $slot : ( null === $ref || (string) $item->get_meta( OrderBridge::ITEM_REF ) === $ref );
			if ( self::is_gov_fee( $item ) && $mine ) {
				$sum += self::net( $order, $item );
			}
		}
		return round( $sum, 2 );
	}

	/** Government fee charged at checkout for one application, before refunds. */
	public static function government_charged( \WC_Order $order, string $ref ): float {
		$sum = 0.0;
		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			if ( self::is_gov_fee( $item ) && (string) $item->get_meta( OrderBridge::ITEM_REF ) === $ref ) {
				$sum += (float) $item->get_total();
			}
		}
		return round( $sum, 2 );
	}

	/**
	 * Line and fee items of one application, or of one pay-first slot when $slot is given.
	 *
	 * @return array{lines: list<\WC_Order_Item_Product>, fees: list<\WC_Order_Item_Fee>}
	 */
	private static function items_for( \WC_Order $order, string $ref, string $slot = '' ): array {
		$out = array(
			'lines' => array(),
			'fees'  => array(),
		);
		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			$mine = '' !== $slot ? (string) $item->get_meta( Prepaid::ITEM_SLOT ) === $slot : (string) $item->get_meta( OrderBridge::ITEM_REF ) === $ref;
			if ( ! $mine ) {
				continue;
			}
			if ( $item instanceof \WC_Order_Item_Product ) {
				$out['lines'][] = $item;
			} elseif ( self::is_gov_fee( $item ) ) {
				$out['fees'][] = $item;
			}
		}
		return $out;
	}

	/**
	 * Release-time check of what the order paid against what the application needs today: the
	 * expected lines at quantity 1, the government fee paid in full net of refunds, above $0.
	 * Pass $slot for a pay-first line whose application does not exist yet.
	 *
	 * @param array{service: string, addons: list<string>, government: array{amount: float}|null} $expected From ExpectedLines::expected().
	 * @return string|null Reason for blocking, or null when it matches.
	 */
	public static function check( \WC_Order $order, string $ref, array $expected, string $slot = '' ): ?string {
		$items = self::items_for( $order, $ref, $slot );
		$due   = null !== $expected['government'] ? (float) $expected['government']['amount'] : 0.0;
		$paid  = 0.0;
		$wrong = __( 'the order lines do not match the application (service, add-ons, government fee)', 'businessapi-forms' );
		if ( 1 !== count( $items['lines'] ) || 1 !== (int) $items['lines'][0]->get_quantity() ) {
			return __( 'the order lines were changed', 'businessapi-forms' );
		}
		$line = $items['lines'][0];
		$have = array_values( array_filter( explode( ',', (string) $line->get_meta( self::ITEM_ADDONS ) ) ) );
		$want = array_values( $expected['addons'] );
		sort( $have );
		sort( $want );
		if ( Carriers::VERSION !== (int) $line->get_meta( self::ITEM_VERSION ) || (string) $line->get_meta( OrderBridge::ITEM_CARRIER ) !== $expected['service'] || $have !== $want ) {
			return $wrong;
		}
		if ( count( $items['fees'] ) !== ( null !== $expected['government'] ? 1 : 0 ) ) {
			return $wrong;
		}
		foreach ( $items['fees'] as $fee ) {
			// Government fees are GST-free: a taxed fee line (edited on the order) is refused.
			if ( 'none' !== (string) $fee->get_tax_status() || abs( (float) $fee->get_total_tax() ) > Money::EPSILON ) {
				return __( 'the government fee line is taxed; government fees must be GST-free', 'businessapi-forms' );
			}
			$paid += self::net_ex_tax( $order, $fee );
		}
		$total = self::net( $order, $line ) + $paid;
		if ( $total <= 0 ) {
			return __( 'this application\'s lines total $0', 'businessapi-forms' );
		}
		if ( $paid + Money::EPSILON < $due ) {
			/* translators: 1: amount paid, 2: amount due. */
			return sprintf( __( 'the government fee paid (%1$s) is less than the current fee (%2$s)', 'businessapi-forms' ), Money::plain( $paid ), Money::plain( $due ) );
		}
		return null;
	}

	/**
	 * Refs whose every line and fee was refunded in full.
	 *
	 * @return list<string>
	 */
	public static function refunded_refs( \WC_Order $order ): array {
		$out = array();
		foreach ( OrderBridge::item_refs( $order ) as $ref ) {
			$items    = self::items_for( $order, $ref );
			$refunded = 0.0;
			$whole    = true;
			foreach ( array_merge( $items['lines'], $items['fees'] ) as $item ) {
				$paid      = (float) $item->get_total() + (float) $item->get_total_tax();
				$net       = self::net( $order, $item );
				$refunded += $paid - $net;
				if ( $net > Money::EPSILON ) {
					$whole = false;
				}
			}
			if ( $whole && $refunded > Money::EPSILON ) {
				$out[] = $ref;
			}
		}
		return $out;
	}

	/**
	 * Pay-first slots not issued yet whose every line and fee was refunded in full.
	 *
	 * @return list<string>
	 */
	public static function refunded_slots( \WC_Order $order ): array {
		$out = array();
		foreach ( Prepaid::item_slots( $order ) as $slot ) {
			$items    = self::items_for( $order, '', $slot );
			$refunded = 0.0;
			$whole    = true;
			foreach ( array_merge( $items['lines'], $items['fees'] ) as $item ) {
				$paid      = (float) $item->get_total() + (float) $item->get_total_tax();
				$net       = self::net( $order, $item );
				$refunded += $paid - $net;
				if ( $net > Money::EPSILON ) {
					$whole = false;
				}
			}
			if ( $whole && $refunded > Money::EPSILON ) {
				$out[] = $slot;
			}
		}
		return $out;
	}

	/** Write a new application's ref to its pay-first lines (the caller saves the order). */
	public static function link_slot( \WC_Order $order, string $slot, string $ref ): void {
		$items = self::items_for( $order, '', $slot );
		foreach ( array_merge( $items['lines'], $items['fees'] ) as $item ) {
			$item->update_meta_data( OrderBridge::ITEM_REF, $ref );
			$item->save();
		}
	}

	/** The application's line title, e.g. "Company registration (with ABN)", or ''. */
	public static function line_name( \WC_Order $order, string $ref ): string {
		foreach ( self::items_for( $order, $ref )['lines'] as $item ) {
			$carrier = (string) $item->get_meta( OrderBridge::ITEM_CARRIER );
			$product = (string) ( OrderBridge::apps( $order )[ $ref ]['product'] ?? $carrier );
			if ( $product === $carrier ) {
				return (string) $item->get_name();
			}
		}
		return '';
	}
}
