<?php
/**
 * "BusinessAPI Forms: Registration form" block.
 *
 * Renders the shortcode on the front end. Editor and other REST renders get a static
 * placeholder, so no form session can be started from there.
 *
 * @package BapiForms
 */

namespace BapiForms\Frontend;

use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class Block {

	public const EDITOR_SCRIPT = 'bapi-forms-block-editor';

	/** Internal rest_do_request calls (editor preloading) do not define REST_REQUEST. */
	private int $rest_depth = 0;

	public function __construct(
		private readonly Plugin $plugin,
		private readonly Shortcode $shortcode,
	) {}

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'localize' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'enter_rest' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'leave_rest' ) );
	}

	/**
	 * @param mixed $response Passed through unchanged.
	 * @return mixed
	 */
	public function enter_rest( $response ) {
		++$this->rest_depth;
		return $response;
	}

	/**
	 * @param mixed $response Passed through unchanged.
	 * @return mixed
	 */
	public function leave_rest( $response ) {
		$this->rest_depth = max( 0, $this->rest_depth - 1 );
		return $response;
	}

	public function register_block(): void {
		$dir = BAPI_FORMS_DIR . 'blocks/registration-form/';

		wp_register_script(
			self::EDITOR_SCRIPT,
			BAPI_FORMS_URL . 'blocks/registration-form/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			BAPI_FORMS_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_SCRIPT, 'businessapi-forms' );

		register_block_type( $dir, array( 'render_callback' => array( $this, 'render' ) ) );
	}

	/** Products for the editor's select. Add-ons are offered inside their parent form. */
	public function localize(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$products = array();
		foreach ( $this->plugin->products->all() as $slug => $product ) {
			if ( ! empty( $product['requires'] ) || empty( $product['type'] ) ) {
				continue;
			}
			$products[] = array(
				'slug'   => (string) $slug,
				'label'  => (string) $product['label'],
				'active' => $this->plugin->products->is_active( (string) $slug ),
			);
		}

		wp_add_inline_script(
			self::EDITOR_SCRIPT,
			'window.bapiFormsBlock = ' . wp_json_encode( array( 'products' => $products ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( $attributes ): string {
		$attributes = (array) $attributes;
		$slug       = sanitize_key( (string) ( $attributes['product'] ?? '' ) );
		$align      = in_array( $attributes['align'] ?? '', array( 'wide', 'full' ), true ) ? $attributes['align'] : 'none';

		if ( $this->is_editor_render() ) {
			return $this->placeholder( $slug );
		}

		return $this->shortcode->render(
			array(
				'product' => $slug,
				'align'   => $align,
			)
		);
	}

	/** Editor, site editor and widget previews render through REST. */
	private function is_editor_render(): bool {
		return $this->rest_depth > 0 || is_admin() || wp_is_serving_rest_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	private function placeholder( string $slug ): string {
		$product = '' !== $slug ? ( $this->plugin->products->all()[ $slug ] ?? null ) : null;
		if ( is_array( $product ) ) {
			$label = (string) $product['label'];
		} else {
			$label = '' === $slug ? __( 'No form selected', 'businessapi-forms' ) : __( 'Unknown form', 'businessapi-forms' );
		}
		$problem = $this->shortcode->problem( $slug );

		if ( null === $problem ) {
			$status = __( 'Visitors see a "Start your application" card here that opens the hosted form.', 'businessapi-forms' );
			$color  = '#00a32a';
		} elseif ( current_user_can( 'manage_options' ) ) {
			/* translators: %s: reason the form is not shown. */
			$status = sprintf( __( 'Hidden from visitors: %s', 'businessapi-forms' ), $problem );
			$color  = '#d63638';
		} else {
			$status = __( 'Hidden from visitors until a site administrator finishes the BusinessAPI Forms setup.', 'businessapi-forms' );
			$color  = '#d63638';
		}

		return sprintf(
			'<div class="bapi-forms-form-block-preview" style="padding:24px;border:1px dashed #c3c4c7;border-radius:8px;text-align:center;background:#f6f7f7;color:#1e1e1e;"><p style="margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970;">%1$s</p><p style="margin:0 0 8px;font-weight:600;">%2$s</p><p style="margin:0;font-size:13px;color:%3$s;">%4$s</p></div>',
			esc_html__( 'BusinessAPI Forms', 'businessapi-forms' ),
			esc_html( $label ),
			esc_attr( $color ),
			esc_html( $status )
		);
	}
}
