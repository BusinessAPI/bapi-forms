<?php
/**
 * My Account > Monitoring: the customer's subscriptions read live from BAPI, with renew, stop,
 * resume and the report PDF streamed through this site.
 *
 * Only subscriptions whose current BAPI reference is held by this customer's paid order are
 * shown. Reference, status and dates are cached per user for a minute so an action right after
 * a view needs no second call; names and events are never stored.
 *
 * @package BapiForms
 */

namespace BapiForms\Monitoring;

use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class AccountTab {

	public const ENDPOINT = 'bapi-monitoring';
	public const ACTION   = 'bapi_forms_monitoring';
	public const REPORT   = 'bapi_forms_monitoring_report';

	/** WooCommerce's own My Account button classes, so the theme styles them. */
	public const BUTTON_CLASS = 'woocommerce-button button wp-element-button';
	private const REWRITE     = 'bapi_forms_monitoring_rewrite';
	private const NOTICE      = 'bapi_forms_monitoring_notice_';
	private const STATUS      = 'bapi_forms_mon_status_';
	private const LIMIT       = 'bapi_forms_rl_mon_';

	/** Per user, per 10 minutes. */
	private const MAX_VIEWS   = 30;
	private const MAX_REPORTS = 10;
	private const MAX_ACTIONS = 20;

	/** Each shown entity costs an events call. */
	private const MAX_SHOWN = 25;

	public function __construct(
		private readonly Api $api,
		private readonly Renewals $renewals,
	) {}

	public function register(): void {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', static fn () => __( 'Monitoring', 'businessapi-forms' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::REPORT, array( $this, 'report' ) );
		add_action( 'init', array( $this, 'flush_once' ), 99 );
	}

	public static function url(): string {
		return wc_get_account_endpoint_url( self::ENDPOINT );
	}

	/**
	 * @param array<string, string> $vars WooCommerce query vars.
	 */
	public function query_vars( $vars ): array {
		$vars                   = (array) $vars;
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	public function flush_once(): void {
		if ( '1' !== get_option( self::REWRITE ) ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE, '1', true );
		}
	}

	/**
	 * Only for customers who bought monitoring.
	 *
	 * @param array<string, string> $items Menu items.
	 */
	public function menu( $items ): array {
		$items = (array) $items;
		if ( ! Entities::for_customer( get_current_user_id(), Plugin::instance()->options->environment() ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Monitoring', 'businessapi-forms' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Monitoring', 'businessapi-forms' );
		}
		return $out;
	}

	public function render(): void {
		$user = get_current_user_id();
		$this->notice( $user );
		$env        = Plugin::instance()->options->environment();
		$candidates = array_slice( Entities::for_customer( $user, $env ), 0, self::MAX_SHOWN, true );
		if ( ! $candidates ) {
			echo '<p>' . esc_html__( 'You are not monitoring any companies or businesses yet.', 'businessapi-forms' ) . '</p>';
			return;
		}
		if ( ! self::allow( $user, 'view', self::MAX_VIEWS ) ) {
			echo '<p>' . esc_html__( 'You have viewed this page many times in a short while. Please try again in a few minutes.', 'businessapi-forms' ) . '</p>';
			return;
		}

		$ids = array();
		foreach ( array_keys( $candidates ) as $key ) {
			$ids[] = Entities::parse( $key )[1];
		}
		$entities = $this->api->entities_by_ids( $ids, $env );
		if ( null === $entities ) {
			echo '<p>' . esc_html__( 'Your monitoring could not be loaded right now. Please try again later.', 'businessapi-forms' ) . '</p>';
			return;
		}

		$shown  = array();
		$status = array();
		foreach ( $candidates as $key => $refs ) {
			$entity    = $entities[ Entities::parse( $key )[1] ] ?? null;
			$reference = is_array( $entity ) ? Entities::norm( (string) ( $entity['reference'] ?? '' ) ) : '';
			// Only the customer's current subscription, never a reuse by someone else.
			if ( '' === $reference || ! in_array( $reference, $refs, true ) || ! Entities::belongs_to( $key, $user, $reference ) ) {
				continue;
			}
			$shown[ $key ]  = $entity;
			$status[ $key ] = self::slim( $entity );
		}
		set_transient( self::STATUS . $user, $status, MINUTE_IN_SECONDS );
		if ( ! $shown ) {
			echo '<p>' . esc_html__( 'You are not monitoring any companies or businesses yet.', 'businessapi-forms' ) . '</p>';
			return;
		}

		echo '<div class="bapi-forms-monitoring">';
		foreach ( $shown as $entity ) {
			$this->entity( $env, $entity );
		}
		echo '<p class="bapi-forms-monitoring-disclaimer"><small>' . esc_html__( 'Changes are found by checking the public registers every day. This is a notification service, not legal, financial or compliance advice.', 'businessapi-forms' ) . '</small></p>';
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $entity BAPI entity object (read now, not stored).
	 */
	private function entity( string $env, array $entity ): void {
		$id      = (int) ( $entity['id'] ?? 0 );
		$key     = Entities::key( $env, $id );
		$status  = (string) ( $entity['status'] ?? '' );
		$expires = (string) ( $entity['expiresAt'] ?? '' );

		echo '<section class="bapi-forms-monitoring-entity">';
		printf( '<h3>%s</h3>', esc_html( Entities::display_name( $entity ) ) );
		echo '<ul class="bapi-forms-monitoring-facts">';
		if ( '' !== Entities::number_label( $entity ) && '' !== trim( (string) ( $entity['entityName'] ?? '' ) ) ) {
			printf( '<li>%s</li>', esc_html( Entities::number_label( $entity ) ) );
		}
		/* translators: %s: Active, Stopped, Ended or Setting up. */
		printf( '<li>%s</li>', esc_html( sprintf( __( 'Status: %s', 'businessapi-forms' ), Entities::simple_status( $entity ) ) ) );
		if ( '' !== $expires && in_array( $status, array( 'active', 'stopped' ), true ) ) {
			/* translators: %s: date. */
			printf( '<li>%s</li>', esc_html( sprintf( __( 'Paid until %s', 'businessapi-forms' ), Notifier::date( $expires ) ) ) );
		} elseif ( '' !== $expires && 'expired' === $status ) {
			/* translators: %s: date. */
			printf( '<li>%s</li>', esc_html( sprintf( __( 'Ended on %s', 'businessapi-forms' ), Notifier::date( $expires ) ) ) );
		}
		echo '</ul>';

		$this->events( $env, $id, $status );
		$this->actions( $env, $entity, $key );
		echo '</section>';
	}

	private function events( string $env, int $id, string $status ): void {
		$response = $this->api->events( $id, $env, 5 );
		$events   = $response->ok() ? (array) ( $response->data['data'] ?? array() ) : array();
		printf( '<h4>%s</h4>', esc_html__( 'Recent changes', 'businessapi-forms' ) );
		if ( ! $response->ok() ) {
			echo '<p>' . esc_html__( 'Changes could not be loaded right now.', 'businessapi-forms' ) . '</p>';
			return;
		}
		if ( ! $events ) {
			echo '<p>' . esc_html( 'active' === $status ? __( 'No changes found so far.', 'businessapi-forms' ) : __( 'No changes were found.', 'businessapi-forms' ) ) . '</p>';
			return;
		}
		$format = (string) get_option( 'date_format' );
		$format = '' !== $format ? $format : 'j F Y';
		echo '<ul class="bapi-forms-monitoring-events">';
		foreach ( $events as $event ) {
			$when = strtotime( (string) ( $event['detectedAt'] ?? '' ) );
			printf(
				'<li>%s <small>(%s)</small></li>',
				esc_html( (string) ( $event['title'] ?? __( 'Change', 'businessapi-forms' ) ) ),
				esc_html( $when ? date_i18n( $format, $when ) : '' )
			);
		}
		echo '</ul>';
	}

	/**
	 * @param array<string, mixed> $entity BAPI entity object.
	 */
	private function actions( string $env, array $entity, string $key ): void {
		$status  = (string) ( $entity['status'] ?? '' );
		$days    = (int) ( $entity['daysRemaining'] ?? 0 );
		$expires = (string) ( $entity['expiresAt'] ?? '' );
		$buttons = array();

		$renewal = '' !== $expires ? Renewals::existing( $key, $expires, false, Entities::norm( (string) ( $entity['reference'] ?? '' ) ) ) : null;
		// Stopped with no paid time left: only a renewal starts it again.
		$ended = 'expired' === $status || ( 'stopped' === $status && '' !== $expires && $expires <= gmdate( 'Y-m-d' ) );
		if ( $renewal && $renewal->needs_payment() ) {
			$buttons[] = sprintf( '<a class="%s" href="%s">%s</a>', esc_attr( self::BUTTON_CLASS ), esc_url( $renewal->get_checkout_payment_url() ), esc_html__( 'Pay to renew', 'businessapi-forms' ) );
		} elseif ( Renewals::sellable() && ( $ended || ( 'active' === $status && $days <= 60 ) ) && ! ( $renewal && $renewal->is_paid() ) ) {
			$buttons[] = $this->button( 'renew', $key, __( 'Renew for 12 months', 'businessapi-forms' ) );
		}
		if ( 'active' === $status ) {
			$buttons[] = $this->button( 'stop', $key, __( 'Stop monitoring', 'businessapi-forms' ), __( 'Stop monitoring? There is no refund for the time left, but you can resume it free of charge until it ends.', 'businessapi-forms' ) );
		} elseif ( 'stopped' === $status && '' !== $expires && $expires > gmdate( 'Y-m-d' ) ) {
			$buttons[] = $this->button( 'resume', $key, __( 'Resume monitoring', 'businessapi-forms' ) );
		}
		if ( 'active' === $status && 'live' === $env ) {
			$buttons[] = sprintf(
				'<a class="%s" href="%s">%s</a>',
				esc_attr( self::BUTTON_CLASS ),
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action' => self::REPORT,
								'entity' => $key,
							),
							admin_url( 'admin-post.php' )
						),
						self::REPORT . $key
					)
				),
				esc_html__( 'Download report (PDF)', 'businessapi-forms' )
			);
		}
		if ( $buttons ) {
			echo '<div class="bapi-forms-monitoring-actions">' . implode( '', $buttons ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	private function button( string $action, string $key, string $label, string $confirm = '' ): string {
		return sprintf(
			'<form method="post" action="%1$s"%6$s><input type="hidden" name="action" value="%2$s"><input type="hidden" name="do" value="%3$s"><input type="hidden" name="entity" value="%4$s">%5$s<button type="submit" class="%8$s">%7$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION ),
			esc_attr( $action ),
			esc_attr( $key ),
			wp_nonce_field( self::ACTION . $action . $key, '_wpnonce', true, false ),
			'' !== $confirm ? ' data-bapi-forms-confirm="' . esc_attr( $confirm ) . '"' : '',
			esc_html( $label ),
			esc_attr( self::BUTTON_CLASS )
		);
	}

	/** Monitoring tab assets, on that endpoint only. */
	public function enqueue(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}
		wp_enqueue_style( 'bapi-forms-account', BAPI_FORMS_URL . 'assets/css/account.css', array(), BAPI_FORMS_VERSION );
		wp_enqueue_script( 'bapi-forms-account', BAPI_FORMS_URL . 'assets/js/account.js', array(), BAPI_FORMS_VERSION, array( 'in_footer' => true ) );
	}

	/** Stop, resume or renew, for the logged-in customer who owns the entity now. */
	public function handle(): void {
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$key  = isset( $_POST['entity'] ) ? sanitize_text_field( wp_unslash( $_POST['entity'] ) ) : '';
		$user = get_current_user_id();
		if ( ! $user ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		$parsed = Entities::parse( $key );
		if ( ! in_array( $do, array( 'stop', 'resume', 'renew' ), true ) || null === $parsed
			|| ! wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '', self::ACTION . $do . $key ) ) {
			$this->flash( $user, __( 'That action is not available. Please reload the page and try again.', 'businessapi-forms' ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		if ( ! self::allow( $user, 'action', self::MAX_ACTIONS ) ) {
			$this->flash( $user, __( 'Too many requests in a short while. Please try again in a few minutes.', 'businessapi-forms' ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		[ $env, $id ] = $parsed;
		// An action changes something: ownership is checked against the live reference.
		$entity    = $this->api->entity( $id, $env );
		$reference = $entity->ok() ? Entities::norm( (string) ( $entity->data['reference'] ?? '' ) ) : '';
		if ( Plugin::instance()->options->environment() !== $env || ! Entities::belongs_to( $key, $user, $reference ) ) {
			$this->flash( $user, __( 'That action is not available. Please reload the page and try again.', 'businessapi-forms' ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		delete_transient( self::STATUS . $user );
		$owner = Entities::owner_order( $key, $reference );

		if ( 'renew' === $do ) {
			$order = $this->renewals->ensure( $env, $entity->data, false, true );
			if ( $order && ! $order->is_paid() ) {
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;
			}
			$this->flash( $user, __( 'Renewal is not available for this entity right now. Please contact us.', 'businessapi-forms' ) );
		} else {
			$response = 'stop' === $do ? $this->api->stop( $id, $env ) : $this->api->resume( $id, $env );
			if ( $response->ok() ) {
				$this->flash( $user, 'stop' === $do ? __( 'Monitoring stopped. You can resume it until the paid period ends.', 'businessapi-forms' ) : __( 'Monitoring resumed.', 'businessapi-forms' ) );
				if ( $owner ) {
					$owner->add_order_note(
						'stop' === $do
							/* translators: %d: entity id. */
							? sprintf( __( 'BusinessAPI Forms: the customer stopped monitoring of entity %d from My Account.', 'businessapi-forms' ), $id )
							/* translators: %d: entity id. */
							: sprintf( __( 'BusinessAPI Forms: the customer resumed monitoring of entity %d from My Account.', 'businessapi-forms' ), $id )
					);
				}
			} else {
				$busy = 'order_processing' === $response->code();
				$this->flash( $user, $busy ? __( 'This order is still being processed. Please try again in a few minutes.', 'businessapi-forms' ) : __( 'That did not work just now. Please try again later or contact us.', 'businessapi-forms' ) );
			}
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Streams the entity report PDF from BAPI (no disk write, no cache). */
	public function report(): void {
		$key  = isset( $_GET['entity'] ) ? sanitize_text_field( wp_unslash( $_GET['entity'] ) ) : '';
		$user = get_current_user_id();
		if ( ! $user ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		$parsed = Entities::parse( $key );
		if ( null === $parsed || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', self::REPORT . $key ) ) {
			wp_die( esc_html__( 'This download is not available.', 'businessapi-forms' ), '', array( 'response' => 403 ) );
		}
		if ( ! self::allow( $user, 'report', self::MAX_REPORTS ) ) {
			$this->flash( $user, __( 'Too many downloads in a short while. Please try again in a few minutes.', 'businessapi-forms' ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		[ $env, $id ] = $parsed;
		$reference    = $this->reference( $user, $key, $id, $env );
		if ( Plugin::instance()->options->environment() !== $env || ! Entities::belongs_to( $key, $user, $reference ) ) {
			wp_die( esc_html__( 'This download is not available.', 'businessapi-forms' ), '', array( 'response' => 403 ) );
		}
		$pdf = $this->api->report( $id, $env );
		if ( '' !== $pdf['code'] ) {
			$this->flash(
				$user,
				match ( $pdf['code'] ) {
					'not_available_in_sandbox' => __( 'Reports are built from live register data, so they are not available in test mode.', 'businessapi-forms' ),
					'entity_not_active'        => __( 'Reports are available while an entity is being monitored.', 'businessapi-forms' ),
					'snapshot_not_ready'       => __( 'This entity is still being set up. Please try again in a few minutes.', 'businessapi-forms' ),
					'incomplete'               => __( 'The report download was interrupted. Please try again.', 'businessapi-forms' ),
					default                    => __( 'The report could not be downloaded right now. Please try again shortly.', 'businessapi-forms' ),
				}
			);
			wp_safe_redirect( self::url() );
			exit;
		}
		$owner = Entities::owner_order( $key, $reference );
		do_action(
			'bapi_forms_audit',
			'document_downloaded',
			array(
				'orderId'    => $owner ? (int) $owner->get_id() : 0,
				'documentId' => 'monitoring-report-' . $id,
			)
		);

		nocache_headers();
		\BapiForms\Documents\Pdf::send( $pdf['body'], $pdf['filename'] );
	}

	/** BAPI's current reference, from the user's one-minute cache or read live. */
	private function reference( int $user, string $key, int $id, string $env ): string {
		$cache = get_transient( self::STATUS . $user );
		if ( is_array( $cache ) && isset( $cache[ $key ]['reference'] ) ) {
			return Entities::norm( (string) $cache[ $key ]['reference'] );
		}
		$entity = $this->api->entity( $id, $env );
		return $entity->ok() ? Entities::norm( (string) ( $entity->data['reference'] ?? '' ) ) : '';
	}

	/**
	 * @param array<string, mixed> $entity BAPI entity.
	 */
	private static function slim( array $entity ): array {
		return array(
			'reference' => Entities::norm( (string) ( $entity['reference'] ?? '' ) ),
			'status'    => (string) ( $entity['status'] ?? '' ),
			'expiresAt' => (string) ( $entity['expiresAt'] ?? '' ),
		);
	}

	/** Per-user counter in a 10 minute window. */
	private static function allow( int $user, string $what, int $max ): bool {
		$key   = self::LIMIT . $what . '_' . $user;
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/** Notice for the next view of the tab. */
	private function flash( int $user, string $text ): void {
		set_transient( self::NOTICE . $user, $text, 5 * MINUTE_IN_SECONDS );
	}

	private function notice( int $user ): void {
		$text = get_transient( self::NOTICE . $user );
		if ( is_string( $text ) && '' !== $text ) {
			delete_transient( self::NOTICE . $user );
			wc_print_notice( $text, 'notice' );
		}
	}
}
