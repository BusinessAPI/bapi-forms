<?php
/**
 * Partner settings. Applicant data is never stored here or anywhere else in WordPress.
 *
 * @package BapiForms
 */

namespace BapiForms\Settings;

defined( 'ABSPATH' ) || exit;

final class Options {

	public const OPTION = 'bapi_forms_settings';

	public const ENV_TEST = 'test';
	public const ENV_LIVE = 'live';

	public const FLOW_FORM_FIRST = 'form_first';
	public const FLOW_PAY_FIRST  = 'pay_first';

	public const NOTICES_CLIENT  = 'client';
	public const NOTICES_PARTNER = 'partner';

	public const STATES = array( 'ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA' );

	private const NOTICES_DEFAULTS = array(
		'mode'          => self::NOTICES_CLIENT,
		'email'         => '',
		'mobile'        => '',
		'unit'          => '',
		'street_number' => '',
		'street_name'   => '',
		'street_type'   => '',
		'suburb'        => '',
		'state'         => '',
		'postcode'      => '',
	);

	/** Installed on most devices; anything else falls back to system-ui. */
	public const SAFE_FONTS = array( 'Arial', 'Helvetica', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Georgia' );

	public const BRANDING_DEFAULTS = array(
		'primary'    => '',
		'radius'     => '',
		'font'       => '',
		// '' (APCA recommendation), WHITE_TEXT or DARK_TEXT.
		'on_primary' => '',
	);

	private const DEFAULTS = array(
		'environment'     => self::ENV_TEST,
		'test_key'        => '',
		'live_key'        => '',
		'products'        => array(),
		'asic_notices'    => self::NOTICES_DEFAULTS,
		'flows'           => array(),
		'branding'        => self::BRANDING_DEFAULTS,
		'session_cap'     => self::DEFAULT_SESSION_CAP,
		'proxy_header'    => '',
		// X-Forwarded-For only: trusted proxies appending to it.
		'proxy_hops'      => 1,
		// Service fees only; government fees always come from BAPI's schedule.
		'pricing'         => array(),
		'release_offline' => false,
	);

	/** New form sessions per hour, site-wide. */
	public const DEFAULT_SESSION_CAP = 200;

	/** Trusted only when the site sits behind that proxy. */
	public const PROXY_HEADERS = array(
		'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP',
		'X-Real-IP'        => 'HTTP_X_REAL_IP',
		'X-Forwarded-For'  => 'HTTP_X_FORWARDED_FOR',
	);

	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	public function update( array $settings ): void {
		foreach ( array( 'test_key', 'live_key' ) as $field ) {
			if ( isset( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
				$settings[ $field ] = \BapiForms\Security\KeyVault::encrypt( $settings[ $field ] );
			}
		}
		update_option( self::OPTION, array_merge( $this->all(), $settings ), false );
	}

	/** The stored key cannot be decrypted (wp-config salts changed), so it must be pasted again. */
	public function key_unreadable( string $environment ): bool {
		if ( $this->key_is_constant( $environment ) ) {
			return false;
		}
		$stored = (string) ( $this->all()[ $environment . '_key' ] ?? '' );
		return '' !== $stored && null === \BapiForms\Security\KeyVault::decrypt( $stored );
	}

	public function environment(): string {
		return self::ENV_LIVE === $this->all()['environment'] ? self::ENV_LIVE : self::ENV_TEST;
	}

	public function is_live(): bool {
		return self::ENV_LIVE === $this->environment();
	}

	/** BAPI_FORMS_TEST_KEY / BAPI_FORMS_LIVE_KEY in wp-config.php win over the stored key. */
	public function key( ?string $environment = null ): string {
		$environment = $environment ?? $this->environment();
		$constant    = self::ENV_LIVE === $environment ? 'BAPI_FORMS_LIVE_KEY' : 'BAPI_FORMS_TEST_KEY';

		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
			return constant( $constant );
		}

		return (string) \BapiForms\Security\KeyVault::decrypt( (string) $this->all()[ $environment . '_key' ] );
	}

	public function key_is_constant( string $environment ): bool {
		$constant = self::ENV_LIVE === $environment ? 'BAPI_FORMS_LIVE_KEY' : 'BAPI_FORMS_TEST_KEY';
		return defined( $constant ) && '' !== (string) constant( $constant );
	}

	public function has_key( ?string $environment = null ): bool {
		return '' !== $this->key( $environment );
	}

	/**
	 * @return array<string, array{service: float}>
	 */
	public function pricing(): array {
		$stored = $this->all()['pricing'];
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $slug => $row ) {
			$out[ (string) $slug ] = array(
				'service' => is_array( $row ) && is_numeric( $row['service'] ?? null ) ? max( 0.0, round( (float) $row['service'], 2 ) ) : 0.0,
			);
		}
		return $out;
	}

	/** Release bank transfer and cheque orders once marked paid. Cash on delivery never is. */
	public function release_offline(): bool {
		return true === $this->all()['release_offline'];
	}

