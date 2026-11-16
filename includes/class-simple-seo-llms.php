<?php
/**
 * llms.txt (llmstxt.org): a Markdown file at /llms.txt that tells AI assistants and agents what the
 * site is and which of its pages matter — the site name, a summary, details, then sections of
 * links, each with the page's description.
 *
 * Only what search engines may show is listed: the posts and terms the XML sitemap would list.
 * With wp-loc every language has its own file under its prefix (/en/llms.txt), and the file of a
 * language names the others. The text is kept in a transient for a day and dropped whenever
 * content or settings change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Llms {
	/** Switch, sections and the number of links in each: common to all languages. */
	public const OPTION = 'simple_seo_llms';

	/** The summary and the details: kept per language with wp-loc. */
	public const TEXT = 'simple_seo_llms_text';

	public const QUERY_VAR = 'simple_seo_llms';

	/** Transient of the built file, per language code. */
	private const CACHE = 'simple_seo_llms_';

	/** The section an agent may skip (its name is part of the format, not translated). */
	private const OPTIONAL = 'Optional';

	private const MAX_LIMIT = 500;

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'enabled'  => true,
				'sections' => array(),
				'limit'    => 50,
			),
			array( self::class, 'sanitize' ),
			false
		);
		Simple_SEO_Settings::register(
			self::TEXT,
			array(
				'summary' => '',
				'details' => '',
			),
			array( self::class, 'sanitize_text' ),
			false,
			true
		);

		add_action( 'init', array( self::class, 'register_rewrites' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'posts_pre_query', array( self::class, 'skip_main_query' ), 10, 2 );
		// After wp-loc has switched the locale to the request's language (template_redirect 1).
		add_action( 'template_redirect', array( self::class, 'serve' ), 2 );

		// What the file shows changed: build it again on the next request.
		add_action( 'save_post', array( self::class, 'post_changed' ), 10, 2 );
		add_action( 'deleted_post', array( self::class, 'flush' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'meta_changed' ), 10, 3 );
		}
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( self::class, 'option_changed' ) );
		}

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'llms', 'llms.txt', array( self::OPTION, self::TEXT, Simple_SEO_Markdown::OPTION ), array( self::class, 'render_tab' ), 35 );
	}

	/** Whether Simple SEO serves llms.txt: switched on, a public site, no other SEO plugin. */
	public static function enabled(): bool {
		return (bool) Simple_SEO_Settings::value( self::OPTION, 'enabled' )
			&& (bool) get_option( 'blog_public' )
			&& '' === Simple_SEO_Plugin::conflicting_plugin();
	}

	public static function register_rewrites(): void {
		// While another SEO plugin is active, the address is its own.
		if ( '' === Simple_SEO_Plugin::conflicting_plugin() ) {
			add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * The llms.txt request needs no posts from the main query.
	 *
	 * @param WP_Post[]|int[]|null $posts
	 * @return WP_Post[]|int[]|null
	 */
	public static function skip_main_query( $posts, WP_Query $query ) {
		return $query->is_main_query() && '' !== (string) $query->get( self::QUERY_VAR ) ? array() : $posts;
	}

	/** The address of the file of a language (under its prefix with wp-loc). */
	public static function url( string $code = '' ): string {
		// From the bare home address: home_url() carries the prefix of the request's language on the site.
		$home = untrailingslashit( set_url_scheme( (string) get_option( 'home' ) ) );
		$url  = get_option( 'permalink_structure' ) ? $home . '/llms.txt' : add_query_arg( self::QUERY_VAR, '1', $home . '/' );

		return '' !== $code ? Simple_SEO_WP_Loc::language_url( $url, $code ) : $url;
	}

	public static function serve(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) || '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return;
		}

		if ( ! self::enabled() ) {
			global $wp_query;

			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		$text = self::get( Simple_SEO_WP_Loc::current_language() );
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			// For agents, not for search results.
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text, built from cleaned parts.
		exit;
	}

	/** The file of a language: from the cache, or built in that language and kept for a day. */
	public static function get( string $code ): string {
		$cached = get_transient( self::CACHE . self::cache_code( $code ) );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$text = (string) Simple_SEO_WP_Loc::in_language( $code, static fn(): string => self::build( $code ) );
		set_transient( self::CACHE . self::cache_code( $code ), $text, DAY_IN_SECONDS );

		return $text;
	}

	/** Build the file in the current language. */
	public static function build( string $code ): string {
		$lines = array( '# ' . self::line( (string) get_bloginfo( 'name' ) ), '' );

		$summary = self::summary();
		if ( '' !== $summary ) {
			array_push( $lines, '> ' . $summary, '' );
		}

		$details = self::details();
		if ( '' !== $details ) {
			array_push( $lines, $details, '' );
		}

		$others = array();
		foreach ( Simple_SEO_WP_Loc::language_names() as $other => $name ) {
			if ( $other !== $code ) {
				$others[] = '[' . self::escape( $name ) . '](' . self::link( self::url( $other ) ) . ')';
			}
		}
		if ( $others ) {
			array_push( $lines, __( 'Other languages:', 'simple-seo' ) . ' ' . implode( ', ', $others ), '' );
		}

		$limit    = self::limit();
		$optional = array();
		foreach ( self::slots() as $slot => $label ) {
			$where = self::section_of( $slot );
			if ( '' === $where ) {
				continue;
			}

			$items = self::items( $slot, $code, $limit );
			if ( ! $items ) {
				continue;
			}

			if ( 'optional' === $where ) {
				$optional = array_merge( $optional, $items );
				continue;
			}

			array_push( $lines, '## ' . self::line( $label ), '', ...$items );
			$lines[] = '';
		}

		if ( Simple_SEO_Sitemaps::enabled() ) {
			$optional[] = self::item( __( 'XML sitemap', 'simple-seo' ), Simple_SEO_Sitemaps::index_url(), __( 'Every address of the site that search engines may show.', 'simple-seo' ) );
		}
		if ( $optional ) {
			array_push( $lines, '## ' . self::OPTIONAL, '', ...$optional );
		}

		/**
		 * The text of llms.txt in a language (Markdown).
		 *
		 * @param string $text
		 * @param string $code Language code, '' without wp-loc.
		 */
		return (string) apply_filters( 'simple_seo_llms_txt', rtrim( implode( "\n", $lines ) ) . "\n", $code );
	}

	/**
	 * Post types and taxonomies that may have a section, slot => heading.
	 *
	 * @return array<string, string>
	 */
	public static function slots(): array {
		$slots = array();
		// Pages first: they say what the site is; then the posts and other types.
		$types = Simple_SEO_Fields::post_types();
		usort( $types, static fn( string $a, string $b ): int => (int) ( 'page' === $b ) - (int) ( 'page' === $a ) );
		foreach ( $types as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			$object                  = get_post_type_object( $type );
			$slots[ 'post:' . $type ] = match ( $type ) {
				'page'  => __( 'Pages', 'simple-seo' ),
				'post'  => __( 'Posts', 'simple-seo' ),
				default => $object ? (string) $object->labels->name : $type,
			};
		}
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			$object                        = get_taxonomy( $taxonomy );
			$slots[ 'term:' . $taxonomy ] = match ( $taxonomy ) {
				'category' => __( 'Categories', 'simple-seo' ),
				'post_tag' => __( 'Tags', 'simple-seo' ),
				default    => $object ? (string) $object->labels->name : $taxonomy,
			};
		}

		return $slots;
	}

	/** Where a slot goes: 'main', 'optional' or '' (left out). Post types are in by default, taxonomies out. */
	public static function section_of( string $slot ): string {
		$sections = (array) Simple_SEO_Settings::value( self::OPTION, 'sections' );
		if ( isset( $sections[ $slot ] ) ) {
			return (string) $sections[ $slot ];
		}

		return str_starts_with( $slot, 'post:' ) ? 'main' : '';
	}

	public static function limit(): int {
		return max( 1, min( self::MAX_LIMIT, (int) Simple_SEO_Settings::value( self::OPTION, 'limit' ) ) );
	}

	/** The blockquote: the saved summary, else the home page's description, else the tagline. */
	public static function summary(): string {
		foreach ( array(
			static fn(): string => (string) Simple_SEO_Settings::value( self::TEXT, 'summary' ),
			static fn(): string => Simple_SEO_Titles::description( Simple_SEO_Context::for_home() ),
			static fn(): string => (string) get_bloginfo( 'description' ),
		) as $source ) {
			$text = self::line( $source() );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	/** The free text after the summary; the format allows no headings there, so they become plain lines. */
	public static function details(): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) Simple_SEO_Settings::value( self::TEXT, 'details' ) ) );

		return (string) preg_replace( '/^[ \t]{0,3}#{1,6}[ \t]*/m', '', $text );
	}

	/** Drop the cached files of every language. */
	public static function flush(): void {
		foreach ( Simple_SEO_WP_Loc::codes() as $code ) {
			delete_transient( self::CACHE . self::cache_code( $code ) );
		}
	}

	/**
	 * @param mixed $post_id
	 * @param mixed $post
	 */
	public static function post_changed( $post_id, $post ): void {
		// Revisions, autosaves and menu items are types of their own, outside the SEO post types.
		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status
			|| ! in_array( $post->post_type, Simple_SEO_Fields::post_types(), true ) ) {
			return;
		}

		self::flush();
	}

	/**
	 * Our own fields: titles, descriptions and noindex change what the file lists.
	 *
	 * @param mixed $meta_id
	 * @param mixed $object_id
	 * @param mixed $meta_key
	 */
	public static function meta_changed( $meta_id, $object_id, $meta_key ): void {
		if ( is_string( $meta_key ) && str_starts_with( $meta_key, '_simple_seo_' ) ) {
			self::flush();
		}
	}

	/**
	 * Settings of Simple SEO and the site options the file is built from.
	 *
	 * @param mixed $option
	 */
	public static function option_changed( $option ): void {
		$option = (string) $option;
		if ( str_starts_with( $option, 'simple_seo_' ) || in_array( $option, array( 'blogname', 'blogdescription', 'home', 'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'blog_public' ), true ) ) {
			self::flush();
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{enabled: bool, sections: array<string, string>, limit: int}
	 */
	public static function sanitize( array $input ): array {
		$sections = array();
		$given    = isset( $input['sections'] ) && is_array( $input['sections'] ) ? $input['sections'] : array();
		foreach ( array_keys( self::slots() ) as $slot ) {
			if ( isset( $given[ $slot ] ) && in_array( (string) $given[ $slot ], array( 'main', 'optional', '' ), true ) ) {
				$sections[ $slot ] = (string) $given[ $slot ];
			}
		}

		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 50;

		return array(
			'enabled'  => ! isset( $input['enabled'] ) || '1' === (string) $input['enabled'],
			'sections' => $sections,
			'limit'    => max( 1, min( self::MAX_LIMIT, $limit ?: 50 ) ),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{summary: string, details: string}
	 */
	public static function sanitize_text( array $input ): array {
		return array(
			'summary' => trim( sanitize_text_field( (string) ( $input['summary'] ?? '' ) ) ),
			'details' => trim( sanitize_textarea_field( (string) ( $input['details'] ?? '' ) ) ),
		);
	}

	public static function render_tab(): void {
		$settings  = Simple_SEO_Settings::get( self::OPTION );
		$text      = Simple_SEO_Settings::get( self::TEXT );
		$language  = Simple_SEO_WP_Loc::admin_language();
		$code      = Simple_SEO_WP_Loc::admin_language_code();
		$file      = dirname( Simple_SEO_Files::path( Simple_SEO_Files::ROBOTS ) ) . '/llms.txt';
		$file      = is_file( $file ) ? $file : '';
		$address   = self::url( $code );
		$preview   = '';
		if ( self::enabled() ) {
			// As the site serves it: in the admin language, with its locale.
			$locale   = Simple_SEO_WP_Loc::locale_of( $code );
			$switched = '' !== $locale && switch_to_locale( $locale );
			try {
				$preview = self::get( $code );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
		}

		require SIMPLE_SEO_DIR . 'includes/views/tab-llms.php';
	}

	/**
	 * The links of a section.
	 *
	 * @return string[]
	 */
	private static function items( string $slot, string $code, int $limit ): array {
		list( $kind, $name ) = explode( ':', $slot, 2 );

		$items = array();
		if ( 'post' === $kind ) {
			foreach ( Simple_SEO_Sitemaps::posts( $name, $code, $limit ) as $post ) {
				$items[] = self::item( get_the_title( $post ), Simple_SEO_Markdown::link_for_post( $post ), Simple_SEO_Titles::description( Simple_SEO_Context::for_post( $post ) ) );
			}
		} else {
			foreach ( Simple_SEO_Sitemaps::terms( $name, $code, $limit ) as $term ) {
				$items[] = self::item( $term->name, Simple_SEO_Markdown::link_for_term( $term ), Simple_SEO_Titles::description( Simple_SEO_Context::for_term( $term ) ) );
			}
		}

		return $items;
	}

	/** - [Title](address): description */
	private static function item( string $title, string $url, string $notes ): string {
		$title = self::line( $title );
		$notes = self::line( $notes );

		return '- [' . self::escape( '' !== $title ? $title : $url ) . '](' . self::link( $url ) . ')' . ( '' !== $notes ? ': ' . $notes : '' );
	}

	/** One line of plain text: tags, entities and line breaks out. */
	private static function line( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** Brackets in the text of a link would end it. */
	private static function escape( string $text ): string {
		return str_replace( array( '\\', '[', ']' ), array( '\\\\', '\[', '\]' ), $text );
	}

	/** Spaces and parentheses in an address would end the link. */
	private static function link( string $url ): string {
		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), esc_url_raw( $url ) );
	}

	private static function cache_code( string $code ): string {
		return '' !== $code ? sanitize_key( $code ) : 'default';
	}
}
