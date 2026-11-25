<?php
/**
 * BusinessAPI Forms: start your application, pay first (HTML). Override: yourtheme/woocommerce/emails/bapi-forms-start-application.php
 *
 * @package BapiForms
 * @var \WC_Order            $order
 * @var string               $email_heading
 * @var string               $additional_content
 * @var \WC_Email            $email
 * @var array<string, mixed> $bapi  sandbox (bool), reminder (bool), product, short_ref, start_url, fixed
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
<p><strong><?php esc_html_e( 'This is a test. Nothing will be registered.', 'businessapi-forms' ); ?></strong></p>
<?php endif; ?>
<p>
	<?php
	if ( ! empty( $bapi['reminder'] ) ) {
		/* translators: 1: product, e.g. Company registration, 2: Application #XXXXXX, 3: order number. */
		printf( esc_html__( 'You paid for your %1$s (%2$s, order %3$s), but we still need your details before we can lodge it.', 'businessapi-forms' ), esc_html( (string) $bapi['product'] ), esc_html( (string) $bapi['short_ref'] ), esc_html( $order->get_order_number() ) );
	} else {
		/* translators: 1: product, e.g. Company registration, 2: Application #XXXXXX, 3: order number. */
		printf( esc_html__( 'Thanks for your payment. Your %1$s (%2$s, order %3$s) is ready for your details.', 'businessapi-forms' ), esc_html( (string) $bapi['product'] ), esc_html( (string) $bapi['short_ref'] ), esc_html( $order->get_order_number() ) );
	}
	?>
</p>
<?php if ( '' !== (string) ( $bapi['fixed'] ?? '' ) ) : ?>
<p><?php echo esc_html( (string) $bapi['fixed'] ); ?></p>
<?php endif; ?>
<p><a class="button" href="<?php echo esc_url( (string) $bapi['start_url'] ); ?>"><?php esc_html_e( 'Fill in your application', 'businessapi-forms' ); ?></a></p>
<p><?php esc_html_e( 'Your details are saved as you go: you can stop and come back with this link. We lodge your application as soon as you finish.', 'businessapi-forms' ); ?></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
