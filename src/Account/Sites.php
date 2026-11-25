<?php
/**
 * This site's registration with Business API (/embed-sites): the hostname allowed to embed forms
 * and the server IP allowed to use a live key. Live entries stay pending until the account owner
 * confirms by email; sandbox entries are active at once.
 *
 * @package BapiForms
 */

namespace BapiForms\Account;

use BapiForms\Api\Client;
use BapiForms\Api\Response;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Sites {

	/** Option holding the server IP BAPI last refused with ip_not_allowed (live only). */
	public const REFUSED_IP_OPTION = 'bapi_forms_ip_refused';

	public const ACTIVE         = 'active';
	public const PENDING        = 'pending';
	public const NOT_REGISTERED = 'not_registered';

	public function __construct( private readonly Client $client, private readonly Options $options ) {}

	public static function host(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * @return array{ok: bool, error: ?string, host: string, ip: string, domain_status: string, ip_status: string, site_id: ?int}
	 */
	public function status( ?string $environment = null ): array {
		$host     = self::host();
		$response = $this->client->get( 'embed-sites', array(), $environment );
		$out      = array(
			'ok'            => $response->ok(),
			'error'         => $response->error,
			'host'          => $host,
			'ip'            => '',
			'domain_status' => self::NOT_REGISTERED,
			'ip_status'     => self::NOT_REGISTERED,
			'site_id'       => null,
		);
		if ( ! $response->ok() ) {
			if ( 404 === $response->status ) {
				$out['error'] = Capabilities::forms_disabled_message();
			}
			return $out;
		}

		$ip        = (string) ( $response->data['callerIp'] ?? '' );
		$out['ip'] = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

		foreach ( (array) ( $response->data['sites'] ?? array() ) as $site ) {
			if ( ! is_array( $site ) || ! self::host_matches( $host, (string) ( $site['domain'] ?? '' ) ) ) {
				continue;
			}
			$exact = strtolower( (string) $site['domain'] ) === $host;
			if ( $exact || self::NOT_REGISTERED === $out['domain_status'] ) {
				$out['domain_status'] = self::normalise_status( (string) ( $site['status'] ?? '' ) );
				$out['site_id']       = isset( $site['id'] ) ? (int) $site['id'] : null;
			}
		}

		// The IP counts on any of the partner's sites (BAPI enforces the union).
		foreach ( (array) ( $response->data['sites'] ?? array() ) as $site ) {
			foreach ( (array) ( $site['ips'] ?? array() ) as $entry ) {
				if ( '' === $out['ip'] || ! is_array( $entry ) || ! self::ip_matches( $out['ip'], (string) ( $entry['ip'] ?? '' ) ) ) {
					continue;
				}
				$entry_status = self::ACTIVE === ( $site['status'] ?? '' ) || 'pending_removal' === ( $site['status'] ?? '' )
					? self::normalise_status( (string) ( $entry['status'] ?? '' ) )
					: self::PENDING;
				if ( self::ACTIVE === $entry_status || self::NOT_REGISTERED === $out['ip_status'] ) {
					$out['ip_status'] = $entry_status;
				}
			}
		}

		if ( self::ACTIVE === $out['ip_status'] && Options::ENV_LIVE === ( $environment ?? $this->options->environment() ) && false !== get_option( self::REFUSED_IP_OPTION ) ) {
			delete_option( self::REFUSED_IP_OPTION );
		}

		return $out;
	}

	/** BAPI only adds what is new and emails the account owner to confirm live entries. */
	public function register( ?string $environment = null ): Response {
		$current = $this->status( $environment );
		if ( ! $current['ok'] ) {
			return Response::error( 0, (string) $current['error'] );
		}

		return $this->client->post(
			'embed-sites',
			array(
				'domain' => $current['host'],
				'ips'    => '' !== $current['ip'] ? array( $current['ip'] ) : array(),
			),
			$environment
		);
	}

	/** Register only when neither the hostname nor the IP is registered yet. */
	public function register_if_missing( ?string $environment = null ): ?Response {
		$current = $this->status( $environment );
		if ( ! $current['ok'] || self::NOT_REGISTERED !== $current['domain_status'] || self::NOT_REGISTERED !== $current['ip_status'] ) {
			return null;
		}

		return $this->register( $environment );
	}

	/** Remember a live ip_not_allowed refusal (only the IP) for the admin notice. */
	public static function note_response( Response $response, string $environment ): void {
		if ( Options::ENV_LIVE !== $environment || 403 !== $response->status || 'ip_not_allowed' !== ( $response->data['code'] ?? null ) ) {
			return;
		}
		$ip    = (string) ( $response->data['callerIp'] ?? '' );
		$value = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '?';
		if ( get_option( self::REFUSED_IP_OPTION ) !== $value ) {
			update_option( self::REFUSED_IP_OPTION, $value, false );
		}
	}

	public static function refused_ip(): ?string {
		$ip = get_option( self::REFUSED_IP_OPTION, null );
		return is_string( $ip ) && '' !== $ip ? $ip : null;
	}

	private static function normalise_status( string $status ): string {
		return match ( $status ) {
			self::ACTIVE, 'pending_removal' => self::ACTIVE,
			self::PENDING                   => self::PENDING,
			default                         => self::NOT_REGISTERED,
		};
	}

	/** Exact host, or a *.example.com pattern covering a subdomain. */
	public static function host_matches( string $host, string $pattern ): bool {
		$pattern = strtolower( $pattern );
		if ( str_starts_with( $pattern, '*.' ) ) {
			return str_ends_with( $host, substr( $pattern, 1 ) );
		}
		return $host === $pattern;
	}

	/** IPv4/IPv6 address equals $entry, or falls inside $entry when it is a CIDR. */
	public static function ip_matches( string $ip, string $entry ): bool {
		$prefix = null;
		if ( str_contains( $entry, '/' ) ) {
			[ $entry, $prefix ] = explode( '/', $entry, 2 );
			$prefix             = (int) $prefix;
		}
		$a = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$b = @inet_pton( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits = null === $prefix ? strlen( $a ) * 8 : max( 0, min( $prefix, strlen( $a ) * 8 ) );
		$full = intdiv( $bits, 8 );
		if ( substr( $a, 0, $full ) !== substr( $b, 0, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $a[ $full ] ) & $mask ) === ( ord( $b[ $full ] ) & $mask );
	}
}
