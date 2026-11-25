<?php
/**
 * Plugin bootstrap.
 *
 * @package BapiForms
 */

namespace BapiForms;

use BapiForms\Account\Capabilities;
use BapiForms\Account\Sites;
use BapiForms\Admin\HelpPage;
use BapiForms\Admin\Notices;
use BapiForms\Admin\SettingsPage;
use BapiForms\Api\Client;
use BapiForms\Applications\Applications;
use BapiForms\Commerce\AdapterRegistry;
use BapiForms\Frontend\Block;
use BapiForms\Frontend\MyAccount;
use BapiForms\Frontend\RestController;
use BapiForms\Frontend\Shortcode;
use BapiForms\Products\ExpectedLines;
use BapiForms\Products\PriceList;
use BapiForms\Products\ProductRegistry;
use BapiForms\Security\Security;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public readonly Options $options;
	public readonly Client $client;
	public readonly Capabilities $capabilities;
	public readonly Sites $sites;
	public readonly ProductRegistry $products;
	public readonly AdapterRegistry $commerce;
	public readonly Applications $applications;
	public readonly PriceList $prices;
	public readonly ExpectedLines $expected;
	public readonly Security $security;

	private function __construct() {
		$this->options      = new Options();
		$this->client       = new Client( $this->options );
		$this->capabilities = new Capabilities( $this->client, $this->options );
		$this->sites        = new Sites( $this->client, $this->options );
		$this->products     = new ProductRegistry( $this->options, $this->capabilities );
		$this->commerce     = new AdapterRegistry();
		$this->applications = new Applications( $this->client );
		$this->prices       = new PriceList( $this->client, $this->options );
		$this->expected     = new ExpectedLines( $this->products, $this->prices );
		$this->security     = new Security( $this->client, $this->options );
	}

	public static function boot(): void {
		$plugin = self::instance();

		// Security first: 2FA gate, integrity, audit.
		$plugin->security->register();
		$plugin->capabilities->register();

		if ( is_admin() ) {
			( new SettingsPage( $plugin ) )->register();
			( new HelpPage( $plugin ) )->register();
			( new Notices( $plugin ) )->register();
		}

		$shortcode = new Shortcode( $plugin );
		$shortcode->register();
		( new Block( $plugin, $shortcode ) )->register();
		( new RestController( $plugin ) )->register();
		// Same routes through admin-ajax, for sites that block the REST API for visitors.
		( new Frontend\AjaxBridge() )->register();
		( new MyAccount( $plugin, $shortcode ) )->register();
		( new Frontend\PrepaidStart( $plugin, $shortcode ) )->register();
		$plugin->applications->register();
		( new Webhooks\Webhooks( $plugin ) )->register();

		foreach ( $plugin->commerce->active() as $adapter ) {
			$adapter->register();
		}
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** An API key for the current environment and an approved commerce plugin are both required. */
	public function can_serve_forms(): bool {
		return $this->options->has_key() && $this->commerce->has_active();
	}
}
