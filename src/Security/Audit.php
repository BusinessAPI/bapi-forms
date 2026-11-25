<?php
/**
 * Audit trail, sent to Business API in the background: do_action( 'bapi_forms_audit', $event,
 * $context ). Never applicant data; login and IP for staff only. The queue keeps the newest MAX
 * events per environment while Business API is unreachable.
 *
 * @package BapiForms
 */

namespace BapiForms\Security;

use BapiForms\Api\Client;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Audit {

	public const QUEUE      = 'bapi_forms_audit_queue';
	public const HOOK_FLUSH = 'bapi_forms_audit_flush';
	public const GROUP      = \BapiForms\Scheduler::GROUP;
	private const MAX       = 200;
	private const BATCH     = 50;

	/** Context keys Business API keeps. */
	private const CONTEXT = array( 'tab', 'fields', 'from', 'to', 'environment', 'orderId', 'ref', 'documentId', 'version', 'files', 'result', 'action' );

	private bool $shutdown_hooked = false;

	public function __construct( private readonly Client $client, private readonly Options $options ) {}

	public function register(): void {
		add_action( 'bapi_forms_audit', array( $this, 'log' ), 10, 2 );
		add_action( self::HOOK_FLUSH, array( $this, 'flush' ) );
	}

	/**
	 * @param string               $event   One of BAPI's audit events (settings_changed, key_rotated, ...).
	 * @param array<string, mixed> $context Short references only.
	 */
	public function log( $event, $context = array() ): void {
		$event   = sanitize_key( (string) $event );
		$context = is_array( $context ) ? array_intersect_key( $context, array_flip( self::CONTEXT ) ) : array();
		$env     = isset( $context['environment'] ) && Options::ENV_LIVE === $context['environment'] ? Options::ENV_LIVE
			: ( isset( $context['environment'] ) && Options::ENV_TEST === $context['environment'] ? Options::ENV_TEST : $this->options->environment() );

		$user  = wp_get_current_user();
		$staff = $user->exists() && ( TwoFactor::is_relevant( $user ) || $user->has_cap( 'edit_shop_orders' ) );
		$item  = array(
			'event'      => $event,
			'actorType'  => $user->exists() ? ( $staff ? 'staff' : 'customer' ) : 'system',
			'occurredAt' => gmdate( 'c' ),
			'host'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'context'    => $context,
		);
		if ( $user->exists() ) {
			$item['wpUserId'] = (int) $user->ID;
		}
		if ( $staff ) {
			$item['wpUserLogin'] = $user->user_login;
			$ip                  = $this->options->client_ip();
			if ( '' !== $ip ) {
				$item['actorIp'] = $ip;
			}
		} elseif ( ! $user->exists() && defined( 'WP_CLI' ) && WP_CLI ) {
			$item['context']['action'] = 'wp-cli';
		}

		$queue           = $this->queue();
		$queue[ $env ][] = $item;
		$queue[ $env ]   = array_slice( $queue[ $env ], -self::MAX );
		update_option( self::QUEUE, $queue, false );
		$this->schedule();
	}

	/** Each environment is sent with its own key; failures stay queued. */
	public function flush(): void {
		$queue = $this->queue();
		foreach ( array( Options::ENV_TEST, Options::ENV_LIVE ) as $env ) {
			if ( empty( $queue[ $env ] ) || ! $this->options->has_key( $env ) ) {
				continue;
			}
			$batch    = array_slice( $queue[ $env ], 0, self::BATCH );
			$response = $this->client->post( 'wordpress/audit-events', array( 'events' => $batch ), $env );
			// Sent, or refused for good (400 bad batch, 404 feature off): drop the batch.
			if ( $response->ok() || in_array( $response->status, array( 400, 404 ), true ) ) {
				$fresh         = $this->queue();
				$fresh[ $env ] = array_slice( $fresh[ $env ] ?? array(), count( $batch ) );
				update_option( self::QUEUE, $fresh, false );
			}
		}
		$rest = $this->queue();
		if ( ! empty( $rest[ Options::ENV_TEST ] ) || ! empty( $rest[ Options::ENV_LIVE ] ) ) {
			if ( function_exists( 'as_schedule_single_action' ) && ! \BapiForms\Scheduler::pending( self::HOOK_FLUSH, null, self::GROUP ) ) {
				as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::HOOK_FLUSH, array(), self::GROUP );
			}
		}
	}

	/** @return array<string, list<array<string, mixed>>> */
	private function queue(): array {
		$queue = get_option( self::QUEUE, array() );
		return is_array( $queue ) ? $queue : array();
	}

	private function schedule(): void {
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
			if ( ! \BapiForms\Scheduler::pending( self::HOOK_FLUSH, null, self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK_FLUSH, array(), self::GROUP );
			}
			return;
		}
		if ( ! $this->shutdown_hooked ) {
			$this->shutdown_hooked = true;
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
	}
}
