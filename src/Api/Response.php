<?php
/**
 * Normalised Business API response.
 *
 * @package BapiForms
 */

namespace BapiForms\Api;

defined( 'ABSPATH' ) || exit;

final class Response {

	/** Code of the answer for a missing API key: never retried. */
	public const NO_KEY = 'no_api_key';

	private function __construct(
		public readonly int $status,
		public readonly array $data,
		public readonly ?string $error,
	) {}

	public static function from_http( int $status, string $body ): self {
		$data = json_decode( $body, true );
		$data = is_array( $data ) ? $data : array();

		if ( $status >= 200 && $status < 300 ) {
			return new self( $status, $data, null );
		}

		return new self( $status, $data, self::extract_error( $status, $data ) );
	}

	public static function error( int $status, string $message ): self {
		return new self( $status, array(), $message );
	}

	/** No API key for the environment: nothing was sent to Business API. */
	public static function no_key(): self {
		return new self( 0, array( 'code' => self::NO_KEY ), __( 'No API key configured for this environment.', 'businessapi-forms' ) );
	}

	public function ok(): bool {
		return null === $this->error;
	}

	/** Business API's machine code ("code" or "error", a short slug), else ''. Never its message. */
	public function code(): string {
		foreach ( array( 'code', 'error' ) as $key ) {
			$code = $this->data[ $key ] ?? null;
			if ( is_string( $code ) && 1 === preg_match( '/^[a-z0-9_.-]{1,64}$/', $code ) ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Worth retrying: no answer, 408, 423, 429, 5xx, 409 lodgement_conflict (Business API is
	 * lodging the same entity for another request), or a missing key (salts rotated, key not
	 * pasted again yet): retries pick it up once the partner saves it.
	 */
	public function is_transient(): bool {
		if ( $this->ok() ) {
			return false;
		}
		return self::NO_KEY === $this->code() || 0 === $this->status || $this->status >= 500 || in_array( $this->status, array( 408, 423, 429 ), true ) || 'lodgement_conflict' === $this->code();
	}

	/**
	 * BAPI returns errors in a few shapes: {error}, {message},
	 * {errors: [{details}]} and {errors: {field: [msg]}}.
	 */
	private static function extract_error( int $status, array $data ): string {
		foreach ( array( 'error', 'message' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				return $data[ $key ];
			}
		}

		if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$first = reset( $data['errors'] );
			if ( is_array( $first ) ) {
				$first = $first['details'] ?? reset( $first );
			}
			if ( is_string( $first ) ) {
				return $first;
			}
		}

		return 401 === $status
			? __( 'The API key was rejected by Business API.', 'businessapi-forms' )
			/* translators: %d: HTTP status code. */
			: sprintf( __( 'Business API returned HTTP %d.', 'businessapi-forms' ), $status );
	}
}
