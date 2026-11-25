<?php
/**
 * BAPI applications and form sessions. Only refs, statuses and request ids reach WordPress.
 *
 * Every call takes the environment the application was created in (stored with the order), so
 * switching the plugin between sandbox and live never misroutes a release.
 *
 * @package BapiForms
 */

namespace BapiForms\Applications;

use BapiForms\Api\Client;
use BapiForms\Api\Response;

defined( 'ABSPATH' ) || exit;

final class Applications {

	public const STATUS_AWAITING_RELEASE = 'awaiting_release';

	/** Statuses after which nothing changes any more. */
	public const TERMINAL = array( 'completed', 'cancelled', 'expired' );

	/** Lodgement started or finished. */
	public const LODGED = array( 'lodged', 'completed', 'action_needed' );

	/** Another worker is lodging it right now. */
	public const STATUS_LODGING = 'lodging';

	public function __construct( private readonly Client $client ) {}

	/**
	 * Generic bapi_forms_release / bapi_forms_cancel hooks for other payment plugins.
	 *
	 * These bypass every WooCommerce check (release guard, price check, order lock) and BAPI bills
	 * the partner on release. Fire bapi_forms_release only after confirming a real payment above
	 * $0 that covers the government fees, and never with a ref taken from a request.
	 */
	public function register(): void {
		add_action( 'bapi_forms_release', array( $this, 'on_release_hook' ) );
		add_action( 'bapi_forms_cancel', array( $this, 'on_cancel_hook' ) );
	}

	/**
	 * @param mixed $ref Application reference.
	 */
	public function on_release_hook( $ref ): void {
		$ref = (string) $ref;
		if ( self::is_ref( $ref ) ) {
			/** Fires after a release triggered through the generic hook. */
			do_action( 'bapi_forms_released', $ref, $this->release( $ref ) );
		}
	}

	/**
	 * @param mixed $ref Application reference.
	 */
	public function on_cancel_hook( $ref ): void {
		$ref = (string) $ref;
		if ( self::is_ref( $ref ) ) {
			/** Fires after a cancel triggered through the generic hook. */
			do_action( 'bapi_forms_cancelled', $ref, $this->cancel( $ref ) );
		}
	}

	/**
	 * Hosted form session: {token, url, applicationRef, expiresAt}. For a refused ref BAPI opens
	 * its amendment (`mode: amend`).
	 *
	 * @param array<string, mixed> $options Non-PII options. Pay first sends only branding and
	 *                                      monitoring.ownedRefs (the rest was set at creation).
	 */
	public function create_session( string $type, string $parent_origin, array $options, ?string $application_ref = null, ?string $environment = null ): Response {
		$body = array(
			'type'         => $type,
			'parentOrigin' => $parent_origin,
			'options'      => (object) $options,
		);
		if ( null !== $application_ref ) {
			$body['applicationRef'] = $application_ref;
		}
		return $this->client->post( 'form-sessions', $body, $environment );
	}

	/**
	 * Pay first: create the prepaid application on payment. The Idempotency-Key makes a retried
	 * job get the same application back, never a second one.
	 *
	 * @param array<string, mixed> $options Non-PII options: flow, prepaid, addons, fixed, asicNotices, branding.
	 */
	public function create( string $type, array $options, string $idempotency_key, string $environment ): Response {
		return $this->client->request(
			'POST',
			'applications',
			array(),
			array(
				'type'    => $type,
				'options' => (object) $options,
			),
			$environment,
			array( 'Idempotency-Key' => $idempotency_key )
		);
	}

	/**
	 * Merge options (never payload). 409 options_frozen once a release was asked for.
	 *
	 * @param array<string, mixed> $options Options to merge.
	 */
	public function patch_options( string $ref, array $options, ?string $environment = null ): Response {
		return $this->client->request( 'PATCH', 'applications/' . rawurlencode( $ref ), array(), array( 'options' => (object) $options ), $environment );
	}

	/**
	 * Pause or resume before lodgement. While held BAPI never releases it (a finished form stays
	 * awaiting_release); clearing the hold releases nothing by itself.
	 */
	public function hold( string $ref, bool $hold, ?string $environment = null ): Response {
		return $this->patch_options( $ref, array( 'hold' => $hold ), $environment );
	}

	/**
	 * Summary without applicant data: {ref, type, status, channel, environment, addons, pricing}.
	 *
	 * @param list<string> $compare Monitoring only: the visitor's other refs; BAPI then lists those
	 *                              for the same entity in monitoring.sameEntity.
	 */
	public function summary( string $ref, ?string $environment = null, array $compare = array() ): Response {
		$compare = array_slice( array_values( array_unique( array_filter( $compare, array( self::class, 'is_ref' ) ) ) ), 0, 200 );
		return $this->client->get( 'applications/' . rawurlencode( $ref ) . '/summary', $compare ? array( 'compare' => implode( ',', $compare ) ) : array(), $environment );
	}

	/** Idempotent: awaiting_release -> released -> lodged. */
	public function release( string $ref, ?string $environment = null ): Response {
		return $this->client->post( 'applications/' . rawurlencode( $ref ) . '/release', array(), $environment );
	}

	/** Idempotent; BAPI refuses once lodged. */
	public function cancel( string $ref, ?string $environment = null ): Response {
		return $this->client->post( 'applications/' . rawurlencode( $ref ) . '/cancel', array(), $environment );
	}

	/** Order refunded or cancelled after release: BAPI closes the amend chain. Idempotent. */
	public function close( string $ref, ?string $environment = null ): Response {
		return $this->client->post( 'applications/' . rawurlencode( $ref ) . '/close', array(), $environment );
	}

	/** {status, requests: [{id, role, status, acn?, abn?}], actionNeeded?} */
	public function status( string $ref, ?string $environment = null ): Response {
		return $this->client->get( 'applications/' . rawurlencode( $ref ) . '/status', array(), $environment );
	}

	/**
	 * Current ASIC fee payment link for a business name paid by card (renewed by BAPI when
	 * expired). Partner only: never store the URL or show it to the customer.
	 */
	public function payment_link( string $ref, ?string $environment = null ): Response {
		return $this->client->get( 'applications/' . rawurlencode( $ref ) . '/payment-link', array(), $environment );
	}

	/** Application refs are ULIDs (Crockford base32, 26 chars). */
	public static function is_ref( string $ref ): bool {
		return 1 === preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/i', $ref );
	}
}
