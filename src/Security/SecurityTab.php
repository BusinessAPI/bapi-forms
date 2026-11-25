<?php
/**
 * Security tab: 2FA status, checklist, integrity, key rotation and recent audit events.
 *
 * @package BapiForms
 */

namespace BapiForms\Security;

use BapiForms\Admin\SettingsPage;
use BapiForms\Plugin;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class SecurityTab {

	public const ROTATE_ACTION = 'bapi_forms_rotate_key';
	public const CHECK_ACTION  = 'bapi_forms_integrity_now';
	private const EVENTS_CACHE = 'bapi_forms_audit_recent_';

	public function __construct( private readonly Security $security ) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ROTATE_ACTION, array( $this, 'rotate' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'check_now' ) );
	}

	private static function done( array $errors, string $success = '' ): void {
		set_transient( 'bapi_forms_notice_' . get_current_user_id(), array( 'errors' => $errors ), 60 );
		$args = array( 'tab' => 'security' );
		if ( ! $errors ) {
			$args['updated'] = 1;
		}
		if ( '' !== $success ) {
			set_transient( 'bapi_forms_security_ok_' . get_current_user_id(), $success, 60 );
		}
		wp_safe_redirect( SettingsPage::url( $args ) );
		exit;
	}

	/** Verify a new key with Business API, swap it in and forget the old one. */
	public function rotate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'businessapi-forms' ), 403 );
		}
		check_admin_referer( self::ROTATE_ACTION );

		$options = $this->security->options();
		$env     = isset( $_POST['environment'] ) && Options::ENV_LIVE === $_POST['environment'] ? Options::ENV_LIVE : Options::ENV_TEST;
		$new     = isset( $_POST['new_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['new_key'] ) ) ) : '';

		if ( $options->key_is_constant( $env ) ) {
			self::done( array( __( 'This key is set in wp-config.php. Change it there.', 'businessapi-forms' ) ) );
		}
		if ( ! Options::key_matches_environment( $new, $env ) ) {
			/* translators: %s: expected key prefix. */
			self::done( array( sprintf( __( 'The new key was not saved. It must be a secret key starting with %s.', 'businessapi-forms' ), 'bapi_sk_' . $env . '_' ) ) );
		}
		if ( $new === $options->key( $env ) ) {
			self::done( array( __( 'That is the key already in use.', 'businessapi-forms' ) ) );
		}

		$check = $this->account_with( $new, $env );
		if ( null === $check['account'] ) {
			/* translators: %s: error. */
			self::done( array( sprintf( __( 'The new key was not saved: Business API did not accept it (%s).', 'businessapi-forms' ), $check['error'] ) ) );
		}
		// Must be the same account as the current key, when that one still works.
		$old = '' !== $options->key( $env ) ? $this->account_with( $options->key( $env ), $env )['account'] : null;
		if ( null !== $old && $old !== $check['account'] ) {
			self::done( array( __( 'The new key belongs to a different Business API account than the current one. It was not saved. To switch accounts, remove the key on the Connection tab first.', 'businessapi-forms' ) ) );
		}

		$plugin                   = Plugin::instance();
		$this->security->rotating = true;
		$options->update( array( $env . '_key' => $new ) );
		$this->security->rotating = false;
		$plugin->capabilities->flush();
		$plugin->prices->flush();
		$plugin->sites->register_if_missing( $env );
		$this->security->audit->log( 'key_rotated', array( 'environment' => $env ) );

		self::done( array(), __( 'Key rotated. The old key is no longer stored here: revoke it in your Business API dashboard (API keys) now.', 'businessapi-forms' ) );
	}

	/**
	 * Account name for a key that is not stored yet.
	 *
	 * @return array{account: ?string, error: string} account is null when refused.
	 */
	private function account_with( string $key, string $env ): array {
		$response = $this->security->client()->request( 'GET', 'account-status', array(), null, $env, array(), 0, $key );
		if ( ! $response->ok() ) {
			return array(
				'account' => null,
				/* translators: %d: HTTP status. */
				'error'   => 0 === $response->status ? (string) $response->error : sprintf( __( 'HTTP %d', 'businessapi-forms' ), $response->status ),
			);
		}
		$data = $response->data;
		return array(
			'account' => strtolower( trim( (string) ( $data['accountName'] ?? '' ) ) ),
			'error'   => '',
		);
	}

	public function check_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'businessapi-forms' ), 403 );
		}
		check_admin_referer( self::CHECK_ACTION );
		$this->security->checklist->debug_log_public( true );
		( new \BapiForms\Admin\Compatibility( \BapiForms\Plugin::instance()->options ) )->rest_check( true );
		$result = $this->security->integrity->run();
		$errors = array();
		if ( Integrity::UNCHECKED === $result['status'] ) {
			/* translators: %s: error. */
			$errors[] = sprintf( __( 'The integrity check could not run: %s', 'businessapi-forms' ), (string) ( $result['error'] ?? '' ) );
		}
		self::done( $errors, Integrity::FAILED === $result['status'] ? '' : __( 'Checks ran again.', 'businessapi-forms' ) );
	}

	public function render(): void {
		$ok = get_transient( 'bapi_forms_security_ok_' . get_current_user_id() );
		delete_transient( 'bapi_forms_security_ok_' . get_current_user_id() );
		if ( is_string( $ok ) && '' !== $ok ) {
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $ok ) );
		}
		$this->render_twofa();
		$this->render_checklist();
		$this->render_integrity();
		$this->render_keys();
		$this->render_events();
	}

	private static function pill( bool $ok, string $yes, string $no, bool $warn = false ): string {
		return sprintf( '<span class="bapi-forms-pill %s">%s</span>', $ok ? 'bapi-forms-ok' : ( $warn ? 'bapi-forms-warn' : 'bapi-forms-error' ), esc_html( $ok ? $yes : $no ) );
	}

	private function render_twofa(): void {
		$users  = get_users(
			array(
				'capability__in' => TwoFactor::CAPABILITIES,
				'number'         => 200,
			)
		);
		$active = array_keys( array_filter( TwoFactor::providers() ) );
		?>
		<section class="bapi-forms-panel">
			<h2><?php esc_html_e( 'Two-factor authentication', 'businessapi-forms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Before live mode can be switched on, every administrator and shop manager needs two-factor authentication. While live, users without it cannot open BusinessAPI Forms screens, pay ASIC fees or retry releases. Sandbox is not affected.', 'businessapi-forms' ); ?></p>
			<p>
				<?php
				echo $active
					/* translators: %s: plugin names. */
					? esc_html( sprintf( __( '2FA plugin detected: %s.', 'businessapi-forms' ), implode( ', ', $active ) ) )
					: esc_html__( 'No supported 2FA plugin is active. Install one of: Two Factor, Wordfence Login Security, WP 2FA or Solid Security.', 'businessapi-forms' );
				?>
			</p>
			<table class="widefat striped" style="max-width:640px">
				<thead><tr><th><?php esc_html_e( 'User', 'businessapi-forms' ); ?></th><th><?php esc_html_e( 'Two-factor', 'businessapi-forms' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $users as $user ) : ?>
					<tr>
						<td><?php echo esc_html( $user->user_login ); ?></td>
						<td><?php echo self::pill( TwoFactor::user_has_2fa( $user ), __( 'On', 'businessapi-forms' ), __( 'Not set up', 'businessapi-forms' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private function render_checklist(): void {
		?>
		<section class="bapi-forms-panel">
			<h2><?php esc_html_e( 'Security checklist', 'businessapi-forms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Required items must pass before live mode can be switched on. Recommended items are advice. Also shown in Tools > Site Health. BusinessAPI Forms is not a security plugin: use a dedicated one for firewall and malware scanning.', 'businessapi-forms' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Check', 'businessapi-forms' ); ?></th><th><?php esc_html_e( 'Status', 'businessapi-forms' ); ?></th><th><?php esc_html_e( 'Details', 'businessapi-forms' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $this->security->checklist->results() as $key => $check ) : ?>
					<tr>
						<td><?php echo esc_html( $check['label'] ); ?> <span class="description">(<?php echo $check['required'] ? esc_html__( 'required for live', 'businessapi-forms' ) : esc_html__( 'recommended', 'businessapi-forms' ); ?>)</span></td>
						<td><?php echo self::pill( $check['pass'], __( 'Pass', 'businessapi-forms' ), $check['required'] ? __( 'Fail', 'businessapi-forms' ) : __( 'Advice', 'businessapi-forms' ), ! $check['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?></td>
						<td><?php echo esc_html( ! $check['pass'] || in_array( $key, array( 'login_limit', 'security' ), true ) ? $check['detail'] : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private function render_integrity(): void {
		$last   = $this->security->integrity->last();
		$status = (string) ( $last['status'] ?? '' );
		$labels = array(
			Integrity::CLEAN     => array( true, __( 'Clean', 'businessapi-forms' ), false ),
			Integrity::FAILED    => array( false, __( 'Failed: forms paused', 'businessapi-forms' ), false ),
			Integrity::UNKNOWN   => array( false, __( 'Version not published (live forms paused)', 'businessapi-forms' ), true ),
			Integrity::BLOCKED   => array( false, __( 'This version is blocked: update the plugin', 'businessapi-forms' ), false ),
			Integrity::UNCHECKED => array( false, __( 'Could not check', 'businessapi-forms' ), true ),
		);
		?>
		<section class="bapi-forms-panel">
			<h2><?php esc_html_e( 'Plugin integrity', 'businessapi-forms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Every day BusinessAPI Forms compares its files with the official release published by Business API. If any file was changed, added or removed, your forms pause and you get an email until the plugin is reinstalled and checked again.', 'businessapi-forms' ); ?></p>
			<p>
				<?php
				if ( isset( $labels[ $status ] ) ) {
					[ $good, $label, $warn ] = $labels[ $status ];
					echo self::pill( $good, $label, $label, $warn ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill().
					printf(
						' %s',
						esc_html(
							sprintf(
								/* translators: 1: version, 2: time ago. */
								__( 'Version %1$s, checked %2$s ago.', 'businessapi-forms' ),
								(string) ( $last['version'] ?? BAPI_FORMS_VERSION ),
								human_time_diff( (int) ( $last['checked_at'] ?? time() ) )
							)
						)
					);
				} else {
					esc_html_e( 'Not checked yet.', 'businessapi-forms' );
				}
				?>
			</p>
			<?php if ( Integrity::FAILED === $status ) : ?>
				<ul class="ul-disc">
					<?php
					foreach ( array(
						'mismatched' => __( 'Changed', 'businessapi-forms' ),
						'missing'    => __( 'Missing', 'businessapi-forms' ),
						'unexpected' => __( 'Unexpected', 'businessapi-forms' ),
					) as $kind => $label ) :
						?>
						<?php foreach ( array_slice( (array) ( $last[ $kind ] ?? array() ), 0, 20 ) as $path ) : ?>
							<li><?php echo esc_html( $label . ': ' . $path ); ?></li>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</ul>
				<p><?php esc_html_e( 'Delete the plugin folder, install the official release from WordPress.org, then run the check again. If you did not change these files, check your site for malware and change administrator passwords.', 'businessapi-forms' ); ?></p>
			<?php elseif ( ! empty( $last['error'] ) ) : ?>
				<p class="description"><?php echo esc_html( (string) $last['error'] ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CHECK_ACTION ); ?>">
				<?php wp_nonce_field( self::CHECK_ACTION ); ?>
				<?php submit_button( __( 'Run checks now', 'businessapi-forms' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}

	private function render_keys(): void {
		$options = $this->security->options();
		?>
		<section class="bapi-forms-panel">
			<h2><?php esc_html_e( 'API key rotation', 'businessapi-forms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Keys are stored encrypted with your site\'s wp-config.php security keys. To rotate: create a new secret key in your Business API dashboard, paste it here, and BusinessAPI Forms checks it with Business API before swapping it in and forgetting the old one. Then revoke the old key in the dashboard.', 'businessapi-forms' ); ?></p>
			<?php
			foreach ( array(
				Options::ENV_TEST => __( 'Sandbox key', 'businessapi-forms' ),
				Options::ENV_LIVE => __( 'Live key', 'businessapi-forms' ),
			) as $env => $label ) :
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ROTATE_ACTION ); ?>">
					<input type="hidden" name="environment" value="<?php echo esc_attr( $env ); ?>">
					<?php wp_nonce_field( self::ROTATE_ACTION ); ?>
					<label for="bapi-forms-rotate-<?php echo esc_attr( $env ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
					<?php if ( $options->key_is_constant( $env ) ) : ?>
						<span class="description"><?php esc_html_e( 'Set in wp-config.php: rotate it there.', 'businessapi-forms' ); ?></span>
					<?php else : ?>
						<code><?php echo esc_html( $options->has_key( $env ) ? Options::mask_key( $options->key( $env ) ) : ( $options->key_unreadable( $env ) ? __( 'unreadable, enter it again', 'businessapi-forms' ) : __( 'none', 'businessapi-forms' ) ) ); ?></code><br>
						<input type="password" class="regular-text code" id="bapi-forms-rotate-<?php echo esc_attr( $env ); ?>" name="new_key" value="" autocomplete="off" placeholder="<?php echo esc_attr( 'bapi_sk_' . $env . '_...' ); ?>">
						<?php submit_button( __( 'Verify and rotate', 'businessapi-forms' ), 'secondary', 'submit', false ); ?>
					<?php endif; ?>
				</form>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private function render_events(): void {
		$options = $this->security->options();
		if ( ! $options->has_key() ) {
			return;
		}
		$cache  = self::EVENTS_CACHE . $options->environment();
		$events = get_transient( $cache );
		if ( ! is_array( $events ) ) {
			$response = $this->security->client()->get( 'wordpress/audit-events', array( 'limit' => '15' ) );
			$events   = $response->ok() && is_array( $response->data['events'] ?? null ) ? $response->data['events'] : array();
			set_transient( $cache, $events, 2 * MINUTE_IN_SECONDS );
		}
		?>
		<section class="bapi-forms-panel">
			<h2><?php esc_html_e( 'Recent activity', 'businessapi-forms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Settings changes, key rotations, live switches, releases, ASIC fee links, downloads and integrity failures are recorded in Business API (no client details). The full list is in your Business API dashboard.', 'businessapi-forms' ); ?></p>
			<?php if ( ! $events ) : ?>
				<p><?php esc_html_e( 'No activity recorded yet.', 'businessapi-forms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'When', 'businessapi-forms' ); ?></th><th><?php esc_html_e( 'Event', 'businessapi-forms' ); ?></th><th><?php esc_html_e( 'User', 'businessapi-forms' ); ?></th><th><?php esc_html_e( 'IP', 'businessapi-forms' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $events as $event ) : ?>
						<?php $time = strtotime( (string) ( $event['occurredAt'] ?? '' ) ); ?>
						<tr>
							<td><?php echo esc_html( $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '' ); ?></td>
							<td><?php echo esc_html( (string) ( $event['label'] ?? $event['event'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $event['wpUserLogin'] ?? ( isset( $event['actorType'] ) ? ucfirst( (string) $event['actorType'] ) : '' ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $event['actorIp'] ?? '' ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}
}
