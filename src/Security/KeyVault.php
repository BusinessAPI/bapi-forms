<?php
/**
 * Encryption at rest for the stored API keys: libsodium secretbox with a key derived from the
 * wp-config salts (AUTH_KEY and SECURE_AUTH_KEY; WordPress's database salt when they are missing
 * or still the sample placeholder), stored as "bapienc:v1:" . base64( nonce . ciphertext ). If
 * the salts change, decryption fails and the plugin asks for the key again.
 *
 * @package BapiForms
 */

namespace BapiForms\Security;

defined( 'ABSPATH' ) || exit;

final class KeyVault {

	public const PREFIX = 'bapienc:v1:';

	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/** '' and already encrypted values are returned as they are. */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || self::is_encrypted( $plaintext ) ) {
			return $plaintext;
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext.
	}

	/** '' stays ''; null when it cannot be decrypted (or was never encrypted). */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}
		if ( ! self::is_encrypted( $stored ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/** 32-byte key from the wp-config salts, else from wp_salt() (stored in the database). */
	private static function key(): string {
		$auth   = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$secure = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '';
		$seed   = self::usable( $auth ) && self::usable( $secure ) && $auth !== $secure
			? $auth . '|' . $secure
			: 'wp_salt|' . wp_salt( 'auth' );
		return sodium_crypto_generichash( 'bapi-forms-key-v1|' . $seed, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/** A real salt: not empty, not the wp-config-sample.php placeholder, not trivially short. */
	private static function usable( string $salt ): bool {
		return strlen( $salt ) >= 32 && 'put your unique phrase here' !== $salt;
	}
}
