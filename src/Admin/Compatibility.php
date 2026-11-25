<?php
/**
 * Site compatibility checks: REST API reachable by visitors and reverse proxy header.
 *
 * @package BapiForms
 */

namespace BapiForms\Admin;

use BapiForms\Frontend\AjaxBridge;
use BapiForms\Frontend\RestController;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Compatibility {

	private const REST_CACHE = 'bapi_forms_rest_check';

	private const REST_TTL = 12 * HOUR_IN_SECONDS;

	/** Plugins known to block the REST API for visitors, named in the advice. */
	public const REST_BLOCKERS = 'Disable REST API, Disable WP REST API, Perfmatters, Solid Security, All-In-One Security, WP Hide & Security Enhancer, Clearfy, Wordfence, a Cloudflare or host firewall rule';

	public function __construct( private readonly Options $options ) {}

	/**
	 * Whether visitors can reach the REST routes: an anonymous POST with a bad nonce must get our
	 * own 403 "bapi_forms_nonce"; anything else means something in front of the route answered.
	 * When blocked, the admin-ajax fallback is probed too.
	 *
	 * @return array{state: string, detail: string, fallback: ?bool, checked_at: int}
	 *         state: ok, blocked, or unknown (the loopback itself failed, e.g. HTTP auth on staging).
	 */
	public function rest_check( bool $fresh = false ): array {
		$cached = $fresh ? false : get_transient( self::REST_CACHE );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			return $cached;
		}

		$rest   = self::probe( rest_url( RestController::NAMESPACE . '/session' ) );
		$result = array(
			'state'      => $rest['state'],
			'detail'     => $rest['detail'],
			'fallback'   => null,
			'checked_at' => time(),
		);
		if ( 'blocked' === $rest['state'] ) {
			$result['fallback'] = 'ok' === self::probe( add_query_arg( 'route', 'session', AjaxBridge::url() ) )['state'];
		}

		set_transient( self::REST_CACHE, $result, self::REST_TTL );
		return $result;
	}

	/** The cached REST check, or null when none ran yet. */
	public function rest_check_cached(): ?array {
		$cached = get_transient( self::REST_CACHE );
		return is_array( $cached ) && isset( $cached['state'] ) ? $cached : null;
	}

	/**
	 * One anonymous POST without cookies and with a bad nonce.
	 *
	 * @return array{state: string, detail: string}
	 */
	private static function probe( string $url ): array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'cookies'     => array(),
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
				'headers'     => array(
					'Content-Type'               => 'application/json',
					RestController::NONCE_HEADER => 'bapi-forms-compat-check',
				),
				'body'        => wp_json_encode( array( 'product' => 'compat_check' ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'state'  => 'unknown',
				'detail' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$err  = is_array( $body ) && isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '';

		if ( 403 === $code && 'bapi_forms_nonce' === $err ) {
			return array(
				'state'  => 'ok',
				'detail' => '',
			);
		}

		// HTTP authentication on the whole site (staging): visitors cannot reach it either way.
		if ( 401 === $code && '' !== (string) wp_remote_retrieve_header( $response, 'www-authenticate' ) && '' === $err ) {
			return array(
				'state'  => 'unknown',
				'detail' => 'HTTP 401 (password protected site)',
			);
		}

		return array(
			'state'  => 'blocked',
			'detail' => trim( 'HTTP ' . $code . ( '' !== $err ? ' ' . sanitize_key( $err ) : '' ) ),
		);
	}

	/**
	 * A proxy header on this request that the Trusted proxy header setting ignores, so the rate
	 * limit counts the proxy instead of the visitor.
	 *
	 * @return array{header: string, chain: int}|null Chain: addresses in X-Forwarded-For.
	 */
	public function proxy_advice(): ?array {
		$current = $this->options->proxy_header();
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return 'CF-Connecting-IP' === $current ? null : array(
				'header' => 'CF-Connecting-IP',
				'chain'  => 1,
			);
		}
		if ( '' !== $current ) {
			return null; // The partner chose one already.
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = count( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ), 'strlen' ) );
			return array(
				'header' => 'X-Forwarded-For',
				'chain'  => max( 1, $chain ),
			);
		}
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			return array(
				'header' => 'X-Real-IP',
				'chain'  => 1,
			);
		}
		return null;
	}

	/**
	 * Proxy advice as one sentence.
	 *
	 * @param array{header: string, chain: int} $advice From proxy_advice().
	 */
	public function proxy_message( array $advice ): string {
		$message = sprintf(
			/* translators: %s: HTTP header name. */
			__( 'Your site seems to be behind a proxy or CDN (requests carry %1$s), but BusinessAPI Forms counts visitors by the connection IP, so every visitor may share one limit. Set Trusted proxy header to %1$s on the Connection tab.', 'businessapi-forms' ),
			$advice['header']
		);
		if ( 'X-Forwarded-For' === $advice['header'] ) {
			$message .= ' ' . __( 'Set Trusted proxies to the number of proxies you run in front of the site (usually 1).', 'businessapi-forms' );
		}
		return $message . ' ' . __( 'Only do this if every request passes through that proxy: otherwise visitors can fake the header to dodge the limit.', 'businessapi-forms' );
	}

	/**
	 * Blocked REST check as one sentence.
	 *
	 * @param array{state: string, detail: string, fallback: ?bool} $check From rest_check().
	 */
	public function rest_message( array $check ): string {
		$message = sprintf(
			/* translators: 1: REST namespace, 2: HTTP status and error code, 3: plugin names. */
			__( 'Visitors cannot reach the REST API route %1$s (%2$s). Allow-list the %1$s namespace for visitors in the plugin or firewall that blocks it (common: %3$s).', 'businessapi-forms' ),
			'/wp-json/' . RestController::NAMESPACE . '/',
			$check['detail'],
			self::REST_BLOCKERS
		);
		if ( true === $check['fallback'] ) {
			return $message . ' ' . __( 'Forms still work through a slower fallback (admin-ajax.php) meanwhile.', 'businessapi-forms' );
		}
		return $message . ' ' . __( 'admin-ajax.php is blocked too, so forms cannot start until one of them is allowed.', 'businessapi-forms' );
	}
}
