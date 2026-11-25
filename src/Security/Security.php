<?php
/**
 * Partner site security: 2FA gate, plugin integrity, checklist, encrypted keys and audit trail.
 * A gate for live mode, not a general security plugin.
 *
 * @package BapiForms
 */

namespace BapiForms\Security;

use BapiForms\Admin\SettingsPage;
use BapiForms\Api\Client;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Security {

	public readonly Checklist $checklist;
	public readonly Integrity $integrity;
	public readonly Audit $audit;

	/** Set while the Security tab rotates a key (it logs key_rotated itself). */
	public bool $rotating = false;

	public function __construct( private readonly Client $client, private readonly Options $options ) {
		$this->checklist = new Checklist();
		$this->integrity = new Integrity( $client, $options );
		$this->audit     = new Audit( $client, $options );
	}

	public function register(): void {
		$this->audit->register();
		$this->integrity->register();

		add_filter( 'site_status_tests', array( $this->checklist, 'site_health' ) );
		add_action( 'update_option_' . Options::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'bapi_forms_release', array( $this, 'on_manual_release' ), 1 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'guard_admin' ), 1 );
			add_action( 'admin_notices', array( $this, 'notices' ) );
			add_filter( 'woocommerce_order_actions', array( $this, 'filter_order_actions' ), 99 );
			foreach ( array( \BapiForms\Commerce\WooCommerce\OrderBridge::ORDER_ACTION, \BapiForms\Commerce\WooCommerce\AsicPayment::ORDER_ACTION ) as $action ) {
				add_action( 'woocommerce_order_action_' . $action, array( $this, 'guard_order_action' ), 1 );
			}
			add_action( 'admin_post_bapi_forms_pay_asic', array( $this, 'on_pay_asic' ), 1 );
			( new SecurityTab( $this ) )->register();
		}
	}

	public function options(): Options {
		return $this->options;
	}

	public function client(): Client {
		return $this->client;
	}

	/**
	 * Sandbox always; live needs 2FA for shop staff. $data: the action reaches order data or
	 * documents, which a saved live key exposes even while the site is back in sandbox.
	 */
	public function user_allowed( ?\WP_User $user = null, bool $data = false ): bool {
		$user = $user ?? wp_get_current_user();
		$live = $this->options->is_live() || ( $data && $this->options->has_key( 'live' ) );
		return ! $live || ! $user->exists() || TwoFactor::compliant( $user );
	}

	public static function blocked_message(): string {
		return TwoFactor::any_provider_active()
			? __( 'BusinessAPI Forms is live or holds a live API key, so shop staff must use two-factor authentication. Set it up on your profile (Users > Profile, or your 2FA plugin\'s screen), then come back.', 'businessapi-forms' )
			: __( 'BusinessAPI Forms is live or holds a live API key, so shop staff must use two-factor authentication. Install a 2FA plugin (for example Two Factor, Wordfence Login Security, WP 2FA or Solid Security), set it up for your account, then come back.', 'businessapi-forms' );
	}

	/** Live: block our screens and admin-post actions for users without 2FA. */
	public function guard_admin(): void {
		if ( $this->user_allowed() && ( 'admin-post.php' !== ( $GLOBALS['pagenow'] ?? '' ) || $this->user_allowed( null, true ) ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only reads which screen this is.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ( isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// phpcs:enable
		$screen = str_starts_with( $page, SettingsPage::SLUG );
		$post   = 'admin-post.php' === ( $GLOBALS['pagenow'] ?? '' ) && str_starts_with( $action, 'bapi_forms_' );
		if ( ! $screen && ! $post ) {
			return;
		}
		$this->audit->log( 'twofa_blocked', array( 'action' => $screen ? 'screen' : substr( $action, 0, 64 ) ) );
		wp_die(
			'<h1>' . esc_html__( 'Two-factor authentication required', 'businessapi-forms' ) . '</h1><p>' . esc_html( self::blocked_message() ) . '</p><p><a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'Go to your profile', 'businessapi-forms' ) . '</a></p>',
			esc_html__( 'Two-factor authentication required', 'businessapi-forms' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * @param array<string, string> $actions Order actions.
	 */
	public function filter_order_actions( $actions ): array {
		$actions = (array) $actions;
		if ( ! $this->user_allowed( null, true ) ) {
			foreach ( array_keys( $actions ) as $key ) {
				if ( str_starts_with( (string) $key, 'bapi_forms_' ) ) {
					unset( $actions[ $key ] );
				}
			}
		}
		return $actions;
	}

	/**
	 * Runs before the real order action handler: refuse without 2FA, else audit it.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function guard_order_action( $order ): void {
		$hook   = current_action();
		$action = substr( $hook, strlen( 'woocommerce_order_action_' ) );
		if ( ! $this->user_allowed( null, true ) ) {
			remove_all_actions( $hook );
			$this->audit->log( 'twofa_blocked', array( 'action' => $action ) );
			if ( class_exists( '\WC_Admin_Meta_Boxes' ) ) {
				\WC_Admin_Meta_Boxes::add_error( self::blocked_message() );
			}
			return;
		}
		$order_id = $order instanceof \WC_Order ? (int) $order->get_id() : 0;
		$this->audit->log( \BapiForms\Commerce\WooCommerce\AsicPayment::ORDER_ACTION === $action ? 'asic_fee_link_opened' : 'release_retry', array( 'orderId' => $order_id ) );
	}

	/** Audit a "Pay ASIC fee" click before its handler runs. */
	public function on_pay_asic(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		$ref      = isset( $_GET['ref'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ) : '';
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, \BapiForms\Commerce\WooCommerce\AsicPayment::nonce_action( $order_id, $ref ) ) ) {
			return; // The handler refuses it too.
		}
		$this->audit->log(
			'asic_fee_link_opened',
			array(
				'orderId' => $order_id,
				'ref'     => $ref,
			)
		);
	}

	/**
	 * Audit a manual bapi_forms_release by a logged-in user.
	 *
	 * @param string $ref Application reference.
	 */
	public function on_manual_release( $ref ): void {
		if ( is_user_logged_in() ) {
			$this->audit->log( 'release_manual', array( 'ref' => (string) $ref ) );
		}
	}

	/**
	 * Why live cannot be switched on.
	 *
	 * @return list<string> Messages (empty = ready).
	 */
	public function live_blockers(): array {
		$messages = array();
		$missing  = TwoFactor::missing();
		if ( $missing ) {
			$messages[] = sprintf(
				/* translators: %s: user logins. */
				__( 'These administrators or shop managers need two-factor authentication first: %s. See the Security tab.', 'businessapi-forms' ),
				implode( ', ', array_map( static fn( \WP_User $u ): string => $u->user_login, array_slice( $missing, 0, 10 ) ) ) . ( count( $missing ) > 10 ? ', ...' : '' )
			);
		}
		$failing = $this->checklist->failing_required();
		if ( $failing ) {
			$messages[] = sprintf(
				/* translators: %s: check names. */
				__( 'These required security checks fail: %s. See the Security tab.', 'businessapi-forms' ),
				implode( ', ', $failing )
			);
		}
		if ( $this->integrity->failed() ) {
			$messages[] = __( 'The plugin failed its integrity check. Reinstall the official release and run the check again on the Security tab.', 'businessapi-forms' );
		}
		return $messages;
	}

	/**
	 * Audit settings changes from any source, WP-CLI included. Field names only, no values.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 */
	public function on_settings_updated( $old_value, $new_value ): void {
		$old     = is_array( $old_value ) ? $old_value : array();
		$new     = is_array( $new_value ) ? $new_value : array();
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			$a = $old[ $key ] ?? null;
			$b = $new[ $key ] ?? null;
			if ( in_array( $key, array( 'test_key', 'live_key' ), true ) ) {
				// Re-encryption of the same key is not a change.
				$a = is_string( $a ) ? KeyVault::decrypt( $a ) : $a;
				$b = is_string( $b ) ? KeyVault::decrypt( $b ) : $b;
			}
			if ( $a !== $b ) {
				$changed[] = (string) $key;
			}
		}
		if ( ! $changed ) {
			return;
		}
		if ( in_array( 'environment', $changed, true ) ) {
			$from = Options::ENV_LIVE === ( $old['environment'] ?? '' ) ? Options::ENV_LIVE : Options::ENV_TEST;
			$to   = Options::ENV_LIVE === ( $new['environment'] ?? '' ) ? Options::ENV_LIVE : Options::ENV_TEST;
			$this->audit->log(
				'live_switched',
				array(
					'from'        => $from,
					'to'          => $to,
					'environment' => Options::ENV_LIVE,
				)
			);
			$changed = array_values( array_diff( $changed, array( 'environment' ) ) );
			// Business API keeps one integrity result per environment: report for the new one.
			$this->integrity->queue_now();
		}
		if ( $this->rotating ) {
			$changed = array_values( array_diff( $changed, array( 'test_key', 'live_key' ) ) );
		}
		if ( $changed ) {
			$this->audit->log( 'settings_changed', array( 'fields' => array_slice( $changed, 0, 20 ) ) );
		}
	}

	public function notices(): void {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! TwoFactor::is_relevant( $user ) || ! \BapiForms\Admin\Notices::screen_allowed( true ) ) {
			return;
		}
		$link = static fn( string $label ): string => sprintf( ' <a href="%s">%s</a>', esc_url( SettingsPage::url( array( 'tab' => 'security' ) ) ), esc_html( $label ) );

		if ( ! $this->user_allowed( $user ) ) {
			printf( '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>', esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ), esc_html( self::blocked_message() ) );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( array( Options::ENV_TEST, Options::ENV_LIVE ) as $env ) {
			if ( $this->options->key_unreadable( $env ) ) {
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
					esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
					esc_html(
						Options::ENV_LIVE === $env
							? __( 'The stored live API key can no longer be decrypted, usually because the security keys (salts) in wp-config.php changed. Forms stop until you paste the key again.', 'businessapi-forms' )
							: __( 'The stored sandbox API key can no longer be decrypted, usually because the security keys (salts) in wp-config.php changed. Paste the key again.', 'businessapi-forms' )
					),
					esc_url( SettingsPage::url( array( 'tab' => 'connection' ) ) ),
					esc_html__( 'Enter the key again', 'businessapi-forms' )
				);
			}
		}

		if ( $this->integrity->failed() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s%s</p></div>',
				esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
				esc_html__( 'The plugin files differ from the official release, so Business API has paused your forms. Reinstall BusinessAPI Forms from WordPress.org and run the check again.', 'businessapi-forms' ),
				$link( __( 'Security tab', 'businessapi-forms' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $link.
			);
		}

		if ( $this->options->is_live() ) {
			$missing = TwoFactor::missing();
			if ( $missing ) {
				printf(
					'<div class="notice notice-warning"><p><strong>%s</strong> %s%s</p></div>',
					esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
					esc_html(
						sprintf(
							/* translators: %d: number of users. */
							_n( '%d administrator or shop manager has no two-factor authentication and is blocked from BusinessAPI Forms.', '%d administrators or shop managers have no two-factor authentication and are blocked from BusinessAPI Forms.', count( $missing ), 'businessapi-forms' ),
							count( $missing )
						)
					),
					$link( __( 'Details', 'businessapi-forms' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $link.
				);
			}
		}
	}
}
