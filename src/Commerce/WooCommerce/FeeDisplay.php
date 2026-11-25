<?php
/**
 * Shows an application's government fee folded into its line, for customers only.
 *
 * Presentation only: the fee stays a separate GST-free fee line, because WooCommerce cannot mix
 * taxable and GST-free amounts in one line and accounting, refunds and the release check rely on
 * it. The Subtotal row includes the folded fees so the totals still add up.
 *
 * Applies only inside known customer templates and customer emails. Admin screens, staff emails,
 * REST, gateway callbacks and the block cart and checkout (no public filters for line prices
 * there) keep the separate lines.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

use BapiForms\Applications\Applications;
use BapiForms\Products\PriceList;

defined( 'ABSPATH' ) || exit;

final class FeeDisplay {

	/** Inside an email's order table: true for a customer email, false for a staff email, null outside. */
	private static ?bool $email_customer = null;

	/** Depth of known customer templates being rendered. */
	private static int $customer_depth = 0;

	/**
	 * Our cart fees, taken out while a classic totals table renders its fee rows.
	 *
	 * @var array<string, object>
	 */
	private static array $stash = array();

	public function register(): void {
		add_filter( 'woocommerce_order_formatted_line_subtotal', array( $this, 'order_line_subtotal' ), 20, 3 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'order_item_meta' ), 20, 2 );
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'order_totals' ), 20, 3 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_start' ), 0, 2 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_end' ), PHP_INT_MAX );

		// The folded view applies only between these hooks.
		$regions = array(
			'woocommerce_order_details_before_order_table' => 'woocommerce_order_details_after_order_table',
			'before_woocommerce_pay_form'                  => 'after_woocommerce_pay',
			'woocommerce_before_cart'                      => 'woocommerce_after_cart',
			// Cart totals alone (wc-ajax refresh after a shipping change).
			'woocommerce_before_cart_totals'               => 'woocommerce_after_cart_totals',
			'woocommerce_review_order_before_cart_contents' => 'woocommerce_review_order_after_order_total',
			'woocommerce_before_mini_cart'                 => 'woocommerce_after_mini_cart',
		);
		foreach ( $regions as $open => $close ) {
			add_action( $open, array( $this, 'open' ), 0 );
			add_action( $close, array( $this, 'close' ), PHP_INT_MAX );
		}
		add_filter( 'pre_render_block', array( $this, 'block_open' ), 10, 2 );
		add_filter( 'render_block_woocommerce/order-confirmation-totals', array( $this, 'block_close' ), PHP_INT_MAX );

		add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'cart_item_price' ), 20, 2 );
		add_filter( 'woocommerce_cart_subtotal', array( $this, 'cart_subtotal' ), 20, 3 );
		add_action( 'woocommerce_before_cart_totals', array( $this, 'stash_fees' ), PHP_INT_MAX );
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'restore_fees' ), 0 );
		add_action( 'woocommerce_after_cart_totals', array( $this, 'restore_fees' ), 0 );
		add_action( 'woocommerce_review_order_after_cart_contents', array( $this, 'stash_fees' ), PHP_INT_MAX );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'restore_fees' ), 0 );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'restore_fees' ), 0 );
		// A theme override without the restore hooks: never leave the fees out past the request.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'restore_fees' ), 0 );
		add_action( 'shutdown', array( $this, 'restore_fees' ), 0 );
	}

	/**
	 * @param \WC_Order|mixed $order         Order.
	 * @param bool|mixed      $sent_to_admin Staff email.
	 */
	public function email_start( $order = null, $sent_to_admin = false ): void {
		self::$email_customer = ! $sent_to_admin;
	}

	public function email_end(): void {
		self::$email_customer = null;
	}

	public function open(): void {
		++self::$customer_depth;
	}

	/** Never below zero, e.g. a theme override without the opening hook. */
	public function close(): void {
		self::$customer_depth = max( 0, self::$customer_depth - 1 );
	}

	/**
	 * @param string|null          $pre   Short-circuit HTML (null to render).
	 * @param array<string, mixed> $block Parsed block.
	 * @return string|null
	 */
	public function block_open( $pre, $block ) {
		if ( null === $pre && is_array( $block ) && 'woocommerce/order-confirmation-totals' === ( $block['blockName'] ?? '' ) ) {
			$this->open();
		}
		return $pre;
	}

	/**
	 * @param string|mixed $html Rendered block.
	 * @return string|mixed
	 */
	public function block_close( $html ) {
		$this->close();
		return $html;
	}

	/** Off by default, so gateways, invoice plugins and integrations keep the separate lines. */
	private static function customer_view(): bool {
		if ( null !== self::$email_customer ) {
			return self::$email_customer;
		}
		if ( self::$customer_depth <= 0 || is_admin() || self::rest_request() ) {
			return false;
		}
		// Payment gateway callbacks, however the theme got involved.
		return empty( $_GET['wc-api'] ) && '' === (string) get_query_var( 'wc-api' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function rest_request(): bool {
		return function_exists( 'wp_is_serving_rest_request' ) ? wp_is_serving_rest_request() : ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/** Store API reads, including block hydration inside a normal page request. */
	public static function store_api_context(): bool {
		if ( self::rest_request() ) {
			return true;
		}
		$cart = WC()->cart;
		return $cart instanceof \WC_Cart && property_exists( $cart, 'cart_context' ) && 'store-api' === $cart->cart_context;
	}

	private static function classic_cart_view(): bool {
		return null === self::$email_customer && self::customer_view() && ! self::store_api_context();
	}

	/** The single government fee line of a v2 application line, or null. */
	private static function fee_for_line( \WC_Order $order, \WC_Order_Item $item ): ?\WC_Order_Item_Fee {
		if ( ! $item instanceof \WC_Order_Item_Product || Carriers::VERSION !== (int) $item->get_meta( OrderLines::ITEM_VERSION ) ) {
			return null;
		}
		$ref  = (string) $item->get_meta( OrderBridge::ITEM_REF );
		$slot = (string) $item->get_meta( Prepaid::ITEM_SLOT );
		if ( ! Applications::is_ref( $ref ) && ! Prepaid::is_slot( $slot ) ) {
			return null;
		}
		$found = array();
		foreach ( $order->get_fees() as $fee ) {
			// Pay first: matched by slot, as the ref arrives once the application is created.
			$mine = Prepaid::is_slot( $slot ) ? (string) $fee->get_meta( Prepaid::ITEM_SLOT ) === $slot : (string) $fee->get_meta( OrderBridge::ITEM_REF ) === $ref;
			if ( self::is_gov_fee( $fee ) && $mine ) {
				$found[] = $fee;
			}
		}
		return 1 === count( $found ) ? $found[0] : null;
	}

	private static function is_gov_fee( \WC_Order_Item $item ): bool {
		return $item instanceof \WC_Order_Item_Fee && 'government' === (string) $item->get_meta( OrderLines::ITEM_FEE )
			&& ( Applications::is_ref( (string) $item->get_meta( OrderBridge::ITEM_REF ) ) || Prepaid::is_slot( (string) $item->get_meta( Prepaid::ITEM_SLOT ) ) );
	}

	/**
	 * @return array<int, \WC_Order_Item_Fee>
	 */
	private static function folded_fees( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$fee = self::fee_for_line( $order, $item );
			if ( null !== $fee ) {
				$out[ $fee->get_id() ] = $fee;
			}
		}
		return $out;
	}

	private static function fee_amount( \WC_Order_Item_Fee $fee, bool $incl ): float {
		return (float) $fee->get_total() + ( $incl ? (float) $fee->get_total_tax() : 0.0 );
	}

	private static function order_incl( string $tax_display = '' ): bool {
		return 'incl' === ( '' !== $tax_display ? $tax_display : get_option( 'woocommerce_tax_display_cart' ) );
	}

	/**
	 * @param string          $subtotal Formatted subtotal.
	 * @param \WC_Order_Item  $item     Item.
	 * @param \WC_Order|mixed $order    Order.
	 */
	public function order_line_subtotal( $subtotal, $item, $order ) {
		if ( ! $order instanceof \WC_Order || ! $item instanceof \WC_Order_Item_Product || ! self::customer_view() ) {
			return $subtotal;
		}
		$fee = self::fee_for_line( $order, $item );
		if ( null === $fee ) {
			return $subtotal;
		}
		$currency = array( 'currency' => $order->get_currency() );
		// The filter does not receive the template's tax display: tell by what WooCommerce rendered.
		$incl  = (float) $item->get_subtotal_tax() > 0
			? wc_price( (float) $order->get_line_subtotal( $item, true ), $currency ) === (string) $subtotal
			: self::order_incl();
		$total = (float) $order->get_line_subtotal( $item, $incl ) + self::fee_amount( $fee, $incl );
		return wc_price( $total, $currency + array( 'ex_tax_label' => ! $incl && $order->get_prices_include_tax() ? 1 : 0 ) );
	}

	/**
	 * "Includes: Service fee $248.00 (excl. GST) + ASIC fee $636.00 (GST-free, at cost)".
	 *
	 * @param array<string, object> $meta Formatted meta.
	 * @param \WC_Order_Item|mixed  $item Item.
	 */
	public function order_item_meta( $meta, $item ) {
		if ( ! $item instanceof \WC_Order_Item_Product || ! self::customer_view() ) {
			return $meta;
		}
		$order = $item->get_order();
		$fee   = $order instanceof \WC_Order ? self::fee_for_line( $order, $item ) : null;
		if ( null === $fee ) {
			return $meta;
		}
		$incl                  = self::order_incl();
		$text                  = self::breakdown(
			(float) $order->get_line_subtotal( $item, $incl ),
			(float) $item->get_subtotal_tax() > 0,
			$incl,
			PriceList::government_label( sanitize_key( (string) $fee->get_meta( OrderLines::ITEM_GOV_KIND ) ) ),
			self::fee_amount( $fee, $incl ),
			$order->get_currency()
		);
		$meta                  = (array) $meta;
		$meta['bapi_includes'] = (object) array(
			'key'           => 'bapi_includes',
			'value'         => $text,
			'display_key'   => __( 'Includes', 'businessapi-forms' ),
			'display_value' => esc_html( $text ),
		);
		return $meta;
	}

	/**
	 * Drops the folded fee rows and adds them to the Subtotal row.
	 *
	 * @param array<string, array{label: string, value: string}> $rows        Total rows.
	 * @param \WC_Order|mixed                                    $order       Order.
	 * @param string                                             $tax_display Tax display.
	 */
	public function order_totals( $rows, $order, $tax_display = '' ) {
		if ( ! is_array( $rows ) || ! $order instanceof \WC_Order || ! self::customer_view() ) {
			return $rows;
		}
		$fees = self::folded_fees( $order );
		if ( ! $fees ) {
			return $rows;
		}
		$incl = self::order_incl( (string) $tax_display );
		$gov  = 0.0;
		$n    = 0;
		foreach ( $fees as $id => $fee ) {
			++$n;
			unset( $rows[ 'fee_' . $id ] );
			$gov += self::fee_amount( $fee, $incl );
		}
		if ( isset( $rows['cart_subtotal'] ) ) {
			$subtotal = (float) $order->get_subtotal();
			if ( $incl ) {
				$tax = 0.0;
				foreach ( $order->get_items() as $item ) {
					$tax += (float) $item->get_subtotal_tax();
				}
				$subtotal += wc_round_tax_total( $tax );
			}
			$label = ! $incl && $order->get_prices_include_tax() && wc_tax_enabled() ? ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>' : '';

			$rows['cart_subtotal']['value'] = wc_price( $subtotal + $gov, array( 'currency' => $order->get_currency() ) ) . $label . self::note( $gov, $n, $order->get_currency() );
		}
		return $rows;
	}

	/**
	 * Our cart fee for a cart item, also while stashed.
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	private static function cart_fee( array $item ): ?object {
		$ref = Carriers::line_key( $item );
		if ( ! Carriers::is_line( $item ) || '' === $ref || null === Carriers::government_of( $item ) || ! WC()->cart instanceof \WC_Cart ) {
			return null;
		}
		$id  = Carriers::fee_id( $ref );
		$fee = self::$stash[ $id ] ?? ( WC()->cart->fees_api()->get_fees()[ $id ] ?? null );
		return is_object( $fee ) && isset( $fee->total ) ? $fee : null;
	}

	private static function cart_fee_amount( object $fee, bool $incl ): float {
		return (float) $fee->total + ( $incl ? (float) ( $fee->tax ?? 0 ) : 0.0 );
	}

	/**
	 * @param string               $html Formatted price.
	 * @param array<string, mixed> $item Cart item.
	 */
	public function cart_item_price( $html, $item ) {
		if ( ! is_array( $item ) || ! isset( $item['line_subtotal'] ) || 1 !== (int) ( $item['quantity'] ?? 0 ) || ! self::classic_cart_view() ) {
			return $html;
		}
		$fee = self::cart_fee( $item );
		if ( null === $fee ) {
			return $html;
		}
		$cart  = WC()->cart;
		$incl  = $cart->display_prices_including_tax();
		$tax   = (float) ( $item['line_subtotal_tax'] ?? 0 );
		$price = wc_price( (float) $item['line_subtotal'] + ( $incl ? $tax : 0.0 ) + self::cart_fee_amount( $fee, $incl ) );
		if ( $tax > 0 ) {
			if ( $incl && ! wc_prices_include_tax() ) {
				$price .= ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
			} elseif ( ! $incl && wc_prices_include_tax() ) {
				$price .= ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
			}
		}
		return $price;
	}

	/**
	 * Classic cart "Includes: ..." detail, or null when the fee is not folded in.
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	public static function cart_breakdown( array $item ): ?string {
		if ( ! isset( $item['line_subtotal'] ) || ! self::classic_cart_view() ) {
			return null;
		}
		$fee = self::cart_fee( $item );
		$gov = Carriers::government_of( $item );
		if ( null === $fee || null === $gov ) {
			return null;
		}
		$incl = WC()->cart->display_prices_including_tax();
		$tax  = (float) ( $item['line_subtotal_tax'] ?? 0 );
		return self::breakdown( (float) $item['line_subtotal'] + ( $incl ? $tax : 0.0 ), $tax > 0, $incl, $gov['label'], self::cart_fee_amount( $fee, $incl ), get_woocommerce_currency() );
	}

	/**
	 * @param string        $html     Formatted subtotal.
	 * @param bool          $compound Compound subtotal.
	 * @param \WC_Cart|null $cart     Cart.
	 */
	public function cart_subtotal( $html, $compound = false, $cart = null ) {
		if ( $compound || ! $cart instanceof \WC_Cart || ! self::classic_cart_view() ) {
			return $html;
		}
		$incl = $cart->display_prices_including_tax();
		$gov  = 0.0;
		$n    = 0;
		foreach ( $cart->get_cart() as $item ) {
			$fee = is_array( $item ) && 1 === (int) ( $item['quantity'] ?? 0 ) ? self::cart_fee( $item ) : null;
			if ( null !== $fee ) {
				$gov += self::cart_fee_amount( $fee, $incl );
				++$n;
			}
		}
		if ( $gov <= 0 ) {
			return $html;
		}
		$subtotal = (float) $cart->get_subtotal() + ( $incl ? (float) $cart->get_subtotal_tax() : 0.0 );
		$label    = '';
		if ( (float) $cart->get_subtotal_tax() > 0 ) {
			if ( $incl && ! wc_prices_include_tax() ) {
				$label = ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
			} elseif ( ! $incl && wc_prices_include_tax() ) {
				$label = ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
			}
		}
		return wc_price( $subtotal + $gov ) . $label . self::note( $gov, $n, get_woocommerce_currency() );
	}

	/**
	 * Takes our folded fees out of the cart while the classic totals table renders its fee rows.
	 * Totals are already stored, so nothing else changes; restore_fees() puts them back.
	 */
	public function stash_fees(): void {
		if ( self::$stash || ! self::classic_cart_view() || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}
		$cart   = WC()->cart;
		$folded = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( is_array( $item ) && 1 === (int) ( $item['quantity'] ?? 0 ) && null !== self::cart_fee( $item ) ) {
				$folded[ Carriers::fee_id( Carriers::line_key( $item ) ) ] = true;
			}
		}
		if ( ! $folded ) {
			return;
		}
		$keep = array();
		foreach ( $cart->fees_api()->get_fees() as $id => $fee ) {
			if ( isset( $folded[ $id ] ) ) {
				self::$stash[ $id ] = $fee;
			} else {
				$keep[ $id ] = $fee;
			}
		}
		if ( self::$stash ) {
			$cart->fees_api()->set_fees( $keep );
		}
	}

	/** Idempotent. */
	public function restore_fees(): void {
		if ( ! self::$stash ) {
			return;
		}
		$stash       = self::$stash;
		self::$stash = array();
		if ( WC()->cart instanceof \WC_Cart ) {
			$fees = WC()->cart->fees_api();
			$fees->set_fees( $fees->get_fees() + $stash );
		}
	}

	/** "Service fee $248.00 (excl. GST) + ASIC fee $636.00 (GST-free, at cost)". */
	private static function breakdown( float $service, bool $taxed, bool $incl, string $gov_label, float $gov, string $currency ): string {
		$service_text = Money::plain( $service, $currency );
		if ( $taxed ) {
			/* translators: %s: amount. */
			$service_text = sprintf( $incl ? __( '%s (incl. GST)', 'businessapi-forms' ) : __( '%s (excl. GST)', 'businessapi-forms' ), $service_text );
		}
		/* translators: 1: service fee with its GST note, 2: fee name, e.g. "ASIC fee", 3: amount. */
		return sprintf( __( 'Service fee %1$s + %2$s %3$s (GST-free, at cost)', 'businessapi-forms' ), $service_text, '' !== $gov_label ? $gov_label : __( 'Government fee', 'businessapi-forms' ), Money::plain( $gov, $currency ) );
	}

	/** " (incl. $1,488.00 government fees, GST-free)". */
	private static function note( float $gov, int $count, string $currency ): string {
		/* translators: %s: amount. */
		$text = sprintf( _n( '(incl. %s government fee, GST-free)', '(incl. %s government fees, GST-free)', max( 1, $count ), 'businessapi-forms' ), Money::plain( $gov, $currency ) );
		return ' <small class="bapi-forms-gov-note">' . esc_html( $text ) . '</small>';
	}
}
