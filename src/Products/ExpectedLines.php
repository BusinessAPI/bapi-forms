<?php
/**
 * What an application must be paid with: one cart line (service fee plus chosen add-ons) and,
 * when the product has one, one GST-free government fee. Worked out from the application summary
 * at /cart and again before release.
 *
 * @package BapiForms
 */

namespace BapiForms\Products;

defined( 'ABSPATH' ) || exit;

final class ExpectedLines {

	public function __construct(
		private readonly ProductRegistry $products,
		private readonly PriceList $prices,
	) {}

	/**
	 * Add-on slugs and pricing codes from a summary. An add-on we cannot map is refused, never
	 * dropped, or it would be lodged unpaid.
	 *
	 * @param array<string, mixed> $summary BAPI application summary.
	 * @return array{addons: list<string>, pricing: array{company?: ?string, term?: int}}|\WP_Error
	 */
	public function inputs( array $summary, string $product ) {
		$addons = array();
		foreach ( (array) ( $summary['addons'] ?? array() ) as $addon ) {
			$slug = is_string( $addon ) ? $this->products->addon_slug( $product, $addon ) : null;
			if ( null === $slug ) {
				return new \WP_Error( 'bapi_forms_addon', __( 'This application includes an extra service this website cannot sell yet, so it cannot be checked out here. Please contact us.', 'businessapi-forms' ), array( 'status' => 409 ) );
			}
			$addons[] = $slug;
		}

		$codes   = is_array( $summary['pricing'] ?? null ) ? $summary['pricing'] : array();
		$pricing = array();
		if ( 'business_name' === $product ) {
			$term            = (int) ( $codes['term'] ?? 3 );
			$pricing['term'] = in_array( $term, PriceList::BN_TERMS, true ) ? $term : 3;
		} elseif ( 'company_registration' === $product ) {
			$key                = $codes['companyKey'] ?? null;
			$pricing['company'] = is_string( $key ) && 1 === preg_match( '#^AP(TY|UB)/[A-Z]{4}$#', $key ) ? $key : null;
		}

		return array(
			'addons'  => array_values( array_unique( $addons ) ),
			'pricing' => $pricing,
		);
	}

	/**
	 * 503 when the schedule cannot be read; 409 only when a fresh schedule has no fee.
	 *
	 * @param list<string>                         $addons  Add-on product slugs.
	 * @param array{company?: ?string, term?: int} $pricing Pricing codes from inputs().
	 * @return array{service: string, addons: list<string>, government: array{kind: string, label: string, amount: float}|null}|\WP_Error
	 */
	public function expected( string $product, array $addons, array $pricing, bool $fresh = false, ?string $environment = null ) {
		$addons     = array_values( array_diff( array_unique( $addons ), array( $product ) ) );
		$government = null;

		if ( $this->prices->has_government_fee( $product ) ) {
			$list = $this->prices->get( $fresh, $environment );
			$fee  = null !== $list ? PriceList::fee_in( $list, $product, $pricing ) : null;
			if ( null === $fee && ! $fresh ) {
				// A cached list may be out of date: only a fresh one may refuse.
				$fresh = true;
				$list  = $this->prices->get( true, $environment );
				$fee   = null !== $list ? PriceList::fee_in( $list, $product, $pricing ) : null;
			}
			if ( null === $list ) {
				return new \WP_Error( 'bapi_forms_pricing_unavailable', __( 'The government fee could not be loaded. Please try again in a few minutes.', 'businessapi-forms' ), array( 'status' => 503 ) );
			}
			if ( null === $fee ) {
				return 'company_registration' === $product
					? new \WP_Error( 'bapi_forms_pricing', __( 'ASIC has no published registration fee for this type of company, so it cannot be checked out here. Please contact us to arrange this registration.', 'businessapi-forms' ), array( 'status' => 409 ) )
					: new \WP_Error( 'bapi_forms_pricing', __( 'The government fee for this application is not available, so it cannot be checked out here. Please contact us.', 'businessapi-forms' ), array( 'status' => 409 ) );
			}
			$government = array(
				'kind'   => $product,
				'label'  => PriceList::government_label( $product ),
				'amount' => round( $fee, 2 ),
			);
		}

		return array(
			'service'    => $product,
			'addons'     => $addons,
			'government' => $government,
		);
	}

	/**
	 * Line title, e.g. "Company registration (with ABN)".
	 *
	 * @param list<string> $addons Add-on product slugs.
	 */
	public static function line_name( string $product, array $addons ): string {
		$products = \BapiForms\Plugin::instance()->products->all();
		$name     = (string) ( $products[ $product ]['label'] ?? $product );
		$extras   = array();
		foreach ( $addons as $addon ) {
			$extras[] = self::addon_label( (string) $addon, $products );
		}
		$extras = array_values( array_filter( array_unique( $extras ) ) );
		if ( ! $extras ) {
			return $name;
		}
		/* translators: 1: product name, 2: add-on names, e.g. "ABN". */
		return sprintf( __( '%1$s (with %2$s)', 'businessapi-forms' ), $name, implode( __( ' and ', 'businessapi-forms' ), $extras ) );
	}

	/**
	 * Short add-on name for line titles.
	 *
	 * @param array<string, array<string, mixed>> $products ProductRegistry::all().
	 */
	private static function addon_label( string $slug, array $products ): string {
		return match ( $slug ) {
			'abn_addon' => __( 'ABN', 'businessapi-forms' ),
			default     => (string) ( $products[ $slug ]['label'] ?? '' ),
		};
	}
}
