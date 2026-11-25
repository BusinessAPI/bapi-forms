<?php
/**
 * Security checklist for the Security tab and Site Health. Detection only: we never ship a
 * firewall or scanner of our own.
 *
 * @package BapiForms
 */

namespace BapiForms\Security;

defined( 'ABSPATH' ) || exit;

final class Checklist {

	private const DEBUG_LOG_CACHE = 'bapi_forms_debuglog_check';

	/** Plugin basename => label. */
	public const SECURITY_PLUGINS = array(
		'wordfence/wordfence.php'                       => 'Wordfence',
		'better-wp-security/better-wp-security.php'     => 'Solid Security',
		'ithemes-security-pro/ithemes-security-pro.php' => 'Solid Security Pro',
		'sucuri-scanner/sucuri.php'                     => 'Sucuri Security',
		'patchstack/patchstack.php'                     => 'Patchstack',
		'all-in-one-wp-security-and-firewall/wp-security.php' => 'All-In-One Security',
		'jetpack-protect/jetpack-protect.php'           => 'Jetpack Protect',
		'malcare-security/malcare.php'                  => 'MalCare',
		'wp-simple-firewall/icwp-wpsf.php'              => 'Shield Security',
		'wp-cerber/wp-cerber.php'                       => 'WP Cerber',
		'defender-security/wp-defender.php'             => 'Defender',
		'bulletproof-security/bulletproof-security.php' => 'BulletProof Security',
	);

	/** Login rate limiters (security plugins count too): basename => label. */
	public const LOGIN_LIMITERS = array(
		'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' => 'Limit Login Attempts Reloaded',
		'loginizer/loginizer.php'             => 'Loginizer',
		'login-lockdown/loginlockdown.php'    => 'Login Lockdown',
		'wps-limit-login/wps-limit-login.php' => 'WPS Limit Login',
	);

	/** Recommended when no security plugin is active. */
	public const RECOMMENDED = array( 'Wordfence', 'Solid Security', 'Sucuri Security', 'Patchstack' );

