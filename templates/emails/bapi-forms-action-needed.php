<?php
/**
 * BusinessAPI Forms: action needed (HTML). Override: yourtheme/woocommerce/emails/bapi-forms-action-needed.php
 *
 * @package BapiForms
 * @var \WC_Order            $order
 * @var string               $email_heading
 * @var string               $additional_content
 * @var \WC_Email            $email
 * @var array<string, mixed> $bapi  sandbox (bool), product, short_ref, action_url, action_label
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
?>

<p>
	<?php
	/* translators: %s: customer first name. */
	echo '' !== $order->get_billing_first_name() ? esc_html( sprintf( __( 'Hi %s,', 'businessapi-forms' ), $order->get_billing_first_name() ) ) : esc_html__( 'Hello,', 'businessapi-forms' );
	?>
</p>
<?php if ( ! empty( $bapi['sandbox'] ) ) : ?>
<p><strong><?php esc_html_e( 'This was a test. Nothing was registered.', 'businessapi-forms' ); ?></strong></p>
<?php endif; ?>
<p>
	<?php
	if ( '' !== (string) ( $bapi['intro'] ?? '' ) ) {
		echo esc_html( (string) $bapi['intro'] );
	} else {
		/* translators: 1: product, e.g. Company registration, 2: Application #XXXXXX, 3: order number. */
		printf( esc_html__( 'The government agency has asked for some changes to your %1$s (%2$s, order %3$s) before it can be registered.', 'businessapi-forms' ), esc_html( (string) $bapi['product'] ), esc_html( (string) $bapi['short_ref'] ), esc_html( $order->get_order_number() ) );
	}
	?>
</p>
<?php if ( '' !== (string) $bapi['action_text'] ) : ?>
<p><?php echo esc_html( (string) $bapi['action_text'] ); ?></p>
<?php endif; ?>
<p><a class="button" href="<?php echo esc_url( (string) $bapi['action_url'] ); ?>"><?php echo esc_html( (string) $bapi['action_label'] ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
