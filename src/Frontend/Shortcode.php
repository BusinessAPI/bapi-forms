<?php
/**
 * [bapi_form product="..."] shortcode.
 *
 * Checks the serving gate locally (never a remote call while the page renders) and outputs the
 * container that assets/js/bapi-forms.js turns into the hosted form iframe.
 *
 * @package BapiForms
 */

namespace BapiForms\Frontend;

use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const TAG = 'bapi_form';

	public const SCRIPT = 'bapi-forms';

	/** Widest the form wrapper gets (hosted form ~784px plus room for its padding). */
	public const MAX_WIDTH = 820;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts  = shortcode_atts(
			array(
				'product' => '',
				'align'   => 'wide',
			),
			(array) $atts,
			self::TAG
		);
		$slug  = sanitize_key( $atts['product'] );
		$align = in_array( $atts['align'], array( 'wide', 'full', 'none' ), true ) ? $atts['align'] : 'wide';

		$problem = $this->problem( $slug );
		if ( null !== $problem ) {
			// Visitors see nothing; admins see why.
			return current_user_can( 'manage_options' )
				? sprintf( '<div class="bapi-forms-form-admin-notice" style="padding:12px;border:1px dashed #d63638;"><strong>BusinessAPI Forms:</strong> %s</div>', esc_html( $problem ) )
				: '';
		}

		// A draft started before switching to pay first still reopens from "Continue your application".
		$resuming = isset( $_GET[ MyAccount::RESUME_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( \BapiForms\Settings\Options::FLOW_PAY_FIRST === $this->plugin->options->flow( $slug ) && ! $resuming ) {
			return $this->buy_card( $slug, $align );
		}

		$this->enqueue();

		// The application is only created in Business API when the visitor clicks Start.
		$label = (string) ( $this->plugin->products->all()[ $slug ]['label'] ?? '' );
		// The hosted form (~784px) is wider than many content columns. alignwide gives it room in
		// block themes; max-width 100% stops themes that widen alignwide with negative margins
		// from scrolling phones sideways.
		$wrapper = 'full' === $align
			? array( 'alignfull', 'max-width:100%;box-sizing:border-box;' )
			: array( 'none' === $align ? '' : 'alignwide', 'max-width:min(' . self::MAX_WIDTH . 'px,100%);margin-left:auto;margin-right:auto;box-sizing:border-box;' );

		return sprintf(
			'<div class="%6$s" style="%7$s" data-product="%1$s"><div class="bapi-forms-form__start" style="padding:24px;border:1px solid #e5e7eb;border-radius:8px;text-align:center;"><p style="margin:0 0 4px;font-weight:600;">%2$s</p><p style="margin:0 0 16px;">%3$s</p><button type="button" class="bapi-forms-form__start-button button wp-element-button">%4$s</button></div><p class="bapi-forms-form__status" role="status" hidden></p><noscript>%5$s</noscript></div>',
			esc_attr( $slug ),
			esc_html( $label ),
			esc_html__( 'Your details are saved securely as you go, so you can come back to finish later.', 'businessapi-forms' ),
			esc_html__( 'Start your application', 'businessapi-forms' ),
			esc_html__( 'Please enable JavaScript to use this form.', 'businessapi-forms' ),
			esc_attr( trim( 'bapi-forms-form ' . $wrapper[0] ) ),
			esc_attr( $wrapper[1] )
		);
	}

	/** Pay first: "Buy now" card linking to the product's shop page. */
	private function buy_card( string $slug, string $align ): string {
		$product = class_exists( '\BapiForms\Commerce\WooCommerce\PayFirstProducts' ) ? \BapiForms\Commerce\WooCommerce\PayFirstProducts::product_for( $slug ) : null;
		if ( null === $product || 'publish' !== $product->get_status() ) {
			return current_user_can( 'manage_options' )
				? sprintf( '<div class="bapi-forms-form-admin-notice" style="padding:12px;border:1px dashed #d63638;"><strong>BusinessAPI Forms:</strong> %s</div>', esc_html__( 'This product is set to "Pay first" but its shop product is missing. Open BusinessAPI Forms > Products and save.', 'businessapi-forms' ) )
				: '';
		}
		$style = 'full' === $align ? 'max-width:100%;box-sizing:border-box;' : 'max-width:min(' . self::MAX_WIDTH . 'px,100%);margin-left:auto;margin-right:auto;box-sizing:border-box;';
		return sprintf(
			'<div class="bapi-forms-form-buy %1$s" style="%2$s"><div style="padding:24px;border:1px solid #e5e7eb;border-radius:8px;text-align:center;"><p style="margin:0 0 4px;font-weight:600;">%3$s</p><p style="margin:0 0 4px;">%4$s</p><p style="margin:0 0 16px;">%5$s</p><a class="button wp-element-button" href="%6$s">%7$s</a></div></div>',
			esc_attr( 'none' === $align ? '' : ( 'full' === $align ? 'alignfull' : 'alignwide' ) ),
			esc_attr( $style ),
			esc_html( $product->get_name() ),
			wp_kses_post( $product->get_price_html() ),
			esc_html__( 'Pay first, then fill in your application online. Your details are saved securely as you go.', 'businessapi-forms' ),
			esc_url( $product->get_permalink() ),
			esc_html__( 'Buy now', 'businessapi-forms' )
		);
	}

	/** Also used by MyAccount for the amend form on the order page. */
	public function enqueue(): void {
		if ( wp_script_is( self::SCRIPT, 'enqueued' ) ) {
			return;
		}

		$branding = $this->plugin->options->branding_for_bapi();
		$accent   = (string) ( $branding['primary'] ?? '' );

		wp_enqueue_script( self::SCRIPT, BAPI_FORMS_URL . 'assets/js/bapi-forms.js', array(), BAPI_FORMS_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			self::SCRIPT,
			'bapiForms',
			array(
				'restUrl'    => esc_url_raw( rest_url( RestController::NAMESPACE . '/' ) ),
				// Nonces are fetched from this uncached URL, never baked into (cacheable) HTML.
				'nonceUrl'   => esc_url_raw( add_query_arg( 'action', 'bapi_forms_nonce', admin_url( 'admin-ajax.php' ) ) ),
				// Same routes through admin-ajax when the site blocks the REST API for visitors.
				'ajaxUrl'    => esc_url_raw( AjaxBridge::url() ),
				'bapiOrigin' => RestController::bapi_origin(),
				// Overlay buttons match the hosted form. Empty: the theme's button style.
				'accent'     => $accent,
				'accentText' => (string) ( $branding['onPrimary'] ?? '' ),
				'returnArg'  => \BapiForms\Commerce\WooCommerce\Carriers::RETURN_ARG,
				'i18n'       => array(
					'title'            => __( 'Application form', 'businessapi-forms' ),
					'inProgress'       => __( 'In progress.', 'businessapi-forms' ),
					'loading'          => __( 'Loading form...', 'businessapi-forms' ),
					'otherTab'         => __( 'This application is open in another tab.', 'businessapi-forms' ),
					'continueHere'     => __( 'Continue here', 'businessapi-forms' ),
					'error'            => __( 'The form could not be loaded. Please refresh the page or try again later.', 'businessapi-forms' ),
					'checkout'         => __( 'Taking you to checkout...', 'businessapi-forms' ),
					'cartFail'         => __( 'Your application is saved but we could not start checkout. Please refresh the page or contact us.', 'businessapi-forms' ),
					'cartRetry'        => __( 'Your application is saved, but we could not reach our registration service to start checkout. Please try again in a moment.', 'businessapi-forms' ),
					'tryAgain'         => __( 'Try again', 'businessapi-forms' ),
					'loadTimeout'      => __( 'The form could not load. Please refresh the page. Anything you entered is saved.', 'businessapi-forms' ),
					'expired'          => __( 'This form timed out. Your details are saved up to your last step.', 'businessapi-forms' ),
					'reload'           => __( 'Reload the form', 'businessapi-forms' ),
					'amendStale'       => __( 'This page has been open a while. Reload the page to keep editing.', 'businessapi-forms' ),
					'reloadPage'       => __( 'Reload the page', 'businessapi-forms' ),
					'returned'         => __( 'Your application is saved and ready for checkout.', 'businessapi-forms' ),
					'toCheckout'       => __( 'Continue to checkout', 'businessapi-forms' ),
					'sessionExpired'   => __( 'Your session has expired. Please refresh the page and try again.', 'businessapi-forms' ),
					'resubmitted'      => __( 'Thanks, your corrected application has been sent. There is no extra charge. We will let you know when it is finished.', 'businessapi-forms' ),
					// Pay first.
					'prepaidDone'      => __( 'Thanks, your application has been sent. We will email you when it is finished.', 'businessapi-forms' ),
					'prepaidHeld'      => __( 'Thanks, we have your details. We will be in touch.', 'businessapi-forms' ),
					'preparing'        => __( 'Getting your form ready...', 'businessapi-forms' ),
					'preparingSlow'    => __( 'Your form is taking longer than usual to get ready. We will email you the link as soon as it is ready.', 'businessapi-forms' ),
					'notAvailable'     => __( 'This form is not available right now. We have been told and will be in touch.', 'businessapi-forms' ),
					'alreadyMonitored' => __( 'This entity is already monitored. If you paid for this by mistake, please contact us for a refund.', 'businessapi-forms' ),
					'payFirst'         => __( 'Please buy this first. You fill in the form right after payment.', 'businessapi-forms' ),
					'buyNow'           => __( 'Buy now', 'businessapi-forms' ),
				),
			)
		);
	}

	/** Why a product's form cannot be served right now, or null when it can. */
	public function problem( string $slug ): ?string {
		if ( ! $this->plugin->commerce->has_active() ) {
			return sprintf(
				/* translators: %s: approved ecommerce plugins. */
				__( 'Forms are not served until an approved ecommerce plugin is active (%s).', 'businessapi-forms' ),
				$this->plugin->commerce->approved_labels()
			);
		}

		if ( ! $this->plugin->options->has_key() ) {
			return __( 'No Business API key is set for the current environment.', 'businessapi-forms' );
		}

		if ( '' === $slug || ! $this->plugin->products->exists( $slug ) ) {
			return __( 'Unknown or missing product in the shortcode.', 'businessapi-forms' );
		}

		// Live eligibility is checked when the visitor clicks Start (RestController::gate()).
		if ( ! $this->plugin->products->is_enabled( $slug ) ) {
			return __( 'This product is switched off in BusinessAPI Forms > Settings.', 'businessapi-forms' );
		}

		return null;
	}
}
