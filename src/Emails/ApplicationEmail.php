<?php
/**
 * Base for our WooCommerce emails (templates overridable in yourtheme/woocommerce/emails/).
 * Loaded only from the woocommerce_email_classes filter, when WC_Email exists.
 *
 * @package BapiForms
 */

namespace BapiForms\Emails;

defined( 'ABSPATH' ) || exit;

abstract class ApplicationEmail extends \WC_Email {

	/**
	 * Template data: product label, short ref, number, links, sandbox flag. Never form data.
	 *
	 * @var array<string, mixed>
	 */
	public array $bapi = array();

	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = BAPI_FORMS_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{order_date}'   => '',
		);
		parent::__construct();
	}

	/**
	 * @param array<string, mixed> $data Template data (see $bapi).
	 * @return bool Sent.
	 */
	public function trigger( \WC_Order $order, array $data ): bool {
		$this->setup_locale();
		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$this->bapi                           = $data;
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	public function get_subject() {
		$subject = parent::get_subject();
		return ! empty( $this->bapi['sandbox'] ) ? __( '[Sandbox test]', 'businessapi-forms' ) . ' ' . $subject : $subject;
	}

	/** Plain text without tags or entities, keeping line breaks. */
	public static function plain( $text ): string {
		$text = (string) preg_replace( '#<br\s*/?>|</p>#i', "\n", (string) $text );
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t" );
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/** Templates escape their output; entities are decoded back to plain characters here. */
	public function get_content_plain() {
		return html_entity_decode( wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** @return array<string, mixed> */
	private function template_args( bool $plain ): array {
		return array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
			'bapi'               => $this->bapi,
		);
	}
}
