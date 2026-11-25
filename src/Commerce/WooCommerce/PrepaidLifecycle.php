<?php
/**
 * Pay-first side of OrderBridge: creating prepaid applications, the prepaid poll, pause/resume
 * and cancelling slots never issued. Runs under OrderBridge::with_lock() unless noted.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Api\Response;
use BapiForms\Applications\Applications;
use BapiForms\Emails\PrepaidEmails;
use BapiForms\Plugin;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

trait PrepaidLifecycle {

	/** Statuses that mean the client finished the form and BAPI took it for lodgement. */
	private static array $prepaid_released = array( 'lodging', 'lodged', 'completed', 'action_needed' );

	/** Order statuses that pause the applications of an order that was paid before. */
	private static array $pause_statuses = array( 'on-hold', 'failed', 'pending' );

	/**
	 * Create the prepaid applications of a paid order once the release guard passed.
	 *
	 * @param int|null $retry Smallest retry delay needed (updated).
	 */
	private function authorise_slots( \WC_Order $order, ?int &$retry ): void {
		if ( $this->stamp_staff_lines( $order ) ) {
			// New government fee lines now count: the release guard runs again with them.
			$refusal = self::release_refusal( $order );
			if ( null !== $refusal ) {
				/* translators: %s: reason. */
				$order->add_order_note( sprintf( __( 'BusinessAPI Forms: applications were not released because %s.', 'businessapi-forms' ), $refusal ) );
				$order->update_meta_data( self::REFUSED_FLAG, $refusal );
				$order->save();
				return;
			}
		}
		$slots = Prepaid::slots( $order );
		if ( ! $slots ) {
			return;
		}
		$lines    = Prepaid::item_slots( $order );
		$refunded = OrderLines::refunded_slots( $order );
		$order_id = (int) $order->get_id();

		foreach ( $slots as $slot => $row ) {
			if ( ! in_array( $slot, $lines, true ) ) {
				continue;
			}
			$state = (string) ( $row['state'] ?? '' );

			// Cancelled before payment (e.g. an unpaid order auto-cancelled), then paid late: the
			// guard passed, so create it after all (same Idempotency-Key, never a second one).
			if ( 'cancelled' === $state && '' === (string) ( $row['ref'] ?? '' ) && ! in_array( $slot, $refunded, true ) ) {
				$row['state']    = 'awaiting_payment';
				$row['attempts'] = 0;
				$state           = 'awaiting_payment';
				/* translators: %s: pay-first line label. */
				$order->add_order_note( sprintf( __( 'BusinessAPI Forms: %s was cancelled before payment, but the order is paid now: creating its application.', 'businessapi-forms' ), Prepaid::slot_label( $slot ) ) );
			}
			if ( ! in_array( $state, array( 'awaiting_payment', 'issuing' ), true ) ) {
				continue;
			}
			if ( in_array( $slot, $refunded, true ) ) {
				$row['state']   = 'cancelled';
				$row['updated'] = time();
				$this->save_slot( $order, $slot, $row );
				continue;
			}
			self::refresh_lock( $order_id );
			$delay = $this->authorise_slot( $order, (string) $slot, $row );
			$retry = null === $delay ? $retry : min( $retry ?? PHP_INT_MAX, $delay );
		}
	}

	/**
	 * Price check, then create, one paid pay-first line's application. Saves $row.
	 *
	 * @param array<string, mixed> $row Slot record.
	 * @return int|null Seconds until a retry is needed, null for none.
	 */
	private function authorise_slot( \WC_Order $order, string $slot, array $row ): ?int {
		$label   = Prepaid::slot_label( $slot );
		$product = (string) ( $row['product'] ?? '' );
		$env     = Options::ENV_LIVE === ( $row['env'] ?? '' ) ? Options::ENV_LIVE : Options::ENV_TEST;
		$addons  = array_values( array_filter( (array) ( $row['addons'] ?? array() ), 'is_string' ) );
		$fixed   = Prepaid::parse_fixed( Prepaid::fixed_string( (array) ( $row['fixed'] ?? array() ) ) );

		// Price check against a fresh fee schedule (paid less: blocked; paid more: noted).
		$expected = Plugin::instance()->expected->expected( $product, $addons, Prepaid::pricing( $product, $fixed ), true, $env );
		if ( is_wp_error( $expected ) ) {
			$delay = null;
			if ( 503 === (int) ( $expected->get_error_data()['status'] ?? 0 ) ) {
				$delay = $this->failed( $order, $label, $row, __( 'its government fee could not be loaded from Business API', 'businessapi-forms' ) );
			} else {
				$this->block_slot( $order, $label, $row, 'price_check', $expected->get_error_message() );
			}
			$this->save_slot( $order, $slot, $row );
			return $delay;
		}
		$fees = 0;
		foreach ( $order->get_fees() as $fee ) {
			$fees += (string) $fee->get_meta( Prepaid::ITEM_SLOT ) === $slot ? 1 : 0;
		}
		if ( null !== $expected['government'] && 0 === $fees ) {
			$this->block_slot( $order, $label, $row, 'price_check', __( 'there is no government fee line for it. Add the government fee line or sell it through checkout', 'businessapi-forms' ) );
			$this->save_slot( $order, $slot, $row );
			return null;
		}
		$check = OrderLines::check( $order, '', $expected, $slot );
		if ( null !== $check ) {
			$this->block_slot( $order, $label, $row, 'price_check', $check );
			$this->save_slot( $order, $slot, $row );
			return null;
		}
		if ( null !== $expected['government'] && empty( $row['overpaid_noted'] ) ) {
			$overpaid = round( OrderLines::government_paid( $order, null, $slot ) - (float) $expected['government']['amount'], 2 );
			if ( $overpaid > Money::EPSILON ) {
				$row['overpaid_noted'] = true;
				$order->add_order_note(
					sprintf(
					/* translators: 1: pay-first line label, 2: amount. */
						__( 'BusinessAPI Forms: %1$s: the government fee paid is %2$s more than the current fee. Its application is created anyway; consider refunding the difference to your client.', 'businessapi-forms' ),
						$label,
						Money::plain( $overpaid )
					)
				);
			}
		}

		return $this->create_slot( $order, $slot, $row, $expected );
	}

	/**
	 * Create the prepaid application of a line that passed its price check. Saves $row.
	 *
	 * @param array<string, mixed> $row      Slot record.
	 * @param array<string, mixed> $expected ExpectedLines answer for the line.
	 * @return int|null Seconds until a retry is needed, null for none.
	 */
	private function create_slot( \WC_Order $order, string $slot, array $row, array $expected ): ?int {
		$order_id = (int) $order->get_id();
		$label    = Prepaid::slot_label( $slot );
		$product  = (string) ( $row['product'] ?? '' );
		$env      = Options::ENV_LIVE === ( $row['env'] ?? '' ) ? Options::ENV_LIVE : Options::ENV_TEST;
		$addons   = array_values( array_filter( (array) ( $row['addons'] ?? array() ), 'is_string' ) );
		$fixed    = Prepaid::parse_fixed( Prepaid::fixed_string( (array) ( $row['fixed'] ?? array() ) ) );
		$delay    = null;

		// What the client paid in government fee now: a later refund or order edit that takes it
		// below this pauses the application (sync_holds(), hold_reason()).
		$row['gov_due'] = null !== $expected['government'] ? OrderLines::government_paid( $order, null, $slot ) : 0.0;

		// "issuing" and the key are stored before the call, so a crash is retried with the same key.
		$row['state']   = 'issuing';
		$row['updated'] = time();
		if ( ! is_string( $row['idem'] ?? null ) || '' === $row['idem'] ) {
			$row['idem'] = Prepaid::idempotency_key( $order_id, $slot, $env );
		}
		$this->save_slot( $order, $slot, $row );
		// Watchdog in case this request dies before the answer is stored.
		if ( ! \BapiForms\Scheduler::pending( self::HOOK_RETRY, array( $order_id ), self::GROUP ) ) {
			as_schedule_single_action( time() + 15 * MINUTE_IN_SECONDS, self::HOOK_RETRY, array( $order_id ), self::GROUP );
		}

		$options = array(
			'flow'        => Options::FLOW_PAY_FIRST,
			'prepaid'     => true,
			'addons'      => Prepaid::addon_keys( $addons ),
			'asicNotices' => $this->options->asic_notices_for_bapi(),
		);
		if ( $fixed ) {
			$options['fixed'] = $fixed;
		} elseif ( 'company_registration' === $product ) {
			// The only company type the form registers, locked like the BN term.
			$options['fixed'] = array( 'companyKey' => \BapiForms\Products\PriceList::V1_COMPANY_KEY );
		}
		$branding = $this->options->branding_for_bapi();
		if ( $branding ) {
			$options['branding'] = $branding;
		}
		$type     = (string) Plugin::instance()->products->bapi_type( $product );
		$response = $this->applications->create( $type, $options, (string) $row['idem'], $env );
		$ref      = $response->ok() ? strtoupper( (string) ( $response->data['ref'] ?? $response->data['applicationRef'] ?? '' ) ) : '';

		// A repeated key returns the application it made, even a cancelled or expired one.
		$created = self::status_of( $response, '' );
		if ( Applications::is_ref( $ref ) && in_array( $created, array( 'cancelled', 'expired' ), true ) ) {
			$this->block_slot(
				$order,
				$label,
				$row,
				$created,
				sprintf(
				/* translators: 1: application reference, 2: status. */
					__( 'its application %1$s is %2$s in Business API. Refund the order or contact Business API support', 'businessapi-forms' ),
					$ref,
					$created
				)
			);
			$this->save_slot( $order, $slot, $row );
			return null;
		}
		if ( Applications::is_ref( $ref ) ) {
			$this->issue_slot( $order, $slot, $row, $ref, $response );
			return null;
		}
		$code = self::safe_code( $response );
		if ( 409 === $response->status && 'prepaid_cap_reached' === $code ) {
			// Too many unused prepaid applications on the account: retried, partner told once.
			if ( empty( $row['cap_mailed'] ) ) {
				$row['cap_mailed'] = time();
				$this->alert_admin( $order, $label, __( 'your Business API account has reached its limit of prepaid applications still waiting for a client\'s details. Cancel or refund unused ones (refunding their orders cancels them), or ask Business API support to raise the limit. It is retried automatically', 'businessapi-forms' ) );
			}
			$delay = $this->failed( $order, $label, $row, __( 'your Business API account has too many prepaid applications waiting for a client\'s details (prepaid_cap_reached)', 'businessapi-forms' ) );
		} elseif ( $response->ok() || $response->is_transient() ) {
			$delay = $this->failed( $order, $label, $row, $response->ok() ? __( 'Business API did not return an application reference', 'businessapi-forms' ) : sprintf( 'HTTP %d', $response->status ) );
		} else {
			$fix = 'idempotency_conflict' === $code
				? __( 'Business API already holds a different application for this line. Contact Business API support, quoting the order number.', 'businessapi-forms' ) . ' ' . __( 'Then click "Retry release" in Order actions on this order.', 'businessapi-forms' )
				: self::fix_for( $code, $response->status );
			$this->block_slot( $order, $label, $row, $code, sprintf( '%s (%s). %s', __( 'Business API refused to create its application', 'businessapi-forms' ), $code, $fix ) );
		}
		$this->save_slot( $order, $slot, $row );
		return $delay ?? null;
	}

	/**
	 * Block a pay-first line until the partner fixes the cause and clicks "Retry release".
	 *
	 * @param array<string, mixed> $row Slot record (updated).
	 */
	private function block_slot( \WC_Order $order, string $label, array &$row, string $code, string $why ): void {
		$this->block(
			$order,
			$label,
			$row,
			'price_check' === $code ? 'price_check' : $code,
			sprintf(
				/* translators: 1: pay-first line label, 2: reason. */
				__( 'BusinessAPI Forms: the application for %1$s was not created: %2$s. Correct the order or refund it. Once fixed, click "Retry release" in Order actions.', 'businessapi-forms' ),
				$label,
				rtrim( $why, '.' )
			),
			$why
		);
	}

	/**
	 * @param array<string, mixed> $row Slot record.
	 */
	private function save_slot( \WC_Order $order, string $slot, array $row ): void {
		$slots          = Prepaid::slots( $order );
		$slots[ $slot ] = $row;
		$order->update_meta_data( Prepaid::META, $slots );
		$order->save();
	}

	/** Set a slot's state (no save: the caller saves the order). */
	private function slot_state( \WC_Order $order, string $slot, string $state ): void {
		$slots = Prepaid::slots( $order );
		if ( ! isset( $slots[ $slot ] ) ) {
			return;
		}
		$slots[ $slot ]['state']   = $state;
		$slots[ $slot ]['updated'] = time();
		if ( 'released' === $state ) {
			unset( $slots[ $slot ]['suspended'] ); // Pauses end with the release (sync_holds()).
		}
		$order->update_meta_data( Prepaid::META, $slots );
		if ( in_array( $state, array( 'cancelled', 'released', 'blocked' ), true ) ) {
			PrepaidEmails::unschedule( (int) $order->get_id(), $slot );
		}
	}

	/**
	 * Link the new application to its line and fee line, track it and email the client.
	 *
	 * @param array<string, mixed> $row Slot record.
	 */
	private function issue_slot( \WC_Order $order, string $slot, array $row, string $ref, Response $response ): void {
		OrderLines::link_slot( $order, $slot, $ref );

		$apps = self::apps( $order );
		if ( ! isset( $apps[ $ref ] ) ) {
			$apps[ $ref ] = array(
				'product'  => (string) ( $row['product'] ?? '' ),
				'env'      => Options::ENV_LIVE === ( $row['env'] ?? '' ) ? Options::ENV_LIVE : Options::ENV_TEST,
				'state'    => 'prepaid',
				'flow'     => Options::FLOW_PAY_FIRST,
				'slot'     => $slot,
				'status'   => self::status_of( $response, 'draft' ),
				'form'     => array(),
				'requests' => array(),
				'acn'      => '',
				'abn'      => '',
				'attempts' => 0,
				'polls'    => 0,
				'updated'  => time(),
			);
			$order->update_meta_data( self::META, $apps );
		}
		$refs = array_map( fn ( $meta ) => (string) ( $meta->value ?? '' ), (array) $order->get_meta( self::ORDER_REF, false, 'edit' ) );
		if ( ! in_array( $ref, $refs, true ) ) {
			$order->add_meta_data( self::ORDER_REF, $ref );
		}
		$row['state']     = 'prepaid';
		$row['ref']       = $ref;
		$row['attempts']  = 0;
		$row['issued_at'] = (int) ( $row['issued_at'] ?? 0 ) > 0 ? (int) $row['issued_at'] : time();
		$row['updated']   = time();
		unset( $row['reason'] );
		if ( \BapiForms\Monitoring\Renewals::PRODUCT === ( $row['product'] ?? '' ) ) {
			\BapiForms\Monitoring\Entities::mark( $order );
		}
		/* translators: %s: Application #XXXXXX. */
		$order->add_order_note( sprintf( __( 'BusinessAPI Forms: %s ready for your client\'s details (pay first).', 'businessapi-forms' ), StatusText::short_ref( $ref ) . ' (' . $ref . ')' ) );
		$this->save_slot( $order, $slot, $row );

		PrepaidEmails::send_start( $order, $slot, false );
		PrepaidEmails::schedule( (int) $order->get_id(), $slot, $row );
		$this->schedule_poll( (int) $order->get_id(), $ref, 0, 0, HOUR_IN_SECONDS );
	}

	/**
	 * Orders made in wp-admin with a Registration product line: stamp the line as pay first and
	 * link a fee line of exactly the government fee. Lines added after payment were never paid for
	 * and are left alone. True when a line was stamped, so the caller re-runs the release guard.
	 */
	private function stamp_staff_lines( \WC_Order $order ): bool {
		$slots   = Prepaid::slots( $order );
		$changed = false;
		$noted   = false;
		$paid    = (int) $order->get_meta( self::PAID_ITEMS );
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || '' !== (string) $item->get_meta( Prepaid::ITEM_SLOT ) || '' !== (string) $item->get_meta( self::ITEM_REF ) ) {
				continue;
			}
			$slug = PayFirstProducts::slug_of( (int) $item->get_product_id() );
			if ( '' === $slug || Options::FLOW_PAY_FIRST !== $this->options->flow( $slug ) ) {
				continue;
			}
			// Added to an order that was already paid: no payment was taken for it.
			if ( $paid > 0 && (int) $item->get_id() > $paid ) {
				if ( '' === (string) $item->get_meta( self::ITEM_IGNORED ) ) {
					$item->update_meta_data( self::ITEM_IGNORED, (string) time() );
					$item->save();
					$noted = true;
					/* translators: %s: product line name. */
					$order->add_order_note( sprintf( __( 'BusinessAPI Forms: "%s" was added after the order was paid, so no payment was taken for it and no application is created. Sell it through checkout (or a new order your client pays) instead.', 'businessapi-forms' ), $item->get_name() ) );
				}
				continue;
			}
			$choice = PayFirstCart::choice_of( $slug, (int) $item->get_product_id(), (int) $item->get_variation_id() );
			if ( null === $choice ) {
				continue;
			}
			$slot = Prepaid::new_slot();
			$item->update_meta_data( self::ITEM_CARRIER, $slug );
			$item->update_meta_data( OrderLines::ITEM_VERSION, Carriers::VERSION );
			$item->update_meta_data( OrderLines::ITEM_ADDONS, implode( ',', $choice['addons'] ) );
			$item->update_meta_data( Prepaid::ITEM_FLOW, Options::FLOW_PAY_FIRST );
			$item->update_meta_data( Prepaid::ITEM_SLOT, $slot );
			$item->update_meta_data( Prepaid::ITEM_FIXED, Prepaid::fixed_string( $choice['fixed'] ) );
			$item->save();

			$gov = PayFirstCart::government( $slug, $choice['fixed'] );
			if ( is_array( $gov ) ) {
				foreach ( $order->get_fees() as $fee ) {
					if ( '' === (string) $fee->get_meta( OrderLines::ITEM_FEE ) && 'none' === (string) $fee->get_tax_status() && abs( (float) $fee->get_total() - (float) $gov['amount'] ) < Money::EPSILON ) {
						$fee->update_meta_data( Prepaid::ITEM_SLOT, $slot );
						$fee->update_meta_data( OrderLines::ITEM_FEE, 'government' );
						$fee->update_meta_data( OrderLines::ITEM_GOV_KIND, $slug );
						$fee->save();
						break;
					}
				}
			}
			$slots[ $slot ] = PayFirstCart::new_record( $slug, $this->options->environment(), $choice['addons'], $choice['fixed'] );
			$changed        = true;
			/* translators: %s: pay-first line label. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: %s added in wp-admin was set up as pay first.', 'businessapi-forms' ), Prepaid::slot_label( $slot ) ) );
		}
		if ( $changed ) {
			$order->update_meta_data( Prepaid::META, $slots );
		}
		if ( $changed || $noted ) {
			$order->save();
		}
		return $changed;
	}

	/**
	 * At payment, record the highest item id once, so lines added later are known to be unpaid.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function remember_paid_items( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || (int) $order->get_meta( self::PAID_ITEMS ) > 0 || ! $order->is_paid() ) {
			return;
		}
		$max = 0;
		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			$max = max( $max, (int) $item->get_id() );
		}
		if ( $max > 0 ) {
			$order->update_meta_data( self::PAID_ITEMS, $max );
			$order->save();
		}
	}

	/**
	 * Poll a prepaid application: form progress and whether the client finished it.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	private function poll_prepaid( \WC_Order $order, string $ref, array $app ): void {
		$order_id     = (int) $order->get_id();
		$slot         = (string) ( $app['slot'] ?? Prepaid::slot_of_ref( $order, $ref ) );
		$response     = $this->applications->summary( $ref, $app['env'] );
		$app['polls'] = (int) ( $app['polls'] ?? 0 ) + 1;
		$next         = $app['polls'] > 60 ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
		$release      = false;
		$now          = false;

		if ( 404 === $response->status ) {
			// Not found (deleted, wrong account or environment): give up after a few checks in a row.
			$app['missing'] = (int) ( $app['missing'] ?? 0 ) + 1;
			if ( $app['missing'] >= self::MAX_MISSING ) {
				$this->block(
					$order,
					$ref,
					$app,
					'not_found',
					sprintf(
					/* translators: 1: application reference, 2: what to check. */
						__( 'BusinessAPI Forms: application %1$s can no longer be found in Business API, so it was stopped here. %2$s', 'businessapi-forms' ),
						$ref,
						self::fix_for( 'HTTP 404', 404 )
					),
					__( 'the application can no longer be found in Business API', 'businessapi-forms' )
				);
				$this->slot_state( $order, $slot, 'blocked' );
			}
		} elseif ( $response->ok() ) {
			unset( $app['missing'] );
			$status = self::status_of( $response, (string) ( $app['status'] ?? 'draft' ) );
			$form   = $response->data['form'] ?? null;
			if ( is_array( $form ) ) {
				$saved       = isset( $form['lastSavedAt'] ) && is_string( $form['lastSavedAt'] ) ? strtotime( $form['lastSavedAt'] ) : false;
				$app['form'] = array(
					'started' => ! empty( $form['started'] ),
					'step'    => isset( $form['step'] ) && is_string( $form['step'] ) ? substr( sanitize_text_field( $form['step'] ), 0, 60 ) : '',
					'index'   => max( 0, (int) ( $form['stepIndex'] ?? 0 ) ),
					'total'   => max( 0, (int) ( $form['stepTotal'] ?? 0 ) ),
					'saved'   => false !== $saved ? (int) $saved : 0,
				);
			}

			if ( in_array( $status, self::$prepaid_released, true ) ) {
				$this->note_fee_rise( $order, $ref, $app, $response->data );
				$this->mark_released( $order, $ref, $app, $status );
				$now = in_array( $status, array( 'lodging', 'lodged' ), true );
				$order->add_order_note(
					sprintf(
					/* translators: %s: application reference. */
						__( 'BusinessAPI Forms: your client finished application %s; Business API released it for lodgement.', 'businessapi-forms' ),
						$ref
					)
				);
				$this->slot_state( $order, $slot, 'released' );
			} elseif ( Applications::STATUS_AWAITING_RELEASE === $status && ( ! empty( $app['held'] ) || ! empty( $app['suspended'] ) ) ) {
				// Finished while paused: it stays here until the hold is cleared (sync_holds()).
				if ( Applications::STATUS_AWAITING_RELEASE !== ( $app['status'] ?? '' ) ) {
					/* translators: %s: application reference. */
					$order->add_order_note( sprintf( __( 'BusinessAPI Forms: your client finished application %s while it is paused. It is released once the order stands behind it again.', 'businessapi-forms' ), $ref ) );
				}
				$app['status'] = $status;
			} elseif ( Applications::STATUS_AWAITING_RELEASE === $status ) {
				// BAPI kept it for us: release it here with the usual guard and price check.
				$app['state']    = 'pending';
				$app['status']   = $status;
				$app['attempts'] = 0;
				$release         = true;
				$refusal         = self::release_refusal( $order );
				$order->add_order_note(
					null === $refusal
					/* translators: %s: application reference. */
					? sprintf( __( 'BusinessAPI Forms: your client finished application %s. Releasing it for lodgement.', 'businessapi-forms' ), $ref )
					/* translators: 1: application reference, 2: reason. */
					: sprintf( __( 'BusinessAPI Forms: your client finished application %1$s. It is not released yet because %2$s.', 'businessapi-forms' ), $ref, $refusal )
				);
				$this->slot_state( $order, $slot, 'released' );
			} elseif ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
				$this->block_gone( $order, $ref, $app, $status );
				$this->slot_state( $order, $slot, 'blocked' );
			} else {
				$app['status'] = $status;
			}
		}

		$app['updated'] = time();
		$apps           = self::apps( $order );
		$apps[ $ref ]   = $app;
		$order->update_meta_data( self::META, $apps );
		$order->save();

		if ( $release ) {
			$this->queue_release( $order_id );
		} elseif ( $now ) {
			// Lodging now: check it right away (mark_released() scheduled one in a few minutes).
			$args = array( $order_id, $ref );
			as_unschedule_action( self::HOOK_POLL, $args, self::GROUP );
			as_enqueue_async_action( self::HOOK_POLL, $args, self::GROUP );
		} elseif ( 'prepaid' === ( $app['state'] ?? '' ) && ! $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			// Cancelled, refunded or trashed orders stop polling; a webhook still triggers a check.
			$this->schedule_poll( $order_id, $ref, 0, 0, $next );
		}
	}

	/**
	 * A pay-first application the release run found still a draft: back to waiting for the
	 * client's form, poll and reminders rescheduled. BAPI lodges it when the client finishes.
	 *
	 * @param array<string, mixed> $app Tracked application (updated).
	 */
	private function back_to_prepaid( \WC_Order $order, string $ref, array &$app ): void {
		$app['state']    = 'prepaid';
		$app['status']   = 'draft';
		$app['attempts'] = 0;
		$app['updated']  = time();
		unset( $app['reason'], $app['missing'] );
		$slot  = (string) ( $app['slot'] ?? Prepaid::slot_of_ref( $order, $ref ) );
		$slots = Prepaid::slots( $order );
		if ( isset( $slots[ $slot ] ) ) {
			$slots[ $slot ]['state']   = 'prepaid';
			$slots[ $slot ]['ref']     = $ref;
			$slots[ $slot ]['updated'] = time();
			unset( $slots[ $slot ]['reason'] );
			$order->update_meta_data( Prepaid::META, $slots );
		}
		/* translators: %s: application reference. */
		$order->add_order_note( sprintf( __( 'BusinessAPI Forms: application %s is still waiting for your client\'s details in Business API, so it is tracked again here. It is lodged when your client finishes the form.', 'businessapi-forms' ), $ref ) );
		$this->schedule_poll( (int) $order->get_id(), $ref, 0, 0, 5 * MINUTE_IN_SECONDS );
		if ( isset( $slots[ $slot ] ) ) {
			PrepaidEmails::schedule( (int) $order->get_id(), $slot, $slots[ $slot ] );
		}
	}

	/**
	 * The government fee went up after the client paid: lodged anyway, with one order note.
	 *
	 * @param array<string, mixed> $app     Tracked application (updated).
	 * @param array<string, mixed> $summary BAPI summary (no applicant data).
	 */
	private function note_fee_rise( \WC_Order $order, string $ref, array &$app, array $summary ): void {
		if ( ! empty( $app['fee_rise_noted'] ) ) {
			return;
		}
		$product = (string) ( $app['product'] ?? '' );
		$inputs  = Plugin::instance()->expected->inputs( $summary, $product );
		if ( is_wp_error( $inputs ) ) {
			return;
		}
		$expected = Plugin::instance()->expected->expected( $product, $inputs['addons'], $inputs['pricing'], false, (string) $app['env'] );
		if ( is_wp_error( $expected ) || null === $expected['government'] ) {
			return;
		}
		$rise = round( (float) $expected['government']['amount'] - OrderLines::government_charged( $order, $ref ), 2 ); // Charged, not net: a refund is not a rise.
		if ( $rise > Money::EPSILON ) {
			$app['fee_rise_noted'] = true;
			$order->add_order_note( self::fee_rise_text( $ref, $rise ) );
		}
	}

	private static function fee_rise_text( string $ref, float $rise ): string {
		return sprintf(
			/* translators: 1: application reference, 2: amount. */
			__( 'BusinessAPI Forms: application %1$s: the government fee went up by %2$s after your client paid. It was lodged anyway (pay first); Business API bills you the current fee.', 'businessapi-forms' ),
			$ref,
			Money::plain( $rise )
		);
	}

	/**
	 * Order on hold, failed or back to pending after payment: pause every application Business API
	 * could still lodge by itself. The release run resumes them. Retried while busy or unreachable.
	 *
	 * @param int|string $order_id Order id.
	 * @param bool|null  $renote   Note pauses already in place too. Default: on a status change,
	 *                             not on a retry after a failed pause.
	 */
	public function suspend_order( $order_id, $renote = null ): void {
		$order_id = (int) $order_id;
		$again    = false;
		$renote   = null === $renote ? self::HOOK_SUSPEND !== current_filter() : (bool) $renote;
		$order    = wc_get_order( $order_id );
		// Never paid (e.g. every new order starts as Pending payment): nothing can lodge, no lock.
		if ( ! $order instanceof \WC_Order || ( ! $order->get_date_paid( 'edit' ) && ! $order->get_meta( self::PAID_FLAG ) ) ) {
			return;
		}
		$done = self::with_lock(
			$order_id,
			function () use ( $order_id, $renote, &$again ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order || ! $order->has_status( self::$pause_statuses ) ) {
					return;
				}
				/* translators: %s: order status, e.g. On hold. */
				$again = $this->sync_holds( $order, sprintf( __( 'the order is %s', 'businessapi-forms' ), wc_get_order_status_name( $order->get_status() ) ), true, false, $renote )['again'];
			},
			10
		);

		$queued = \BapiForms\Scheduler::pending( self::HOOK_SUSPEND, array( $order_id ), self::GROUP )
			|| \BapiForms\Scheduler::pending( self::HOOK_SUSPEND, array( $order_id, true ), self::GROUP );
		if ( ( ! $done || $again ) && ! $queued ) {
			// Order busy: the retry still owes this status change its notes.
			as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::HOOK_SUSPEND, ! $done && $renote ? array( $order_id, true ) : array( $order_id ), self::GROUP );
		}
	}

	/**
	 * After a partial refund, deleted refund or item edit: queue a check that the order still pays
	 * for each application.
	 *
	 * @param int|string $order_id Order id (or the refund id first, for woocommerce_refund_deleted).
	 * @param mixed      $second   Order id for woocommerce_refund_deleted, items for saved_order_items.
	 */
	public function queue_recheck( $order_id, $second = null ): void {
		if ( 'woocommerce_refund_deleted' === current_filter() ) {
			$order_id = $second;
		}
		$args = array( (int) $order_id );
		if ( (int) $order_id <= 0 ) {
			return;
		}
		if ( ! \BapiForms\Scheduler::pending( self::HOOK_RECHECK, $args, self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_RECHECK, $args, self::GROUP );
		}
	}

	/**
	 * Pause what the order no longer pays for, resume what it pays for again, and queue a release
	 * for any resumed application that is awaiting release.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function recheck_holds( $order_id ): void {
		$order_id = (int) $order_id;
		$result   = array(
			'again'   => false,
			'release' => false,
		);
		$done     = self::with_lock(
			$order_id,
			function () use ( $order_id, &$result ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
					return; // Cancelled there (cancel_locked()), held only when that fails.
				}
				$refusal = $order->has_status( self::$pause_statuses )
					/* translators: %s: order status, e.g. On hold. */
					? sprintf( __( 'the order is %s', 'businessapi-forms' ), wc_get_order_status_name( $order->get_status() ) )
					: self::release_refusal( $order );
				$result = $this->sync_holds( $order, $refusal );
			},
			10
		);
		if ( ( ! $done || $result['again'] ) && ! \BapiForms\Scheduler::pending( self::HOOK_RECHECK, array( $order_id ), self::GROUP ) ) {
			as_schedule_single_action( time() + ( $done ? 5 : 1 ) * MINUTE_IN_SECONDS, self::HOOK_RECHECK, array( $order_id ), self::GROUP );
		}
		if ( $result['release'] ) {
			$this->queue_release( $order_id );
		}
	}

	/** Whether Business API could lodge it by itself (the client finishing or retrying the form). */
	private static function holdable( array $app ): bool {
		return in_array( $app['state'] ?? '', array( 'prepaid', 'pending', 'blocked' ), true )
			|| ( 'released' === ( $app['state'] ?? '' ) && Prepaid::app_startable( $app ) );
	}

	/**
	 * Pause (options.hold) applications the paid order no longer stands behind, and resume them once
	 * it does. One the client finished while paused goes back to pending for the release run.
	 *
	 * @param string|null $refusal Why the whole order cannot release (guard, on hold), or null to
	 *                             check each application's own lines (hold_reason()).
	 * @param bool        $set     Also pause (false: only resume, as the release run does).
	 * @param bool        $mail    Email the admin when an application is paused.
	 * @param bool        $renote  Note applications that were already paused as well.
	 * @return array{again: bool, release: bool} again: retry later; release: queue a release.
	 */
	private function sync_holds( \WC_Order $order, ?string $refusal, bool $set = true, bool $mail = true, bool $renote = false ): array {
		$out = array(
			'again'   => false,
			'release' => false,
		);
		if ( ! $order->get_date_paid( 'edit' ) && ! $order->get_meta( self::PAID_FLAG ) ) {
			return $out;
		}
		$order_id = (int) $order->get_id();
		$refs     = self::item_refs( $order );
		$apps     = self::apps( $order );
		$slots    = Prepaid::slots( $order );
		$changed  = false;
		$remind   = array();
		foreach ( $apps as $ref => $app ) {
			$ref = (string) $ref;
			if ( ! in_array( $ref, $refs, true ) || ! self::holdable( $app ) ) {
				continue;
			}
			$held = ! empty( $app['held'] ) || ! empty( $app['suspended'] );
			$why  = $refusal ?? $this->hold_reason( $order, $ref, $app );
			if ( null !== $why && ! $held && $set ) {
				$want = true;
			} elseif ( null === $why && $held ) {
				$want = false;
			} else {
				if ( null !== $why && $held && $set && $renote ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: application reference, 2: reason. */
							__( 'BusinessAPI Forms: application %1$s stays paused because %2$s. It resumes by itself once this is resolved.', 'businessapi-forms' ),
							$ref,
							rtrim( $why, '.' )
						)
					);
				}
				continue;
			}
			self::refresh_lock( $order_id );
			$changed = true;
			$next    = $this->apply_hold( $order, $ref, $app, $slots, $want, $why, $mail );
			if ( 'remind' === $next ) {
				$remind[] = (string) $app['slot'];
			} elseif ( '' !== $next ) {
				$out[ $next ] = true;
			}
			$apps[ $ref ] = $app;
		}
		if ( $changed ) {
			$order->update_meta_data( self::META, $apps );
			$order->update_meta_data( Prepaid::META, $slots );
			$order->save();
			foreach ( $remind as $slot ) {
				\BapiForms\Emails\PrepaidEmails::schedule( $order_id, $slot, Prepaid::slots( $order )[ $slot ] ?? array() );
			}
		}
		return $out;
	}

	/**
	 * Pause ($want) or resume one application in Business API. Updates $app and $slots.
	 *
	 * @param array<string, mixed> $app   Tracked application.
	 * @param array<string, mixed> $slots Pay-first slots of the order.
	 * @return string What the caller does next: release, again (retry soon), remind (email the client) or ''.
	 */
	private function apply_hold( \WC_Order $order, string $ref, array &$app, array &$slots, bool $want, ?string $why, bool $mail ): string {
		$response = $this->applications->hold( $ref, $want, (string) $app['env'] );
		if ( $response->ok() ) {
			unset( $app['hold_attempts'] );
			if ( $want ) {
				$app['held'] = true;
				$order->add_order_note(
					sprintf(
					/* translators: 1: application reference, 2: reason. */
						__( 'BusinessAPI Forms: application %1$s paused because %2$s. Business API will not lodge it, even if your client finishes the form, until this is resolved; it then resumes by itself.', 'businessapi-forms' ),
						$ref,
						rtrim( $why, '.' )
					)
				);
				if ( $mail ) {
					$this->alert_hold( $order, $ref, $why );
				}
				return '';
			}
			return $this->resumed( $order, $ref, $app, $slots, $response );
		} elseif ( 409 === $response->status && 'hold_not_available' === $response->code() ) {
			// Lodged, completed or cancelled meanwhile: nothing to pause. The poll records it.
			unset( $app['held'], $app['suspended'], $app['hold_attempts'] );
			as_enqueue_async_action( self::HOOK_POLL, array( (int) $order->get_id(), $ref ), self::GROUP );
		} elseif ( $response->is_transient() && (int) ( $app['hold_attempts'] ?? 0 ) < 8 ) {
			$app['hold_attempts'] = (int) ( $app['hold_attempts'] ?? 0 ) + 1;
			return 'again';
		} else {
			unset( $app['hold_attempts'] );
			$order->add_order_note(
				$want
					? sprintf(
						/* translators: 1: application reference, 2: reason, 3: HTTP status. */
						__( 'BusinessAPI Forms: application %1$s should be paused because %2$s, but Business API could not be told (HTTP %3$d). If your client finishes the form it may be lodged: check the order, and refund it if it cannot be fixed.', 'businessapi-forms' ),
						$ref,
						rtrim( (string) $why, '.' ),
						$response->status
					)
					/* translators: 1: application reference, 2: HTTP status. */
					: sprintf( __( 'BusinessAPI Forms: application %1$s could not be resumed (HTTP %2$d). Click "Retry release" in Order actions to try again.', 'businessapi-forms' ), $ref, $response->status )
			);
			if ( $want && $mail ) {
				$this->alert_hold( $order, $ref, (string) $why );
			}
		}
		return '';
	}

	/**
	 * Business API resumed it: release it again if it is ready, or remind the client to finish.
	 *
	 * @param array<string, mixed> $app   Tracked application (updated).
	 * @param array<string, mixed> $slots Pay-first slots of the order (updated).
	 * @return string release, remind or ''.
	 */
	private function resumed( \WC_Order $order, string $ref, array &$app, array &$slots, Response $response ): string {
		$slot = (string) ( $app['slot'] ?? '' );
		// Older pay-first pauses switched options.prepaid off: switch it back on (draft only).
		if ( ! empty( $app['suspended'] ) && 'prepaid' === ( $app['state'] ?? '' ) ) {
			$this->applications->patch_options( $ref, array( 'prepaid' => true ), (string) $app['env'] );
		}
		unset( $app['held'], $app['suspended'] );
		if ( isset( $slots[ $slot ] ) ) {
			unset( $slots[ $slot ]['suspended'] );
		}
		/* translators: %s: application reference. */
		$order->add_order_note( sprintf( __( 'BusinessAPI Forms: application %s resumed: the order stands behind it again.', 'businessapi-forms' ), $ref ) );
		if ( Applications::STATUS_AWAITING_RELEASE === self::status_of( $response, '' ) && in_array( $app['state'] ?? '', array( 'prepaid', 'released' ), true ) ) {
			// The client finished it while paused: released here with the usual guard and price check.
			$app['state']    = 'pending';
			$app['status']   = Applications::STATUS_AWAITING_RELEASE;
			$app['attempts'] = 0;
			if ( isset( $slots[ $slot ] ) ) {
				$slots[ $slot ]['state']   = 'released';
				$slots[ $slot ]['updated'] = time();
				\BapiForms\Emails\PrepaidEmails::unschedule( (int) $order->get_id(), $slot );
			}
			return 'release';
		} elseif ( 'pending' === ( $app['state'] ?? '' ) || ( 'blocked' === ( $app['state'] ?? '' ) && in_array( $app['reason'] ?? '', array( 'price_check', 'application_on_hold' ), true ) ) ) {
			// Blocked only by the pause or the order no longer paying for it: release again.
			$app['state']    = 'pending';
			$app['attempts'] = 0;
			unset( $app['reason'] );
			return 'release';
		} elseif ( 'prepaid' === ( $app['state'] ?? '' ) && '' !== $slot ) {
			return 'remind';
		}
		return '';
	}

	/**
	 * Why this application's own lines no longer pay for it, or null. Local amounts only; the
	 * release run's price check against Business API stays the final check.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	private function hold_reason( \WC_Order $order, string $ref, array $app ): ?string {
		$product = (string) ( $app['product'] ?? '' );
		$line    = null;
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (string) $item->get_meta( self::ITEM_REF ) === $ref ) {
				$line = $item;
				break;
			}
		}
		if ( ! $line instanceof \WC_Order_Item_Product || Carriers::VERSION !== (int) $line->get_meta( OrderLines::ITEM_VERSION ) ) {
			return null; // Not a line this plugin made: the release run's price check refuses it.
		}
		$slot   = (string) ( $app['slot'] ?? '' );
		$row    = '' !== $slot ? ( Prepaid::slots( $order )[ $slot ] ?? array() ) : array();
		$addons = 'pay_first' === ( $app['flow'] ?? '' ) && isset( $row['addons'] )
			? array_values( array_filter( (array) $row['addons'], 'is_string' ) )
			: array_values( array_filter( explode( ',', (string) $line->get_meta( OrderLines::ITEM_ADDONS ) ) ) );
		$due    = isset( $row['gov_due'] ) && is_numeric( $row['gov_due'] ) ? (float) $row['gov_due'] : OrderLines::government_charged( $order, $ref );
		if ( $due < Money::EPSILON && Plugin::instance()->prices->has_government_fee( $product ) ) {
			return __( 'its government fee line is missing from the order', 'businessapi-forms' );
		}
		return OrderLines::check(
			$order,
			$ref,
			array(
				'service'    => (string) $line->get_meta( self::ITEM_CARRIER ),
				'addons'     => $addons,
				'government' => $due > Money::EPSILON ? array( 'amount' => $due ) : null,
			)
		);
	}

	/** Email the site admin about an application paused for a reason other than the order status. */
	private function alert_hold( \WC_Order $order, string $ref, string $why ): void {
		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: order number. */
			sprintf( __( 'BusinessAPI Forms: an application on order #%s was paused', 'businessapi-forms' ), $order->get_order_number() ),
			sprintf(
				/* translators: 1: application reference, 2: order number, 3: reason, 4: order admin URL. */
				__( "Application %1\$s on order #%2\$s was paused because %3\$s. Business API will not lodge it, even if your client finishes the form.\n\nCorrect the order (or collect the difference) and it resumes by itself; if it cannot be fixed, refund the order, which cancels the application.\n%4\$s", 'businessapi-forms' ),
				$ref,
				$order->get_order_number(),
				rtrim( $why, '.' ),
				$order->get_edit_order_url()
			)
		);
	}

	/** Refund, cancel or trash: cancel pay-first lines whose application was never created. */
	private function cancel_unissued( \WC_Order $order, bool $refunded_only ): void {
		$slots   = Prepaid::slots( $order );
		$wanted  = $refunded_only ? OrderLines::refunded_slots( $order ) : Prepaid::item_slots( $order );
		$changed = false;
		foreach ( $slots as $slot => $row ) {
			if ( ! in_array( $slot, $wanted, true ) || ! in_array( $row['state'] ?? '', Prepaid::UNISSUED, true ) ) {
				continue;
			}
			$row['state']   = 'cancelled';
			$row['updated'] = time();
			$slots[ $slot ] = $row;
			$changed        = true;
			/* translators: %s: pay-first line label. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: %s cancelled. Its application had not been created yet.', 'businessapi-forms' ), Prepaid::slot_label( $slot ) ) );
		}
		if ( $changed ) {
			$order->update_meta_data( Prepaid::META, $slots );
			$order->save();
		}
	}
}
