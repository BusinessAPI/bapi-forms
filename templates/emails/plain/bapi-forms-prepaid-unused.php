<?php
/**
 * BusinessAPI Forms: paid application not filled in, pay first (plain text, to the partner). Override: yourtheme/woocommerce/emails/plain/bapi-forms-prepaid-unused.php
 *
 * @package BapiForms
 * @var \WC_Order            $order
 * @var string               $email_heading
 * @var string               $additional_content
 * @var array<string, mixed> $bapi  sandbox (bool), product, short_ref, ref, days, started (bool), order_url
 */

defined( 'ABSPATH' ) || exit;

use BapiForms\Emails\ApplicationEmail;

echo '= ' . esc_html( ApplicationEmail::plain( $email_heading ) ) . " =\n\n";
if ( ! empty( $bapi['sandbox'] ) ) {
	echo esc_html( ApplicationEmail::plain( __( 'Sandbox test order.', 'businessapi-forms' ) ) ) . "\n\n";
}
/* translators: 1: order number, 2: product, 3: application reference, 4: number of days. */
echo esc_html( ApplicationEmail::plain( sprintf( __( 'Your client paid for order #%1$s (%2$s, %3$s) %4$d days ago, but has not filled in the application yet.', 'businessapi-forms' ), $order->get_order_number(), (string) $bapi['product'], (string) $bapi['ref'], (int) $bapi['days'] ) ) ) . "\n\n";
echo esc_html( ApplicationEmail::plain( ! empty( $bapi['started'] ) ? __( 'They started the form but did not finish it.', 'businessapi-forms' ) : __( 'They have not started the form.', 'businessapi-forms' ) ) ) . "\n\n";
echo esc_html( ApplicationEmail::plain( \BapiForms\Emails\PrepaidUnusedEmail::reminded_text( $bapi ) . ' ' . __( 'Contact your client, or refund the order (that cancels the application). Nothing happens automatically.', 'businessapi-forms' ) ) ) . "\n\n";
echo esc_html( ApplicationEmail::plain( __( 'Open the order', 'businessapi-forms' ) ) ) . ': ' . esc_url( (string) $bapi['order_url'] ) . "\n\n";
if ( $additional_content ) {
	echo esc_html( ApplicationEmail::plain( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( ApplicationEmail::plain( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
