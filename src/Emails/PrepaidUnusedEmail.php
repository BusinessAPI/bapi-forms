<?php
/**
 * Pay first admin email: a paid application still not filled in after 30 days. Nothing is
 * cancelled or refunded automatically.
 *
 * @package BapiForms
 */

namespace BapiForms\Emails;

defined( 'ABSPATH' ) || exit;

final class PrepaidUnusedEmail extends ApplicationEmail {

	public function __construct() {
		$this->id             = 'bapi_forms_prepaid_unused';
		$this->title          = __( 'Application: paid but not filled in (pay first)', 'businessapi-forms' );
		$this->description    = __( 'Sent to you once when a client has not filled in a paid "pay first" application 30 days after payment (BusinessAPI Forms).', 'businessapi-forms' );
		$this->template_html  = 'emails/bapi-forms-prepaid-unused.php';
		$this->template_plain = 'emails/plain/bapi-forms-prepaid-unused.php';
		parent::__construct();
		$this->customer_email = false;
		$this->recipient      = $this->get_option( 'recipient', (string) get_option( 'admin_email' ) );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array(
				'recipient' => array(
					'title'       => __( 'Recipient(s)', 'businessapi-forms' ),
					'type'        => 'text',
					/* translators: %s: admin email. */
					'description' => sprintf( __( 'Enter recipients (comma separated). Defaults to %s.', 'businessapi-forms' ), '<code>' . esc_attr( (string) get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
			),
			(array) $this->form_fields
		);
	}

	public function get_default_subject() {
		return __( 'Paid application not filled in yet (order {order_number})', 'businessapi-forms' );
	}

	public function get_default_heading() {
		return __( 'Paid application not filled in yet', 'businessapi-forms' );
	}

	public function get_default_additional_content() {
		return '';
	}

	/**
	 * "We reminded them after 2, 7 and 21 days." from the reminder days actually used.
	 *
	 * @param array<string, mixed> $bapi Template data.
	 */
	public static function reminded_text( array $bapi ): string {
		$days = array_values( array_filter( array_map( 'floatval', (array) ( $bapi['reminder_days'] ?? array( 2, 7, 21 ) ) ), fn ( $d ) => $d > 0 ) );
		if ( ! $days ) {
			return __( 'No reminders were sent to them.', 'businessapi-forms' );
		}
		$labels = array_map( fn ( $d ) => rtrim( rtrim( number_format( $d, 2, '.', '' ), '0' ), '.' ), $days );
		/* translators: %s: list of numbers of days, e.g. "2, 7 and 21". */
		return sprintf( __( 'We reminded them after %s days.', 'businessapi-forms' ), wp_sprintf( '%l', $labels ) );
	}

	/**
	 * @param array<string, mixed> $data Template data.
	 */
	public function trigger( \WC_Order $order, array $data ): bool {
		$this->setup_locale();
		$this->object                         = $order;
		$this->bapi                           = $data;
		$this->recipient                      = '' !== (string) $this->get_option( 'recipient', '' ) ? (string) $this->get_option( 'recipient' ) : (string) get_option( 'admin_email' );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$sent                                 = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}
}
