<?php
/**
 * Products the plugin can offer: labels, dependencies and the BAPI capability key that decides
 * live eligibility. Onboarding rules live in BAPI.
 *
 * @package BapiForms
 */

namespace BapiForms\Products;

use BapiForms\Account\Capabilities;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class ProductRegistry {

	public function __construct(
		private readonly Options $options,
		private readonly Capabilities $capabilities,
	) {}

	/**
	 * `type` is the BAPI application type; add-ons have none and use `addon` (their key in
	 * BAPI `options.addons`).
	 *
	 * @return array<string, array{label: string, description: string, capability: string, requires: ?string, type: ?string}>
	 */
	public function all(): array {
		$products = array(
			'company_registration' => array(
				'label'       => __( 'Company registration', 'businessapi-forms' ),
				'description' => __( 'Register a new Australian company with ASIC.', 'businessapi-forms' ),
				'capability'  => 'company_registration',
				'requires'    => null,
				'type'        => 'company',
			),
			'abn_addon'            => array(
				'label'       => __( 'ABN add-on for company registration', 'businessapi-forms' ),
				'description' => __( 'Offer ABN and tax registrations (GST, PAYG, FBT, LCT, FTC, WET) inside the company registration form.', 'businessapi-forms' ),
				'capability'  => 'abn_registration',
				'requires'    => 'company_registration',
				'type'        => null,
				'addon'       => 'abn',
			),
			'abn_registration'     => array(
				'label'       => __( 'ABN registration', 'businessapi-forms' ),
				'description' => __( 'Standalone ABN for an existing company or a sole trader.', 'businessapi-forms' ),
				'capability'  => 'abn_registration',
				'requires'    => null,
				'type'        => 'abn',
			),
			'business_name'        => array(
				'label'       => __( 'Business name registration', 'businessapi-forms' ),
				'description' => __( 'Register a business name. You pay the ASIC fee by card through an ASIC payment link shown on the order.', 'businessapi-forms' ),
				'capability'  => 'business_name_registration',
				'requires'    => null,
				'type'        => 'business_name',
			),
			'ppsr_search'          => array(
				'label'       => __( 'PPSR search', 'businessapi-forms' ),
				'description' => __( 'PPSR search reports with downloadable certificates.', 'businessapi-forms' ),
				'capability'  => 'ppsr_search',
				'requires'    => null,
				'type'        => 'ppsr_search',
			),
			'entity_monitoring'    => array(
				'label'       => __( 'Entity monitoring', 'businessapi-forms' ),
				'description' => __( 'Recurring monitoring of companies and ABNs with change alerts.', 'businessapi-forms' ),
				'capability'  => 'entity_monitoring',
				'requires'    => null,
				'type'        => 'monitoring',
			),
		);

		/**
		 * Filters the products the plugin can offer.
		 *
		 * @param array $products Product definitions keyed by slug.
		 */
		return apply_filters( 'bapi_forms_products', $products );
	}

	public function exists( string $slug ): bool {
		return isset( $this->all()[ $slug ] );
	}

	/**
	 * Live eligibility (always allowed in sandbox).
	 *
	 * @return array{allowed: bool, reason: ?string, instructions: ?string}
	 */
	public function eligibility( string $slug ): array {
		$product = $this->all()[ $slug ] ?? null;
		if ( null === $product ) {
			return array(
				'allowed'      => false,
				'reason'       => __( 'Unknown product.', 'businessapi-forms' ),
				'instructions' => null,
			);
		}
		$eligibility = $this->capabilities->eligibility( $product['capability'] );

		// Live never sells at $0 (a $0 order would release a paid lodgement).
		if ( $eligibility['allowed'] && $this->options->is_live() && $this->options->service_fee( $slug ) <= 0 ) {
			return array(
				'allowed'      => false,
				'reason'       => __( 'No service fee is set for this product, so it cannot be sold live.', 'businessapi-forms' ),
				'instructions' => __( 'Enter a service fee above $0 below and save.', 'businessapi-forms' ),
			);
		}

		return $eligibility;
	}

	/**
	 * Enabled products without a service price above 0 (they block live).
	 *
	 * @return list<string>
	 */
	public function unpriced(): array {
		$enabled = $this->options->enabled_products();
		$missing = array();
		foreach ( array_keys( $this->all() ) as $slug ) {
			if ( ! empty( $enabled[ $slug ] ) && $this->options->service_fee( (string) $slug ) <= 0 ) {
				$missing[] = (string) $slug;
			}
		}
		return $missing;
	}

	/**
	 * Switched on, with its parent product. Local settings only, so safe on page renders; live
	 * eligibility is checked when the visitor starts the form.
	 */
	public function is_enabled( string $slug ): bool {
		$product = $this->all()[ $slug ] ?? null;
		if ( null === $product || empty( $this->options->enabled_products()[ $slug ] ) ) {
			return false;
		}
		return null === $product['requires'] || $this->is_enabled( $product['requires'] );
	}

	/**
	 * Switched on, eligible and its parent active. Reads the account status in live, so keep it
	 * out of page renders (use is_enabled()).
	 */
	public function is_active( string $slug ): bool {
		$product = $this->all()[ $slug ] ?? null;
		if ( null === $product ) {
			return false;
		}

		if ( empty( $this->options->enabled_products()[ $slug ] ) ) {
			return false;
		}

		if ( null !== $product['requires'] && ! $this->is_active( $product['requires'] ) ) {
			return false;
		}

		return $this->eligibility( $slug )['allowed'];
	}

	/** BAPI application type, null for add-ons and unknown slugs. */
	public function bapi_type( string $slug ): ?string {
		return $this->all()[ $slug ]['type'] ?? null;
	}

	/**
	 * Active add-ons (BAPI keys) offered inside a product's form.
	 *
	 * @return list<string>
	 */
	public function active_addons( string $slug ): array {
		$addons = array();
		foreach ( $this->all() as $addon_slug => $product ) {
			if ( $slug === $product['requires'] && ! empty( $product['addon'] ) && $this->is_active( $addon_slug ) ) {
				$addons[] = (string) $product['addon'];
			}
		}
		return $addons;
	}

	/** Add-on product slug by its BAPI key (e.g. "abn" on company). */
	public function addon_slug( string $slug, string $addon ): ?string {
		foreach ( $this->all() as $addon_slug => $product ) {
			if ( $slug === $product['requires'] && ( $product['addon'] ?? null ) === $addon ) {
				return $addon_slug;
			}
		}
		return null;
	}
}
