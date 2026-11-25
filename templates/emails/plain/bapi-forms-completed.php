<?php
/**
 * BusinessAPI Forms: completed (plain text). Override: yourtheme/woocommerce/emails/plain/bapi-forms-completed.php
 *
 * @package BapiForms
 * @var \WC_Order            $order
 * @var string               $email_heading
 * @var string               $additional_content
 * @var array<string, mixed> $bapi  sandbox (bool), product, short_ref, numbers, documents ({label, url}), account_url
 */

defined( 'ABSPATH' ) || exit;

use BapiForms\Emails\ApplicationEmail;

echo '= ' . esc_html( ApplicationEmail::plain( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name. */
echo esc_html( ApplicationEmail::plain( '' !== $order->get_billing_first_name() ? sprintf( __( 'Hi %s,', 'businessapi-forms' ), $order->get_billing_first_name() ) : __( 'Hello,', 'businessapi-forms' ) ) ) . "\n\n";
if ( ! empty( $bapi['sandbox'] ) ) {
	echo esc_html( ApplicationEmail::plain( __( 'This was a test. Nothing was registered.', 'businessapi-forms' ) ) ) . "\n\n";
}
/* translators: 1: product, 2: Application #XXXXXX, 3: order number. */
echo esc_html( ApplicationEmail::plain( sprintf( __( 'Good news: your %1$s (%2$s, order %3$s) is complete.', 'businessapi-forms' ), (string) $bapi['product'], (string) $bapi['short_ref'], $order->get_order_number() ) ) ) . "\n\n";
foreach ( (array) ( $bapi['numbers'] ?? array() ) as $bapi_forms_number ) {
	echo esc_html( ApplicationEmail::plain( $bapi_forms_number ) ) . "\n";
}
echo "\n";
if ( ! empty( $bapi['documents'] ) ) {
	echo esc_html( ApplicationEmail::plain( __( 'Your documents:', 'businessapi-forms' ) ) ) . "\n";
	foreach ( (array) $bapi['documents'] as $bapi_forms_document ) {
		echo '- ' . esc_html( ApplicationEmail::plain( $bapi_forms_document['label'] ) ) . ': ' . esc_url( (string) $bapi_forms_document['url'] ) . "\n";
	}
	echo "\n";
} else {
	echo esc_html( ApplicationEmail::plain( __( 'Your documents will be on your order as soon as they are ready.', 'businessapi-forms' ) ) ) . "\n\n";
}
echo esc_html( ApplicationEmail::plain( __( 'View your order', 'businessapi-forms' ) ) ) . ': ' . esc_url( (string) $bapi['account_url'] ) . "\n\n";
if ( $additional_content ) {
	echo esc_html( ApplicationEmail::plain( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( ApplicationEmail::plain( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
