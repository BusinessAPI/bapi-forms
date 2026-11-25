<?php
/**
 * Daily plugin integrity self-check against the sha256 file hashes Business API publishes for
 * each released version (the WordPress.org release zip). A failure is reported to Business API,
 * which pauses form sessions for this site until a clean check; unpublished versions pause live only.
 *
 * @package BapiForms
 */

namespace BapiForms\Security;

use BapiForms\Api\Client;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Integrity {

	public const OPTION     = 'bapi_forms_integrity';
	public const HOOK_CHECK = 'bapi_forms_integrity_check';
	public const GROUP      = \BapiForms\Scheduler::GROUP;

	public const CLEAN   = 'clean';
	public const FAILED  = 'failed';
	public const UNKNOWN = 'unknown';
	public const BLOCKED = 'blocked';
	/** Business API could not be asked (no key, offline): nothing reported. */
	public const UNCHECKED = 'unchecked';

	/** Local files that are never shipped and harmless. */
	private const IGNORE = array( '.DS_Store' );

	public function __construct( private readonly Client $client, private readonly Options $options ) {}

	public function register(): void {
		add_action( self::HOOK_CHECK, array( $this, 'run' ) );
		add_action( 'action_scheduler_init', array( $this, 'schedule' ) );
	}

	/** Keep the daily check scheduled (deactivation clears it) and check at once after an update. */
	public function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$last = $this->last();
		if ( get_transient( 'bapi_forms_integrity_sched' ) && BAPI_FORMS_VERSION === ( $last['version'] ?? '' ) ) {
			return; // Checked within the hour.
		}
		set_transient( 'bapi_forms_integrity_sched', 1, HOUR_IN_SECONDS );
		if ( ! as_has_scheduled_action( self::HOOK_CHECK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_CHECK, array(), self::GROUP );
		}
		if ( isset( $last['version'] ) && BAPI_FORMS_VERSION !== $last['version'] && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_CHECK, array( 'version-changed' ), self::GROUP );
			$last['version'] = BAPI_FORMS_VERSION; // Queued once.
			update_option( self::OPTION, $last, false );
		}
	}

	/**
	 * @return array{status?: string, version?: string, checked_at?: int, mismatched?: list<string>, missing?: list<string>, unexpected?: list<string>, error?: string, sessions_allowed?: bool|null}
	 */
	public function last(): array {
		$last = get_option( self::OPTION, array() );
		return is_array( $last ) ? $last : array();
	}

	/** E.g. after switching environment. */
	public function queue_now(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_CHECK, array( 'requested' ), self::GROUP );
		}
	}

	public function failed(): bool {
		return self::FAILED === ( $this->last()['status'] ?? '' );
	}

	/**
	 * Run the check for the current environment and report it to Business API.
	 *
	 * @return array<string, mixed> The stored result.
	 */
	public function run(): array {
		$environment = $this->options->environment();
		$previous    = $this->last();
		$result      = array(
			'version'     => BAPI_FORMS_VERSION,
			'environment' => $environment,
			'checked_at'  => time(),
		);

		$response = $this->options->has_key() ? $this->client->get( 'wordpress/plugin-hashes', array( 'version' => BAPI_FORMS_VERSION ) ) : null;
		if ( null === $response || ! $response->ok() ) {
			$result['status'] = self::UNCHECKED;
			$result['error']  = null === $response ? __( 'No API key for this environment.', 'businessapi-forms' ) : (string) $response->error;
			update_option( self::OPTION, $result, false );
			return $result;
		}

		$status = (string) ( $response->data['status'] ?? '' );
		$files  = is_array( $response->data['files'] ?? null ) ? $response->data['files'] : array();

		if ( 'blocked' === $status ) {
			$result['status'] = self::BLOCKED;
		} elseif ( 'known' !== $status || array() === $files ) {
			$result['status'] = self::UNKNOWN;
		} else {
			$result = array_merge( $result, self::compare( BAPI_FORMS_DIR, $files ) );
		}

		// BAPI only understands clean / failed / unknown (a blocked version is refused there anyway).
		$report                     = self::BLOCKED === $result['status'] ? self::UNKNOWN : $result['status'];
		$sent                       = $this->client->post(
			'wordpress/integrity',
			array(
				'host'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'version'    => BAPI_FORMS_VERSION,
				'status'     => $report,
				'mismatched' => array_slice( $result['mismatched'] ?? array(), 0, 50 ),
				'missing'    => array_slice( $result['missing'] ?? array(), 0, 50 ),
				'unexpected' => array_slice( $result['unexpected'] ?? array(), 0, 50 ),
			)
		);
		$result['reported']         = $sent->ok();
		$result['sessions_allowed'] = $sent->ok() ? (bool) ( $sent->data['sessionsAllowed'] ?? true ) : null;
		if ( ! $sent->ok() ) {
			$result['error'] = (string) $sent->error;
		}

		update_option( self::OPTION, $result, false );

		if ( self::FAILED === $result['status'] ) {
			$this->on_failure( $result, $previous );
		}

		return $result;
	}

	/**
	 * @param string                $dir   Plugin folder.
	 * @param array<string, string> $files Relative path => sha256.
	 * @return array{status: string, mismatched: list<string>, missing: list<string>, unexpected: list<string>}
	 */
	public static function compare( string $dir, array $files ): array {
		$dir        = trailingslashit( wp_normalize_path( $dir ) );
		$mismatched = array();
		$missing    = array();
		foreach ( $files as $path => $hash ) {
			$path = (string) $path;
			if ( str_contains( $path, '..' ) ) {
				continue;
			}
			$full = $dir . $path;
			if ( ! is_file( $full ) ) {
				$missing[] = $path;
			} elseif ( ! hash_equals( strtolower( (string) $hash ), (string) hash_file( 'sha256', $full ) ) ) {
				$mismatched[] = $path;
			}
		}

		$unexpected = array();
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || in_array( $file->getFilename(), self::IGNORE, true ) ) {
				continue;
			}
			$relative = substr( wp_normalize_path( $file->getPathname() ), strlen( $dir ) );
			if ( ! isset( $files[ $relative ] ) ) {
				$unexpected[] = $relative;
			}
		}
		sort( $unexpected );

		return array(
			'status'     => ( $mismatched || $missing || $unexpected ) ? self::FAILED : self::CLEAN,
			'mismatched' => $mismatched,
			'missing'    => $missing,
			'unexpected' => $unexpected,
		);
	}

	/**
	 * Email the site admin once per distinct result per day, and audit it.
	 *
	 * @param array<string, mixed> $result   This result.
	 * @param array<string, mixed> $previous Previous result.
	 */
	private function on_failure( array $result, array $previous ): void {
		$signature = md5( wp_json_encode( array( $result['mismatched'], $result['missing'], $result['unexpected'] ) ) );
		$same      = self::FAILED === ( $previous['status'] ?? '' ) && ( $previous['signature'] ?? '' ) === $signature;
		$recent    = (int) ( $previous['mailed_at'] ?? 0 ) > time() - DAY_IN_SECONDS;

		$result['signature'] = $signature;
		$result['mailed_at'] = $previous['mailed_at'] ?? 0;

		if ( ! ( $same && $recent ) ) {
			$count = count( $result['mismatched'] ) + count( $result['missing'] ) + count( $result['unexpected'] );
			$lines = array_merge(
				array_map( static fn( $p ) => 'Changed: ' . $p, array_slice( $result['mismatched'], 0, 20 ) ),
				array_map( static fn( $p ) => 'Missing: ' . $p, array_slice( $result['missing'], 0, 20 ) ),
				array_map( static fn( $p ) => 'Unexpected: ' . $p, array_slice( $result['unexpected'], 0, 20 ) )
			);
			wp_mail(
				(string) get_option( 'admin_email' ),
				/* translators: %s: site name. */
				sprintf( __( '[%s] BusinessAPI Forms failed its integrity check', 'businessapi-forms' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ),
				sprintf(
					/* translators: 1: number of files, 2: file list, 3: settings URL. */
					__( "The BusinessAPI Forms plugin files on your site differ from the official release (%1\$d files). This can mean the plugin was tampered with. Business API has paused your forms until the plugin is clean.\n\n%2\$s\n\nWhat to do: delete the plugin folder, install the official release from WordPress.org, then run the check again: %3\$s\n\nIf you did not change these files, also check your site for malware and change your administrator passwords.", 'businessapi-forms' ),
					$count,
					implode( "\n", $lines ),
					admin_url( 'admin.php?page=bapi-forms&tab=security' )
				)
			);
			$result['mailed_at'] = time();
			do_action(
				'bapi_forms_audit',
				'integrity_failure',
				array(
					'version' => BAPI_FORMS_VERSION,
					'files'   => $count,
				)
			);
		}
		update_option( self::OPTION, $result, false );
	}
}
