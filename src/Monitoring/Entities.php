<?php
/**
 * Which orders, and so which customer, a monitored entity belongs to.
 *
 * Only the key "{env}:{entityId}" is stored, as order meta. Ownership follows BAPI's current
 * `reference` for the subscription (the application ref), never "ever paid for this id": when
 * that reference changes, the old customer loses access at once.
 *
 * @package BapiForms
 */

namespace BapiForms\Monitoring;

use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Entities {

	public const META_ENTITY = '_bapi_forms_monitoring_entity';

	/** Marks orders that carry monitoring, so owned_refs() reads only those. */
	public const META_MARKER = '_bapi_forms_monitoring';

	/** @var array<string, list<string>> "user:env:paid" => refs, per request. */
	private static array $owned_cache = array();

	/** Caps the entities the daily check and My Account look at, to bound BAPI calls. */
	private const MAX = 2000;

	public static function key( string $env, int $id ): string {
		return $env . ':' . $id;
	}

	/**
	 * @return array{0: string, 1: int}|null [env, id]
	 */
	public static function parse( string $key ): ?array {
		if ( 1 !== preg_match( '/^(test|live):([1-9]\d{0,18})$/', $key, $m ) ) {
			return null;
		}
		return array( $m[1], (int) $m[2] );
	}

	/** The caller saves the order. */
	public static function attach( \WC_Order $order, string $env, int $id ): bool {
		$key = self::key( $env, $id );
		if ( in_array( $key, self::on_order( $order ), true ) ) {
			return false;
		}
		$order->add_meta_data( self::META_ENTITY, $key );
		self::mark( $order );
		return true;
	}

	/** The caller saves the order. */
	public static function mark( \WC_Order $order ): void {
		if ( '1' !== (string) $order->get_meta( self::META_MARKER, true, 'edit' ) ) {
			$order->update_meta_data( self::META_MARKER, '1' );
		}
		self::flush();
	}

	public static function flush(): void {
		self::$owned_cache = array();
	}

	/** @return list<string> entity keys on one order */
	public static function on_order( \WC_Order $order ): array {
		return array_values(
			array_filter(
				array_map( static fn ( $meta ) => (string) ( $meta->value ?? '' ), (array) $order->get_meta( self::META_ENTITY, false, 'edit' ) ),
				static fn ( $key ) => null !== self::parse( $key )
			)
		);
	}

	/** BAPI stamps `reference` with the lowercase ULID; the plugin keeps refs uppercase. */
	public static function norm( string $r ): string {
		return strtoupper( trim( $r ) );
	}

	/**
	 * Normalised refs an order holds for one entity: the application that bought it, or the
	 * subscription a renewal order renews.
	 *
	 * @return list<string>
	 */
	public static function refs( \WC_Order $order, string $key ): array {
		$parsed = self::parse( $key );
		if ( null === $parsed ) {
			return array();
		}
		[ $env, $id ] = $parsed;
		$refs         = array();
		foreach ( OrderBridge::apps( $order ) as $ref => $app ) {
			if ( (int) ( $app['monitoring_entity'] ?? 0 ) === $id && (string) ( $app['env'] ?? '' ) === $env ) {
				$refs[] = self::norm( (string) $ref );
			}
		}
		$record = Renewals::record( $order );
		if ( $record && (int) $record['entity'] === $id && $env === (string) $record['env'] ) {
			if ( '' !== (string) ( $record['ref'] ?? '' ) ) {
				$refs[] = self::norm( (string) $record['ref'] );
			}
		}
		return array_values( array_unique( $refs ) );
	}

	/**
	 * Orders that bought or renewed an entity, newest first.
	 *
	 * @return list<\WC_Order>
	 */
	public static function orders( string $key ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		return array_values(
			array_filter(
				(array) wc_get_orders(
					array(
						'limit'      => 50,
						'orderby'    => 'ID',
						'order'      => 'DESC',
						'type'       => 'shop_order',
						'status'     => array_keys( wc_get_order_statuses() ),
						'meta_key'   => self::META_ENTITY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				),
				static fn ( $order ) => $order instanceof \WC_Order
			)
		);
	}

	/**
	 * The order whose customer owns the current subscription ($reference read live from BAPI).
	 *
	 * Needs a paid original order holding the ref. Returns the newest order that counts (its
	 * billing email gets the notices); renewal orders count only once paid and applied.
	 */
	public static function owner_order( string $key, string $reference ): ?\WC_Order {
		$reference = self::norm( $reference );
		if ( '' === $reference ) {
			return null;
		}
		$chain    = array();
		$original = null;
		foreach ( self::orders( $key ) as $order ) {
			if ( ! in_array( $reference, self::refs( $order, $key ), true ) ) {
				continue;
			}
			$record = Renewals::record( $order );
			if ( null === $record ) {
				$original = $original ?? $order;
			}
			$chain[] = $order;
		}
		if ( ! $original || ! $original->is_paid() ) {
			return null;
		}
		foreach ( $chain as $order ) {
			$record = Renewals::record( $order );
			if ( null === $record ) {
				return $order;
			}
			if ( 'renewed' === ( $record['state'] ?? '' ) && $order->is_paid() ) {
				return $order;
			}
		}
		return $original;
	}

	public static function belongs_to( string $key, int $user_id, string $reference ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$owner = self::owner_order( $key, $reference );
		return $owner && (int) $owner->get_customer_id() === $user_id;
	}

	/**
	 * Entity keys from a customer's paid orders, with their refs. Candidates only: the caller
	 * checks them against BAPI's current reference before showing anything.
	 *
	 * @return array<string, list<string>> key => refs
	 */
	public static function for_customer( int $user_id, ?string $env = null ): array {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wc_get_orders(
			array(
				'customer_id'  => $user_id,
				'limit'        => 200,
				'orderby'      => 'ID',
				'order'        => 'DESC',
				'type'         => 'shop_order',
				'status'       => array_keys( wc_get_order_statuses() ),
				'meta_key'     => self::META_ENTITY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		) as $order ) {
			if ( ! $order instanceof \WC_Order || ! $order->is_paid() ) {
				continue;
			}
			foreach ( self::on_order( $order ) as $key ) {
				if ( null !== $env && ! str_starts_with( $key, $env . ':' ) ) {
					continue;
				}
				$out[ $key ] = array_values( array_unique( array_merge( $out[ $key ] ?? array(), self::refs( $order, $key ) ) ) );
			}
		}
		return $out;
	}

	/** BAPI's limit for ownedRefs and /summary compare. */
	public const OWNED_REFS_MAX = 200;

	/**
	 * A customer's monitoring refs in one environment, newest first, so the form can say "you
	 * already monitor this". Includes applications BAPI is still setting up.
	 *
	 * $paid false returns refs on orders awaiting payment instead, for the /cart duplicate check.
	 *
	 * @return list<string>
	 */
	public static function owned_refs( int $user_id, string $env, bool $paid = true ): array {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$cache_key = $user_id . ':' . $env . ':' . ( $paid ? '1' : '0' );
		if ( isset( self::$owned_cache[ $cache_key ] ) ) {
			return self::$owned_cache[ $cache_key ];
		}
		// meta_key / meta_compare, not meta_query: the posts order store drops meta_query.
		$args = array(
			'customer_id'  => $user_id,
			'limit'        => self::OWNED_REFS_MAX,
			'orderby'      => 'ID',
			'order'        => 'DESC',
			'type'         => 'shop_order',
			'status'       => $paid ? array_keys( wc_get_order_statuses() ) : array( 'wc-pending', 'wc-on-hold' ),
			'meta_key'     => self::META_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare' => 'EXISTS',
		);
		$refs = array();
		foreach ( (array) wc_get_orders( $args ) as $order ) {
			if ( ! $order instanceof \WC_Order || $paid !== $order->is_paid() ) {
				continue;
			}
			$refs = array_merge( $refs, self::order_refs( $order, $env ) );
		}
		$refs = array_values( array_unique( array_filter( $refs, static fn ( $r ) => 1 === preg_match( '/^[0-9A-Z]{26}$/', $r ) ) ) );
		$refs = array_slice( $refs, 0, self::OWNED_REFS_MAX );

		self::$owned_cache[ $cache_key ] = $refs;
		return $refs;
	}

	/**
	 * @return list<string>
	 */
	private static function order_refs( \WC_Order $order, string $env ): array {
		$refs = array();
		foreach ( OrderBridge::apps( $order ) as $ref => $app ) {
			if ( Renewals::PRODUCT === ( $app['product'] ?? '' ) && (string) ( $app['env'] ?? '' ) === $env ) {
				$refs[] = self::norm( (string) $ref );
			}
		}
		$record = Renewals::record( $order );
		if ( $record && (string) ( $record['env'] ?? '' ) === $env && '' !== (string) ( $record['ref'] ?? '' ) ) {
			$refs[] = self::norm( (string) $record['ref'] );
		}
		foreach ( self::on_order( $order ) as $key ) {
			if ( str_starts_with( $key, $env . ':' ) ) {
				$refs = array_merge( $refs, self::refs( $order, $key ) );
			}
		}
		return $refs;
	}

	/**
	 * Tracked entity ids per environment, from one meta query. Ownership is checked later.
	 *
	 * @return array<string, list<int>> env => ids
	 */
	public static function all( ?string $env = null ): array {
		global $wpdb;
		$out   = array(
			Options::ENV_TEST => array(),
			Options::ENV_LIVE => array(),
		);
		$hpos  = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$like  = null === $env ? '%' : $wpdb->esc_like( $env . ':' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
		$keys = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY meta_value LIMIT %d", self::META_ENTITY, $like, self::MAX ) );
		foreach ( $keys as $key ) {
			$parsed = self::parse( (string) $key );
			if ( null !== $parsed && ! in_array( $parsed[1], $out[ $parsed[0] ], true ) ) {
				$out[ $parsed[0] ][] = $parsed[1];
			}
		}
		return null === $env ? $out : array( $env => $out[ $env ] );
	}

	/** "ACN 004 085 616". Register numbers, not form data. */
	public static function number_label( array $entity ): string {
		$acn = preg_replace( '/\D/', '', (string) ( $entity['acn'] ?? '' ) );
		$abn = preg_replace( '/\D/', '', (string) ( $entity['abn'] ?? '' ) );
		if ( 9 === strlen( $acn ) ) {
			return 'ACN ' . implode( ' ', str_split( $acn, 3 ) );
		}
		if ( 11 === strlen( $abn ) ) {
			return 'ABN ' . substr( $abn, 0, 2 ) . ' ' . implode( ' ', str_split( substr( $abn, 2 ), 3 ) );
		}
		return '';
	}

	public static function simple_status( array $entity ): string {
		return match ( (string) ( $entity['status'] ?? '' ) ) {
			'active'  => __( 'Active', 'businessapi-forms' ),
			'stopped' => __( 'Stopped', 'businessapi-forms' ),
			'expired' => __( 'Ended', 'businessapi-forms' ),
			default   => __( 'Setting up', 'businessapi-forms' ),
		};
	}

	/** Entity name, or its number when the register gave none. */
	public static function display_name( array $entity ): string {
		$name = trim( (string) ( $entity['entityName'] ?? '' ) );
		if ( '' !== $name ) {
			return $name;
		}
		$number = self::number_label( $entity );
		return '' !== $number ? $number : __( 'Monitored entity', 'businessapi-forms' );
	}

	/** The ACN/ABN first, so a notice reads the same whether a webhook or the daily check sent it. */
	public static function subject_label( array $entity ): string {
		$number = self::number_label( $entity );
		return '' !== $number ? $number : self::display_name( $entity );
	}
}
