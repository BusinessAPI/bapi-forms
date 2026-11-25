<?php
/**
 * Keeps hidden carrier products out of product listings: Store API, WC REST API,
 * wc_get_products(), product RSS feeds and SEO plugin sitemaps. Reading a carrier by id is
 * unaffected. Core sitemaps, the product page and add to cart are handled in Carriers.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CarrierVisibility {

	/** Store API product routes: collection, by id (group 1) or by slug (group 2). */
	private const STORE_API_PRODUCTS = '#^/wc/store(?:/v\d+)?/products(?:/(?:(\d+)|([^/]+?)))?/?$#';

	private bool $store_api_listing = false;

	public function register(): void {
		add_filter( 'rest_request_before_callbacks', array( $this, 'store_api_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'store_api_done' ), 10, 3 );
		add_action( 'pre_get_posts', array( $this, 'exclude_from_query' ) );
		add_filter( 'woocommerce_rest_product_object_query', array( $this, 'exclude_from_rest_args' ), 10, 2 );
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( $this, 'exclude_from_products_query' ), 10, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( $this, 'yoast_sitemap' ) );
		add_filter( 'rank_math/sitemap/entry', array( $this, 'rank_math_sitemap' ), 10, 3 );
		// Plugin products without an image show a document icon, not the shop's placeholder.
		add_filter( 'woocommerce_product_get_image', array( $this, 'product_image' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'cart_thumbnail' ), 10, 2 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( $this, 'store_api_images' ), 10, 2 );
	}

	/** A carrier or pay-first product with no image set. */
	private static function needs_icon( mixed $product ): bool {
		if ( ! $product instanceof \WC_Product || $product->get_image_id() ) {
			return false;
		}
		$id     = (int) $product->get_id();
		$parent = (int) $product->get_parent_id();
		if ( $parent > 0 && wc_get_product( $parent ) && wc_get_product( $parent )->get_image_id() ) {
			return false; // A variation shows its parent's image.
		}
		return Carriers::is_carrier( $id ) || '' !== PayFirstProducts::slug_of( $id );
	}

	private static function icon_url(): string {
		return BAPI_FORMS_URL . 'assets/img/document.svg';
	}

	private static function icon_html(): string {
		return sprintf( '<img src="%s" alt="" width="96" height="96" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail bapi-forms-product-icon" loading="lazy" decoding="async" />', esc_url( self::icon_url() ) );
	}

	/**
	 * @param string $html    Image HTML.
	 * @param mixed  $product Product.
	 */
	public function product_image( $html, $product ): string {
		return self::needs_icon( $product ) ? self::icon_html() : (string) $html;
	}

	/**
	 * @param string $html Thumbnail HTML.
	 * @param mixed  $item Cart item.
	 */
	public function cart_thumbnail( $html, $item ): string {
		$product = is_array( $item ) ? ( $item['data'] ?? null ) : null;
		return self::needs_icon( $product ) ? self::icon_html() : (string) $html;
	}

	/**
	 * @param mixed $images Store API image objects.
	 * @param mixed $item   Cart item.
	 * @return mixed
	 */
	public function store_api_images( $images, $item ) {
		$product = is_array( $item ) ? ( $item['data'] ?? null ) : null;
		if ( ! self::needs_icon( $product ) ) {
			return $images;
		}
		$url = self::icon_url();
		return array(
			(object) array(
				'id'        => 0,
				'src'       => $url,
				'thumbnail' => $url,
				'srcset'    => '',
				'sizes'     => '',
				'name'      => '',
				'alt'       => '',
			),
		);
	}

	/** @return list<int> */
	public static function ids(): array {
		return array_values( array_filter( array_map( 'intval', (array) get_option( Carriers::OPTION, array() ) ) ) );
	}

	/**
	 * Store API: a single carrier is a 404; listings drop carriers via pre_get_posts.
	 *
	 * @param mixed            $response Response so far (null unless short-circuited).
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function store_api_request( $response, $handler, $request ) {
		if ( null !== $response || ! $request instanceof \WP_REST_Request || 'GET' !== $request->get_method() ) {
			return $response;
		}
		if ( ! preg_match( self::STORE_API_PRODUCTS, $request->get_route(), $match ) ) {
			return $response;
		}
		if ( ! empty( $match[1] ) ) {
			return in_array( (int) $match[1], self::ids(), true )
				? new \WP_Error( 'woocommerce_rest_product_invalid_id', __( 'Invalid product ID.', 'businessapi-forms' ), array( 'status' => 404 ) )
				: $response;
		}
		if ( ! empty( $match[2] ) ) {
			return in_array( sanitize_title( rawurldecode( (string) $match[2] ) ), self::slugs(), true )
				? new \WP_Error( 'woocommerce_rest_product_invalid_slug', __( 'Invalid product slug.', 'businessapi-forms' ), array( 'status' => 404 ) )
				: $response;
		}
		$this->store_api_listing = true;
		return $response;
	}

	/**
	 * @param mixed $response Response.
	 * @return mixed
	 */
	public function store_api_done( $response ) {
		$this->store_api_listing = false;
		return $response;
	}

	/**
	 * Store API product listings (while one runs) and product RSS feeds.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function exclude_from_query( $query ): void {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}
		$types   = (array) $query->get( 'post_type' );
		$product = in_array( 'product', $types, true );
		if ( $product && ( $this->store_api_listing || ( $query->is_main_query() && $query->is_feed() ) ) ) {
			$query->query_vars = $this->exclude_from_args( $query->query_vars );
		}
	}

	/** @return list<string> */
	private static function slugs(): array {
		$out = array();
		foreach ( self::ids() as $id ) {
			$slug = (string) get_post_field( 'post_name', $id );
			if ( '' !== $slug ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * WC REST API product list, unless the caller asked for specific ids or can edit products.
	 *
	 * @param array<string, mixed>  $args    WP_Query args.
	 * @param \WP_REST_Request|null $request Request.
	 * @return array<string, mixed>
	 */
	public function exclude_from_rest_args( $args, $request = null ): array {
		if ( current_user_can( 'edit_products' ) || ( $request instanceof \WP_REST_Request && ! empty( $request->get_param( 'include' ) ) ) ) {
			return (array) $args;
		}
		return $this->exclude_from_args( $args );
	}

	/**
	 * Add carriers to post__not_in, or drop them from post__in, which WP_Query prefers.
	 *
	 * @param array<string, mixed> $args WP_Query args.
	 * @return array<string, mixed>
	 */
	public function exclude_from_args( $args ): array {
		$args = (array) $args;
		$ids  = self::ids();
		if ( ! $ids ) {
			return $args;
		}
		if ( ! empty( $args['post__in'] ) ) {
			$in               = array_values( array_diff( array_map( 'intval', (array) $args['post__in'] ), $ids ) );
			$args['post__in'] = $in ? $in : array( 0 );
			return $args;
		}
		// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- a handful of carrier ids.
		$args['post__not_in'] = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) ), $ids ) ) );
		return $args;
	}

	/**
	 * wc_get_products(), unless the caller asked for specific ids.
	 *
	 * @param array<string, mixed> $wp_query_args WP_Query args.
	 * @param array<string, mixed> $query_vars    wc_get_products() vars.
	 * @return array<string, mixed>
	 */
	public function exclude_from_products_query( $wp_query_args, $query_vars ): array {
		// WooCommerce maps "include" to post__in before this filter runs.
		if ( ! empty( $query_vars['include'] ) || ! empty( $query_vars['post__in'] ) ) {
			return (array) $wp_query_args;
		}
		return $this->exclude_from_args( $wp_query_args );
	}

	/**
	 * @param array<int> $ids Excluded post ids.
	 * @return array<int>
	 */
	public function yoast_sitemap( $ids ): array {
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), self::ids() ) ) );
	}

	/**
	 * Rank Math sitemap: false drops the entry.
	 *
	 * @param array|false $url    Sitemap entry.
	 * @param string      $type   Entry type ("post" for posts of any type).
	 * @param mixed       $post   Post object.
	 * @return array|false
	 */
	public function rank_math_sitemap( $url, $type, $post ) {
		if ( 'post' === $type && $post instanceof \WP_Post && in_array( (int) $post->ID, self::ids(), true ) ) {
			return false;
		}
		return $url;
	}
}