	/** Dollars, 0 when not set. */
	public function service_fee( string $slug ): float {
		return $this->pricing()[ $slug ]['service'] ?? 0.0;
	}

	/**
	 * Switched on by the partner, before live eligibility is applied.
	 *
	 * @return array<string, bool>
	 */
	public function enabled_products(): array {
		$products = $this->all()['products'];
		return is_array( $products ) ? array_map( 'boolval', $products ) : array();
	}

	/**
	 * Who receives ASIC notices, with the partner's service details for "partner" mode.
	 *
	 * @return array<string, string>
	 */
	public function asic_notices(): array {
		$stored = $this->all()['asic_notices'];
		return array_merge( self::NOTICES_DEFAULTS, is_array( $stored ) ? array_map( 'strval', $stored ) : array() );
	}

	public function notices_to_partner(): bool {
		return self::NOTICES_PARTNER === $this->asic_notices()['mode'];
	}

	/**
	 * Partner details only; the applicant's own are collected in the form.
	 *
	 * @return array<string, mixed>
	 */
	public function asic_notices_for_bapi(): array {
		if ( ! $this->notices_to_partner() ) {
			return array( 'mode' => self::NOTICES_CLIENT );
		}

		$notices = $this->asic_notices();

		return array(
			'mode'    => self::NOTICES_PARTNER,
			'email'   => $notices['email'],
			'mobile'  => $notices['mobile'],
			'address' => array(
				'unitOrOfficeNumber' => $notices['unit'],
				'streetNumber'       => $notices['street_number'],
				'streetName'         => $notices['street_name'],
				'streetType'         => $notices['street_type'],
				'locality'           => $notices['suburb'],
				'state'              => $notices['state'],
				'postCode'           => $notices['postcode'],
			),
		);
	}

	/** Form first (default) or pay first. An add-on follows its parent product. */
	public function flow( string $slug = '' ): string {
		if ( '' === $slug ) {
			return self::FLOW_FORM_FIRST;
		}
		$parent = self::PARENTS[ $slug ] ?? null;
		if ( null !== $parent ) {
			$slug = $parent;
		}
		$flows = $this->all()['flows'];
		return is_array( $flows ) && self::FLOW_PAY_FIRST === ( $flows[ $slug ] ?? null ) ? self::FLOW_PAY_FIRST : self::FLOW_FORM_FIRST;
	}

	public const PARENTS = array( 'abn_addon' => 'company_registration' );

	/**
	 * Mirrors BAPI's business name rules.
	 *
	 * @param array<string, string> $details Sanitised details.
	 * @return list<string> Error messages, empty when valid.
	 */
	public static function validate_service_details( array $details ): array {
		$errors = array();

		if ( ! is_email( $details['email'] ) ) {
			$errors[] = __( 'Service email must be a valid email address.', 'businessapi-forms' );
		}
		if ( '' !== $details['mobile'] && 1 !== preg_match( '/^(\+?61|0)4\d{8}$/', $details['mobile'] ) ) {
			$errors[] = __( 'Service mobile must be an Australian mobile number, e.g. 0412 345 678.', 'businessapi-forms' );
		}
		if ( '' === $details['street_name'] || strlen( $details['street_name'] ) > 75 ) {
			$errors[] = __( 'Street name is required (max 75 characters).', 'businessapi-forms' );
		}
		if ( '' === $details['street_number'] ) {
			$errors[] = __( 'Street number is required.', 'businessapi-forms' );
		}
		if ( '' === $details['suburb'] || strlen( $details['suburb'] ) > 75 ) {
			$errors[] = __( 'Suburb is required (max 75 characters).', 'businessapi-forms' );
		}
		if ( ! in_array( $details['state'], self::STATES, true ) ) {
			$errors[] = __( 'Choose a state or territory.', 'businessapi-forms' );
		}
		if ( 1 !== preg_match( '/^\d{4}$/', $details['postcode'] ) ) {
			$errors[] = __( 'Postcode must be 4 digits.', 'businessapi-forms' );
		}

		return $errors;
	}

	/** 0 = no cap. */
	public function session_cap(): int {
		return max( 0, (int) $this->all()['session_cap'] );
	}

	public const MAX_PROXY_HOPS = 5;

	/** '' means REMOTE_ADDR only. */
	public function proxy_header(): string {
		$header = (string) $this->all()['proxy_header'];
		return isset( self::PROXY_HEADERS[ $header ] ) ? $header : '';
	}

	public function proxy_hops(): int {
		return min( self::MAX_PROXY_HOPS, max( 1, (int) ( $this->all()['proxy_hops'] ?? 1 ) ) );
	}

