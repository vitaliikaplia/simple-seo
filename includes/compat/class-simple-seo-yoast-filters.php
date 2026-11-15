<?php
/**
 * The filters of Yoast SEO that themes hook into, applied to Simple SEO's output: the title, the
 * meta description, canonical, robots, Open Graph and X, the breadcrumb trail, the Schema graph and
 * the sitemaps.
 *
 * Each Yoast filter gets the value Simple SEO is about to print, with the arguments themes expect;
 * a presentation or context object Yoast would pass second is null here. Filters about one page
 * (title, head tags, breadcrumbs, Schema) apply only to the page being shown: themes decide with
 * is_singular() and the like. wpseo_json_ld_output is Simple_SEO_Schema's own: it decides the mode
 * Auto. Loaded by Simple_SEO_Yoast_Compat::load(), so never next to Yoast or another SEO plugin.
 * The breadcrumb markup filters live in Simple_SEO_Yoast_Compat::breadcrumb(), wpseo_replacements
 * in Simple_SEO_Yoast_Compat::replace_vars().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Yoast_Filters {
	/** Head tag id => Yoast filter of its content (og:locale goes through two, in this order). */
	private const TAG_FILTERS = array(
		'og:locale'        => array( 'wpseo_locale', 'wpseo_og_locale' ),
		'og:type'          => array( 'wpseo_opengraph_type' ),
		'og:title'         => array( 'wpseo_opengraph_title' ),
		'og:description'   => array( 'wpseo_opengraph_desc' ),
		'og:url'           => array( 'wpseo_opengraph_url' ),
		'og:image'         => array( 'wpseo_opengraph_image' ),
		'og:image:width'   => array( 'wpseo_opengraph_image_width' ),
		'og:image:height'  => array( 'wpseo_opengraph_image_height' ),
		'og:image:type'    => array( 'wpseo_opengraph_image_type' ),
		'canonical'        => array( 'wpseo_canonical' ),
	);

	/** The tags that describe og:image and go with it. */
	private const IMAGE_TAGS = array( 'og:image:width', 'og:image:height', 'og:image:type', 'og:image:alt' );

	public static function init(): void {
		// Before the filters of Simple SEO's own API (priority 10), so those have the last word.
		add_filter( 'simple_seo_document_title', array( self::class, 'title' ), 5, 2 );
		add_filter( 'simple_seo_head_tags', array( self::class, 'head_tags' ), 5, 2 );
		add_filter( 'simple_seo_breadcrumbs', array( self::class, 'breadcrumbs' ), 5, 2 );
		add_filter( 'simple_seo_schema_pieces', array( self::class, 'schema_pieces' ), 5, 2 );
		add_filter( 'simple_seo_schema_graph', array( self::class, 'schema_graph' ), 5, 2 );
		add_filter( 'simple_seo_sitemap_exclude', array( self::class, 'sitemap_exclude' ), 5, 2 );
		add_filter( 'simple_seo_sitemap_entry', array( self::class, 'sitemap_entry' ), 5, 3 );
		add_filter( 'simple_seo_sitemap_url', array( self::class, 'sitemap_url' ), 5, 2 );
		// Right after Simple SEO's own robots (20), before the themes' wp_robots fallbacks.
		add_filter( 'wp_robots', array( self::class, 'robots' ), 21 );
	}

	/** wpseo_title, on the text of <title>. */
	public static function title( string $title, Simple_SEO_Context $context ): string {
		$filtered = apply_filters( 'wpseo_title', $title, null );

		return is_string( $filtered ) && $filtered !== $title ? Simple_SEO_Variables::plain( $filtered ) : $title;
	}

	/**
	 * wpseo_metadesc, wpseo_canonical, Open Graph and X filters on the head tags of the page shown.
	 *
	 * @param array<string, mixed> $tags
	 * @return array<string, mixed>
	 */
	public static function head_tags( $tags, Simple_SEO_Context $context ) {
		if ( ! is_array( $tags ) || $context !== Simple_SEO_Context::current() ) {
			return $tags;
		}

		// The description is asked for even when there is none: themes fill it in where it is empty.
		$ours        = self::content( $tags, 'description' );
		$description = apply_filters( 'wpseo_metadesc', $ours, null );
		$description = is_string( $description ) ? ( $description !== $ours ? Simple_SEO_Variables::plain( $description ) : $ours ) : '';
		if ( '' === $description ) {
			unset( $tags['description'] );
		} elseif ( isset( $tags['description'] ) ) {
			$tags['description']['attributes']['content'] = $description;
		} else {
			// First, where Simple SEO prints its own.
			$tags = array( 'description' => self::meta( 'description', $description ) ) + $tags;
		}

		// Only the tags Simple SEO prints are filtered: a page without them stays without them.
		foreach ( self::TAG_FILTERS as $id => $filters ) {
			if ( ! isset( $tags[ $id ] ) ) {
				continue;
			}
			$ours  = self::content( $tags, $id );
			$value = $ours;
			foreach ( $filters as $filter ) {
				$value = apply_filters( $filter, $value, null );
			}
			if ( $value === $ours ) {
				continue;
			}
			// false (as themes return to drop a tag) and '' alike.
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( in_array( $id, array( 'og:title', 'og:description' ), true ) ) {
				$value = Simple_SEO_Variables::plain( $value );
			}
			if ( '' === $value ) {
				unset( $tags[ $id ] );
				continue;
			}
			$tags[ $id ]['attributes'][ 'canonical' === $id ? 'href' : 'content' ] = $value;
		}
		if ( ! isset( $tags['og:image'] ) ) {
			foreach ( self::IMAGE_TAGS as $id ) {
				unset( $tags[ $id ] );
			}
		}

		// X reads og:title and og:image; its own tag only where a theme gives it something else.
		$tags = self::twitter_tag( $tags, 'twitter:title', 'wpseo_twitter_title', 'og:title' );

		return self::twitter_tag( $tags, 'twitter:image', 'wpseo_twitter_image', 'og:image' );
	}

	/**
	 * wpseo_robots_array and wpseo_robots on core's robots of the page, when a theme hooks either.
	 *
	 * @param array<string, bool|string> $robots
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ) {
		if ( ! is_array( $robots ) || is_admin() || ! get_option( 'blog_public' ) || ( ! has_filter( 'wpseo_robots_array' ) && ! has_filter( 'wpseo_robots' ) ) ) {
			return $robots;
		}

		$yoast  = self::yoast_robots( $robots );
		$before = implode( ', ', $yoast );

		$filtered = apply_filters( 'wpseo_robots_array', $yoast, null );
		$string   = implode( ', ', array_filter( array_map( 'strval', is_array( $filtered ) ? array_filter( $filtered, 'is_scalar' ) : $yoast ) ) );
		$string   = apply_filters( 'wpseo_robots', $string, null );
		if ( ! is_string( $string ) || $string === $before ) {
			return $robots;
		}

		return self::core_robots( $string );
	}

	/**
	 * wpseo_breadcrumb_links on the trail of the page shown: links of url and text, the archive of a
	 * post type marked with ptarchive.
	 *
	 * @param array<int, mixed> $items
	 * @return array<int, mixed>
	 */
	public static function breadcrumbs( $items, Simple_SEO_Context $context ) {
		if ( ! is_array( $items ) || $context !== Simple_SEO_Context::current() || ! has_filter( 'wpseo_breadcrumb_links' ) ) {
			return $items;
		}

		$links    = self::links( $items );
		$filtered = apply_filters( 'wpseo_breadcrumb_links', $links );
		if ( ! is_array( $filtered ) || $filtered === $links ) {
			return $items;
		}
		$links = $filtered;

		$trail = array();
		foreach ( $links as $link ) {
			if ( is_scalar( $link['text'] ?? null ) ) {
				$trail[] = array(
					'label' => Simple_SEO_Variables::plain( (string) $link['text'] ),
					'url'   => is_string( $link['url'] ?? null ) ? $link['url'] : '',
				);
			}
		}

		return $trail;
	}

	/**
	 * wpseo_schema_<piece> on each piece of the graph of the page shown (website, organization,
	 * person, webpage, mainimage, article, author, breadcrumb), and wpseo_schema_person_data on the
	 * people with their user's ID. A piece a theme turns into anything but an array goes.
	 *
	 * @param array<string, mixed> $pieces
	 * @return array<string, mixed>
	 */
	public static function schema_pieces( $pieces, Simple_SEO_Context $context ) {
		if ( ! is_array( $pieces ) || $context !== Simple_SEO_Context::current() ) {
			return $pieces;
		}

		foreach ( $pieces as $key => $piece ) {
			$key = (string) $key;
			if ( ! is_array( $piece ) ) {
				continue;
			}
			$piece = apply_filters( 'wpseo_schema_' . $key, $piece, null );
			$user  = Simple_SEO_Schema::piece_user( $key, $context );
			if ( $user && is_array( $piece ) ) {
				$piece = apply_filters( 'wpseo_schema_person_data', $piece, $user );
			}
			$pieces[ $key ] = is_array( $piece ) ? $piece : array();
		}

		return $pieces;
	}

	/**
	 * wpseo_schema_graph on the whole graph of the page shown: themes take nodes out or add theirs.
	 *
	 * @param array<int, mixed> $graph
	 * @return array<int, mixed>
	 */
	public static function schema_graph( $graph, Simple_SEO_Context $context ) {
		if ( ! is_array( $graph ) || $context !== Simple_SEO_Context::current() ) {
			return $graph;
		}

		$filtered = apply_filters( 'wpseo_schema_graph', $graph, null );

		return is_array( $filtered ) ? $filtered : $graph;
	}

	/**
	 * Breadcrumb items as Yoast's links: url and text, and ptarchive (the post type) on the archive
	 * of a post type.
	 *
	 * @param array<int, mixed> $items
	 * @return array<int, array<string, string>>
	 */
	public static function links( array $items ): array {
		// has_archive is true or the archive's slug.
		$archives = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			$link = ! empty( $type->has_archive ) ? get_post_type_archive_link( $type->name ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$archives[ $link ] = $type->name;
			}
		}

		$links = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$link = array(
				'url'  => (string) ( $item['url'] ?? '' ),
				'text' => (string) ( $item['label'] ?? '' ),
			);
			if ( isset( $archives[ $link['url'] ] ) ) {
				$link['ptarchive'] = $archives[ $link['url'] ];
			}
			$links[] = $link;
		}

		return $links;
	}

	/**
	 * wpseo_exclude_from_sitemap_by_post_ids and wpseo_exclude_from_sitemap_by_term_ids.
	 *
	 * @param mixed $ids
	 * @return mixed
	 */
	public static function sitemap_exclude( $ids, string $kind ) {
		$filter = 'term' === $kind ? 'wpseo_exclude_from_sitemap_by_term_ids' : 'wpseo_exclude_from_sitemap_by_post_ids';
		$more   = apply_filters( $filter, array() );

		return is_array( $more ) ? array_merge( (array) $ids, $more ) : $ids;
	}

	/**
	 * wpseo_sitemap_entry on an address ($type post, term or user, with its object), and
	 * wpseo_sitemap_post_type_first_links on the home page or archive opening a post type's sitemap.
	 *
	 * @param mixed                                     $entry
	 * @param WP_Post|WP_Term|WP_User|WP_Post_Type|null $object
	 * @return mixed
	 */
	public static function sitemap_entry( $entry, string $kind, $object ) {
		if ( ! is_array( $entry ) ) {
			return $entry;
		}

		$ours = self::yoast_url( $entry );
		if ( 'archive' === $kind ) {
			$links = apply_filters( 'wpseo_sitemap_post_type_first_links', array( $ours ), $object instanceof WP_Post_Type ? $object->name : '' );
			$url   = is_array( $links ) ? reset( $links ) : $ours;
		} else {
			$url = apply_filters( 'wpseo_sitemap_entry', $ours, 'author' === $kind ? 'user' : $kind, $object );
		}

		if ( $url === $ours ) {
			return $entry;
		}
		if ( ! is_array( $url ) || ! is_string( $url['loc'] ?? null ) || '' === $url['loc'] ) {
			return null;
		}

		$images = array();
		foreach ( (array) ( $url['images'] ?? array() ) as $image ) {
			$src = is_array( $image ) ? ( $image['src'] ?? '' ) : $image;
			if ( is_string( $src ) && '' !== $src ) {
				$images[] = $src;
			}
		}

		return array(
			'loc'        => $url['loc'],
			'lastmod'    => is_string( $url['mod'] ?? null ) ? $url['mod'] : '',
			'images'     => $images,
			// The other languages' addresses belong to the address they were found for.
			'alternates' => $url['loc'] === $entry['loc'] ? (array) ( $entry['alternates'] ?? array() ) : array(),
		);
	}

	/**
	 * wpseo_sitemap_url on the XML of one address.
	 *
	 * @param mixed                $xml
	 * @param array<string, mixed> $entry
	 * @return mixed
	 */
	public static function sitemap_url( $xml, $entry ) {
		$filtered = apply_filters( 'wpseo_sitemap_url', $xml, is_array( $entry ) ? self::yoast_url( $entry ) : array() );

		return is_string( $filtered ) ? $filtered : $xml;
	}

	/**
	 * An address as Yoast's sitemap filters have it: loc, mod and images of src.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{loc: string, mod: string, images: array<int, array{src: string}>}
	 */
	private static function yoast_url( array $entry ): array {
		return array(
			'loc'    => (string) ( $entry['loc'] ?? '' ),
			'mod'    => (string) ( $entry['lastmod'] ?? '' ),
			'images' => array_map( static fn( $src ): array => array( 'src' => (string) $src ), array_values( (array) ( $entry['images'] ?? array() ) ) ),
		);
	}

	/**
	 * Core's robots as Yoast's array: index and follow first, then the rest as "name" or "name:value".
	 *
	 * @param array<string, bool|string> $robots
	 * @return array<string, string>
	 */
	private static function yoast_robots( array $robots ): array {
		$yoast = array(
			'index'  => empty( $robots['noindex'] ) ? 'index' : 'noindex',
			'follow' => empty( $robots['nofollow'] ) ? 'follow' : 'nofollow',
		);
		foreach ( $robots as $name => $value ) {
			$name = (string) $name;
			if ( in_array( $name, array( 'index', 'noindex', 'follow', 'nofollow' ), true ) || false === $value || '' === $value ) {
				continue;
			}
			$yoast[ $name ] = true === $value ? $name : $name . ':' . $value;
		}

		return $yoast;
	}

	/**
	 * A robots string ("noindex, follow, max-snippet:-1") as core's array. index is what search
	 * engines assume anyway and is left out; follow only stays next to noindex.
	 *
	 * @return array<string, bool|string>
	 */
	private static function core_robots( string $string ): array {
		$robots = array();
		foreach ( explode( ',', strtolower( $string ) ) as $directive ) {
			$directive = trim( $directive );
			if ( 'none' === $directive ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
				continue;
			}
			$parts = explode( ':', $directive, 2 );
			$name  = trim( $parts[0] );
			if ( '' === $name || 'index' === $name || ! preg_match( '/^[a-z][a-z0-9-]*$/', $name ) ) {
				continue;
			}
			$robots[ $name ] = isset( $parts[1] ) ? trim( $parts[1] ) : true;
		}
		if ( isset( $robots['nofollow'] ) || ! isset( $robots['noindex'] ) ) {
			unset( $robots['follow'] );
		}

		return $robots;
	}

	/**
	 * The content (or href) of a head tag; '' when there is no such tag.
	 *
	 * @param array<string, mixed> $tags
	 */
	private static function content( array $tags, string $id ): string {
		$attributes = is_array( $tags[ $id ]['attributes'] ?? null ) ? $tags[ $id ]['attributes'] : array();
		$value      = $attributes['canonical' === $id ? 'href' : 'content'] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * An X tag where a theme's filter gives X something other than the Open Graph tag.
	 *
	 * @param array<string, mixed> $tags
	 * @return array<string, mixed>
	 */
	private static function twitter_tag( array $tags, string $id, string $filter, string $og ): array {
		if ( ! isset( $tags[ $og ] ) ) {
			return $tags;
		}

		$og_value = self::content( $tags, $og );
		$value    = apply_filters( $filter, $og_value, null );
		$value    = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( 'twitter:title' === $id ) {
			$value = Simple_SEO_Variables::plain( $value );
		}
		if ( '' !== $value && $value !== $og_value ) {
			$tags[ $id ] = self::meta( $id, $value );
		}

		return $tags;
	}

	/** @return array{tag: string, attributes: array<string, string>} <meta name="…" content="…"> */
	private static function meta( string $name, string $content ): array {
		return array(
			'tag'        => 'meta',
			'attributes' => array(
				'name'    => $name,
				'content' => $content,
			),
		);
	}
}
