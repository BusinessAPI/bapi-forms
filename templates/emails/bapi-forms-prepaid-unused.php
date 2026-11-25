<?php
/**
 * BusinessAPI Forms: paid application not filled in, pay first (HTML, to the partner). Override: yourtheme/woocommerce/emails/bapi-forms-prepaid-unused.php
 *
 * @package BapiForms
 * @var \WC_Order            $order
 * @var string               $email_heading
 * @var string               $additional_content
 * @var \WC_Email            $email
 * @var array<string, mixed> $bapi  sandbox (bool), product, short_ref, ref, days, started (bool), order_url
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
?>

<?php if ( ! empty( $bapi['sandbox'] ) ) : ?>
<p><strong><?php esc_html_e( 'Sandbox test order.', 'businessapi-forms' ); ?></strong></p>
<?php endif; ?>
<p>
	<?php
	/* translators: 1: order number, 2: product, 3: application reference, 4: number of days. */
	printf( esc_html__( 'Your client paid for order #%1$s (%2$s, %3$s) %4$d days ago, but has not filled in the application yet.', 'businessapi-forms' ), esc_html( $order->get_order_number() ), esc_html( (string) $bapi['product'] ), esc_html( (string) $bapi['ref'] ), (int) $bapi['days'] );
	?>
</p>
<p><?php echo esc_html( ! empty( $bapi['started'] ) ? __( 'They started the form but did not finish it.', 'businessapi-forms' ) : __( 'They have not started the form.', 'businessapi-forms' ) ); ?></p>
<p><?php echo esc_html( \BapiForms\Emails\PrepaidUnusedEmail::reminded_text( $bapi ) . ' ' . __( 'Contact your client, or refund the order (that cancels the application). Nothing happens automatically.', 'businessapi-forms' ) ); ?></p>
<p><a class="button" href="<?php echo esc_url( (string) $bapi['order_url'] ); ?>"><?php esc_html_e( 'Open the order', 'businessapi-forms' ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
