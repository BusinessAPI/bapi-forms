<?php
/**
 * Business API webhooks: registration of this site's endpoint and the signed receiver.
 *
 * The endpoint is registered per environment when a key is saved and checked daily. BAPI returns
 * the signing secret once; it is stored encrypted, and the previous one is kept for a day so
 * queued events still verify. Events only trigger a status poll, so a forged or stale event can
 * cause at most one extra status read. Payloads are never stored; only delivery ids, for replays.
 *
 * @package BapiForms
 */

namespace BapiForms\Webhooks;

use BapiForms\Applications\Applications;
use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Plugin;
use BapiForms\Security\KeyVault;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Webhooks {

	public const OPTION        = 'bapi_forms_webhooks';
	public const EVENT         = 'application.status_changed';
	public const EVENTS        = array( self::EVENT, 'monitoring.change_detected', 'monitoring.expiring', 'monitoring.expired' );
	public const HOOK_REGISTER = 'bapi_forms_webhooks_register';
	public const HOOK_CHECK    = 'bapi_forms_webhooks_check';
	public const GROUP         = \BapiForms\Scheduler::GROUP;
	public const ADMIN_ACTION  = self::HOOK_REGISTER;
	private const NAMESPACE    = 'bapi-forms/v1';
	private const TOLERANCE    = 300;
	private const SEEN_TTL     = DAY_IN_SECONDS;
	private const PREVIOUS_TTL = DAY_IN_SECONDS;
	private const LOCK_PREFIX  = 'bapi_forms_wh_lock_';
	private const LOCK_TTL     = 120;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'update_option_' . Options::OPTION, array( $this, 'on_settings_changed' ), 10, 2 );
		add_action( 'add_option_' . Options::OPTION, array( $this, 'on_settings_added' ), 10, 2 );
		// Changed on this site, not a cloned database: move the endpoint.
		add_action( 'update_option_home', array( $this, 'on_site_moved' ) );
		add_action( 'update_option_permalink_structure', array( $this, 'on_site_moved' ) );
		add_action( self::HOOK_REGISTER, array( $this, 'run_register' ) );
		add_action( self::HOOK_CHECK, array( $this, 'check' ) );
		add_action( 'action_scheduler_init', array( $this, 'schedule' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_admin' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/(?P<env>test|live)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true', // Authenticated by the HMAC signature below.
			)
		);
	}

	public static function receiver_url( string $environment ): string {
		return rest_url( self::NAMESPACE . '/webhooks/' . $environment );
	}

	/** Verifies X-Bapi-Signature, then queues a status poll; replays get 200 without acting again. */
	public function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$env  = Options::ENV_LIVE === $request['env'] ? Options::ENV_LIVE : Options::ENV_TEST;
		$body = (string) $request->get_body();

		if ( ! self::verify( (string) $request->get_header( 'x_bapi_signature' ), $body, self::secrets( $env ) ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_body' ), 400 );
		}
		$delivery = is_string( $event['deliveryId'] ?? null ) && 1 === preg_match( '/^wd_[0-9]{1,20}$/', $event['deliveryId'] ) ? $event['deliveryId'] : '';
		if ( '' === $delivery ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_body' ), 400 );
		}
		$seen = 'bapi_forms_wh_' . md5( $env . '|' . $delivery );
		if ( get_transient( $seen ) ) {
			return new \WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}

		// BAPI sends ULIDs lowercase; this plugin keys refs uppercase.
		$ref    = is_string( $event['ref'] ?? null ) ? strtoupper( $event['ref'] ) : '';
		$polled = 0;
		if ( self::EVENT === ( $event['event'] ?? '' ) && Applications::is_ref( $ref ) && ( $event['environment'] ?? $env ) === $env ) {
			$polled = self::poll_now( $ref );
			/**
			 * A verified application event, without applicant data.
			 *
			 * @param array{ref: string, type: string, status: string, progress: string, environment: string} $event
			 */
			do_action(
				'bapi_forms_webhook_event',
				array(
					'ref'         => $ref,
					'type'        => sanitize_key( (string) ( $event['type'] ?? '' ) ),
					'status'      => sanitize_key( (string) ( $event['status'] ?? '' ) ),
					'progress'    => sanitize_key( (string) ( $event['progress'] ?? '' ) ),
					'environment' => $env,
				)
			);
		}

		$name = (string) ( $event['event'] ?? '' );
		if ( in_array( $name, self::EVENTS, true ) && str_starts_with( $name, 'monitoring.' ) ) {
			/**
			 * A verified monitoring event, reduced to references and dates.
			 *
			 * @param string $event       monitoring.change_detected | monitoring.expiring | monitoring.expired.
			 * @param array  $payload     {monitoringEventId?, eventType?, detectedAt?, daysRemaining?, simulated?, entity: {id, acn, abn, status, monitoringStatus, expiresAt, daysRemaining}}.
			 * @param string $environment test or live.
			 */
			do_action( 'bapi_forms_webhook', $name, self::monitoring_payload( $event ), $env );
		}

		set_transient( $seen, 1, self::SEEN_TTL );
		return new \WP_REST_Response(
			array(
				'received' => true,
				'polled'   => $polled,
			),
			200
		);
	}

	/**
	 * Whitelisted copy of a monitoring webhook body.
	 *
	 * @param array<string, mixed> $event Verified body.
	 * @return array<string, mixed>
	 */
	public static function monitoring_payload( array $event ): array {
		$entity = is_array( $event['entity'] ?? null ) ? $event['entity'] : array();
		$out    = array(
			'entity' => array(
				'id'               => (int) ( $entity['id'] ?? 0 ),
				'acn'              => preg_replace( '/\D/', '', (string) ( $entity['acn'] ?? '' ) ),
				'abn'              => preg_replace( '/\D/', '', (string) ( $entity['abn'] ?? '' ) ),
				'status'           => sanitize_key( (string) ( $entity['status'] ?? '' ) ),
				'monitoringStatus' => sanitize_key( (string) ( $entity['monitoringStatus'] ?? '' ) ),
				'expiresAt'        => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $entity['expiresAt'] ?? '' ) ) ? (string) $entity['expiresAt'] : '',
				'daysRemaining'    => (int) ( $entity['daysRemaining'] ?? 0 ),
			),
		);
		if ( isset( $event['monitoringEventId'] ) ) {
			$out['monitoringEventId'] = (int) $event['monitoringEventId'];
		}
		if ( isset( $event['eventType'] ) ) {
			$out['eventType'] = sanitize_key( (string) $event['eventType'] );
		}
		if ( isset( $event['detectedAt'] ) ) {
			$out['detectedAt'] = sanitize_text_field( (string) $event['detectedAt'] );
		}
		if ( isset( $event['daysRemaining'] ) ) {
			$out['daysRemaining'] = (int) $event['daysRemaining'];
		}
		if ( ! empty( $event['simulated'] ) ) {
			$out['simulated'] = true;
		}
		return $out;
	}

	/**
	 * Checks "t={unix},v1={hex HMAC-SHA256 of t.body}" against any secret, within 5 minutes.
	 *
	 * @param list<string> $secrets Candidate secrets.
	 */
	public static function verify( string $header, string $body, array $secrets, ?int $now = null ): bool {
		$parts = array();
		foreach ( explode( ',', $header ) as $pair ) {
			$kv = explode( '=', trim( $pair ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ] = $kv[1];
			}
		}
		$t   = isset( $parts['t'] ) && ctype_digit( $parts['t'] ) ? (int) $parts['t'] : 0;
		$sig = isset( $parts['v1'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $parts['v1'] ) ? $parts['v1'] : '';
		if ( 0 === $t || '' === $sig || abs( ( $now ?? time() ) - $t ) > self::TOLERANCE ) {
			return false;
		}
		foreach ( $secrets as $secret ) {
			if ( '' !== $secret && hash_equals( hash_hmac( 'sha256', $t . '.' . $body, $secret ), $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return int Polls queued.
	 */
	public static function poll_now( string $ref ): int {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}
		$queued = 0;
		foreach ( OrderBridge::orders_with_ref( $ref ) as $id ) {
			$order = wc_get_order( $id );
			// Prepaid too: the client may just have finished the form.
			if ( ! $order instanceof \WC_Order || ! in_array( OrderBridge::apps( $order )[ $ref ]['state'] ?? '', array( 'released', 'prepaid' ), true ) ) {
				continue;
			}
			OrderBridge::poll_soon( (int) $order->get_id(), $ref, 0 );
			++$queued;
		}
		return $queued;
	}

	/**
	 * @param mixed $old   Previous settings.
	 * @param mixed $value New settings.
	 */
	public function on_settings_changed( $old, $value ): void {
		$old   = is_array( $old ) ? $old : array();
		$value = is_array( $value ) ? $value : array();
		$state = self::state();
		$dirty = false;
		foreach ( array( Options::ENV_TEST, Options::ENV_LIVE ) as $env ) {
			if ( ( $old[ $env . '_key' ] ?? '' ) !== ( $value[ $env . '_key' ] ?? '' ) ) {
				// If the new key belongs to another account, ensure() deletes the old endpoint with the old key.
				$id = (int) ( $state[ $env ]['id'] ?? 0 );
				if ( $id > 0 && is_string( $old[ $env . '_key' ] ?? null ) && '' !== $old[ $env . '_key' ] ) {
					$state[ $env ]['retire'] = array(
						'id'  => $id,
						'key' => (string) $old[ $env . '_key' ],
					);
					$dirty                   = true;
				}
				self::queue_register( $env );
			}
		}
		if ( $dirty ) {
			update_option( self::OPTION, $state, false );
		}
	}

	public function on_site_moved(): void {
		$state = self::state();
		foreach ( array( Options::ENV_TEST, Options::ENV_LIVE ) as $env ) {
			$id = (int) ( $state[ $env ]['id'] ?? 0 );
			if ( $id > 0 ) {
				$state[ $env ]['moved'] = $id;
				self::queue_register( $env );
			}
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Settings.
	 */
	public function on_settings_added( $option, $value ): void {
		$this->on_settings_changed( array(), $value );
	}

	public static function queue_register( string $env ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! \BapiForms\Scheduler::pending( self::HOOK_REGISTER, array( $env ), self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK_REGISTER, array( $env ), self::GROUP );
			}
		}
	}

	/** Re-added after a deactivation cleared the plugin's actions. */
	public function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK_CHECK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 10 * MINUTE_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_CHECK, array(), self::GROUP );
		}
	}

	/** Daily self-heal: missing, disabled or failing endpoints, changed URLs, keys or secrets. */
	public function check(): void {
		foreach ( array( Options::ENV_TEST, Options::ENV_LIVE ) as $env ) {
			if ( '' !== $this->plugin->options->key( $env ) ) {
				$this->ensure( $env );
			}
		}
	}

	/**
	 * @param string $env Environment.
	 */
	public function run_register( $env ): void {
		$this->ensure( Options::ENV_LIVE === $env ? Options::ENV_LIVE : Options::ENV_TEST );
	}

	/**
	 * Registers or repairs this site's endpoint. One run at a time per environment: two concurrent
	 * rotations would leave a secret BAPI no longer signs with.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function ensure( string $env ): array {
		if ( ! \BapiForms\Lock::acquire( self::LOCK_PREFIX . $env, self::LOCK_TTL ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Another webhook registration is running. Try again in a minute.', 'businessapi-forms' ),
			);
		}
		try {
			return $this->ensure_locked( $env );
		} finally {
			\BapiForms\Lock::release( self::LOCK_PREFIX . $env );
		}
	}

	/**
	 * @return array{ok: bool, error: string}
	 */
	private function ensure_locked( string $env ): array {
		$client = $this->plugin->client;
		$key    = $this->plugin->options->key( $env );
		$url    = self::receiver_url( $env );
		if ( '' === $key ) {
			return self::save( $env, array( 'status' => 'no_key' ), '' );
		}
		$state = self::state()[ $env ] ?? array();

		$list = $client->get( 'webhooks', array(), $env );
		if ( ! $list->ok() ) {
			return self::failed( $env, (string) $list->error );
		}
		$endpoints = array_values( array_filter( (array) ( $list->data['data'] ?? array() ), 'is_array' ) );
		$by_id     = static function ( int $id ) use ( $endpoints ): ?array {
			foreach ( $endpoints as $endpoint ) {
				if ( $id > 0 && (int) ( $endpoint['id'] ?? 0 ) === $id ) {
					return $endpoint;
				}
			}
			return null;
		};

		// An endpoint the new key cannot see belongs to the old key's account: delete it with that key.
		$retire = is_array( $state['retire'] ?? null ) ? $state['retire'] : null;
		if ( null !== $retire && null === $by_id( (int) ( $retire['id'] ?? 0 ) ) ) {
			$this->delete_with_old_key( $env, $key, $retire );
		}

		$found = null;
		foreach ( $endpoints as $endpoint ) {
			if ( ( $endpoint['url'] ?? '' ) === $url ) {
				$found = $endpoint;
				break;
			}
		}

		// Moved on this site: PATCH the URL. A cloned database never fires the hook, so it
		// registers its own endpoint instead.
		$moved = (int) ( $state['moved'] ?? 0 );
		if ( null === $found && $moved > 0 && (int) ( $state['id'] ?? 0 ) === $moved && null !== $by_id( $moved ) ) {
			$patched = $client->request( 'PATCH', 'webhooks/' . $moved, array(), array( 'url' => $url ), $env );
			if ( ! $patched->ok() ) {
				return self::failed( $env, (string) $patched->error );
			}
			$found = array_merge( $by_id( $moved ), array( 'url' => $url ) );
		}

		if ( null === $found ) {
			$created = $client->post(
				'webhooks',
				array(
					'url'        => $url,
					'eventTypes' => self::EVENTS,
				),
				$env
			);
			if ( ! $created->ok() || ! is_string( $created->data['secret'] ?? null ) ) {
				return self::failed( $env, (string) ( $created->error ?? __( 'Unexpected answer from Business API.', 'businessapi-forms' ) ) );
			}
			return self::registered( $env, (int) ( $created->data['id'] ?? 0 ), $key, $url, (string) $created->data['secret'] );
		}

		$id = (int) ( $found['id'] ?? 0 );
		// Failing deliveries usually mean another copy of this site rotated the secret.
		$failing = (int) ( $found['consecutive_failures'] ?? 0 ) > 0 || ! empty( $found['disabled_at'] );
		$types   = (array) ( $found['event_types'] ?? array() );
		if ( empty( $found['enabled'] ) || array_diff( self::EVENTS, $types ) ) {
			$patched = $client->request(
				'PATCH',
				'webhooks/' . $id,
				array(),
				array(
					'enabled'    => true,
					'eventTypes' => array_values( array_unique( array_merge( $types, self::EVENTS ) ) ),
				),
				$env
			);
			if ( ! $patched->ok() ) {
				return self::failed( $env, (string) $patched->error );
			}
		}

		$have = isset( $state['secret'] ) ? KeyVault::decrypt( (string) $state['secret'] ) : null;
		if ( ! $failing && (int) ( $state['id'] ?? -1 ) === $id && is_string( $have ) && '' !== $have ) {
			return self::registered( $env, $id, $key, $url, null );
		}

		// No usable secret here (new key, restored database, changed salts) or failing: rotate.
		$rotated = $client->post( 'webhooks/' . $id . '/rotate-secret', array(), $env );
		if ( ! $rotated->ok() || ! is_string( $rotated->data['secret'] ?? null ) ) {
			return self::failed( $env, (string) ( $rotated->error ?? __( 'Unexpected answer from Business API.', 'businessapi-forms' ) ) );
		}
		return self::registered( $env, $id, $key, $url, (string) $rotated->data['secret'] );
	}

	/**
	 * Best effort, never logged.
	 *
	 * @param array<string, mixed> $retire {id, key (encrypted)} of the previous key's endpoint.
	 */
	private function delete_with_old_key( string $env, string $key, array $retire ): void {
		$old_key = KeyVault::decrypt( (string) ( $retire['key'] ?? '' ) );
		if ( is_string( $old_key ) && '' !== $old_key && $old_key !== $key ) {
			$this->plugin->client->request( 'DELETE', 'webhooks/' . (int) $retire['id'], array(), null, $env, array(), 15, $old_key );
		}
	}

	/** @return array<string, mixed> */
	private static function failed( string $env, string $error ): array {
		return self::save(
			$env,
			array(
				'status' => 'failed',
				'error'  => $error,
			),
			''
		);
	}

	/**
	 * @param string|null $secret New signing secret, or null to keep the stored one.
	 * @return array<string, mixed>
	 */
	private static function registered( string $env, int $id, string $key, string $url, ?string $secret ): array {
		return self::save(
			$env,
			array(
				'status' => 'registered',
				'id'     => $id,
				'key'    => self::key_hash( $key ),
				'url'    => $url,
				'events' => self::EVENTS,
			),
			$secret
		);
	}

	/**
	 * $secret null or '' keeps the current secret; a value replaces it, keeping the old one a day.
	 *
	 * @param array<string, mixed> $entry State.
	 * @return array{ok: bool, error: string}
	 */
	private static function save( string $env, array $entry, ?string $secret ): array {
		$all                     = self::state();
		$current                 = $all[ $env ] ?? array();
		$entry                  += array( 'error' => '' );
		$entry['at']             = time();
		$entry['secret']         = $current['secret'] ?? '';
		$entry['previous']       = $current['previous'] ?? '';
		$entry['previous_until'] = (int) ( $current['previous_until'] ?? 0 );
		if ( is_string( $secret ) && '' !== $secret ) {
			$entry['previous']       = (string) ( $current['secret'] ?? '' );
			$entry['previous_until'] = time() + self::PREVIOUS_TTL;
			$entry['secret']         = KeyVault::encrypt( $secret );
		}
		if ( 'registered' !== ( $entry['status'] ?? '' ) ) {
			// Keep the last good registration and pending work for the next try.
			$entry = array_merge( array_intersect_key( $current, array_flip( array( 'id', 'key', 'url', 'events', 'retire', 'moved' ) ) ), $entry );
		}
		$all[ $env ] = $entry;
		update_option( self::OPTION, $all, false );
		return array(
			'ok'    => 'registered' === ( $entry['status'] ?? '' ),
			'error' => (string) $entry['error'],
		);
	}

	/** @return array<string, array<string, mixed>> */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @return list<string>
	 */
	public static function secrets( string $env ): array {
		$entry = self::state()[ $env ] ?? array();
		$out   = array();
		$cur   = isset( $entry['secret'] ) ? KeyVault::decrypt( (string) $entry['secret'] ) : null;
		if ( is_string( $cur ) && '' !== $cur ) {
			$out[] = $cur;
		}
		if ( (int) ( $entry['previous_until'] ?? 0 ) > time() ) {
			$prev = KeyVault::decrypt( (string) ( $entry['previous'] ?? '' ) );
			if ( is_string( $prev ) && '' !== $prev ) {
				$out[] = $prev;
			}
		}
		return $out;
	}

	private static function key_hash( string $key ): string {
		return substr( hash( 'sha256', $key ), 0, 16 );
	}

	public function handle_admin(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->plugin->security->user_allowed() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'businessapi-forms' ), 403 );
		}
		check_admin_referer( self::ADMIN_ACTION );
		foreach ( array( Options::ENV_TEST, Options::ENV_LIVE ) as $env ) {
			if ( $this->plugin->options->has_key( $env ) ) {
				$this->ensure( $env );
			}
		}
		wp_safe_redirect( \BapiForms\Admin\SettingsPage::url( array( 'tab' => 'help' ) ) . '#bapi-forms-webhooks' );
		exit;
	}

	/** Status lines for the Help tab. */
	public function render_status(): void {
		$state = self::state();
		echo '<ul class="bapi-forms-bullets">';
		foreach ( array(
			Options::ENV_TEST => __( 'Sandbox', 'businessapi-forms' ),
			Options::ENV_LIVE => __( 'Live', 'businessapi-forms' ),
		) as $env => $label ) {
			if ( ! $this->plugin->options->has_key( $env ) ) {
				continue;
			}
			$entry = $state[ $env ] ?? array();
			$ok    = 'registered' === ( $entry['status'] ?? '' );
			printf(
				'<li>%s: <span class="bapi-forms-pill %s">%s</span>%s</li>',
				esc_html( $label ),
				esc_attr( $ok ? 'bapi-forms-ok' : 'bapi-forms-warn' ),
				esc_html( $ok ? __( 'Connected', 'businessapi-forms' ) : __( 'Not connected', 'businessapi-forms' ) ),
				! $ok && '' !== (string) ( $entry['error'] ?? '' ) ? ' <small>' . esc_html( (string) $entry['error'] ) . '</small>' : ''
			);
		}
		echo '</ul>';
		printf(
			'<form method="post" action="%s"><input type="hidden" name="action" value="%s">%s%s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ADMIN_ACTION ),
			wp_nonce_field( self::ADMIN_ACTION, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
			get_submit_button( __( 'Connect webhooks again', 'businessapi-forms' ), 'secondary', 'submit', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
		);
	}
}
