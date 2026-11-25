<?php
/**
 * Application documents on partner-domain links, streamed from Business API and never stored.
 *
 * Links are /bapi/download/{order id}-{HMAC of order, ref and document id}, so they reveal neither
 * the ref nor Business API. Allowed: the customer who owns the order, staff (2FA while live), or
 * for guest orders whoever has the order key, as on WooCommerce's order received page.
 *
 * @package BapiForms
 */

namespace BapiForms\Documents;

use BapiForms\Applications\Applications;
use BapiForms\Commerce\WooCommerce\OrderBridge;
use BapiForms\Commerce\WooCommerce\StatusText;
use BapiForms\Frontend\RateLimiter;
use BapiForms\Frontend\RestController;
use BapiForms\Plugin;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Downloads {

	/** For sites without pretty permalinks. */
	public const QUERY_VAR = 'bapi_forms_download';

	private const PATH = 'bapi/download/';
	/** How long a guest link works without WooCommerce's email check; the order page renders fresh ones. */
	private const GUEST_TTL = 7 * DAY_IN_SECONDS;
	/** Per 10 minutes, per visitor IP (IPv6: per /64) and per logged-in user. */
	private const RATE_LIMIT  = 60;
	private const RATE_WINDOW = 600;

	/** @var array<string, array<int, array<string, mixed>>> Per-request memo of document lists. */
	private array $lists = array();

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_download' ), 1 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer' ), 6 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20, 2 );
	}

	/**
	 * Documents ready for an application. Empty when there are none or BAPI cannot be read.
	 *
	 * @return list<array{id: string, label: string, filename: string, contentType: string}>
	 */
	public function list( string $ref, string $environment ): array {
		$memo = $environment . ':' . $ref;
		if ( isset( $this->lists[ $memo ] ) ) {
			return $this->lists[ $memo ];
		}
		$response = $this->plugin->client->get( 'applications/' . rawurlencode( $ref ) . '/documents', array(), $environment );
		$out      = array();
		foreach ( $response->ok() ? (array) ( $response->data['documents'] ?? array() ) : array() as $document ) {
			if ( ! is_array( $document ) || ! is_string( $document['id'] ?? null ) || 1 !== preg_match( '/^[a-z]+-[0-9]+(-[0-9]+)?$/', $document['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'          => $document['id'],
				'label'       => sanitize_text_field( (string) ( $document['label'] ?? __( 'Document', 'businessapi-forms' ) ) ),
				'filename'    => sanitize_file_name( (string) ( $document['filename'] ?? 'document.pdf' ) ),
				'contentType' => 'application/pdf',
			);
		}
		$this->lists[ $memo ] = $out;
		return $out;
	}

	/**
	 * Released applications that may have documents: completed, or a company whose ACN is known
	 * while its ABN add-on is still with the ATO.
	 *
	 * @return array<string, array<string, mixed>> ref => tracked application
	 */
	public static function candidates( \WC_Order $order ): array {
		$refs = OrderBridge::item_refs( $order );
		$out  = array();
		foreach ( OrderBridge::apps( $order ) as $ref => $app ) {
			$ref = (string) $ref;
			if ( ! in_array( $ref, $refs, true ) || 'released' !== ( $app['state'] ?? '' ) || ! Applications::is_ref( $ref ) ) {
				continue;
			}
			if ( 'completed' === ( $app['status'] ?? '' ) || '' !== (string) ( $app['acn'] ?? '' ) ) {
				$out[ $ref ] = $app;
			}
		}
		return $out;
	}

	/**
	 * @return list<array{ref: string, product: string, label: string, filename: string, url: string}>
	 */
	public function for_order( \WC_Order $order ): array {
		$out = array();
		foreach ( self::candidates( $order ) as $ref => $app ) {
			$env = Options::ENV_LIVE === ( $app['env'] ?? '' ) ? Options::ENV_LIVE : Options::ENV_TEST;
			foreach ( $this->list( $ref, $env ) as $document ) {
				$out[] = array(
					'ref'      => $ref,
					'product'  => (string) ( $app['product'] ?? '' ),
					'label'    => $document['label'],
					'filename' => $document['filename'],
					'url'      => self::url( $order, $ref, $document['id'] ),
				);
			}
		}
		return $out;
	}

	public static function token( int $order_id, string $ref, string $document_id ): string {
		return substr( hash_hmac( 'sha256', $order_id . '|' . $ref . '|' . $document_id, wp_salt( 'auth' ) . '|bapi-forms-download' ), 0, 32 );
	}

	/**
	 * Guest orders carry the order key plus a signed expiry. Once that has passed, the guest needs
	 * WooCommerce's own email check (the order page, which renders fresh links).
	 */
	public static function url( \WC_Order $order, string $ref, string $document_id ): string {
		$opaque = $order->get_id() . '-' . self::token( (int) $order->get_id(), $ref, $document_id );
		$url    = get_option( 'permalink_structure' ) ? home_url( '/' . self::PATH . $opaque ) : add_query_arg( self::QUERY_VAR, $opaque, home_url( '/' ) );
		if ( 0 === (int) $order->get_customer_id() ) {
			$expires = time() + self::GUEST_TTL;
			$url     = add_query_arg(
				array(
					'key' => $order->get_order_key(),
					'exp' => $expires,
					'sig' => self::guest_signature( $order, $expires ),
				),
				$url
			);
		}
		return $url;
	}

	private static function guest_signature( \WC_Order $order, int $expires ): string {
		return substr( hash_hmac( 'sha256', $order->get_id() . '|' . $order->get_order_key() . '|' . $expires, wp_salt( 'auth' ) . '|bapi-forms-guest' ), 0, 32 );
	}

	/** An unexpired guest_signature() in the URL, or WooCommerce's email check passes as on the order page. */
	private static function guest_verified( \WC_Order $order ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- capability link, signed.
		$expires = isset( $_GET['exp'] ) ? absint( $_GET['exp'] ) : 0;
		$sig     = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:enable
		if ( $expires >= time() && $expires <= time() + self::GUEST_TTL && hash_equals( self::guest_signature( $order, $expires ), $sig ) ) {
			return true;
		}
		return ! \Automattic\WooCommerce\Internal\Utilities\Users::should_user_verify_order_email( $order->get_id(), null, 'order-received' );
	}

	/**
	 * @param \WP|mixed $wp Current request.
	 */
	public function maybe_download( $wp ): void {
		$opaque = '';
		$path   = $wp instanceof \WP ? (string) $wp->request : '';
		if ( str_starts_with( $path, self::PATH ) ) {
			$opaque = substr( $path, strlen( self::PATH ) );
		} elseif ( isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability link, checked below.
			$opaque = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- page cache convention.
		}
		nocache_headers();
		Pdf::no_store_headers();

		if ( 1 !== preg_match( '/^([0-9]{1,20})-([a-f0-9]{32})\/?$/', $opaque, $m ) ) {
			$this->stop( 404 );
		}

		$user = wp_get_current_user();
		/** This filter is documented in src/Frontend/RestController.php */
		$ip = (string) apply_filters( 'bapi_forms_client_ip', $this->plugin->options->client_ip() );
		if ( RateLimiter::hit( 'download', RestController::rate_subject( $ip ), self::RATE_LIMIT, self::RATE_WINDOW )
			|| ( $user->exists() && RateLimiter::hit( 'download_user', 'u' . $user->ID, self::RATE_LIMIT, self::RATE_WINDOW ) ) ) {
			$this->stop( 429 );
		}

		// Log in first, before the order is loaded, so the answer is the same for every order id.
		$guest = ! $user->exists();
		if ( $guest && '' === self::order_key() ) {
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}

		$order  = wc_get_order( (int) $m[1] );
		$access = $order instanceof \WC_Order ? $this->access( $order ) : 'no';
		if ( 'login' === $access ) {
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}
		if ( 'verify' === $access ) {
			$this->guest_stop( $order ); // The key was right: send them to the order page's email check.
		}
		if ( 'ok' !== $access ) {
			$guest ? $this->guest_stop( null ) : $this->stop( 404 ); // Never reveal whether the order or document exists.
		}

		if ( 'HEAD' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
			// Link checkers never trigger a Business API fetch or an audit entry.
			status_header( 200 );
			header( 'Content-Type: application/pdf' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			exit;
		}

		foreach ( self::candidates( $order ) as $ref => $app ) {
			$env = Options::ENV_LIVE === ( $app['env'] ?? '' ) ? Options::ENV_LIVE : Options::ENV_TEST;
			foreach ( $this->list( $ref, $env ) as $document ) {
				if ( hash_equals( self::token( (int) $order->get_id(), $ref, $document['id'] ), $m[2] ) ) {
					$this->stream( $order, $ref, $env, $document );
				}
			}
		}
		// The order key was right (access ok), so pointing a guest at their order reveals nothing.
		$guest ? $this->guest_stop( $order ) : $this->stop( 404 );
	}

	private static function order_key(): string {
		return isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability link.
	}

	/** Shared caches and CDNs; nocache_headers() covers browsers. */

	/**
	 * 'ok', 'login' (a registered customer's order opened with its key, nobody logged in), 'verify'
	 * (a guest order's key with an expired link and no email check passed) or 'no'.
	 */
	private function access( \WC_Order $order ): string {
		$user = wp_get_current_user();
		if ( $user->exists() && ( current_user_can( 'edit_shop_orders' ) || current_user_can( 'edit_shop_order', $order->get_id() ) ) ) {
			return $this->plugin->security->user_allowed( $user, true ) ? 'ok' : 'no'; // Staff: 2FA gate for live data.
		}

		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'pending', 'checkout-draft' ), true ) ) {
			return 'no';
		}
		$key      = self::order_key();
		$keyed    = '' !== $key && hash_equals( (string) $order->get_order_key(), $key );
		$customer = (int) $order->get_customer_id();
		if ( $customer > 0 && ! $user->exists() ) {
			return $keyed ? 'login' : 'no';
		}
		if ( $customer > 0 ) {
			return (int) $user->ID === $customer ? 'ok' : 'no';
		}
		if ( ! $keyed ) {
			return 'no';
		}
		return self::guest_verified( $order ) ? 'ok' : 'verify';
	}

	/**
	 * Streams the file straight to the browser: no disk write, no cache. Exits.
	 *
	 * @param array{id: string, label: string, filename: string, contentType: string} $document Document.
	 */
	private function stream( \WC_Order $order, string $ref, string $env, array $document ): void {
		$pdf = $this->plugin->client->pdf( 'applications/' . rawurlencode( $ref ) . '/documents/' . rawurlencode( $document['id'] ), $env );
		if ( '' !== $pdf['code'] ) {
			$this->stop(
				match ( true ) {
				\BapiForms\Api\Response::NO_KEY === $pdf['code'] => 503,
				409 === $pdf['status']                     => 409,
				default                                    => 502,
				}
			);
		}

		do_action(
			'bapi_forms_audit',
			'document_downloaded',
			array(
				'orderId'     => (int) $order->get_id(),
				'ref'         => $ref,
				'documentId'  => $document['id'],
				'environment' => $env,
			)
		);

		Pdf::send( $pdf['body'], $document['filename'] );
	}

	/** Exits. */
	private function stop( int $status ): void {
		$messages = array(
			404 => __( 'This download link is not valid, or you do not have access to it. If you are the customer, log in to your account and open the order.', 'businessapi-forms' ),
			409 => __( 'Your document is still being prepared. Please try again in a few minutes.', 'businessapi-forms' ),
			429 => __( 'Too many download requests. Please wait a few minutes and try again.', 'businessapi-forms' ),
			502 => __( 'Your document could not be downloaded right now. Please try again in a few minutes.', 'businessapi-forms' ),
			503 => __( 'Downloads are not available right now. Please try again later.', 'businessapi-forms' ),
		);
		if ( 409 === $status || 502 === $status ) {
			header( 'Retry-After: 300' );
		} elseif ( 429 === $status ) {
			header( 'Retry-After: ' . self::RATE_WINDOW );
		}
		header( 'X-Robots-Tag: noindex, nofollow' );
		wp_die( esc_html( $messages[ $status ] ?? $messages[404] ), esc_html__( 'Download', 'businessapi-forms' ), array( 'response' => 409 === $status ? 503 : absint( $status ) ) );
	}

	/**
	 * A guest link that did not work. $order only when the key was proven right, so a stranger
	 * never learns whether an order exists. Exits.
	 */
	private function guest_stop( ?\WC_Order $order ): void {
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( $order instanceof \WC_Order ) {
			$message = sprintf(
				'<p>%s</p><p><a href="%s">%s</a></p>',
				esc_html__( 'This download link is no longer valid. Open your order to see your current documents.', 'businessapi-forms' ),
				esc_url( $order->get_checkout_order_received_url() ),
				esc_html__( 'Open your order', 'businessapi-forms' )
			);
		} else {
			$account = wc_get_page_permalink( 'myaccount' );
			$message = sprintf(
				'<p>%s</p><p><a href="%s">%s</a></p>',
				esc_html__( 'This download link is not valid or has expired. If you have an account, log in to download your documents from your order. Otherwise use the link in your most recent email from us, or contact us.', 'businessapi-forms' ),
				esc_url( $account ),
				esc_html__( 'Log in to your account', 'businessapi-forms' )
			);
		}
		wp_die( wp_kses_post( $message ), esc_html__( 'Download', 'businessapi-forms' ), array( 'response' => 404 ) );
	}

	/** Not home_url( REQUEST_URI ): that repeats the subdirectory on a site installed in /shop/. */
	private static function current_url(): string {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? '/' . ltrim( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/' ) : '/';
		$parts = wp_parse_url( home_url() );
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return esc_url_raw( ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . $port . $uri );
	}

	/**
	 * @param \WC_Order|mixed $order Order.
	 */
	public function render_customer( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::candidates( $order ) ) {
			return;
		}
		// Links are signed for guests: only mint them for the verified owner (or staff), not for
		// whatever else renders order details (invoice or order lookup plugins).
		$staff = current_user_can( 'edit_shop_orders' ) || current_user_can( 'edit_shop_order', $order->get_id() );
		if ( $staff ? ! $this->plugin->security->user_allowed( null, true ) : ! \BapiForms\Frontend\MyAccount::page_owner( $order ) ) {
			return; // Staff pass the 2FA gate first, or a signed link would skip it once logged out.
		}
		$documents = $this->for_order( $order );
		if ( ! $documents ) {
			return;
		}
		echo '<section class="bapi-forms-documents" style="margin:2em 0"><h2>' . esc_html__( 'Your documents', 'businessapi-forms' ) . '</h2><ul>';
		foreach ( $documents as $document ) {
			printf(
				'<li><a href="%s" rel="nofollow">%s</a> <small>(%s)</small></li>',
				esc_url( $document['url'] ),
				esc_html( $document['label'] ),
				esc_html( StatusText::short_ref( $document['ref'] ) )
			);
		}
		echo '</ul></section>';
	}

	/**
	 * @param string     $screen_id     Screen (HPOS or posts).
	 * @param mixed|null $post_or_order Post or order.
	 */
	public function add_meta_box( $screen_id, $post_or_order = null ): void {
		$screen = wc_get_page_screen_id( 'shop-order' );
		if ( $screen_id !== $screen && 'shop_order' !== $screen_id ) {
			return;
		}
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order instanceof \WC_Order || ! self::candidates( $order ) ) {
			return;
		}
		add_meta_box( 'bapi-forms-documents', __( 'Documents (BusinessAPI Forms)', 'businessapi-forms' ), array( $this, 'render_meta_box' ), $screen_id, 'side', 'default' );
	}

	/**
	 * @param mixed $post_or_order Post or order.
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( ! $this->plugin->security->user_allowed( null, true ) ) {
			echo '<p>' . esc_html( \BapiForms\Security\Security::blocked_message() ) . '</p>';
			return;
		}
		$documents = $this->for_order( $order );
		if ( ! $documents ) {
			echo '<p>' . esc_html__( 'No documents yet. They appear here once the government agency has finished (company documents can take a few minutes after registration).', 'businessapi-forms' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $documents as $document ) {
			printf( '<li><a href="%s">%s</a><br><small>%s</small></li>', esc_url( $document['url'] ), esc_html( $document['label'] ), esc_html( StatusText::short_ref( $document['ref'] ) ) );
		}
		echo '</ul><p class="description">' . esc_html__( 'Fetched from Business API on each download, never stored on this site. Your client sees the same links in My Account and in the completed email.', 'businessapi-forms' ) . '</p>';
	}
}
