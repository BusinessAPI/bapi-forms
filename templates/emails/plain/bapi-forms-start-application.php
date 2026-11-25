<?php
/**
 * BusinessAPI Forms: start your application, pay first (plain text). Override: yourtheme/woocommerce/emails/plain/bapi-forms-start-application.php
 *
 * @package BapiForms
 * @var \WC_Order            $order
 * @var string               $email_heading
 * @var string               $additional_content
 * @var array<string, mixed> $bapi  sandbox (bool), reminder (bool), product, short_ref, start_url, fixed
 */

defined( 'ABSPATH' ) || exit;

use BapiForms\Emails\ApplicationEmail;

echo '= ' . esc_html( ApplicationEmail::plain( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name. */
echo esc_html( ApplicationEmail::plain( '' !== $order->get_billing_first_name() ? sprintf( __( 'Hi %s,', 'businessapi-forms' ), $order->get_billing_first_name() ) : __( 'Hello,', 'businessapi-forms' ) ) ) . "\n\n";
if ( ! empty( $bapi['sandbox'] ) ) {
	echo esc_html( ApplicationEmail::plain( __( 'This is a test. Nothing will be registered.', 'businessapi-forms' ) ) ) . "\n\n";
}
if ( ! empty( $bapi['reminder'] ) ) {
	/* translators: 1: product, 2: Application #XXXXXX, 3: order number. */
	echo esc_html( ApplicationEmail::plain( sprintf( __( 'You paid for your %1$s (%2$s, order %3$s), but we still need your details before we can lodge it.', 'businessapi-forms' ), (string) $bapi['product'], (string) $bapi['short_ref'], $order->get_order_number() ) ) ) . "\n\n";
} else {
	/* translators: 1: product, 2: Application #XXXXXX, 3: order number. */
	echo esc_html( ApplicationEmail::plain( sprintf( __( 'Thanks for your payment. Your %1$s (%2$s, order %3$s) is ready for your details.', 'businessapi-forms' ), (string) $bapi['product'], (string) $bapi['short_ref'], $order->get_order_number() ) ) ) . "\n\n";
}
if ( '' !== (string) ( $bapi['fixed'] ?? '' ) ) {
	echo esc_html( ApplicationEmail::plain( $bapi['fixed'] ) ) . "\n\n";
}
echo esc_html( ApplicationEmail::plain( __( 'Fill in your application', 'businessapi-forms' ) ) ) . ': ' . esc_url( (string) $bapi['start_url'] ) . "\n\n";
echo esc_html( ApplicationEmail::plain( __( 'Your details are saved as you go: you can stop and come back with this link. We lodge your application as soon as you finish.', 'businessapi-forms' ) ) ) . "\n\n";
if ( $additional_content ) {
	echo esc_html( ApplicationEmail::plain( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( ApplicationEmail::plain( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
