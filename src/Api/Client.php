<?php
/**
 * Business API (V2) client. Server-side only; bodies are never logged (they may hold applicant data).
 *
 * @package BapiForms
 */

namespace BapiForms\Api;

use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Client {

	/** Seconds; callers with a visitor waiting pass a shorter one. */
	public const TIMEOUT = 20;
	/** PDFs (documents, monitoring reports): slower and larger than JSON answers. */
	public const PDF_TIMEOUT = 60;
	public const PDF_MAX     = 25 * MB_IN_BYTES;

	public function __construct( private readonly Options $options ) {}

	public function base_url( ?string $environment = null ): string {
		$environment = $environment ?? $this->options->environment();
		$prefix      = Options::ENV_LIVE === $environment ? '/api/v2' : '/api/test/v2';
		return untrailingslashit( BAPI_FORMS_API_BASE ) . $prefix;
	}

	public function get( string $path, array $query = array(), ?string $environment = null ): Response {
		return $this->request( 'GET', $path, $query, null, $environment );
	}

	public function post( string $path, array $body = array(), ?string $environment = null ): Response {
		return $this->request( 'POST', $path, array(), $body, $environment );
	}

	public function delete( string $path, ?string $environment = null ): Response {
		return $this->request( 'DELETE', $path, array(), null, $environment );
	}

	/**
	 * @param array<string, string> $headers Extra headers (e.g. Idempotency-Key); never override the ones set here.
	 * @param int                   $timeout Seconds, 0 for the default. Keep it short while a visitor waits.
	 * @param string                $key     A key other than the stored one (e.g. the previous key after a rotation).
	 */
	public function request( string $method, string $path, array $query = array(), ?array $body = null, ?string $environment = null, array $headers = array(), int $timeout = 0, string $key = '' ): Response {
		$key = '' !== $key ? $key : $this->options->key( $environment );
		if ( '' === $key ) {
			return Response::no_key();
		}

		$url = $this->base_url( $environment ) . '/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'              => $method,
			'timeout'             => $timeout > 0 ? $timeout : self::TIMEOUT,
			'redirection'         => 0, // Never follow a redirect with the secret key.
			'sslverify'           => true,
			'limit_response_size' => 2 * MB_IN_BYTES,
			'headers'             => self::headers( $key, 'application/json' ),
		);

		$args['headers'] += $headers;

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$result = wp_remote_request( $url, $args );

		if ( is_wp_error( $result ) ) {
			return Response::error( 0, $result->get_error_message() );
		}

		$response = Response::from_http(
			(int) wp_remote_retrieve_response_code( $result ),
			(string) wp_remote_retrieve_body( $result )
		);

		\BapiForms\Account\Sites::note_response( $response, $environment ?? $this->options->environment() );

		return $response;
	}

	/**
	 * A PDF, held in memory only. `code` is '' for a complete PDF, else no_api_key, unreachable,
	 * incomplete (truncated or not a PDF), Business API's code or http_{status}.
	 *
	 * @return array{status: int, body: string, code: string}
	 */
	public function pdf( string $path, ?string $environment = null ): array {
		$key = $this->options->key( $environment );
		if ( '' === $key ) {
			return array(
				'status' => 0,
				'body'   => '',
				'code'   => Response::NO_KEY,
			);
		}
		$result = wp_remote_get(
			$this->base_url( $environment ) . '/' . ltrim( $path, '/' ),
			array(
				'timeout'             => self::PDF_TIMEOUT,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => self::PDF_MAX,
				'headers'             => self::headers( $key, 'application/pdf, application/json' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'code'   => 'unreachable',
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $result );
		$type   = strtolower( (string) wp_remote_retrieve_header( $result, 'content-type' ) );
		$body   = (string) wp_remote_retrieve_body( $result );
		if ( 200 === $status && str_starts_with( $type, 'application/pdf' ) ) {
			// Never a partial file: size cap, dropped connection or not a PDF at all.
			$length   = wp_remote_retrieve_header( $result, 'content-length' );
			$complete = str_starts_with( $body, '%PDF' ) && strlen( $body ) < self::PDF_MAX && ( ! is_numeric( $length ) || strlen( $body ) === (int) $length );
			return array(
				'status' => $complete ? 200 : 502,
				'body'   => $complete ? $body : '',
				'code'   => $complete ? '' : 'incomplete',
			);
		}
		$code = Response::from_http( $status, $body )->code();
		return array(
			'status' => $status,
			'body'   => '',
			'code'   => '' !== $code ? $code : 'http_' . $status,
		);
	}

	/** @return array<string, string> */
	private static function headers( string $key, string $accept ): array {
		return array(
			'Authorization'        => 'Bearer ' . $key,
			'Accept'               => $accept,
			'User-Agent'           => 'BapiForms/' . BAPI_FORMS_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			// BAPI refuses form sessions from blocked plugin versions.
			'X-Bapi-Forms-Version' => BAPI_FORMS_VERSION,
		);
	}
}
