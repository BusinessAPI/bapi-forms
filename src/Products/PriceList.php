<?php
/**
 * Business API price list (GET /pricing): the government fee schedule and the partner's own BAPI
 * prices, cached per environment and key.
 *
 * Government fees are charged to the client at cost as a separate GST-free cart fee. ASIC bills
 * the partner for company and business name fees; BAPI charges PPSR fees at cost. A company type
 * with no listed fee is refused at checkout, never guessed.
 *
 * @package BapiForms
 */

namespace BapiForms\Products;

use BapiForms\Api\Client;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class PriceList {

	private const TRANSIENT = 'bapi_forms_pricing_';
	private const TTL       = 6 * HOUR_IN_SECONDS;
	private const RETRY_TTL = 5 * MINUTE_IN_SECONDS;

	/** Plugin product slug -> BAPI yourPrice key. */
	private const YOUR_PRICE_KEYS = array(
		'company_registration' => 'company_registration',
		'abn_addon'            => 'abn_registration',
		'abn_registration'     => 'abn_registration',
		'business_name'        => 'business_name_registration',
		'ppsr_search'          => 'ppsr_search',
		'entity_monitoring'    => 'entity_monitoring',
	);

	/** Products with a government fee, by who collects it from the partner. */
	public const ASIC_PAID_BY_PARTNER = array( 'company_registration', 'business_name' );
	public const CHARGED_BY_BAPI      = array( 'ppsr_search' );

	/** v1 hosted company form: proprietary company limited by shares only (APTY/LMSH/PROP). */
	public const V1_COMPANY_KEY = 'APTY/PROP';

	/** Business name terms (years) in the ASIC schedule. */
	public const BN_TERMS = array( 1, 3 );

	public function __construct(
		private readonly Client $client,
		private readonly Options $options,
	) {}

	/**
	 * Price list in dollars, or null when BAPI could not be reached.
	 *
	 * @param bool $fresh Read from BAPI now; a failure is then not cached.
	 * @return array{government: array<string, mixed>, your: array<string, float>, effective_from: string}|null
	 */
	public function get( bool $fresh = false, ?string $environment = null ): ?array {
		$environment = Options::ENV_LIVE === $environment || ( null === $environment && $this->options->is_live() ) ? Options::ENV_LIVE : Options::ENV_TEST;
		$key         = $this->transient_key( $environment );
		$cached      = $fresh ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			return empty( $cached['error'] ) ? $cached : null;
		}
		if ( ! $this->options->has_key( $environment ) ) {
			return null;
		}

		$response = $this->client->get( 'pricing', array(), $environment );
		$data     = $response->ok() ? $response->data : array();
		if ( ! isset( $data['governmentFees'] ) || ! is_array( $data['governmentFees'] ) ) {
			if ( ! $fresh ) {
				set_transient( $key, array( 'error' => true ), self::RETRY_TTL );
			}
			return null;
		}

		$cents = static fn( $v ): ?float => is_numeric( $v ) ? round( (float) $v / 100, 2 ) : null;
		$gov   = $data['governmentFees'];
		$bn    = array();
		foreach ( self::BN_TERMS as $term ) {
			$bn[ $term ] = $cents( $gov['business_name_registration'][ (string) $term ] ?? null );
		}
		$your = array();
		foreach ( (array) ( $data['yourPrice'] ?? array() ) as $product => $price ) {
			$amount = is_array( $price ) ? $cents( $price['amount'] ?? null ) : null;
			if ( null !== $amount ) {
				$your[ (string) $product ] = $amount;
			}
		}

		$company = array();
		foreach ( (array) ( $gov['asic']['companyRegistration'] ?? array() ) as $company_key => $amount ) {
			$amount = $cents( $amount );
			if ( null !== $amount && 1 === preg_match( '#^AP(TY|UB)/[A-Z]{4}$#', (string) $company_key ) ) {
				$company[ (string) $company_key ] = $amount;
			}
		}

		$list = array(
			'government'     => array(
				'company_registration' => $company,
				'business_name'        => $bn,
				'ppsr_search'          => $cents( $gov['ppsr_search'] ?? null ),
			),
			'your'           => $your,
			'effective_from' => sanitize_text_field( (string) ( $gov['effectiveFrom'] ?? '' ) ),
		);
		set_transient( $key, $list, self::TTL );

		return $list;
	}

	/**
	 * Cached list only, never a BAPI call (safe for page renders).
	 *
	 * @return array{government: array<string, mixed>, your: array<string, float>, effective_from: string}|null
	 */
	public function cached(): ?array {
		$cached = get_transient( $this->transient_key( $this->options->environment() ) );
		return is_array( $cached ) && empty( $cached['error'] ) ? $cached : null;
	}

	public function flush(): void {
		delete_transient( $this->transient_key( Options::ENV_TEST ) );
		delete_transient( $this->transient_key( Options::ENV_LIVE ) );
	}

	/** Keyed by a short hash of the API key, so a new key never sees another account's prices. */
	private function transient_key( string $environment ): string {
		return self::TRANSIENT . $environment . '_' . substr( hash( 'sha256', $this->options->key( $environment ) ), 0, 12 );
	}

	/**
	 * Government fee in dollars (GST-free), or null when there is none or it cannot be read.
	 *
	 * @param array{term?: int, company?: ?string} $context Business name term in years (default 3),
	 *                                                       or company schedule key, e.g. APTY/PROP.
	 */
	public function government_fee( string $slug, array $context = array() ): ?float {
		$list = $this->get();
		return null === $list ? null : self::fee_in( $list, $slug, $context );
	}

	/**
	 * Government fee from an already loaded price list.
	 *
	 * @param array<string, mixed>                 $price_list Price list from get().
	 * @param array{term?: int, company?: ?string} $context    As government_fee().
	 */
	public static function fee_in( array $price_list, string $slug, array $context = array() ): ?float {
		$fee = match ( $slug ) {
			'business_name'        => $price_list['government']['business_name'][ (int) ( $context['term'] ?? 3 ) ] ?? null,
			'company_registration' => isset( $context['company'] ) ? ( $price_list['government']['company_registration'][ (string) $context['company'] ] ?? null ) : null,
			default                => $price_list['government'][ $slug ] ?? null,
		};

		return is_float( $fee ) && $fee > 0 ? $fee : null;
	}

	/** The partner's own BAPI price in dollars (GST inclusive), or null. */
	public function your_price( string $slug ): ?float {
		$list = $this->get();
		$key  = self::YOUR_PRICE_KEYS[ $slug ] ?? null;
		return null !== $list && null !== $key && isset( $list['your'][ $key ] ) ? $list['your'][ $key ] : null;
	}

	public function has_government_fee( string $slug ): bool {
		return in_array( $slug, self::ASIC_PAID_BY_PARTNER, true ) || in_array( $slug, self::CHARGED_BY_BAPI, true );
	}

	/** Customer-facing name of a product's government fee (cart fee and line detail). */
	public static function government_label( string $slug ): string {
		return match ( $slug ) {
			'ppsr_search' => __( 'PPSR search fee', 'businessapi-forms' ),
			default       => __( 'ASIC fee', 'businessapi-forms' ),
		};
	}
}
