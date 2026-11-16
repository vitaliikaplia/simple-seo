<?php
/**
 * WooCommerce (decisions/woocommerce): a product in the Schema graph of Simple SEO instead of
 * WooCommerce's own JSON-LD, Open Graph of products, and the return policy of the shop; the shop
 * page as the page of the archive of products (its SEO fields, title and image), the trail of
 * products through the shop and their categories, the cart, the checkout and the account hidden
 * from search engines and the sitemaps, and hidden products out of the sitemaps.
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

	/** WooCommerce's pages that are no content: hidden from search engines and the sitemaps. */
	public const PAGES = array( 'cart', 'checkout', 'myaccount' );

	/** WooCommerce's JSON-LD types that Simple SEO's graph says itself. */
	private const SAID = array( 'product', 'review', 'website', 'onlinestore' );

	public static function init(): void {
		// Before the filters of Yoast's compatibility (5), so a theme's filter of the graph sees the product.
		add_filter( 'simple_seo_schema_pieces', array( self::class, 'schema_pieces' ), 4, 2 );
		add_filter( 'simple_seo_head_tags', array( self::class, 'head_tags' ), 4, 2 );
		add_filter( 'woocommerce_structured_data_type_for_page', array( self::class, 'woocommerce_types' ) );
		add_filter( 'simple_seo_archive_page', array( self::class, 'archive_page' ), 10, 2 );
		add_filter( 'simple_seo_breadcrumb_taxonomy', array( self::class, 'breadcrumb_taxonomy' ), 10, 2 );
		add_filter( 'simple_seo_noindex', array( self::class, 'noindex' ), 10, 2 );
		add_filter( 'simple_seo_sitemap_exclude', array( self::class, 'sitemap_exclude' ), 10, 3 );
		add_filter( 'simple_seo_markdown_meta', array( self::class, 'markdown_meta' ), 10, 2 );
		add_filter( 'simple_seo_markdown_content', array( self::class, 'markdown_content' ), 10, 2 );
	}

	/**
	 * The shop page stands for the archive of products.
	 *
	 * @param mixed $page_id
	 * @return mixed
	 */
	public static function archive_page( $page_id, string $post_type ) {
		return 'product' === $post_type && self::active() ? max( 0, (int) wc_get_page_id( 'shop' ) ) : $page_id;
	}

	/**
	 * Products go through their categories, not their brands or tags.
	 *
	 * @param mixed $taxonomy
	 * @return mixed
	 */
	public static function breadcrumb_taxonomy( $taxonomy, string $post_type ) {
		return 'product' === $post_type && taxonomy_exists( 'product_cat' ) ? 'product_cat' : $taxonomy;
	}

	/** The IDs of the cart, the checkout and the account, those there are. */
	public static function pages(): array {
		if ( ! self::active() ) {
			return array();
		}

		return array_values( array_filter( array_map( static fn( string $page ): int => (int) wc_get_page_id( $page ), self::PAGES ), static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * The cart, the checkout and the account are no content (WooCommerce hides them from search
	 * engines too): no canonical, no sitemap, no audit of them as pages to rank.
	 *
	 * @param mixed $noindex
	 * @return mixed
	 */
	public static function noindex( $noindex, Simple_SEO_Context $context ) {
		return Simple_SEO_Context::SINGULAR === $context->kind && in_array( $context->object_id, self::pages(), true ) ? true : $noindex;
	}

	/**
	 * Out of the sitemaps: the cart, the checkout, the account, and the shop page (the sitemap of
	 * products opens with its address); products hidden from the catalog and the search.
	 *
	 * @param mixed $ids
	 * @return mixed
	 */
	public static function sitemap_exclude( $ids, string $kind, string $name ) {
		if ( 'post' !== $kind || ! is_array( $ids ) || ! self::active() ) {
			return $ids;
		}
		if ( 'page' === $name ) {
			return array_merge( $ids, self::pages(), array( max( 0, (int) wc_get_page_id( 'shop' ) ) ) );
		}
		if ( 'product' === $name ) {
			return array_merge( $ids, self::hidden_products() );
		}

		return $ids;
	}

	/**
	 * Products hidden from the catalog and the search both (their visibility is "Hidden").
	 *
	 * @return int[]
	 */
	public static function hidden_products(): array {
		$query = new WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- once for a sitemap of products.
				'tax_query'              => array(
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
						'operator' => 'AND',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
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
	 * The details of a product in its Markdown version: the price as the shop shows it (from the
	 * lowest to the highest of the variations; the regular one beside a sale), the stock, the SKU
	 * and the attributes shown on the product page.
	 *
	 * @param mixed $details
	 * @return mixed
	 */
	public static function markdown_meta( $details, Simple_SEO_Context $context ) {
		$product = is_array( $details ) ? self::product( $context ) : null;
		if ( null === $product ) {
			return $details;
		}

		$price = '';
		if ( $product->is_type( 'variable' ) ) {
			$low   = (string) $product->get_variation_price( 'min', true );
			$high  = (string) $product->get_variation_price( 'max', true );
			$price = '' === $low ? '' : ( $low === $high ? self::money( $low ) : self::money( $low ) . ' – ' . self::money( $high ) );
		} elseif ( '' !== (string) $product->get_price() ) {
			$price = self::money( wc_get_price_to_display( $product ) );
			if ( $product->is_on_sale() && '' !== (string) $product->get_regular_price() ) {
				/* translators: %s: the regular price. */
				$price .= ' (' . sprintf( __( 'regular price %s', 'simple-seo' ), self::money( wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ) ) ) . ')';
			}
		}
		// Empty values — no price, no SKU — are left out of the list.
		$details[ __( 'Price', 'simple-seo' ) ] = Simple_SEO_HTML_Markdown::escape( $price );

		$details[ __( 'Availability', 'simple-seo' ) ] = match ( self::availability( $product ) ) {
			'out of stock'        => __( 'Out of stock', 'simple-seo' ),
			'available for order' => __( 'Available for order', 'simple-seo' ),
			default               => __( 'In stock', 'simple-seo' ),
		};

		$details[ __( 'SKU', 'simple-seo' ) ] = Simple_SEO_HTML_Markdown::escape( trim( (string) $product->get_sku() ) );

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_visible() ) {
				continue;
			}
			// The names of the terms of a global attribute, the values of a custom one.
			$values = $attribute->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
			$value  = implode( ', ', array_filter( array_map( static fn( $value ): string => Simple_SEO_Variables::plain( (string) $value ), (array) $values ) ) );

			$details[ Simple_SEO_Variables::plain( wc_attribute_label( $attribute->get_name(), $product ) ) ] = Simple_SEO_HTML_Markdown::escape( $value );
		}

		return $details;
	}

	/**
	 * The short description of a product above its description, as on the product page.
	 *
	 * @param mixed $markdown
	 * @return mixed
	 */
	public static function markdown_content( $markdown, WP_Post $post ) {
		if ( ! is_string( $markdown ) || 'product' !== $post->post_type || ! self::active() ) {
			return $markdown;
		}

		// Without a short description, nothing comes before the text.
		$short = Simple_SEO_HTML_Markdown::convert( wpautop( strip_shortcodes( trim( (string) $post->post_excerpt ) ) ), (string) get_permalink( $post ) );

		return trim( $short . "\n\n" . $markdown );
	}

	/** An amount as the shop writes it: its currency, separators and decimals. */
	private static function money( $amount ): string {
		return Simple_SEO_Variables::plain( wc_price( (float) $amount ) );
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
