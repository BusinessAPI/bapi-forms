<?php
/**
 * Customer email: application completed, with its number and partner-domain document links.
 *
 * @package BapiForms
 */

namespace BapiForms\Emails;

defined( 'ABSPATH' ) || exit;

final class CompletedEmail extends ApplicationEmail {

	public function __construct() {
		$this->id             = 'bapi_forms_completed';
		$this->title          = __( 'Application: completed', 'businessapi-forms' );
		$this->description    = __( 'Sent to the customer once when an application is completed (BusinessAPI Forms), with the ACN, ABN or business name document number and links to download the documents.', 'businessapi-forms' );
		$this->template_html  = 'emails/bapi-forms-completed.php';
		$this->template_plain = 'emails/plain/bapi-forms-completed.php';
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your application is complete (order {order_number})', 'businessapi-forms' );
	}

	public function get_default_heading() {
		return __( 'Your application is complete', 'businessapi-forms' );
	}

	public function get_default_additional_content() {
		return __( 'Please keep these documents somewhere safe.', 'businessapi-forms' );
	}
}
