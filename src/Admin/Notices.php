<?php
/**
 * Admin notices: commerce plugin gate and missing API key.
 *
 * @package BapiForms
 */

namespace BapiForms\Admin;

use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class Notices {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Notices show on the Plugins and BusinessAPI Forms screens only, plus WooCommerce
	 * screens when $woocommerce is true.
	 */
	public static function screen_allowed( bool $woocommerce = false ): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( 'plugins' === $id || str_contains( $id, SettingsPage::SLUG ) ) {
			return true;
		}
		return $woocommerce && function_exists( 'wc_get_screen_ids' ) && in_array( $id, wc_get_screen_ids(), true );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::screen_allowed( true ) ) {
			return;
		}

		if ( \BapiForms\Commerce\WooCommerceAdapter::too_old() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
				esc_html(
					sprintf(
						/* translators: 1: installed WooCommerce version, 2: minimum version. */
						__( 'Forms are not being served: WooCommerce %1$s is too old. Update WooCommerce to %2$s or newer.', 'businessapi-forms' ),
						\BapiForms\Commerce\WooCommerceAdapter::version(),
						\BapiForms\Commerce\WooCommerceAdapter::MIN_VERSION
					)
				)
			);
		} elseif ( ! $this->plugin->commerce->has_active() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
				esc_html(
					sprintf(
						/* translators: %s: list of approved ecommerce plugins. */
						__( 'Forms are not being served. Install and activate an approved ecommerce plugin first (%s).', 'businessapi-forms' ),
						$this->plugin->commerce->approved_labels()
					)
				)
			);
		}

		if ( ! self::screen_allowed() ) {
			return; // The rest is for the Plugins and BusinessAPI Forms screens.
		}

		$refused = \BapiForms\Account\Sites::refused_ip();
		if ( null !== $refused && $this->plugin->options->is_live() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
				esc_html(
					sprintf(
						/* translators: %s: server IP address. */
						__( 'Business API refused requests from this server\'s new IP %s. Register it and confirm the email.', 'businessapi-forms' ),
						$refused
					)
				),
				esc_url( SettingsPage::url( array( 'tab' => 'connection' ) ) ),
				esc_html__( 'Register this site', 'businessapi-forms' )
			);
		}

		$this->compatibility();

		if ( ! $this->plugin->options->has_key() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
				esc_html__( 'No Business API key is set for the current environment.', 'businessapi-forms' ),
				esc_url( SettingsPage::url() ),
				esc_html__( 'Add your API key', 'businessapi-forms' )
			);
		}
	}

	/** REST blocked for visitors. The loopback test itself only runs on our screens. */
	private function compatibility(): void {
		if ( ! $this->plugin->can_serve_forms() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		$ours   = str_contains( $id, SettingsPage::SLUG );
		$compat = new Compatibility( $this->plugin->options );
		$rest   = $ours ? $compat->rest_check() : $compat->rest_check_cached();

		if ( null !== $rest && 'blocked' === $rest['state'] ) {
			printf(
				'<div class="notice %s"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				true === $rest['fallback'] ? 'notice-warning' : 'notice-error',
				esc_html__( 'BusinessAPI Forms:', 'businessapi-forms' ),
				esc_html( $compat->rest_message( $rest ) ),
				esc_url( SettingsPage::url( array( 'tab' => 'help' ) ) . '#compatibility' ),
				esc_html__( 'Site compatibility help', 'businessapi-forms' )
			);
		}

		// Only on the Connection tab, where the setting is.
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$proxy = $ours && 'connection' === $tab ? $compat->proxy_advice() : null;
		if ( null !== $proxy ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p><details><summary>%s</summary><p>%s</p></details></div>',
				esc_html(
					sprintf(
						/* translators: %s: HTTP header name. */
						__( 'Tip: your site seems to sit behind a proxy or CDN. Setting Trusted proxy header to %s below makes visitor rate limits count each visitor.', 'businessapi-forms' ),
						$proxy['header']
					)
				),
				esc_html__( 'Learn more', 'businessapi-forms' ),
				esc_html( $compat->proxy_message( $proxy ) )
			);
		}
	}
}
