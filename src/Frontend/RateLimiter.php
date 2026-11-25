<?php
/**
 * Atomic fixed-window counters for the public REST routes.
 *
 * Object cache incr when available, otherwise one INSERT ... ON DUPLICATE KEY UPDATE on the
 * options table. Keys are salted hashes, so no IP address is stored.
 *
 * @package BapiForms
 */

namespace BapiForms\Frontend;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	private const PREFIX = 'bapi_forms_rl_';

	/** Count one hit; true when it is over $limit in the current window. */
	public static function hit( string $bucket, string $subject, int $limit, int $window ): bool {
		if ( $limit <= 0 ) {
			return false;
		}

		[ $key, $name ] = self::keys( $bucket, $subject, $window );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'bapi_forms_rl', $window );
			$count = (int) wp_cache_incr( $key, 1, 'bapi_forms_rl' );
			return $count > $limit;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $name ) );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		// phpcs:enable

		if ( 1 === $count ) {
			self::cleanup();
		}

		return $count > $limit;
	}

	/** Hits so far in the current window, without counting one. */
	public static function count( string $bucket, string $subject, int $window ): int {
		[ $key, $name ] = self::keys( $bucket, $subject, $window );
		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $key, 'bapi_forms_rl' );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * Object cache key and options row name of the current window.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function keys( string $bucket, string $subject, int $window ): array {
		$slot    = (int) floor( time() / $window );
		$expires = ( $slot + 1 ) * $window;
		$hash    = substr( hash_hmac( 'sha256', $bucket . '|' . $subject . '|' . $slot, wp_salt( 'nonce' ) ), 0, 24 );
		return array( $bucket . '_' . $hash, self::PREFIX . $expires . '_' . $hash );
	}

	/** Delete counters from windows that have ended. */
	private static function cleanup(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like( self::PREFIX ) . '%' ) );
		$old   = array();
		foreach ( $names as $name ) {
			if ( 1 === preg_match( '/^' . self::PREFIX . '(\d+)_/', $name, $m ) && (int) $m[1] < time() ) {
				$old[] = $name;
			}
		}
		if ( $old ) {
			$in = implode( ',', array_fill( 0, count( $old ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN ($in)", $old ) );
		}
	}
}
