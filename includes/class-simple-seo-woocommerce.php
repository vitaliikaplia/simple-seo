<?php
/**
 * WooCommerce (decisions/woocommerce): a product in the Schema graph of Simple SEO instead of
 * WooCommerce's own JSON-LD, Open Graph of products, and the return policy of the shop.
 *
 * The product node is WooCommerce's own — it knows its prices, sales, variations, taxes, stock,
 * identifiers and reviews best — taken from its generator (the filter
 * woocommerce_structured_data_product) and tied into the graph: the page it is the main entity
 * of, its image, the seller (whom the site represents) and its primary brand. Where Simple SEO prints its
 * graph, WooCommerce prints none of what the graph already says.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_WooCommerce {
	/** The taxonomy of brands of WooCommerce (9.6 and later). */
	public const BRANDS = 'product_brand';

	/** WooCommerce's JSON-LD types that Simple SEO's graph says itself. */
	private const SAID = array( 'product', 'review', 'website', 'onlinestore' );

	public static function init(): void {
		// Before the filters of Yoast's compatibility (5), so a theme's filter of the graph sees the product.
		add_filter( 'simple_seo_schema_pieces', array( self::class, 'schema_pieces' ), 4, 2 );
		add_filter( 'simple_seo_head_tags', array( self::class, 'head_tags' ), 4, 2 );
		add_filter( 'woocommerce_structured_data_type_for_page', array( self::class, 'woocommerce_types' ) );
	}

	/** Whether WooCommerce is there. */
	public static function active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * The product a page shows: a singular product page, not behind a password.
	 *
	 * @return WC_Product|null
	 */
	public static function product( Simple_SEO_Context $context ) {
		if ( ! self::active() || Simple_SEO_Context::SINGULAR !== $context->kind || $context->password_required
			|| ! $context->object instanceof WP_Post || 'product' !== $context->object->post_type ) {
			return null;
		}

		$product = wc_get_product( $context->object );

		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * WooCommerce's own Product node of a product, as its generator makes it; null when it makes
	 * none (no price, no rating, no review).
	 *
	 * @param WC_Product $product
	 * @return array<string, mixed>|null
	 */
	public static function woocommerce_node( $product ): ?array {
		$generator = WC()->structured_data ?? null;
		if ( ! is_object( $generator ) || ! method_exists( $generator, 'generate_product_data' ) ) {
			return null;
		}

		$node  = null;
		$catch = static function ( $markup ) use ( &$node ) {
			$node = is_array( $markup ) ? $markup : null;

			return $markup;
		};
		add_filter( 'woocommerce_structured_data_product', $catch, PHP_INT_MAX );
		try {
			$generator->generate_product_data( $product );
		} finally {
			remove_filter( 'woocommerce_structured_data_product', $catch, PHP_INT_MAX );
		}

		return $node;
	}

	/**
	 * The product of the page in the graph, tied to the page, its image and the seller; the return
	 * policy of the shop on the organization.
	 *
	 * @param mixed $pieces
	 * @return mixed
	 */
	public static function schema_pieces( $pieces, Simple_SEO_Context $context ) {
		if ( ! is_array( $pieces ) || ! self::active() ) {
			return $pieces;
		}

		$policy = self::return_policy();
		if ( '' !== $policy && isset( $pieces['organization'] ) && is_array( $pieces['organization'] ) ) {
			$pieces['organization']['hasMerchantReturnPolicy'] = array(
				'@type'              => 'MerchantReturnPolicy',
				'merchantReturnLink' => $policy,
			);
		}

		$product = self::product( $context );
		$page    = isset( $pieces['webpage']['@id'] ) ? (string) $pieces['webpage']['@id'] : '';
		$node    = null !== $product && '' !== $page ? self::woocommerce_node( $product ) : null;
		if ( null === $node ) {
			return $pieces;
		}

		$node['@id']              = $page . '#product';
		$node['mainEntityOfPage'] = array( '@id' => $page );
		if ( isset( $pieces['mainimage']['@id'] ) ) {
			$node['image'] = array( '@id' => $pieces['mainimage']['@id'] );
		}
		// WooCommerce names the first brand of a product: the primary one is the brand, as in the trail.
		$brand = taxonomy_exists( self::BRANDS ) ? Simple_SEO_Primary_Term::get( $context->object, self::BRANDS ) : null;
		if ( $brand ) {
			$logo          = function_exists( 'wc_get_brand_thumbnail_url' ) ? (string) wc_get_brand_thumbnail_url( (int) $brand->term_id, 'full' ) : '';
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => Simple_SEO_Variables::plain( $brand->name ),
			) + ( '' !== $logo ? array( 'logo' => $logo ) : array() );
		}

		$seller = Simple_SEO_Schema::publisher();
		if ( $seller && ! empty( $node['offers'] ) && is_array( $node['offers'] ) ) {
			foreach ( $node['offers'] as $key => $offer ) {
				if ( is_array( $offer ) ) {
					$node['offers'][ $key ]['seller'] = array( '@id' => $seller['@id'] );
				}
			}
		}

		$pieces['product'] = $node;

		return $pieces;
	}

	/** The address of the return policy of the shop: WooCommerce's page of it, when visitors see it; ''. */
	public static function return_policy(): string {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return '';
		}

		$page = get_post( (int) wc_get_page_id( 'refund_returns' ) );
		if ( ! $page instanceof WP_Post || '' !== $page->post_password || ! is_post_publicly_viewable( $page ) ) {
			return '';
		}

		return (string) get_permalink( $page );
	}

	/**
	 * Open Graph of a product: its type, price, availability, code and brand.
	 *
	 * @param mixed $tags
	 * @return mixed
	 */
	public static function head_tags( $tags, Simple_SEO_Context $context ) {
		$product = is_array( $tags ) && isset( $tags['og:type'] ) ? self::product( $context ) : null;
		if ( null === $product ) {
			return $tags;
		}

		$tags['og:type']['attributes']['content'] = 'product';
		unset( $tags['article:published_time'], $tags['article:modified_time'] );

		$price = '' !== (string) $product->get_price() ? (string) wc_format_decimal( wc_get_price_to_display( $product ), wc_get_price_decimals() ) : '';
		$brand = taxonomy_exists( self::BRANDS ) ? Simple_SEO_Primary_Term::get( $context->object, self::BRANDS ) : null;
		$more  = array(
			'product:price:amount'      => $price,
			'product:price:currency'    => '' !== $price ? (string) get_woocommerce_currency() : '',
			'product:availability'      => self::availability( $product ),
			'product:retailer_item_id'  => (string) $product->get_sku(),
			'product:brand'             => $brand ? Simple_SEO_Variables::plain( $brand->name ) : '',
		);
		foreach ( array_filter( $more, static fn( string $content ): bool => '' !== $content ) as $property => $content ) {
			$tags[ $property ] = array(
				'tag'        => 'meta',
				'attributes' => array(
					'property' => $property,
					'content'  => $content,
				),
			);
		}

		return $tags;
	}

	/**
	 * The availability of a product as Open Graph names it.
	 *
	 * @param WC_Product $product
	 */
	public static function availability( $product ): string {
		if ( ! $product->is_in_stock() ) {
			return 'out of stock';
		}

		return 'onbackorder' === $product->get_stock_status() ? 'available for order' : 'in stock';
	}

	/**
	 * WooCommerce's JSON-LD on a page with Simple SEO's graph: nothing the graph says itself — the
	 * product, its reviews, the site, the shop — and no trail when the graph has one. A list left
	 * empty would print every type: then it names one that is none.
	 *
	 * @param mixed $types
	 * @return mixed
	 */
	public static function woocommerce_types( $types ) {
		$context = Simple_SEO_Context::current();
		if ( ! is_array( $types ) || ! Simple_SEO_Schema::prints( $context ) ) {
			return $types;
		}

		$said = Simple_SEO_Schema::has_breadcrumbs( $context ) ? array_merge( self::SAID, array( 'breadcrumblist' ) ) : self::SAID;

		$left = array_values( array_filter( array_diff( $types, $said ) ) );

		return $left ? $left : array( 'none' );
	}
}
