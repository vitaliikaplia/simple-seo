<?php
/**
 * XML sitemaps of their own instead of core's wp-sitemap.xml: core's renderer has no room for
 * images or language alternates (xhtml:link), and Simple SEO keeps the addresses Yoast SEO used, so
 * a sitemap already submitted to Google Search Console keeps working after the switch:
 *
 *   /sitemap_index.xml                 the index, grouped by language
 *   /{post type}-sitemap.xml, …2.xml   posts of a type, 1000 a page (the page type starts with the
 *                                      home page, other types with their archive)
 *   /{taxonomy}-sitemap.xml            terms with posts
 *   /author-sitemap.xml                authors, while author archives are shown to search engines
 *   /sitemap.xml, /wp-sitemap.xml      redirect to the index
 *   /sitemap.css                       how a browser shows them
 *
 * With wp-loc every language has its own sitemaps under its own prefix, the way wp-loc builds
 * addresses: /post-sitemap.xml lists the default language (and what has no language),
 * /en/post-sitemap.xml the English posts. A sitemap at /en/ lists only addresses under /en/, as the
 * sitemap protocol wants, and every address names its translations (xhtml:link alternate) and
 * x-default. Post types and taxonomies wp-loc does not translate stay in the default language.
 *
 * Only what search engines may show goes in: published, without a password, not hidden (noindex)
 * by its kind or on its own, and not pointing to another address with its canonical.
 *
 * Browsers no longer run XSLT (Chrome has dropped it), so the sitemaps are styled with CSS: a
 * readable list with dates, languages and images instead of the raw XML tree.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Sitemaps {
	public const OPTION = 'simple_seo_sitemaps';

	public const QUERY_VAR = 'simple_seo_sitemap';

	public const PAGE_VAR = 'simple_seo_sitemap_page';

	/** Query values of the routes that are not a list of addresses. */
	private const ROUTE_INDEX = '__index';
	private const ROUTE_ROOT  = '__root';
	private const ROUTE_CSS   = '__css';

	/** Addresses on one sitemap page. */
	private const PAGE_SIZE = 1000;

	public static function init(): void {
		Simple_SEO_Settings::register( self::OPTION, array( 'enabled' => true ), array( self::class, 'sanitize' ), true );

		add_action( 'init', array( self::class, 'register_rewrites' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'wp_sitemaps_enabled', array( self::class, 'core_sitemaps_enabled' ) );
		add_filter( 'posts_pre_query', array( self::class, 'skip_main_query' ), 10, 2 );
		// After wp-loc has switched the locale to the request's language (template_redirect 1), before core's sitemaps (10).
		add_action( 'template_redirect', array( self::class, 'serve' ), 2 );
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), 20, 2 );
	}

	/** Whether Simple SEO serves its sitemaps: switched on, a public site, no other SEO plugin. */
	public static function enabled(): bool {
		return (bool) Simple_SEO_Settings::value( self::OPTION, 'enabled' )
			&& (bool) get_option( 'blog_public' )
			&& '' === Simple_SEO_Plugin::conflicting_plugin();
	}

	public static function register_rewrites(): void {
		// While another SEO plugin is active, the same addresses are its own.
		if ( '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return;
		}

		add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?' . self::QUERY_VAR . '=' . self::ROUTE_INDEX, 'top' );
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?' . self::QUERY_VAR . '=' . self::ROUTE_ROOT, 'top' );
		add_rewrite_rule( '^sitemap\.css$', 'index.php?' . self::QUERY_VAR . '=' . self::ROUTE_CSS, 'top' );
		add_rewrite_rule( '^([a-z0-9_-]+?)-sitemap([0-9]*)\.xml$', 'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::PAGE_VAR . '=$matches[2]', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PAGE_VAR;

		return $vars;
	}

	/** core's wp-sitemap.xml steps aside while ours are served (its addresses redirect here). */
	public static function core_sitemaps_enabled( $enabled ): bool {
		return self::enabled() ? false : (bool) $enabled;
	}

	/**
	 * A sitemap request needs no posts from the main query: skip that database query.
	 *
	 * @param WP_Post[]|int[]|null $posts
	 * @return WP_Post[]|int[]|null
	 */
	public static function skip_main_query( $posts, WP_Query $query ) {
		return $query->is_main_query() && '' !== (string) $query->get( self::QUERY_VAR ) ? array() : $posts;
	}

	/** The index, always at the address of the default language. */
	public static function index_url(): string {
		return self::url( self::ROUTE_INDEX, 1, Simple_SEO_WP_Loc::default_language() );
	}

	/**
	 * The address of a sitemap page in a language (under its prefix with wp-loc); the page number
	 * is left out for the first page.
	 */
	public static function url( string $name, int $page = 1, string $code = '' ): string {
		if ( ! get_option( 'permalink_structure' ) ) {
			$args = array( self::QUERY_VAR => $name );
			if ( $page > 1 ) {
				$args[ self::PAGE_VAR ] = $page;
			}
			$url = add_query_arg( $args, home_url( '/' ) );
		} elseif ( self::ROUTE_INDEX === $name ) {
			$url = home_url( '/sitemap_index.xml' );
		} elseif ( self::ROUTE_CSS === $name ) {
			$url = home_url( '/sitemap.css' );
		} else {
			$url = home_url( '/' . $name . '-sitemap' . ( $page > 1 ? $page : '' ) . '.xml' );
		}

		return '' !== $code ? Simple_SEO_WP_Loc::language_url( self::unprefixed( $url ), $code ) : $url;
	}

	/** Answer sitemap addresses and send core's old ones to the index. */
	public static function serve(): void {
		$name = (string) get_query_var( self::QUERY_VAR );

		if ( '' === $name ) {
			if ( ( get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) ) && self::enabled() ) {
				wp_safe_redirect( self::index_url(), 301, 'Simple SEO' );
				exit;
			}
			return;
		}

		if ( ! self::enabled() ) {
			// Switched off: WordPress's own sitemap is back, and the index addresses lead to it.
			$core = in_array( $name, array( self::ROUTE_INDEX, self::ROUTE_ROOT ), true ) && function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ? get_sitemap_url( 'index' ) : false;
			if ( is_string( $core ) && '' !== $core ) {
				wp_safe_redirect( $core, 302, 'Simple SEO' );
				exit;
			}
			self::not_found();
			return;
		}

		if ( self::ROUTE_CSS === $name ) {
			self::send( self::render_stylesheet(), 'text/css', true );
		}

		// One index for all languages, at the default language's address.
		$default = Simple_SEO_WP_Loc::default_language();
		if ( self::ROUTE_ROOT === $name || ( self::ROUTE_INDEX === $name && Simple_SEO_WP_Loc::current_language() !== $default ) ) {
			wp_safe_redirect( self::index_url(), 301, 'Simple SEO' );
			exit;
		}

		$xml = self::ROUTE_INDEX === $name ? self::render_index() : self::render_sitemap( $name, max( 1, (int) get_query_var( self::PAGE_VAR ) ) );
		if ( null === $xml ) {
			self::not_found();
			return;
		}

		self::send( $xml, 'application/xml' );
	}

	/** robots.txt names the index (1.8 will add the editor around it). */
	public static function robots_txt( $output, $is_public ): string {
		$output = (string) $output;
		if ( $is_public && self::enabled() ) {
			$output .= "\nSitemap: " . esc_url_raw( self::index_url() ) . "\n";
		}

		return $output;
	}

	/**
	 * The sitemaps that have something to list, in the order of the index: language by language,
	 * the default one first.
	 *
	 * @return array<int, array{name: string, kind: string, language: string, pages: int}>
	 */
	public static function sitemaps(): array {
		$candidates = array();
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$candidates[ $type ] = 'post';
		}
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			$candidates[ $taxonomy ] ??= 'term';
		}
		$candidates['author'] ??= 'author';

		$sitemaps = array();
		foreach ( Simple_SEO_WP_Loc::codes() as $code ) {
			foreach ( $candidates as $name => $kind ) {
				$name = (string) $name;
				if ( self::kind_of( $name ) !== $kind ) {
					continue;
				}
				$pages = self::page_count( $name, $kind, $code );
				if ( $pages > 0 ) {
					$sitemaps[] = array(
						'name'     => $name,
						'kind'     => $kind,
						'language' => $code,
						'pages'    => $pages,
					);
				}
			}
		}

		return $sitemaps;
	}

	/** What a sitemap name lists: post, term, author or '' for no such sitemap. */
	public static function kind_of( string $name ): string {
		if ( in_array( $name, Simple_SEO_Fields::post_types(), true ) && is_post_type_viewable( $name ) ) {
			return 'post';
		}
		if ( in_array( $name, Simple_SEO_Fields::taxonomies(), true ) && is_taxonomy_viewable( $name ) ) {
			return 'term';
		}

		return 'author' === $name ? 'author' : '';
	}

	public static function page_size(): int {
		/**
		 * Addresses on one sitemap page (search engines take up to 50 000).
		 *
		 * @param int $size
		 */
		return max( 1, min( 50000, (int) apply_filters( 'simple_seo_sitemap_entries', self::PAGE_SIZE ) ) );
	}

	/** Pages of a sitemap in a language ('' — the request's language, or no languages at all). */
	public static function page_count( string $name, string $kind = '', ?string $code = null ): int {
		$kind = '' !== $kind ? $kind : self::kind_of( $name );
		$code = $code ?? Simple_SEO_WP_Loc::current_language();

		switch ( $kind ) {
			case 'post':
				if ( ! self::has_language( $code, Simple_SEO_WP_Loc::translatable_post_type( $name ) ) ) {
					return 0;
				}
				$pages = (int) ceil( (int) $GLOBALS['wpdb']->get_var( self::post_sql( $name, 'COUNT(*)', $code ) ) / self::page_size() );
				return 0 === $pages && self::leading_entries( $name, $code ) ? 1 : $pages;
			case 'term':
				if ( ! self::has_language( $code, Simple_SEO_WP_Loc::translatable_taxonomy( $name ) ) ) {
					return 0;
				}
				return (int) ceil( (int) $GLOBALS['wpdb']->get_var( self::term_sql( $name, 'COUNT(*)', $code ) ) / self::page_size() );
			case 'author':
				return Simple_SEO_Indexing::slot_is_noindex( 'author' ) ? 0 : (int) ceil( (int) $GLOBALS['wpdb']->get_var( self::author_sql( 'COUNT(DISTINCT p.post_author)', $code ) ) / self::page_size() );
		}

		return 0;
	}

	public static function render_index(): string {
		$xml = self::xml_head( Simple_SEO_WP_Loc::default_language() ) . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( self::sitemaps() as $sitemap ) {
			for ( $page = 1; $page <= $sitemap['pages']; $page++ ) {
				$xml     .= "\t<sitemap>\n\t\t<loc>" . esc_xml( self::url( $sitemap['name'], $page, $sitemap['language'] ) ) . "</loc>\n";
				$modified = self::page_lastmod( $sitemap['name'], $sitemap['kind'], $page, $sitemap['language'] );
				if ( '' !== $modified ) {
					$xml .= "\t\t<lastmod>" . esc_xml( $modified ) . "</lastmod>\n";
				}
				$xml .= "\t</sitemap>\n";
			}
		}

		return $xml . "</sitemapindex>\n";
	}

	/** A sitemap page in a language (the request's by default); null when there is no such page. */
	public static function render_sitemap( string $name, int $page, ?string $code = null ): ?string {
		$code    = $code ?? Simple_SEO_WP_Loc::current_language();
		$entries = self::entries( $name, $page, $code );
		if ( null === $entries ) {
			return null;
		}

		// Namespaces only when used.
		$xml = self::xml_head( $code ) . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
			. ( array_filter( array_column( $entries, 'alternates' ) ) ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '' )
			. ( array_filter( array_column( $entries, 'images' ) ) ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '' )
			. ">\n";

		foreach ( $entries as $entry ) {
			$xml .= "\t<url>\n\t\t<loc>" . esc_xml( $entry['loc'] ) . "</loc>\n";
			if ( '' !== $entry['lastmod'] ) {
				$xml .= "\t\t<lastmod>" . esc_xml( $entry['lastmod'] ) . "</lastmod>\n";
			}
			foreach ( $entry['alternates'] as $hreflang => $href ) {
				$xml .= "\t\t" . '<xhtml:link rel="alternate" hreflang="' . esc_xml( (string) $hreflang ) . '" href="' . esc_xml( $href ) . '"/>' . "\n";
			}
			foreach ( $entry['images'] as $image ) {
				$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . esc_xml( $image ) . "</image:loc>\n\t\t</image:image>\n";
			}
			$xml .= "\t</url>\n";
		}

		return $xml . "</urlset>\n";
	}

	/**
	 * The addresses of a sitemap page in a language (the request's by default); null when there is
	 * no such sitemap or page.
	 *
	 * @return array<int, array{loc: string, lastmod: string, images: string[], alternates: array<string, string>}>|null
	 */
	public static function entries( string $name, int $page, ?string $code = null ): ?array {
		$code = $code ?? Simple_SEO_WP_Loc::current_language();
		$kind = self::kind_of( $name );
		if ( '' === $kind || $page < 1 || $page > self::page_count( $name, $kind, $code ) ) {
			return null;
		}

		switch ( $kind ) {
			case 'post':
				$entries = self::post_entries( $name, $page, $code );
				return 1 === $page ? array_merge( self::leading_entries( $name, $code ), $entries ) : $entries;
			case 'term':
				return self::term_entries( $name, $page, $code );
			default:
				return self::author_entries( $page, $code );
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		return array( 'enabled' => ! isset( $input['enabled'] ) || '1' === (string) $input['enabled'] );
	}

	// ----------------------------------------------------------------------------------------
	// Posts.

	/**
	 * SQL over the posts of a type a sitemap may list in a language; the noindex fields are
	 * checked here already.
	 */
	private static function post_sql( string $type, string $select, string $code, string $tail = '' ): string {
		global $wpdb;

		list( $join, $language ) = Simple_SEO_WP_Loc::language_sql( 'p.ID', $wpdb->prepare( '%s', 'post_' . $type ), $code );

		$sql = $wpdb->prepare(
			"SELECT {$select} FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s{$join} WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $select and $join are ours.
			Simple_SEO_Fields::meta_key( 'index' ),
			$type
		);
		// A hidden type lists only the posts shown on their own; a shown type leaves out those hidden on their own.
		$sql .= Simple_SEO_Indexing::slot_is_noindex( 'post:' . $type ) ? " AND m.meta_value = 'index'" : " AND ( m.meta_value IS NULL OR m.meta_value <> 'noindex' )";

		$exclude = 'page' === $type ? self::front_page_ids() : array();
		if ( $exclude ) {
			$sql .= ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $exclude ) ) . ')';
		}

		return $sql . $language . $tail;
	}

	private static function limit( int $page ): string {
		return sprintf( ' LIMIT %d OFFSET %d', self::page_size(), ( $page - 1 ) * self::page_size() );
	}

	/** @return array<int, array{loc: string, lastmod: string, images: string[], alternates: array<string, string>}> */
	private static function post_entries( string $type, int $page, string $code ): array {
		$ids = array_map( 'intval', (array) $GLOBALS['wpdb']->get_col( self::post_sql( $type, 'p.ID', $code, ' ORDER BY p.ID ASC' . self::limit( $page ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$element_type = 'post_' . $type;
		$groups       = Simple_SEO_WP_Loc::translation_groups( $ids, $element_type );
		$all          = array_values( array_unique( array_merge( $ids, ...array_values( array_map( 'array_values', $groups ) ) ) ) );
		_prime_post_caches( $all, true, true );
		self::prime_thumbnails( $ids );

		$urls    = self::urls_by_language( $all, $element_type, array( self::class, 'post_url' ) );
		$entries = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || '' === ( $urls[ $id ] ?? '' ) ) {
				continue;
			}

			$entries[] = array(
				'loc'        => $urls[ $id ],
				'lastmod'    => (string) get_post_modified_time( 'c', true, $post ),
				'images'     => self::images( $post ),
				'alternates' => self::alternates( $groups[ $id ] ?? array(), $urls ),
			);
		}

		return $entries;
	}

	/** The address of a post for the sitemap: '' when search engines should not get it. */
	private static function post_url( int $id ): string {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return '';
		}

		return self::indexable_address( Simple_SEO_Context::for_post( $post ), (string) get_permalink( $post ) );
	}

	/**
	 * The page type opens with the home page, every other type with its archive: the address in the
	 * language of the sitemap, with those of the other languages as alternates.
	 *
	 * @return array<int, array{loc: string, lastmod: string, images: string[], alternates: array<string, string>}>
	 */
	private static function leading_entries( string $type, string $code ): array {
		if ( 'page' === $type ) {
			$context = static fn(): Simple_SEO_Context => Simple_SEO_Context::for_home();
			$latest  = 'post';
		} else {
			$object = get_post_type_object( $type );
			if ( 'post' === $type || ! $object instanceof WP_Post_Type || ! $object->has_archive ) {
				return array();
			}
			$context = static fn(): Simple_SEO_Context => Simple_SEO_Context::for_post_type_archive( $type );
			$latest  = $type;
		}

		// A type wp-loc does not translate has one archive, in the default language.
		$codes    = Simple_SEO_WP_Loc::translatable_post_type( $type ) ? Simple_SEO_WP_Loc::codes() : array( Simple_SEO_WP_Loc::codes()[0] );
		$urls     = array();
		$modified = array();
		foreach ( $codes as $language ) {
			Simple_SEO_WP_Loc::in_language(
				$language,
				static function () use ( $language, $context, &$urls, &$modified ): void {
					$page = $context();
					// Under the language's home even where wp-loc leaves home_url() alone; one entry per address.
					$url = Simple_SEO_WP_Loc::language_url( self::indexable_address( $page, Simple_SEO_Indexing::own_address( $page ) ), $language );
					if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
						$urls[ $language ] = $url;
						// A static front page has its own date; a list of posts is as new as its newest post.
						$modified[ $language ] = $page->object instanceof WP_Post ? (string) get_post_modified_time( 'c', true, $page->object ) : '';
					}
				}
			);
		}

		if ( ! isset( $urls[ $code ] ) ) {
			return array();
		}

		return array(
			array(
				'loc'        => $urls[ $code ],
				'lastmod'    => '' !== $modified[ $code ] ? $modified[ $code ] : self::latest_modified( $latest ),
				'images'     => array(),
				'alternates' => self::alternates( array_combine( array_keys( $urls ), array_keys( $urls ) ), $urls ),
			),
		);
	}

	/**
	 * The static front page and its translations: listed as the home page, not as pages.
	 *
	 * @return int[]
	 */
	private static function front_page_ids(): array {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		if ( ! $front ) {
			return array();
		}

		$groups = Simple_SEO_WP_Loc::translation_groups( array( $front ), 'post_page' );

		return array_values( array_unique( array_merge( array( $front ), array_values( $groups[ $front ] ?? array() ) ) ) );
	}

	/** Newest modification of a post type's published posts, W3C format; '' for none. */
	private static function latest_modified( string $type ): string {
		global $wpdb;

		$gmt = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = ''", $type ) );

		return self::w3c( $gmt );
	}

	/** @param int[] $ids */
	private static function prime_thumbnails( array $ids ): void {
		$thumbnails = array();
		foreach ( $ids as $id ) {
			$thumbnail = (int) get_post_meta( $id, '_thumbnail_id', true );
			if ( $thumbnail ) {
				$thumbnails[] = $thumbnail;
			}
		}
		if ( $thumbnails ) {
			_prime_post_caches( array_values( array_unique( $thumbnails ) ), false, true );
		}
	}

	/**
	 * The images of a post: its featured image and the images in its text.
	 *
	 * @return string[]
	 */
	private static function images( WP_Post $post ): array {
		$images    = array();
		$thumbnail = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
		if ( $thumbnail ) {
			$url = wp_get_attachment_url( $thumbnail );
			if ( is_string( $url ) && '' !== $url ) {
				$images[] = $url;
			}
		}

		if ( str_contains( $post->post_content, '<img' ) && preg_match_all( '/<img\s[^>]*?\bsrc\s*=\s*(["\'])(.+?)\1/i', $post->post_content, $matches ) ) {
			foreach ( $matches[2] as $src ) {
				$src = trim( html_entity_decode( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( str_starts_with( $src, '//' ) ) {
					$src = set_url_scheme( 'http:' . $src );
				} elseif ( str_starts_with( $src, '/' ) ) {
					$src = home_url( $src );
				}
				if ( preg_match( '#^https?://[^\s]+$#i', $src ) ) {
					$images[] = $src;
				}
			}
		}

		/**
		 * The images a post lists in the sitemap; [] for none.
		 *
		 * @param string[] $images Absolute addresses.
		 * @param WP_Post  $post
		 */
		$images = (array) apply_filters( 'simple_seo_sitemap_images', array_values( array_unique( $images ) ), $post );

		return array_values( array_filter( array_map( 'strval', $images ), static fn( string $url ): bool => (bool) preg_match( '#^https?://#i', $url ) ) );
	}

	// ----------------------------------------------------------------------------------------
	// Terms.

	private static function term_sql( string $taxonomy, string $select, string $code, string $tail = '' ): string {
		global $wpdb;

		list( $join, $language ) = Simple_SEO_WP_Loc::language_sql( 'tt.term_taxonomy_id', $wpdb->prepare( '%s', 'tax_' . $taxonomy ), $code );

		$sql  = $wpdb->prepare(
			"SELECT {$select} FROM {$wpdb->term_taxonomy} tt LEFT JOIN {$wpdb->termmeta} m ON m.term_id = tt.term_id AND m.meta_key = %s{$join} WHERE tt.taxonomy = %s AND tt.count > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $select and $join are ours.
			Simple_SEO_Fields::meta_key( 'index' ),
			$taxonomy
		);
		$sql .= Simple_SEO_Indexing::slot_is_noindex( 'term:' . $taxonomy ) ? " AND m.meta_value = 'index'" : " AND ( m.meta_value IS NULL OR m.meta_value <> 'noindex' )";

		return $sql . $language . $tail;
	}

	/** @return array<int, array{loc: string, lastmod: string, images: string[], alternates: array<string, string>}> */
	private static function term_entries( string $taxonomy, int $page, string $code ): array {
		global $wpdb;

		// Terms go by their term_taxonomy_id: that is what wp-loc keys their translations by.
		$rows = (array) $wpdb->get_results( self::term_sql( $taxonomy, 'tt.term_taxonomy_id, tt.term_id', $code, ' ORDER BY tt.term_id ASC' . self::limit( $page ) ) );
		if ( ! $rows ) {
			return array();
		}

		$element_type = 'tax_' . $taxonomy;
		$ids          = array_map( static fn( $row ): int => (int) $row->term_taxonomy_id, $rows );
		$groups       = Simple_SEO_WP_Loc::translation_groups( $ids, $element_type );
		$all          = array_values( array_unique( array_merge( $ids, ...array_values( array_map( 'array_values', $groups ) ) ) ) );
		$term_ids     = self::term_ids( $all );
		_prime_term_caches( array_values( $term_ids ), true );

		$urls = self::urls_by_language(
			$all,
			$element_type,
			static fn( int $tt_id ): string => isset( $term_ids[ $tt_id ] ) ? self::term_url( $term_ids[ $tt_id ], $taxonomy ) : ''
		);

		$modified = self::terms_modified( array_values( array_intersect_key( $term_ids, array_flip( $ids ) ) ), $taxonomy );
		$entries  = array();
		foreach ( $ids as $tt_id ) {
			if ( '' === ( $urls[ $tt_id ] ?? '' ) ) {
				continue;
			}
			$entries[] = array(
				'loc'        => $urls[ $tt_id ],
				'lastmod'    => $modified[ $term_ids[ $tt_id ] ] ?? '',
				'images'     => array(),
				'alternates' => self::alternates( $groups[ $tt_id ] ?? array(), $urls ),
			);
		}

		return $entries;
	}

	private static function term_url( int $term_id, string $taxonomy ): string {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term || $term->count < 1 ) {
			return '';
		}

		$link = get_term_link( $term );

		return self::indexable_address( Simple_SEO_Context::for_term( $term ), is_string( $link ) ? $link : '' );
	}

	/**
	 * @param int[] $tt_ids
	 * @return array<int, int> term_taxonomy_id => term_id
	 */
	private static function term_ids( array $tt_ids ): array {
		global $wpdb;

		if ( ! $tt_ids ) {
			return array();
		}

		$map = array();
		foreach ( (array) $wpdb->get_results( 'SELECT term_taxonomy_id, term_id FROM ' . $wpdb->term_taxonomy . ' WHERE term_taxonomy_id IN (' . implode( ',', array_map( 'intval', $tt_ids ) ) . ')' ) as $row ) {
			$map[ (int) $row->term_taxonomy_id ] = (int) $row->term_id;
		}

		return $map;
	}

	/**
	 * Newest modification among the published posts of each term, W3C format.
	 *
	 * @param int[] $term_ids
	 * @return array<int, string>
	 */
	private static function terms_modified( array $term_ids, string $taxonomy ): array {
		global $wpdb;

		if ( ! $term_ids ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, MAX(p.post_modified_gmt) AS modified FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND p.post_status = 'publish' AND tt.term_id IN (" . implode( ',', array_map( 'intval', $term_ids ) ) . ') GROUP BY tt.term_id', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
				$taxonomy
			)
		);

		$modified = array();
		foreach ( $rows as $row ) {
			$modified[ (int) $row->term_id ] = self::w3c( (string) $row->modified );
		}

		return $modified;
	}

	// ----------------------------------------------------------------------------------------
	// Authors.

	/** Authors with published posts in a language (posts without a language count for the default one). */
	private static function author_sql( string $select, string $code, string $tail = '' ): string {
		global $wpdb;

		$types = array_map( static fn( string $type ): string => "'" . esc_sql( $type ) . "'", Simple_SEO_Fields::post_types() );
		if ( ! $types ) {
			$types = array( "''" );
		}

		list( $join, $language ) = Simple_SEO_WP_Loc::language_sql( 'p.ID', "CONCAT('post_', p.post_type)", $code );

		return "SELECT {$select} FROM {$wpdb->posts} p{$join} WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN (" . implode( ',', $types ) . ')' . $language . $tail;
	}

	/** @return array<int, array{loc: string, lastmod: string, images: string[], alternates: array<string, string>}> */
	private static function author_entries( int $page, string $code ): array {
		$rows = (array) $GLOBALS['wpdb']->get_results( self::author_sql( 'p.post_author, MAX(p.post_modified_gmt) AS modified', $code, ' GROUP BY p.post_author ORDER BY p.post_author ASC' . self::limit( $page ) ) );
		cache_users( array_map( static fn( $row ): int => (int) $row->post_author, $rows ) );

		return (array) Simple_SEO_WP_Loc::in_language(
			$code,
			static function () use ( $rows, $code ): array {
				$entries = array();
				foreach ( $rows as $row ) {
					$user = get_userdata( (int) $row->post_author );
					if ( ! $user instanceof WP_User ) {
						continue;
					}
					$url = Simple_SEO_WP_Loc::language_url( self::indexable_address( Simple_SEO_Context::for_author( $user ), get_author_posts_url( (int) $user->ID ) ), $code );
					if ( '' !== $url ) {
						$entries[] = array(
							'loc'        => $url,
							'lastmod'    => self::w3c( (string) $row->modified ),
							'images'     => array(),
							'alternates' => array(),
						);
					}
				}
				return $entries;
			}
		);
	}

	// ----------------------------------------------------------------------------------------
	// Shared.

	/** A non-default language lists a kind only when wp-loc translates it. */
	private static function has_language( string $code, bool $translatable ): bool {
		return '' === $code || $code === Simple_SEO_WP_Loc::default_language() || $translatable;
	}

	/** The address, when search engines may show the page and it is its own canonical; else ''. */
	private static function indexable_address( Simple_SEO_Context $context, string $address ): string {
		if ( '' === $address || Simple_SEO_Indexing::is_noindex( $context ) ) {
			return '';
		}

		return Simple_SEO_Indexing::canonical( $context ) === $address ? $address : '';
	}

	/**
	 * Addresses of elements worked out in each element's own language (wp-loc gives a term the
	 * link of its translation in the current language).
	 *
	 * @param int[]                $ids
	 * @param callable(int):string $address
	 * @return array<int, string>
	 */
	private static function urls_by_language( array $ids, string $element_type, callable $address ): array {
		$by_language = array();
		$languages   = Simple_SEO_WP_Loc::element_languages( $ids, $element_type );
		foreach ( $ids as $id ) {
			$by_language[ $languages[ $id ] ?? '' ][] = $id;
		}

		$urls = array();
		foreach ( $by_language as $language => $group ) {
			Simple_SEO_WP_Loc::in_language(
				(string) $language,
				static function () use ( $group, $address, &$urls ): void {
					foreach ( $group as $id ) {
						$urls[ $id ] = (string) $address( $id );
					}
				}
			);
		}

		return $urls;
	}

	/**
	 * xhtml:link alternates of one translation group: hreflang => address, and x-default for the
	 * default language; [] when fewer than two languages are listed.
	 *
	 * @param array<string, int|string> $group language code => key into $urls.
	 * @param array<int|string, string> $urls
	 * @return array<string, string>
	 */
	private static function alternates( array $group, array $urls ): array {
		$alternates = array();
		// In the order of the site's languages, whatever order the translations come in.
		foreach ( Simple_SEO_WP_Loc::languages() as $code => $hreflang ) {
			if ( isset( $group[ $code ] ) && '' !== ( $urls[ $group[ $code ] ] ?? '' ) ) {
				$alternates[ $hreflang ] = $urls[ $group[ $code ] ];
			}
		}
		if ( count( $alternates ) < 2 ) {
			return array();
		}

		$default = Simple_SEO_WP_Loc::default_language();
		if ( isset( $group[ $default ] ) && '' !== ( $urls[ $group[ $default ] ] ?? '' ) ) {
			$alternates['x-default'] = $urls[ $group[ $default ] ];
		}

		return $alternates;
	}

	/** The newest lastmod of a sitemap page, for the index; '' when unknown. */
	private static function page_lastmod( string $name, string $kind, int $page, string $code ): string {
		global $wpdb;

		switch ( $kind ) {
			case 'post':
				$gmt    = (string) $wpdb->get_var( 'SELECT MAX(t.post_modified_gmt) FROM (' . self::post_sql( $name, 'p.post_modified_gmt', $code, ' ORDER BY p.ID ASC' . self::limit( $page ) ) . ') t' );
				$latest = self::w3c( $gmt );
				if ( 1 === $page ) {
					foreach ( self::leading_entries( $name, $code ) as $entry ) {
						$latest = max( $latest, $entry['lastmod'] );
					}
				}
				return $latest;
			case 'term':
				$ids      = array_map( 'intval', (array) $wpdb->get_col( self::term_sql( $name, 'tt.term_id', $code, ' ORDER BY tt.term_id ASC' . self::limit( $page ) ) ) );
				$modified = self::terms_modified( $ids, $name );
				return $modified ? max( $modified ) : '';
			case 'author':
				return self::w3c( (string) $wpdb->get_var( 'SELECT MAX(t.modified) FROM (' . self::author_sql( 'MAX(p.post_modified_gmt) AS modified', $code, ' GROUP BY p.post_author ORDER BY p.post_author ASC' . self::limit( $page ) ) . ') t' ) );
		}

		return '';
	}

	/** A GMT MySQL date in W3C format; '' for none. */
	private static function w3c( string $gmt ): string {
		if ( '' === $gmt || str_starts_with( $gmt, '0000' ) ) {
			return '';
		}

		return (string) mysql2date( 'Y-m-d\TH:i:s+00:00', $gmt, false );
	}

	/** An address without the language prefix of the current request (the default language's form). */
	private static function unprefixed( string $url ): string {
		$current = Simple_SEO_WP_Loc::current_language();
		$default = Simple_SEO_WP_Loc::default_language();
		if ( '' === $current || $current === $default ) {
			return $url;
		}

		$home = trailingslashit( (string) apply_filters( 'wpml_home_url', '', $current ) );
		$raw  = trailingslashit( (string) apply_filters( 'wpml_home_url', '', $default ) );

		return str_starts_with( $url, $home ) ? $raw . substr( $url, strlen( $home ) ) : $url;
	}

	/** The XML declaration and the stylesheet of the sitemap's language. */
	private static function xml_head( string $code ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			// The version in the address: browsers keep the stylesheet a day, an update gets a fresh one.
			. '<?xml-stylesheet type="text/css" href="' . esc_xml( add_query_arg( 'ver', SIMPLE_SEO_VERSION, self::url( self::ROUTE_CSS, 1, $code ) ) ) . '"?>' . "\n";
	}

	/**
	 * How a browser shows a sitemap: CSS on the XML itself, with the labels in the language of the
	 * request. Search engines ignore it.
	 */
	public static function render_stylesheet(): string {
		$label = static fn( string $text ): string => '"' . addcslashes( str_replace( array( "\r", "\n" ), ' ', $text ), '"\\' ) . '"';

		$title    = $label( __( 'XML sitemap', 'simple-seo' ) );
		$index    = $label( __( 'The sitemaps of the site, language by language. Search engines read every one of them.', 'simple-seo' ) );
		$list     = $label( __( 'Pages search engines may show, with their translations and images.', 'simple-seo' ) );
		$modified = $label( __( 'Last modified:', 'simple-seo' ) );
		$langs    = $label( __( 'Languages:', 'simple-seo' ) );
		$image    = $label( __( 'Image:', 'simple-seo' ) );
		$total    = $label( __( 'Addresses:', 'simple-seo' ) );

		return <<<CSS
@namespace url(http://www.sitemaps.org/schemas/sitemap/0.9);
@namespace xhtml url(http://www.w3.org/1999/xhtml);
@namespace image url(http://www.google.com/schemas/sitemap-image/1.1);

urlset, sitemapindex { display: flex; flex-direction: column; max-width: 1100px; margin: 0 auto; padding: 24px 16px 40px; font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1d2327; counter-reset: entry; }
urlset::before, sitemapindex::before { order: -2; content: {$title}; margin: 0 0 4px; font-size: 24px; font-weight: 600; line-height: 1.3; }
/* After every entry in the document, so the counter holds their number; shown first. */
urlset::after, sitemapindex::after { order: -1; margin: 0 0 20px; padding: 0 0 16px; border-bottom: 1px solid #dcdcde; color: #50575e; }
urlset::after { content: {$list} " " {$total} " " counter(entry); }
sitemapindex::after { content: {$index} " " {$total} " " counter(entry); }
url, sitemap { display: block; margin: 0 0 8px; padding: 12px 16px 12px 56px; position: relative; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; counter-increment: entry; }
url::before, sitemap::before { content: counter(entry); position: absolute; left: 16px; top: 12px; width: 28px; color: #8c8f94; font-variant-numeric: tabular-nums; }
loc { display: block; color: #2271b1; font-weight: 600; word-break: break-all; }
lastmod { display: block; margin-top: 2px; color: #50575e; font-size: 13px; }
lastmod::before { content: {$modified} " "; }
xhtml|link { display: block; margin-top: 2px; color: #50575e; font-size: 13px; word-break: break-all; }
xhtml|link::before { content: attr(hreflang) " — " attr(href); }
xhtml|link:first-of-type { margin-top: 8px; padding-top: 6px; border-top: 1px dashed #dcdcde; }
xhtml|link:first-of-type::before { content: {$langs} " " attr(hreflang) " — " attr(href); }
image|image { display: block; margin-top: 2px; color: #50575e; font-size: 13px; word-break: break-all; }
image|loc::before { content: {$image} " "; }
@media (prefers-color-scheme: dark) {
	:root { background: #1d2327; }
	urlset, sitemapindex { color: #f0f0f1; }
	url, sitemap { background: #2c3338; border-color: #3c434a; }
	loc { color: #72aee6; }
	lastmod, xhtml|link, image|image, urlset::before, sitemapindex::before, urlset::after, sitemapindex::after { color: #c3c4c7; }
}

CSS;
	}

	private static function send( string $body, string $content_type, bool $cacheable = false ): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
			if ( $cacheable ) {
				header( 'Cache-Control: public, max-age=86400' );
			}
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- XML built from escaped parts.
		exit;
	}

	private static function not_found(): void {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
