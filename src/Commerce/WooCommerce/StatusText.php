<?php
/**
 * Words for an application's status: the customer's five statuses ("Your applications" on the
 * order page) and the partner's order notes. Never the agency's messages.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Plugin;
use BapiForms\Products\ExpectedLines;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class StatusText {

	/**
	 * Order note for a change of status or progress. Never the agency's messages.
	 *
	 * @param array<string, mixed> $app            Tracked application.
	 * @param bool                 $status_changed False when only the progress changed.
	 */
	public static function status_note( string $ref, array $app, bool $status_changed = true ): string {
		$progress = 'action_needed' === $app['status'] || 'completed' === $app['status'] ? '' : self::progress_text( $app, true );
		if ( self::sandbox_validated( $app ) ) {
			/* translators: %s: application reference. */
			$note = sprintf( __( 'BusinessAPI Forms: application %s was validated by the ATO test service (sandbox, no ABN issued). Nothing was registered.', 'businessapi-forms' ), $ref );
		} elseif ( ! $status_changed && '' !== $progress ) {
			/* translators: 1: application reference, 2: progress, e.g. "Lodged, waiting for ASIC." */
			$note = sprintf( __( 'BusinessAPI Forms: application %1$s: %2$s', 'businessapi-forms' ), $ref, $progress );
		} else {
			$note = sprintf(
				/* translators: 1: application reference, 2: status. */
				__( 'BusinessAPI Forms: application %1$s status is now "%2$s".', 'businessapi-forms' ),
				$ref,
				str_replace( '_', ' ', $app['status'] )
			);
			if ( '' !== $progress ) {
				$note .= ' ' . $progress;
			}
		}
		if ( 'action_needed' === $app['status'] && 'ppsr_search' === ( $app['product'] ?? '' ) ) {
			// A PPSR search that failed: nothing to correct, Business API runs it again or credits it.
			$request = (string) ( $app['request'] ?? '' );
			$request = '' !== $request ? $request : __( 'unknown', 'businessapi-forms' );
			$note    = sprintf(
				/* translators: 1: application reference, 2: Business API request id. */
				__( 'BusinessAPI Forms: the PPSR search for application %1$s did not complete (request %2$s). There is nothing to correct in the search details. Contact Business API support quoting request %2$s to have the search run again or the Business API charge credited, then tell your client, or refund their order.', 'businessapi-forms' ),
				$ref,
				$request
			);
		} elseif ( 'action_needed' === $app['status'] && 'lapsed' === ( $app['asic'] ?? '' ) ) {
			// Older BAPI (lapsed link reported as action_needed). Not a correction; customer still sees In progress.
			$note .= ' ' . self::lapsed_text();
		} elseif ( 'action_needed' === $app['status'] ) {
			$count = max( 1, (int) ( $app['issues'] ?? 0 ) );
			$note .= ' ' . sprintf(
				/* translators: %d: number of corrections. */
				_n( 'ASIC or the ATO asked for %d correction.', 'ASIC or the ATO asked for %d corrections.', $count, 'businessapi-forms' ),
				$count
			);
			$note .= ' ' . ( '' !== (string) ( $app['request'] ?? '' )
				? sprintf(
					/* translators: 1: Business API request id, 2: application reference. */
					__( 'See the errors on request %1$s in your Business API dashboard (search for its ID). If none are shown there, contact Business API support quoting application %2$s. Then contact your client for the corrected information.', 'businessapi-forms' ),
					$app['request'],
					$ref
				)
				: sprintf(
					/* translators: %s: application reference. */
					__( 'Contact Business API support for the details, quoting application %s, then contact your client for the corrected information.', 'businessapi-forms' ),
					$ref
				) );
		}
		if ( '' !== (string) ( $app['acn'] ?? '' ) ) {
			/* translators: %s: ACN. */
			$note .= ' ' . sprintf( __( 'ACN %s.', 'businessapi-forms' ), $app['acn'] ?? '' );
		}
		if ( '' !== (string) ( $app['abn'] ?? '' ) ) {
			$note .= ' ' . self::abn_label( $app ) . ' ' . $app['abn'] . '.';
		}
		if ( '' !== (string) ( $app['document'] ?? '' ) ) {
			/* translators: %s: ASIC document number, e.g. 1-11DXAZ9L. */
			$note .= ' ' . sprintf( __( 'ASIC document number %s.', 'businessapi-forms' ), $app['document'] );
		}
		return $note;
	}

	/**
	 * Sandbox ABN validated by the ATO test service: no ABN is ever issued.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function sandbox_validated( array $app ): bool {
		return 'sandbox_validated' === ( $app['progress'] ?? '' )
			|| ( 'completed' === ( $app['status'] ?? '' ) && 'abn_registration' === ( $app['product'] ?? '' ) && Options::ENV_TEST === ( $app['env'] ?? '' ) && '' === (string) ( $app['abn'] ?? '' ) );
	}

	/**
	 * The agency holding the application: from the request in progress, else from the product.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	private static function agency( array $app ): string {
		return match ( (string) ( $app['agency'] ?? '' ) ) {
			'abn'           => __( 'the ATO', 'businessapi-forms' ),
			'company', 'bn' => __( 'ASIC', 'businessapi-forms' ),
			'ppsr'          => __( 'the PPSR', 'businessapi-forms' ),
			default         => match ( (string) ( $app['product'] ?? '' ) ) {
				'abn_registration'                      => __( 'the ATO', 'businessapi-forms' ),
				'company_registration', 'business_name' => __( 'ASIC', 'businessapi-forms' ),
				'ppsr_search'                           => __( 'the PPSR', 'businessapi-forms' ),
				default                                 => __( 'the government agency', 'businessapi-forms' ),
			},
		};
	}

	/**
	 * Plain words for BAPI's agency progress, or '' when there is none.
	 *
	 * @param array<string, mixed> $app   Tracked application.
	 * @param bool                 $admin Partner-facing (order note) rather than customer-facing.
	 */
	private static function progress_text( array $app, bool $admin ): string {
		if ( self::sandbox_validated( $app ) ) {
			return __( 'Validated by the ATO test service (sandbox, no ABN issued).', 'businessapi-forms' );
		}
		return match ( (string) ( $app['progress'] ?? '' ) ) {
			/* translators: %s: agency, e.g. ASIC or the ATO. */
			'with_agency'           => sprintf( __( 'Lodged, waiting for %s.', 'businessapi-forms' ), self::agency( $app ) ),
			/* translators: %s: agency, e.g. ASIC or the ATO. */
			'manual_review'         => sprintf( __( 'With %s for manual review (this can take a few business days).', 'businessapi-forms' ), self::agency( $app ) ),
			'awaiting_asic_payment' => $admin
				? ( 'lapsed' === ( $app['asic'] ?? '' ) ? self::lapsed_text() : ( '' !== (string) ( $app['request'] ?? '' )
					/* translators: %s: Business API request id. */
					? sprintf( __( 'Waiting for the ASIC fee payment (request %s): click "Pay ASIC fee" in the BusinessAPI Forms box on this order to pay ASIC\'s fee by card. ASIC registers the business name once it is paid.', 'businessapi-forms' ), $app['request'] )
					: __( 'Waiting for the ASIC fee payment: click "Pay ASIC fee" in the BusinessAPI Forms box on this order to pay ASIC\'s fee by card. ASIC registers the business name once it is paid.', 'businessapi-forms' ) ) )
				: '',
			default                 => '',
		};
	}

	/** Partner wording for an expired ASIC payment link. Still waiting for the fee, never "cancelled". */
	public static function lapsed_text(): string {
		return __( 'Waiting for the ASIC fee payment. The payment link expired: get a new link with "Get new payment link" in the BusinessAPI Forms box on this order, then pay ASIC\'s fee by card. ASIC registers the business name once it is paid.', 'businessapi-forms' );
	}

	/**
	 * "Your applications" on the order page: status and outcomes only, never application details.
	 *
	 * @param \WC_Order|mixed $order Order.
	 */
	public static function customer_status( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$refs = OrderBridge::item_refs( $order );
		$apps = array_filter( OrderBridge::apps( $order ), fn ( $app, $ref ) => in_array( (string) $ref, $refs, true ), ARRAY_FILTER_USE_BOTH );
		// Pay first: lines whose application is not created yet (or was cancelled before it was).
		$lines   = Prepaid::item_slots( $order );
		$pending = array_filter( Prepaid::slots( $order ), fn ( $row, $slot ) => in_array( (string) $slot, $lines, true ) && in_array( $row['state'] ?? '', array( 'awaiting_payment', 'issuing', 'blocked', 'cancelled' ), true ) && '' === (string) ( $row['ref'] ?? '' ), ARRAY_FILTER_USE_BOTH );
		if ( ! $apps && ! $pending ) {
			return;
		}
		$labels = Plugin::instance()->products->all();
		echo '<section class="bapi-forms-applications" style="margin:2em 0"><h2>' . esc_html__( 'Your applications', 'businessapi-forms' ) . '</h2><ul>';
		foreach ( $pending as $row ) {
			if ( 'cancelled' === ( $row['state'] ?? '' ) ) {
				$text = __( 'Cancelled.', 'businessapi-forms' );
			} elseif ( 'blocked' === ( $row['state'] ?? '' ) && $order->is_paid() ) {
				// Waiting for the partner to fix something: no promise of an email link.
				$text = __( 'In progress. We will be in touch about your application.', 'businessapi-forms' );
			} elseif ( $order->is_paid() ) {
				$text = __( 'In progress. Your form is being prepared; we will email you the link.', 'businessapi-forms' );
			} else {
				$text = __( 'Awaiting payment. You can fill in your application once payment is confirmed.', 'businessapi-forms' );
			}
			printf(
				'<li><strong>%s:</strong> %s</li>',
				esc_html( ExpectedLines::line_name( (string) ( $row['product'] ?? '' ), (array) ( $row['addons'] ?? array() ) ) ),
				esc_html( $text )
			);
		}
		foreach ( $apps as $ref => $app ) {
			$line = self::customer_label( $app, $order, (string) $ref );
			foreach ( array(
				'acn' => __( 'ACN', 'businessapi-forms' ),
				'abn' => self::abn_label( $app ),
			) as $key => $name ) {
				if ( '' !== (string) ( $app[ $key ] ?? '' ) ) {
					$line .= ' ' . $name . ' ' . $app[ $key ] . '.';
				}
			}
			if ( 'completed' === ( $app['status'] ?? '' ) && '' !== (string) ( $app['document'] ?? '' ) ) {
				/* translators: %s: ASIC document number, e.g. 1-11DXAZ9L. */
				$line .= ' ' . sprintf( __( 'ASIC document number %s.', 'businessapi-forms' ), $app['document'] );
			}
			printf(
				'<li data-bapi-ref="%s"><strong>%s (%s):</strong> <span class="bapi-forms-app-status">%s</span>%s</li>',
				esc_attr( strtoupper( (string) $ref ) ),
				// The line's own title ("Company registration (with ABN)"), else the product name.
				esc_html( '' !== OrderLines::line_name( $order, (string) $ref ) ? OrderLines::line_name( $order, (string) $ref ) : (string) ( $labels[ $app['product'] ?? '' ]['label'] ?? __( 'Application', 'businessapi-forms' ) ) ),
				esc_html( self::short_ref( (string) $ref ) ),
				esc_html( $line ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped HTML from amend_button() and start_button().
				\BapiForms\Frontend\MyAccount::amend_button( $order, (string) $ref, $app ) . \BapiForms\Frontend\PrepaidStart::start_button( $order, (string) $ref, $app )
			);
		}
		echo '</ul></section>';
	}

	/**
	 * Customer status: awaiting payment, in progress, completed, action needed or cancelled only.
	 * Agency detail is for the partner (order notes).
	 *
	 * @param array<string, mixed> $app   Tracked application.
	 * @param \WC_Order            $order Order.
	 * @param string               $ref   Application reference (the Amend offer needs it).
	 */
	public static function customer_label( array $app, \WC_Order $order, string $ref = '' ): string {
		$state  = (string) ( $app['state'] ?? '' );
		$status = (string) ( $app['status'] ?? '' );
		if ( 'cancelled' === $state || in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
			return __( 'Cancelled.', 'businessapi-forms' );
		}
		if ( 'prepaid' === $state ) {
			if ( ! empty( $app['suspended'] ) || ! empty( $app['held'] ) || ! \BapiForms\Frontend\PrepaidStart::order_allows_start( $order, $ref ) ) {
				return __( 'In progress.', 'businessapi-forms' );
			}
			return ! empty( $app['form']['started'] )
				? __( 'Action needed: finish filling in your application.', 'businessapi-forms' )
				: __( 'Action needed: fill in your application to get started.', 'businessapi-forms' );
		}
		if ( 'released' !== $state ) {
			// Unpaid (pending, on hold for a cheque or bank transfer, failed): nothing is sent yet.
			return $order->is_paid()
				? __( 'In progress.', 'businessapi-forms' )
				: __( 'Awaiting payment. Your application will be sent once payment is confirmed.', 'businessapi-forms' );
		}
		if ( 'action_needed' === $status && 'lapsed' === ( $app['asic'] ?? '' ) ) {
			return __( 'In progress.', 'businessapi-forms' ); // The partner renews ASIC's payment link: not the customer's action.
		}
		// The Amend offer only where the button is (paid, not refunded, cancelled or on hold).
		if ( 'action_needed' === $status && ! empty( $app['amendable'] ) && '' !== $ref && \BapiForms\Frontend\MyAccount::can_amend( $order, $ref ) ) {
			return __( 'Action needed: the government agency asked for corrections. Amend your application to fix them and resubmit, at no extra charge.', 'businessapi-forms' );
		}
		if ( 'action_needed' === $status && 'ppsr_search' === ( $app['product'] ?? '' ) ) {
			return __( 'Action needed: your PPSR search could not be completed. We will contact you about it.', 'businessapi-forms' );
		}
		if ( 'action_needed' === $status ) {
			return __( 'Action needed: the government agency asked for corrections. We will contact you about them.', 'businessapi-forms' );
		}
		if ( 'completed' === $status ) {
			// Companies and ABNs only show completed once their number is known.
			$number = match ( (string) ( $app['product'] ?? '' ) ) {
				'company_registration' => (string) ( $app['acn'] ?? '' ),
				'abn_registration'     => (string) ( $app['abn'] ?? '' ),
				default                => 'n/a',
			};
			if ( '' !== $number ) {
				return __( 'Completed.', 'businessapi-forms' );
			}
		}
		return __( 'In progress.', 'businessapi-forms' );
	}

	/**
	 * "Holder ABN" for a business name: the holder's existing ABN, not one the application produced.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function abn_label( array $app ): string {
		return 'business_name' === ( $app['product'] ?? '' ) ? __( 'Holder ABN', 'businessapi-forms' ) : __( 'ABN', 'businessapi-forms' );
	}

	/** Short, non-identifying label for an application: the last 6 characters of its reference. */
	public static function short_ref( string $ref ): string {
		/* translators: %s: last 6 characters of the application reference. */
		return sprintf( __( 'Application #%s', 'businessapi-forms' ), strtoupper( substr( $ref, -6 ) ) );
	}
}