	/**
	 * @return array<string, array{label: string, required: bool, pass: bool, detail: string}>
	 */
	public function results(): array {
		$security = $this->active_from( self::SECURITY_PLUGINS );
		$limiters = array_merge( $security, $this->active_from( self::LOGIN_LIMITERS ) );

		return array(
			'https'         => array(
				'label'    => __( 'HTTPS', 'businessapi-forms' ),
				'required' => true,
				'pass'     => $this->https(),
				'detail'   => __( 'Your site address (Settings > General) must start with https:// and the certificate must be valid.', 'businessapi-forms' ),
			),
			'php'           => array(
				'label'    => __( 'Supported PHP', 'businessapi-forms' ),
				'required' => true,
				'pass'     => version_compare( PHP_VERSION, '8.1', '>=' ),
				/* translators: %s: PHP version. */
				'detail'   => sprintf( __( 'PHP 8.1 or newer is required (this server runs %s). Ask your host to upgrade.', 'businessapi-forms' ), PHP_VERSION ),
			),
			'debug_display' => array(
				'label'    => __( 'Errors not shown to visitors', 'businessapi-forms' ),
				'required' => true,
				'pass'     => ! $this->displays_errors(),
				'detail'   => __( 'Set WP_DEBUG_DISPLAY to false (or WP_DEBUG to false) in wp-config.php, and display_errors off in PHP.', 'businessapi-forms' ),
			),
			'debug_log'     => array(
				'label'    => __( 'debug.log not public', 'businessapi-forms' ),
				'required' => true,
				'pass'     => ! $this->debug_log_public(),
				'detail'   => __( 'wp-content/debug.log can be downloaded by anyone. Delete it and block it in your web server, or set WP_DEBUG_LOG to a path outside the web root.', 'businessapi-forms' ),
			),
			'file_edit'     => array(
				'label'    => __( 'File editor disabled', 'businessapi-forms' ),
				'required' => false,
				'pass'     => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
				'detail'   => __( "Add define( 'DISALLOW_FILE_EDIT', true ); to wp-config.php so a stolen admin login cannot edit plugin code.", 'businessapi-forms' ),
			),
			'admin_user'    => array(
				'label'    => __( 'No "admin" username', 'businessapi-forms' ),
				'required' => false,
				'pass'     => ! username_exists( 'admin' ),
				'detail'   => __( 'A user called "admin" is the first name attackers guess. Create a new administrator and delete it (assign its content to the new user).', 'businessapi-forms' ),
			),
			'xmlrpc'        => array(
				'label'    => __( 'XML-RPC disabled', 'businessapi-forms' ),
				'required' => false,
				'pass'     => ! apply_filters( 'xmlrpc_enabled', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
				'detail'   => __( 'XML-RPC allows password guessing at scale. Turn it off in your security plugin or block xmlrpc.php in the web server (keep it only if an app needs it).', 'businessapi-forms' ),
			),
			'auto_update'   => array(
				'label'    => __( 'Auto-updates for BusinessAPI Forms', 'businessapi-forms' ),
				'required' => false,
				'pass'     => in_array( plugin_basename( BAPI_FORMS_FILE ), (array) get_site_option( 'auto_update_plugins', array() ), true ),
				'detail'   => __( 'Enable auto-updates for BusinessAPI Forms on the Plugins screen so security fixes arrive without delay.', 'businessapi-forms' ),
			),
			'login_limit'   => array(
				'label'    => __( 'Login rate limiting', 'businessapi-forms' ),
				'required' => false,
				'pass'     => array() !== $limiters || (bool) apply_filters( 'bapi_forms_login_rate_limited', false ),
				'detail'   => array() !== $limiters
					/* translators: %s: plugin names. */
					? sprintf( __( 'Provided by %s.', 'businessapi-forms' ), implode( ', ', array_unique( $limiters ) ) )
					: __( 'Limit failed logins with your security plugin or a plugin such as Limit Login Attempts Reloaded.', 'businessapi-forms' ),
			),
			'security'      => array(
				'label'    => __( 'Security plugin', 'businessapi-forms' ),
				'required' => false,
				'pass'     => array() !== $security,
				'detail'   => array() !== $security
					/* translators: %s: plugin names. */
					? sprintf( __( 'Active: %s.', 'businessapi-forms' ), implode( ', ', array_unique( $security ) ) )
					/* translators: %s: plugin names. */
					: sprintf( __( 'No firewall or malware scanner found. We recommend one of: %s.', 'businessapi-forms' ), implode( ', ', self::RECOMMENDED ) ),
			),
		) + $this->compatibility();
	}

	/**
	 * Advisory compatibility checks: REST API reachable by visitors, reverse proxy header.
	 *
	 * @return array<string, array{label: string, required: bool, pass: bool, detail: string, badge: string}>
	 */
	private function compatibility(): array {
		$compat = new \BapiForms\Admin\Compatibility( \BapiForms\Plugin::instance()->options );
		$rest   = $compat->rest_check();
		$proxy  = $compat->proxy_advice();

		return array(
			'rest_api'     => array(
				'label'    => __( 'Form routes reachable by visitors', 'businessapi-forms' ),
				'required' => false,
				'pass'     => 'blocked' !== $rest['state'],
				'detail'   => 'blocked' === $rest['state']
					? $compat->rest_message( $rest )
					: ( 'unknown' === $rest['state']
						/* translators: %s: error detail. */
						? sprintf( __( 'Could not test the REST API from the server itself (%s). If forms do not start for visitors, check that nothing blocks /wp-json/bapi-forms/v1/.', 'businessapi-forms' ), $rest['detail'] )
						: __( 'Visitors can reach the /wp-json/bapi-forms/v1/ routes.', 'businessapi-forms' ) ),
				'badge'    => __( 'Compatibility', 'businessapi-forms' ),
			),
			'proxy_header' => array(
				'label'    => __( 'Visitor IP behind a proxy or CDN', 'businessapi-forms' ),
				'required' => false,
				'pass'     => null === $proxy,
				'detail'   => null !== $proxy ? $compat->proxy_message( $proxy ) : __( 'No proxy header to trust was detected, or the matching Trusted proxy header is set.', 'businessapi-forms' ),
				'badge'    => __( 'Compatibility', 'businessapi-forms' ),
			),
		);
	}

	/**
	 * Failing required checks (they block switching live on).
	 *
	 * @return array<string, string> Key => label.
	 */
	public function failing_required(): array {
		$out = array();
		foreach ( $this->results() as $key => $check ) {
			if ( $check['required'] && ! $check['pass'] ) {
				$out[ $key ] = $check['label'];
			}
		}
		return $out;
	}

	public function https(): bool {
		return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) && 'https' === wp_parse_url( site_url(), PHP_URL_SCHEME );
	}

