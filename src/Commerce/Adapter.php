<?php
/**
 * Approved ecommerce plugin integration: turns "the partner was paid" into a BAPI release and
 * links orders to applications by opaque reference only.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce;

defined( 'ABSPATH' ) || exit;

interface Adapter {

	/** Stable identifier, e.g. "woocommerce". */
	public function id(): string;

	public function label(): string;

	public function is_available(): bool;

	/** Only called when available. */
	public function register(): void;

	/**
	 * Put a verified application (awaiting release) in the cart as one line, plus its GST-free
	 * government fee when given, and return the checkout URL.
	 *
	 * @param list<string>                                         $addon_products Add-on product slugs.
	 * @param array{kind?: string, label?: string, amount?: float} $government     ExpectedLines::expected()['government'], or array().
	 * @return string|\WP_Error Checkout URL.
	 */
	public function start_checkout( string $application_ref, string $product, array $addon_products, array $government = array() ): string|\WP_Error;

	/**
	 * Application refs in the visitor's cart for one product and environment (one monitoring
	 * order per entity per cart).
	 *
	 * @return list<string>
	 */
	public function refs_in_cart( string $product, string $env ): array;
}
