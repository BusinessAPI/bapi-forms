<?php
/**
 * AUD amounts: comparison tolerance and plain-text formatting for notes, emails and errors.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Money {

	/** Half a cent: amounts closer than this are equal. */
	public const EPSILON = 0.005;

	/** "$110.00" without HTML, in the shop currency or the one given. */
	public static function plain( float $amount, string $currency = '' ): string {
		$args = '' !== $currency ? array( 'currency' => $currency ) : array();
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' );
	}
}
