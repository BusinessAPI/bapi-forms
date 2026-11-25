<?php
/**
 * "Action needed" and "Completed" customer emails, sent once per transition from the status poll.
 * Action needed is sent again only after the application left that state and came back; a lapsed
 * ASIC payment is the partner's to fix, so it sends nothing. Completed waits up to about 2 hours
 * for company and PPSR documents, then goes without links. Order meta keeps timestamps only.
 *
 * @package BapiForms
 */

namespace BapiForms\Emails;

use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Commerce\WooCommerce\StatusText;
use BapiForms\Documents\Downloads;
use BapiForms\Plugin;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Emails {

	public const META       = '_bapi_forms_emails';
	public const HOOK_RETRY = 'bapi_forms_completed_email';
	public const GROUP      = \BapiForms\Scheduler::GROUP;

	/** Waits for documents before the completed email goes without them. */
	private const DOC_WAITS = array( 10 * MINUTE_IN_SECONDS, 20 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS );

	/** Products whose documents are made after the agency finishes. */
	private const WAIT_FOR_DOCUMENTS = array( 'company_registration', 'ppsr_search' );

	/** Products with their own customer emails. */
	private const SKIP = array( 'entity_monitoring' );

	public function __construct( private readonly Plugin $plugin, private readonly Downloads $downloads ) {}

	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'email_classes' ) );
		add_action( 'bapi_forms_application_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( self::HOOK_RETRY, array( $this, 'retry_completed' ), 10, 3 );
		add_action( \BapiForms\Monitoring\Notifier::HOOK_DAILY, array( $this, 'sweep_waiting' ) );
	}

	/**
	 * Daily: queue the last try of a "completed" email whose document retries are gone. Polls stop
	 * once an application is completed, so nothing else would send it.
	 */
	public function sweep_waiting(): void {
		$waits = (array) apply_filters( 'bapi_forms_completed_email_document_waits', self::DOC_WAITS );
		$stale = (int) array_sum( array_map( 'intval', $waits ) ) + HOUR_IN_SECONDS;
		$ids   = (array) wc_get_orders(
			array(
				'limit'      => 100,
				'return'     => 'ids',
				'orderby'    => 'date_modified',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META,
						'value'   => 'completed_waiting',
						'compare' => 'LIKE',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$apps = OrderBridge::apps( $order );
			foreach ( self::sent( $order ) as $ref => $entry ) {
				$ref = (string) $ref;
				if ( ! is_array( $entry ) || empty( $entry['completed_waiting'] ) || ! empty( $entry['completed'] ) || time() - (int) $entry['completed_waiting'] < $stale ) {
					continue;
				}
				if ( ! isset( $apps[ $ref ] ) || 'completed' !== self::customer_state( $apps[ $ref ] ) || self::retry_pending( (int) $id, $ref ) ) {
					continue;
				}
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_RETRY, array( (int) $id, $ref, count( $waits ) ), self::GROUP );
			}
		}
	}

	/**
	 * @param array<string, \WC_Email> $emails WooCommerce emails.
	 */
	public function email_classes( $emails ): array {
		$emails                                    = (array) $emails;
		$emails['BapiForms_ActionNeededEmail']     = new ActionNeededEmail();
		$emails['BapiForms_CompletedEmail']        = new CompletedEmail();
		$emails['BapiForms_StartApplicationEmail'] = new StartApplicationEmail();
		$emails['BapiForms_PrepaidUnusedEmail']    = new PrepaidUnusedEmail();
		return $emails;
	}

	/**
	 * Called inside the poll (order lock held); the poll saves the order afterwards.
	 *
	 * @param \WC_Order|mixed      $order Order.
	 * @param string|mixed         $ref   Application reference.
	 * @param array<string, mixed> $app   Tracked application after the change.
	 */
	public function on_status_changed( $order, $ref, $app ): void {
		if ( ! $order instanceof \WC_Order || ! is_array( $app ) || in_array( (string) ( $app['product'] ?? '' ), self::SKIP, true ) ) {
			return;
		}
		$ref   = (string) $ref;
		$sent  = self::sent( $order );
		$entry = $sent[ $ref ] ?? array();
		$state = self::customer_state( $app );

		if ( 'action_needed' === $state ) {
			if ( empty( $entry['action_needed'] ) && $this->send_action_needed( $order, $ref, $app ) ) {
				$entry['action_needed'] = time();
			}
		} elseif ( ! empty( $entry['action_needed'] ) ) {
			unset( $entry['action_needed'] ); // Left action needed: a later refusal is a new transition.
		}

		if ( 'completed' === $state && empty( $entry['completed'] ) && empty( $entry['completed_waiting'] ) ) {
			if ( $this->completed_now_or_wait( $order, $ref, $app, 0 ) ) {
				$entry['completed'] = time();
			} else {
				$entry['completed_waiting'] = time();
			}
		} elseif ( 'completed' === $state && empty( $entry['completed'] ) && time() - (int) $entry['completed_waiting'] > 10 * MINUTE_IN_SECONDS && ! self::retry_pending( (int) $order->get_id(), $ref ) ) {
			// Its document retry is gone: send now with whatever documents there are.
			$waits = (array) apply_filters( 'bapi_forms_completed_email_document_waits', self::DOC_WAITS );
			if ( $this->completed_now_or_wait( $order, $ref, $app, count( $waits ) ) ) {
				$entry['completed'] = time();
				unset( $entry['completed_waiting'] );
			}
		}

		$sent[ $ref ] = $entry;
		$order->update_meta_data( self::META, $sent );
	}

	/**
	 * Documents were not ready yet: try again.
	 *
	 * @param int|string $order_id Order id.
	 * @param string     $ref      Application reference.
	 * @param int|string $attempt  Waits done so far.
	 */
	public function retry_completed( $order_id, $ref, $attempt ): void {
		$order_id = (int) $order_id;
		$done     = OrderBridge::with_lock(
			$order_id,
			function () use ( $order_id, $ref, $attempt ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$ref   = (string) $ref;
				$sent  = self::sent( $order );
				$entry = $sent[ $ref ] ?? array();
				$app   = OrderBridge::apps( $order )[ $ref ] ?? null;
				if ( ! empty( $entry['completed'] ) || ! is_array( $app ) ) {
					return;
				}
				if ( 'completed' !== self::customer_state( $app ) ) {
					// No longer completed (e.g. action needed again): a later "completed" starts afresh.
					if ( ! empty( $entry['completed_waiting'] ) ) {
						unset( $entry['completed_waiting'] );
						$sent[ $ref ] = $entry;
						$order->update_meta_data( self::META, $sent );
						$order->save();
					}
					return;
				}
				if ( $this->completed_now_or_wait( $order, $ref, $app, (int) $attempt ) ) {
					$entry['completed'] = time();
					unset( $entry['completed_waiting'] );
					$sent[ $ref ] = $entry;
					$order->update_meta_data( self::META, $sent );
					$order->save();
				}
			},
			30
		);
		if ( ! $done ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_RETRY, array( $order_id, (string) $ref, (int) $attempt ), self::GROUP );
		}
	}

	private static function retry_pending( int $order_id, string $ref ): bool {
		$waits = (array) apply_filters( 'bapi_forms_completed_email_document_waits', self::DOC_WAITS );
		$last  = count( $waits ) + 1;
		for ( $attempt = 0; $attempt <= $last; $attempt++ ) {
			if ( \BapiForms\Scheduler::pending( self::HOOK_RETRY, array( $order_id, $ref, $attempt ), self::GROUP ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Send the completed email or schedule a wait for documents. True when done (sent, or
	 * switched off in WooCommerce).
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	private function completed_now_or_wait( \WC_Order $order, string $ref, array $app, int $attempt ): bool {
		$documents = array_values( array_filter( $this->downloads->for_order( $order ), fn ( $d ) => $d['ref'] === $ref ) );
		$waits     = apply_filters( 'bapi_forms_completed_email_document_waits', self::DOC_WAITS );
		if ( ! $documents && in_array( (string) ( $app['product'] ?? '' ), self::WAIT_FOR_DOCUMENTS, true ) && $attempt < count( $waits ) ) {
			$args = array( (int) $order->get_id(), $ref, $attempt + 1 );
			if ( ! \BapiForms\Scheduler::pending( self::HOOK_RETRY, $args, self::GROUP ) ) {
				as_schedule_single_action( time() + (int) $waits[ $attempt ], self::HOOK_RETRY, $args, self::GROUP );
			}
			return false;
		}

		$email = self::email( 'BapiForms_CompletedEmail' );
		if ( null === $email ) {
			return true;
		}
		$sent = $email->trigger(
			$order,
			array(
				'product'     => self::product_label( $app ),
				'short_ref'   => StatusText::short_ref( $ref ),
				'numbers'     => self::numbers( $app ),
				'documents'   => array_map(
					fn ( $d ) => array(
						'label' => $d['label'],
						'url'   => $d['url'],
					),
					$documents
				),
				'account_url' => self::order_url( $order ),
				'sandbox'     => self::sandbox( $app ),
			)
		);
		if ( $sent ) {
			/* translators: %s: application reference. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: "completed" email sent to the customer for application %s.', 'businessapi-forms' ), $ref ) );
		}
		return true;
	}

	/** @param array<string, mixed> $app Tracked application. */
	private function send_action_needed( \WC_Order $order, string $ref, array $app ): bool {
		$email = self::email( 'BapiForms_ActionNeededEmail' );
		if ( null === $email ) {
			return true;
		}
		$amend = self::amend_url( $order, $ref, $app );
		// A failed PPSR search has nothing to correct: the partner runs it again or refunds.
		$ppsr = 'ppsr_search' === ( $app['product'] ?? '' ) && '' === $amend;
		$sent = $email->trigger(
			$order,
			array(
				'intro'        => $ppsr ? __( 'Your PPSR search could not be completed. We will be in touch shortly to run it again or arrange a refund. You can check your order at any time.', 'businessapi-forms' ) : '',
				'product'      => self::product_label( $app ),
				'short_ref'    => StatusText::short_ref( $ref ),
				'sandbox'      => self::sandbox( $app ),
				'action_url'   => '' !== $amend ? $amend : self::order_url( $order ),
				'action_label' => '' !== $amend ? __( 'Fix and resubmit, no extra charge', 'businessapi-forms' ) : __( 'View your order', 'businessapi-forms' ),
				'action_text'  => $ppsr ? '' : ( '' !== $amend
					? __( 'Use the button below to review the changes and send your application again. There is no extra charge.', 'businessapi-forms' )
					: __( 'We will be in touch about the changes needed. You can check the status of your application on your order at any time.', 'businessapi-forms' ) ),
			)
		);
		if ( $sent ) {
			/* translators: %s: application reference. */
			$order->add_order_note( sprintf( __( 'BusinessAPI Forms: "action needed" email sent to the customer for application %s.', 'businessapi-forms' ), $ref ) );
		}
		return true;
	}

	/**
	 * The amend link when the customer can amend this application now, else ''.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function amend_url( \WC_Order $order, string $ref, array $app ): string {
		$class = '\BapiForms\Frontend\MyAccount';
		if ( ! class_exists( $class ) || ! is_callable( array( $class, 'amend_url' ) ) ) {
			return '';
		}
		// can_amend() reads the saved order meta; inside the poll the change is not saved yet, so
		// the tracked application passed in (amendable + an amendable product) also counts.
		$saved    = is_callable( array( $class, 'can_amend' ) ) && call_user_func( array( $class, 'can_amend' ), $order, $ref );
		$products = defined( $class . '::AMENDABLE_PRODUCTS' ) ? (array) constant( $class . '::AMENDABLE_PRODUCTS' ) : array();
		$fresh    = 'action_needed' === ( $app['status'] ?? '' ) && ! empty( $app['amendable'] ) && in_array( (string) ( $app['product'] ?? '' ), $products, true )
			// Same order checks as on the order page: paid, not refunded, cancelled or on hold.
			&& is_callable( array( $class, 'order_allows_amend' ) ) && call_user_func( array( $class, 'order_allows_amend' ), $order, $ref, $app );
		if ( ! $saved && ! $fresh ) {
			return '';
		}
		$url = call_user_func( array( $class, 'amend_url' ), $order, $ref );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * The customer's simple status, same rules as StatusText::customer_label(): action_needed,
	 * completed or other.
	 *
	 * @param array<string, mixed> $app Tracked application.
	 */
	public static function customer_state( array $app ): string {
		if ( 'released' !== ( $app['state'] ?? '' ) ) {
			return 'other';
		}
		$status  = (string) ( $app['status'] ?? '' );
		$product = (string) ( $app['product'] ?? '' );
		if ( 'action_needed' === $status ) {
			return 'lapsed' === ( $app['asic'] ?? '' ) ? 'other' : 'action_needed';
		}
		if ( 'completed' !== $status || 'sandbox_validated' === ( $app['progress'] ?? '' ) ) {
			return 'other';
		}
		$number = match ( $product ) {
			'company_registration' => (string) ( $app['acn'] ?? '' ),
			'abn_registration'     => (string) ( $app['abn'] ?? '' ),
			default                => 'n/a',
		};
		return '' !== $number ? 'completed' : 'other';
	}

	/**
	 * E.g. "ACN 123 456 789", "ABN 12 345 678 901", "ASIC document number 1-ABC".
	 *
	 * @param array<string, mixed> $app Tracked application.
	 * @return list<string>
	 */
	private static function numbers( array $app ): array {
		$out = array();
		$acn = preg_replace( '/\D/', '', (string) ( $app['acn'] ?? '' ) );
		$abn = preg_replace( '/\D/', '', (string) ( $app['abn'] ?? '' ) );
		if ( 9 === strlen( $acn ) ) {
			/* translators: %s: ACN. */
			$out[] = sprintf( __( 'ACN %s', 'businessapi-forms' ), implode( ' ', str_split( $acn, 3 ) ) );
		}
		if ( 11 === strlen( $abn ) ) {
			$out[] = \BapiForms\Commerce\WooCommerce\StatusText::abn_label( $app ) . ' ' . substr( $abn, 0, 2 ) . ' ' . implode( ' ', str_split( substr( $abn, 2 ), 3 ) );
		}
		if ( '' !== (string) ( $app['document'] ?? '' ) ) {
			/* translators: %s: ASIC document number. */
			$out[] = sprintf( __( 'ASIC document number %s', 'businessapi-forms' ), (string) $app['document'] );
		}
		return $out;
	}

	/** @param array<string, mixed> $app Tracked application. */
	private static function product_label( array $app ): string {
		$all = Plugin::instance()->products->all();
		return (string) ( $all[ (string) ( $app['product'] ?? '' ) ]['label'] ?? __( 'Application', 'businessapi-forms' ) );
	}

	/**
	 * @param array<string, mixed> $app Tracked application.
	 */
	private static function sandbox( array $app ): bool {
		return Options::ENV_TEST === ( $app['env'] ?? '' );
	}

	/** My Account for registered customers, the order received page for guests. */
	private static function order_url( \WC_Order $order ): string {
		return (int) $order->get_customer_id() > 0 ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
	}

	private static function email( string $id ): ?ApplicationEmail {
		$emails = WC()->mailer()->get_emails();
		return ( $emails[ $id ] ?? null ) instanceof ApplicationEmail ? $emails[ $id ] : null;
	}

	/** @return array<string, array<string, int>> */
	private static function sent( \WC_Order $order ): array {
		$sent = $order->get_meta( self::META );
		return is_array( $sent ) ? $sent : array();
	}
}