	public function displays_errors(): bool {
		$wp  = defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
		$ini = ini_get( 'display_errors' );
		return $wp || in_array( strtolower( (string) $ini ), array( '1', 'on', 'yes', 'true', 'stdout' ), true );
	}

	/** HEAD request to the debug log URL when it sits inside the web root. Cached for 6 hours. */
	public function debug_log_public( bool $fresh = false ): bool {
		$cached = $fresh ? false : get_transient( self::DEBUG_LOG_CACHE );
		if ( is_array( $cached ) ) {
			return ! empty( $cached['public'] );
		}

		$url = content_url( 'debug.log' );
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			$path = wp_normalize_path( WP_DEBUG_LOG );
			$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
			$url  = str_starts_with( $path, $root . '/' ) ? site_url( substr( $path, strlen( $root ) ) ) : '';
		}

		$public = false;
		if ( '' !== $url ) {
			$response = wp_remote_head(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
				)
			);
			$public   = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
		}
		set_transient( self::DEBUG_LOG_CACHE, array( 'public' => $public ), 6 * HOUR_IN_SECONDS );
		return $public;
	}

	/**
	 * @param array<string, string> $known Basename => label.
	 * @return list<string> Labels of the active ones.
	 */
	private function active_from( array $known ): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_intersect_key( $known, array_flip( $active ) ) );
	}

	/**
	 * Tools > Site Health direct tests.
	 *
	 * @param array<string, mixed> $tests Tests.
	 */
	public function site_health( $tests ): array {
		$tests = (array) $tests;
		foreach ( array_keys( $this->results() ) as $key ) {
			$tests['direct'][ 'bapi_forms_' . $key ] = array(
				'label' => __( 'BusinessAPI Forms site check', 'businessapi-forms' ),
				'test'  => fn(): array => $this->site_health_result( $key ),
			);
		}
		return $tests;
	}

	private function site_health_result( string $key ): array {
		$check = $this->results()[ $key ];
		$live  = $check['required'] ? __( 'Required before BusinessAPI Forms can go live.', 'businessapi-forms' ) : __( 'Recommended.', 'businessapi-forms' );
		return array(
			/* translators: %s: check name. */
			'label'       => $check['pass'] ? sprintf( __( 'BusinessAPI Forms: %s', 'businessapi-forms' ), $check['label'] ) : sprintf( __( 'BusinessAPI Forms check needs attention: %s', 'businessapi-forms' ), $check['label'] ),
			'status'      => $check['pass'] ? 'good' : ( $check['required'] ? 'critical' : 'recommended' ),
			'badge'       => array(
				'label' => $check['badge'] ?? __( 'Security', 'businessapi-forms' ),
				'color' => $check['pass'] ? 'blue' : ( $check['required'] ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $check['pass'] ? $check['label'] : $check['detail'] . ' ' . $live ) . '</p>',
			'actions'     => '',
			'test'        => 'bapi_forms_' . $key,
		);
	}
}
