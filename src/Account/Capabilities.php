<?php
/**
 * Partner account status and per-product live eligibility from GET /account-status (`products`
 * block). Fails closed: without that block live products stay off. The `wordpress` block is only
 * there once WordPress forms are on for the account (onboarding submitted with the terms).
 *
 * @package BapiForms
 */

namespace BapiForms\Account;

use BapiForms\Api\Client;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	private const CACHE_TTL   = 10 * MINUTE_IN_SECONDS;
	private const FAILURE_TTL = MINUTE_IN_SECONDS;

	/** The last good status stands in for this long while a refresh runs or BAPI is unreachable. */
	private const MAX_STALE = DAY_IN_SECONDS;

	/** account-status is read while a visitor or admin waits: never hang the page on it. */
	private const TIMEOUT = 5;

	/** Background refresh (Action Scheduler, else WP-Cron). Arg: environment. */
	public const HOOK_REFRESH = 'bapi_forms_refresh_account_status';

	private const GROUP = \BapiForms\Scheduler::GROUP;

	/** Option per environment (not a transient: an object cache may drop transients early). */
	private const OPTION_PREFIX = 'bapi_forms_account_';

	public const REGISTER_URL = 'https://businessapi.com.au/register';

	/** Shown when Business API answers as if WordPress forms were off for the account (no `wordpress` block, 404 on embed-sites or form-sessions). */
	public static function forms_disabled_message(): string {
		return __( 'WordPress forms are not on for this Business API account yet. In your Business API dashboard go to Onboarding, choose "Forms on my WordPress site", agree to the terms and submit. Sandbox works straight away.', 'businessapi-forms' );
	}

	public function __construct(
		private readonly Client $client,
		private readonly Options $options,
	) {}

	public function register(): void {
		add_action( self::HOOK_REFRESH, array( $this, 'refresh_in_background' ) );
	}

	/**
	 * Cached account status. A stale entry (up to a day old) is served while a background job
	 * refreshes it; BAPI is only asked inline when nothing usable is stored. Temporary failures
	 * keep the last good status ("stale"); an auth error replaces it at once.
	 *
	 * @param bool $refresh Ask BAPI now (admin "Refresh", background job).
	 * @return array{ok: bool, error: ?string, account: array, products: ?array, forms_enabled?: bool, checked_at: int, stale?: bool}
	 */
	public function status( ?string $environment = null, bool $refresh = false ): array {
		$environment = $environment ?? $this->options->environment();
		$entry       = $this->entry( $environment );

		if ( ! $refresh && null !== $entry ) {
			if ( (int) $entry['expires'] > time() ) {
				return $this->effective( $entry );
			}
			if ( $this->usable_good( $entry ) ) {
				// Stale: answer with the last good status now, refresh in the background.
				$entry['expires'] = time() + self::FAILURE_TTL; // One background job at a time.
				$this->save( $environment, $entry );
				$this->schedule_refresh( $environment );
				return $entry['good'] + array( 'stale' => true );
			}
		}

		return $this->fetch( $environment, $entry );
	}

	public function refresh_in_background( $environment = null ): void {
		$environment = Options::ENV_LIVE === $environment ? Options::ENV_LIVE : Options::ENV_TEST;
		if ( $this->options->has_key( $environment ) ) {
			$this->status( $environment, true );
		}
	}

	/**
	 * @param array<string, mixed>|null $entry Stored entry for this key, or null.
	 */
	private function fetch( string $environment, ?array $entry ): array {
		$response = $this->client->request( 'GET', 'account-status', array(), null, $environment, array(), self::TIMEOUT );

		$status = array(
			'ok'            => $response->ok(),
			'error'         => $response->error,
			'account'       => $response->ok() ? array_intersect_key( $response->data, array_flip( array( 'accountName', 'companyName' ) ) ) : array(),
			'products'      => $response->ok() && isset( $response->data['products'] ) && is_array( $response->data['products'] ) ? $response->data['products'] : null,
			// Business API adds the `wordpress` block only once WordPress forms are on for the account.
			'forms_enabled' => $response->ok() && is_array( $response->data['wordpress'] ?? null ),
			'checked_at'    => time(),
		);

		$temporary = $response->is_transient();

		$good = $status['ok'] ? $status : ( $temporary && null !== $entry && $this->usable_good( $entry ) ? $entry['good'] : null );

		$entry = array(
			'hash'      => $this->key_hash( $environment ),
			'status'    => $status,
			'temporary' => $temporary,
			'good'      => $good,
			'expires'   => time() + ( $status['ok'] ? self::CACHE_TTL : self::FAILURE_TTL ),
		);
		$this->save( $environment, $entry );

		return $this->effective( $entry );
	}

	/**
	 * The latest result, or the last good status while BAPI is temporarily unreachable.
	 *
	 * @param array<string, mixed> $entry Stored entry.
	 */
	private function effective( array $entry ): array {
		$status = (array) $entry['status'];
		if ( empty( $status['ok'] ) && ! empty( $entry['temporary'] ) && $this->usable_good( $entry ) ) {
			return $entry['good'] + array(
				'stale'         => true,
				'refresh_error' => $status['error'] ?? null,
			);
		}
		return $status;
	}

	/** @param array<string, mixed> $entry Stored entry. */
	private function usable_good( array $entry ): bool {
		return is_array( $entry['good'] ?? null ) && ! empty( $entry['good']['ok'] ) && (int) ( $entry['good']['checked_at'] ?? 0 ) > time() - self::MAX_STALE;
	}

	/**
	 * Stored entry for the environment's current key, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function entry( string $environment ): ?array {
		$entry = get_option( self::OPTION_PREFIX . $environment );
		if ( ! is_array( $entry ) || ( $entry['hash'] ?? '' ) !== $this->key_hash( $environment ) || ! is_array( $entry['status'] ?? null ) ) {
			return null;
		}
		return $entry;
	}

	/** @param array<string, mixed> $entry Entry. */
	private function save( string $environment, array $entry ): void {
		update_option( self::OPTION_PREFIX . $environment, $entry, false );
	}

	private function schedule_refresh( string $environment ): void {
		$args = array( $environment );
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_REFRESH, $args, self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK_REFRESH, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_REFRESH, $args ) ) {
			wp_schedule_single_event( time(), self::HOOK_REFRESH, $args );
		}
	}

	public function flush(): void {
		delete_option( self::OPTION_PREFIX . Options::ENV_TEST );
		delete_option( self::OPTION_PREFIX . Options::ENV_LIVE );
	}

	/**
	 * @param string $capability BAPI capability key (see ProductRegistry).
	 * @return array{allowed: bool, reason: ?string, instructions: ?string}
	 */
	public function eligibility( string $capability ): array {
		if ( ! $this->options->is_live() ) {
			return array(
				'allowed'      => true,
				'reason'       => null,
				'instructions' => null,
			);
		}

		$status = $this->status();

		if ( ! $status['ok'] ) {
			return array(
				'allowed'      => false,
				'reason'       => $status['error'] ?? __( 'Could not reach Business API.', 'businessapi-forms' ),
				'instructions' => null,
			);
		}

		if ( null === $status['products'] ) {
			return array(
				'allowed'      => false,
				'reason'       => __( 'Business API did not report live eligibility for this account.', 'businessapi-forms' ),
				'instructions' => __( 'Contact Business API support.', 'businessapi-forms' ),
			);
		}

		$product = $status['products'][ $capability ] ?? null;

		if ( ! is_array( $product ) ) {
			return array(
				'allowed'      => false,
				'reason'       => __( 'Not available on this account.', 'businessapi-forms' ),
				'instructions' => null,
			);
		}

		return array(
			'allowed'      => ! empty( $product['live'] ),
			'reason'       => isset( $product['reason'] ) ? (string) $product['reason'] : null,
			'instructions' => isset( $product['instructions'] ) ? (string) $product['instructions'] : null,
		);
	}

	private function key_hash( string $environment ): string {
		// Changing the API key invalidates the stored status.
		return substr( md5( $this->options->key( $environment ) ), 0, 8 );
	}
}
