<?php
/**
 * Pay first customer email: start your application, also sent as reminders.
 *
 * @package BapiForms
 */

namespace BapiForms\Emails;

defined( 'ABSPATH' ) || exit;

final class StartApplicationEmail extends ApplicationEmail {

	public function __construct() {
		$this->id             = 'bapi_forms_start_application';
		$this->title          = __( 'Application: start your application (pay first)', 'businessapi-forms' );
		$this->description    = __( 'Sent to the customer when a paid "pay first" application is ready for their details, and as reminders after 2, 7 and 21 days while it is not filled in (BusinessAPI Forms).', 'businessapi-forms' );
		$this->template_html  = 'emails/bapi-forms-start-application.php';
		$this->template_plain = 'emails/plain/bapi-forms-start-application.php';
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Start your application (order {order_number})', 'businessapi-forms' );
	}

	public function get_default_heading() {
		return __( 'Start your application', 'businessapi-forms' );
	}

	public function get_default_additional_content() {
		return '';
	}

	public function get_subject() {
		if ( empty( $this->bapi['reminder'] ) ) {
			return parent::get_subject();
		}
		$subject = $this->format_string( __( 'Reminder: we still need your details (order {order_number})', 'businessapi-forms' ) );
		return ! empty( $this->bapi['sandbox'] ) ? __( '[Sandbox test]', 'businessapi-forms' ) . ' ' . $subject : $subject;
	}

	public function get_heading() {
		return empty( $this->bapi['reminder'] ) ? parent::get_heading() : __( 'We still need your details', 'businessapi-forms' );
	}
}