	/**
	 * Visitor IP for rate limiting: REMOTE_ADDR, or the trusted proxy header when enabled.
	 *
	 * Entries left of what the trusted proxies appended to X-Forwarded-For can be typed by the
	 * visitor, so with N trusted proxies the visitor is the N-th entry from the right.
	 */
	public function client_ip(): string {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$header = $this->proxy_header();
		if ( '' !== $header ) {
			$raw       = isset( $_SERVER[ self::PROXY_HEADERS[ $header ] ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ self::PROXY_HEADERS[ $header ] ] ) ) : '';
			$parts     = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
			$hops      = 'X-Forwarded-For' === $header ? $this->proxy_hops() : 1;
			$candidate = $parts ? (string) $parts[ max( 0, count( $parts ) - $hops ) ] : '';
			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}
		return $ip;
	}

	/**
	 * Hosted form appearance as stored. Empty values mean BAPI's default.
	 *
	 * @return array{primary: string, radius: string, font: string, on_primary: string}
	 */
	public function branding(): array {
		$stored = $this->all()['branding'];
		$merged = array_merge( self::BRANDING_DEFAULTS, is_array( $stored ) ? array_map( 'strval', $stored ) : array() );
		return array_intersect_key( $merged, self::BRANDING_DEFAULTS );
	}

	/**
	 * options.branding for BAPI, set values only.
	 *
	 * @return array<string, string|int>
	 */
	public function branding_for_bapi(): array {
		[ $clean ] = self::validate_branding( $this->branding() );
		$out       = array();
		if ( '' !== $clean['primary'] ) {
			$out['primary'] = $clean['primary'];
		}
		if ( '' !== $clean['radius'] ) {
			$out['radius'] = (int) $clean['radius'];
		}
		if ( '' !== $clean['font'] ) {
			$out['font'] = $clean['font'];
		}
		// Only an explicit choice: BAPI applies its own APCA recommendation otherwise.
		if ( '' !== $clean['primary'] && '' !== $clean['on_primary'] ) {
			$out['onPrimary'] = $clean['on_primary'];
		}
		return $out;
	}

	/**
	 * Invalid values are blanked (BAPI default).
	 *
	 * @param array<string, mixed> $input Raw values (primary, radius, font, on_primary).
	 * @return array{0: array{primary: string, radius: string, font: string, on_primary: string}, 1: list<string>}
	 */
	public static function validate_branding( array $input ): array {
		$clean  = self::BRANDING_DEFAULTS;
		$errors = array();

		$primary = is_string( $input['primary'] ?? null ) ? trim( $input['primary'] ) : '';
		if ( '' !== $primary ) {
			$hex = sanitize_hex_color( '#' . ltrim( $primary, '#' ) );
			if ( is_string( $hex ) && 7 === strlen( $hex ) ) {
				$clean['primary'] = strtolower( $hex );
			} else {
				$errors[] = __( 'Accent colour must be a 6-digit hex colour, e.g. #1d4ed8.', 'businessapi-forms' );
			}
		}

		$radius = isset( $input['radius'] ) && is_scalar( $input['radius'] ) ? trim( (string) $input['radius'] ) : '';
		if ( '' !== $radius ) {
			if ( 1 === preg_match( '/^\d{1,2}$/', $radius ) && (int) $radius <= 24 ) {
				$clean['radius'] = (string) (int) $radius;
			} else {
				$errors[] = __( 'Corner radius must be a whole number from 0 to 24.', 'businessapi-forms' );
			}
		}

		$font = is_string( $input['font'] ?? null ) ? trim( $input['font'] ) : '';
		if ( '' !== $font ) {
			if ( in_array( $font, self::SAFE_FONTS, true ) && 1 === preg_match( '/^[A-Za-z0-9 ]{1,40}$/', $font ) ) {
				$clean['font'] = $font;
			} else {
				$errors[] = __( 'Choose a font from the list.', 'businessapi-forms' );
			}
		}

		$on_primary = is_string( $input['on_primary'] ?? null ) ? strtolower( trim( $input['on_primary'] ) ) : '';
		if ( '' !== $on_primary ) {
			if ( in_array( $on_primary, array( self::WHITE_TEXT, self::DARK_TEXT ), true ) ) {
				$clean['on_primary'] = $on_primary;
			} else {
				$errors[] = __( 'Button text must be White or Dark.', 'businessapi-forms' );
			}
		}

		return array( $clean, $errors );
	}

	public const WHITE_TEXT = '#ffffff';

	/** Tailwind gray-900, as in Business API's forms. */
	public const DARK_TEXT = '#111827';

	/** White wins unless dark text is this many Lc ahead. Must match BAPI Branding::APCA_WHITE_BIAS (used by the settings preview). */
	public const APCA_WHITE_BIAS = 5.0;

	/** bapi_sk_(test|live)_<48 hex>. */
	public static function key_matches_environment( string $key, string $environment ): bool {
		return 1 === preg_match( '/^bapi_sk_' . preg_quote( $environment, '/' ) . '_[a-f0-9]{48}$/', $key );
	}

	public static function mask_key( string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		return substr( $key, 0, 13 ) . str_repeat( '*', 8 ) . substr( $key, -4 );
	}
}
