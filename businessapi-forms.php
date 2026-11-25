<?php
/**
 * Plugin Name:       BusinessAPI Forms
 * Description:       Offer company, ABN and business name registrations, PPSR searches and entity monitoring through Business API hosted forms.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * WC requires at least: 8.2
 * WC tested up to:   11.3
 * Author:            Business API
 * Author URI:        https://businessapi.com.au
 * License:           GPL-2.0-or-later
 * Text Domain:       businessapi-forms
 *
 * @package BapiForms
 */

defined( 'ABSPATH' ) || exit;

define( 'BAPI_FORMS_VERSION', '1.0.0' );
define( 'BAPI_FORMS_FILE', __FILE__ );
define( 'BAPI_FORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BAPI_FORMS_URL', plugin_dir_url( __FILE__ ) );

// Base URL of Business API. Override in wp-config.php for local development.
if ( ! defined( 'BAPI_FORMS_API_BASE' ) ) {
	define( 'BAPI_FORMS_API_BASE', 'https://businessapi.com.au' );
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BapiForms\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$path = BAPI_FORMS_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( BapiForms\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( BapiForms\Lifecycle::class, 'deactivate' ) );
// Rewrite rules (My Account endpoints, download links) are rebuilt without ours on the next request.
register_deactivation_hook( __FILE__, static fn () => delete_option( 'rewrite_rules' ) );

// Orders go through WC CRUD only (HPOS) and the block cart and checkout use Store API hooks.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( BapiForms\Plugin::class, 'boot' ) );
