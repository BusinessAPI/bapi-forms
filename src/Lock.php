<?php
/**
 * Named locks as rows in the options table, holding their expiry time.
 *
 * Taken with INSERT IGNORE, which is atomic; add_option() is not (it reads the cache first and
 * inserts with ON DUPLICATE KEY UPDATE, so two requests can both "add" the row). A lock past its
 * expiry (a crashed request) is taken over with a compare-and-set, so only one waiter wins it.
 *
 * @package BapiForms
 */

namespace BapiForms;

defined( 'ABSPATH' ) || exit;

final class Lock {

	/** @var array<string, string> Value written by this request, per lock it holds. */
	private static array $held = array();

	/** Take $name for $ttl seconds, waiting up to $wait seconds for it. */
	public static function acquire( string $name, int $ttl, int $wait = 0 ): bool {
		global $wpdb;
		$deadline = time() + $wait;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		while ( true ) {
			$value = self::value( $ttl );
			if ( 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) ) ) {
				return self::won( $name, $value );
			}
			// A missing row (released meanwhile) or an unreadable one reads as '' and expiry 0.
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( self::expiry( $current ) < time() && 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current ) ) ) {
				return self::won( $name, $value );
			}
			if ( time() >= $deadline ) {
				return false;
			}
			usleep( 250000 );
		}
		// phpcs:enable
	}

	/** Push the expiry out again before the next slow step. No effect once another request took it over. */
	public static function refresh( string $name, int $ttl ): void {
		global $wpdb;
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		$value = self::value( $ttl );
		$done  = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, self::$held[ $name ] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 === (int) $done ) {
			self::$held[ $name ] = $value;
		}
		self::forget( $name );
	}

	/** Release $name if this request still holds it. */
	public static function release( string $name ): void {
		global $wpdb;
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		$where = array(
			'option_name'  => $name,
			'option_value' => self::$held[ $name ],
		);
		$wpdb->delete( $wpdb->options, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		unset( self::$held[ $name ] );
		self::forget( $name );
	}

	/** Run $callback holding $name. False when the lock could not be taken. */
	public static function run( string $name, int $ttl, callable $callback, int $wait = 0 ): bool {
		if ( ! self::acquire( $name, $ttl, $wait ) ) {
			return false;
		}
		try {
			$callback();
		} finally {
			self::release( $name );
		}
		return true;
	}

	/** "expiry|token": the token tells this request's hold apart from a later owner's. */
	private static function value( int $ttl ): string {
		return ( time() + $ttl ) . '|' . wp_generate_password( 12, false );
	}

	private static function expiry( string $value ): int {
		return (int) strtok( $value, '|' );
	}

	private static function won( string $name, string $value ): bool {
		self::$held[ $name ] = $value;
		self::forget( $name );
		return true;
	}

	/** Keep the options cache from answering for a row changed behind its back. */
	private static function forget( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
