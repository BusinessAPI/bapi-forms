<?php
/**
 * BusinessAPI Forms: completed (HTML). Override: yourtheme/woocommerce/emails/bapi-forms-completed.php
 *
 * @package BapiForms
 * @var \WC_Order            $order
 * @var string               $email_heading
 * @var string               $additional_content
 * @var \WC_Email            $email
 * @var array<string, mixed> $bapi  sandbox (bool), product, short_ref, numbers (list of "ACN 123 456 789"), documents (list of {label, url}), account_url
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
	/* translators: 1: product, e.g. Company registration, 2: Application #XXXXXX, 3: order number. */
	printf( esc_html__( 'Good news: your %1$s (%2$s, order %3$s) is complete.', 'businessapi-forms' ), esc_html( (string) $bapi['product'] ), esc_html( (string) $bapi['short_ref'] ), esc_html( $order->get_order_number() ) );
	?>
</p>
<?php if ( ! empty( $bapi['numbers'] ) ) : ?>
	<p>
		<?php foreach ( (array) $bapi['numbers'] as $bapi_forms_number ) : ?>
			<strong><?php echo esc_html( (string) $bapi_forms_number ); ?></strong><br>
		<?php endforeach; ?>
	</p>
<?php endif; ?>
<?php if ( ! empty( $bapi['documents'] ) ) : ?>
	<h2><?php esc_html_e( 'Your documents', 'businessapi-forms' ); ?></h2>
	<ul>
		<?php foreach ( (array) $bapi['documents'] as $bapi_forms_document ) : ?>
			<li><a href="<?php echo esc_url( (string) $bapi_forms_document['url'] ); ?>"><?php echo esc_html( (string) $bapi_forms_document['label'] ); ?></a></li>
		<?php endforeach; ?>
	</ul>
	<p><?php esc_html_e( 'You can also download them any time from your order.', 'businessapi-forms' ); ?></p>
<?php else : ?>
	<p><?php esc_html_e( 'Your documents will be on your order as soon as they are ready.', 'businessapi-forms' ); ?></p>
<?php endif; ?>
<p><a class="button" href="<?php echo esc_url( (string) $bapi['account_url'] ); ?>"><?php esc_html_e( 'View your order', 'businessapi-forms' ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
