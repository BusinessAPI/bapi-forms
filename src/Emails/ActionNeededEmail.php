<?php
/**
 * Customer email: the agency asked for changes. Links to the amend form, else to the order.
 *
 * @package BapiForms
 */

namespace BapiForms\Emails;

defined( 'ABSPATH' ) || exit;

final class ActionNeededEmail extends ApplicationEmail {

	public function __construct() {
		$this->id             = 'bapi_forms_action_needed';
		$this->title          = __( 'Application: action needed', 'businessapi-forms' );
		$this->description    = __( 'Sent to the customer once when an application needs changes (BusinessAPI Forms). No application details are included.', 'businessapi-forms' );
		$this->template_html  = 'emails/bapi-forms-action-needed.php';
		$this->template_plain = 'emails/plain/bapi-forms-action-needed.php';
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Action needed on your application (order {order_number})', 'businessapi-forms' );
	}

	public function get_default_heading() {
		return __( 'Action needed on your application', 'businessapi-forms' );
	}

	public function get_default_additional_content() {
		return '';
	}
}
