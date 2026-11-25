<?php
/**
 * BusinessAPI Forms: action needed (plain text). Override: yourtheme/woocommerce/emails/plain/bapi-forms-action-needed.php
 *
 * @package BapiForms
 * @var \WC_Order            $order
 * @var string               $email_heading
 * @var string               $additional_content
 * @var array<string, mixed> $bapi  sandbox (bool), intro, product, short_ref, action_text, action_url, action_label
 */

defined( 'ABSPATH' ) || exit;

use BapiForms\Emails\ApplicationEmail;

echo '= ' . esc_html( ApplicationEmail::plain( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name. */
echo esc_html( ApplicationEmail::plain( '' !== $order->get_billing_first_name() ? sprintf( __( 'Hi %s,', 'businessapi-forms' ), $order->get_billing_first_name() ) : __( 'Hello,', 'businessapi-forms' ) ) ) . "\n\n";
if ( ! empty( $bapi['sandbox'] ) ) {
	echo esc_html( ApplicationEmail::plain( __( 'This was a test. Nothing was registered.', 'businessapi-forms' ) ) ) . "\n\n";
}
if ( '' !== (string) ( $bapi['intro'] ?? '' ) ) {
	echo esc_html( ApplicationEmail::plain( $bapi['intro'] ) ) . "\n\n";
} else {
	/* translators: 1: product, 2: Application #XXXXXX, 3: order number. */
	echo esc_html( ApplicationEmail::plain( sprintf( __( 'The government agency has asked for some changes to your %1$s (%2$s, order %3$s) before it can be registered.', 'businessapi-forms' ), (string) $bapi['product'], (string) $bapi['short_ref'], $order->get_order_number() ) ) ) . "\n\n";
}
if ( '' !== (string) $bapi['action_text'] ) {
	echo esc_html( ApplicationEmail::plain( $bapi['action_text'] ) ) . "\n\n";
}
echo esc_html( ApplicationEmail::plain( $bapi['action_label'] ) ) . ': ' . esc_url( (string) $bapi['action_url'] ) . "\n\n";
if ( $additional_content ) {
	echo esc_html( ApplicationEmail::plain( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( ApplicationEmail::plain( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
