<?php
/**
 * Markdown versions of pages (llmstxt.org, version 2): the page an agent reads instead of the HTML,
 * at the page's own address with index.md added (/about/ → /about/index.md, the home page →
 * /index.md; an address with a file name gets .md: /about.html → /about.html.md). /about.md is
 * understood too. Without pretty permalinks: ?simple_seo_markdown=1.
 *
 * Off until it is switched on. Then only what search engines may show has one: published, without
 * a password, not hidden from search engines — posts of every type with SEO fields, the home page,
 * the lists of latest posts, terms and post type archives. Each kind of page (the slots: post:{type},
 * archive:{type}, term:{taxonomy}) is switched on or off with its parts — the breadcrumbs, the
 * description, the details (address, dates, author, terms; price and stock of products) and the
 * image; a post or a term may say otherwise with its own field.
 *
 * HTML pages that have a Markdown version name it with <link rel="alternate" type="text/markdown">
 * and the llms.txt of their language with rel="describedby", in the head and in the Link header.
 * llms.txt links to the Markdown versions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Markdown {
	/** The switch and the slots: common to all languages, read on every page (the head links). */
	public const OPTION = 'simple_seo_markdown';

	/** The Markdown version of a page without pretty permalinks. */
	public const QUERY_VAR = 'simple_seo_markdown';

	/** Parts a slot may leave out; the title and the text are always there. */
	public const PARTS = array( 'breadcrumbs', 'description', 'meta', 'image' );

	/** Parts of the pages of posts only: archives have neither details nor an image. */
	private const POST_PARTS = array( 'meta', 'image' );

	/**
	 * The request as it came (REQUEST_URI, PATH_INFO), while WordPress parses the address of the
	 * page instead.
	 *
	 * @var array<string, string>
	 */
	private static array $request = array();

	/** Whether the request asks for a Markdown version. */
	private static bool $requested = false;

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'enabled' => false,
				'slots'   => array(),
			),
			array( self::class, 'sanitize' ),
			true
		);

		add_action( 'init', array( self::class, 'register_fields' ), 5 );
		// Before WordPress reads the address: it parses the page's own one.
		add_filter( 'do_parse_request', array( self::class, 'parse' ), 1 );
		add_action( 'parse_request', array( self::class, 'restore' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		// After wp-loc has switched the locale to the request's language (template_redirect 1).
		add_action( 'template_redirect', array( self::class, 'serve' ), 2 );
		// Where core sends its own Link headers.
		add_action( 'template_redirect', array( self::class, 'link_header' ), 11 );
		Simple_SEO_Head::add_provider( 'markdown', array( self::class, 'head_tags' ), 90 );
	}

	/** The field of a post or a term, while the Markdown versions are on. */
	public static function register_fields(): void {
		if ( ! Simple_SEO_Settings::value( self::OPTION, 'enabled' ) ) {
			return;
		}

		Simple_SEO_Fields::register(
			'markdown',
			array(
				'label'   => __( 'Markdown version', 'simple-seo' ),
				'type'    => 'select',
				'level'   => Simple_SEO_Fields::LEVEL_ADVANCED,
				'options' => array(
					''    => __( 'As set for this type of page', 'simple-seo' ),
					'on'  => __( 'Serve one', 'simple-seo' ),
					'off' => __( 'Do not serve one', 'simple-seo' ),
				),
				'order'   => 90,
				'group'   => 'indexing',
				'preview' => static function ( string $type, $object ): string {
					$context = 'term' === $type && $object instanceof WP_Term ? Simple_SEO_Context::for_term( $object ) : Simple_SEO_Context::for_post( $object );

					return self::available( $context ) ? self::url( $context ) : __( 'none', 'simple-seo' );
				},
			)
		);
	}

	/** Whether Simple SEO serves Markdown versions: switched on, a public site, no other SEO plugin. */
	public static function enabled(): bool {
		return (bool) Simple_SEO_Settings::value( self::OPTION, 'enabled' )
			&& (bool) get_option( 'blog_public' )
			&& '' === Simple_SEO_Plugin::conflicting_plugin();
	}

	/** Whether the current request asks for a Markdown version. */
	public static function requested(): bool {
		return self::$requested || '' !== (string) get_query_var( self::QUERY_VAR );
	}

	/**
	 * The address of a page the address of a Markdown version stands for; null when it is none.
	 * The query string stays.
	 */
	public static function page_address( string $uri ): ?string {
		$position = strpos( $uri, '?' );
		$path     = false === $position ? $uri : substr( $uri, 0, $position );
		$query    = false === $position ? '' : substr( $uri, $position );

		if ( str_ends_with( $path, '/index.html.md' ) ) {
			$page = substr( $path, 0, -strlen( 'index.html.md' ) );
		} elseif ( str_ends_with( $path, '/index.md' ) ) {
			$page = substr( $path, 0, -strlen( 'index.md' ) );
		} elseif ( str_ends_with( $path, '.md' ) && ! str_ends_with( $path, '/.md' ) ) {
			$page = substr( $path, 0, -3 );
		} else {
			return null;
		}

		return $page . $query;
	}

	/**
	 * A request for a Markdown version: WordPress parses the page's address instead.
	 *
	 * @param mixed $parse
	 * @return mixed
	 */
	public static function parse( $parse ) {
		if ( ! $parse || is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) || ! self::enabled() ) {
			return $parse;
		}

		// As WordPress itself reads it (slashed): only the end of the path changes.
		$uri  = (string) $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed by WordPress next.
		$page = self::page_address( $uri );
		if ( null === $page ) {
			return $parse;
		}

		self::$requested              = true;
		self::$request['REQUEST_URI'] = $uri;
		$_SERVER['REQUEST_URI']       = $page;

		// Some servers pass the path as PATH_INFO too, and WordPress then reads that one.
		$info = isset( $_SERVER['PATH_INFO'] ) ? (string) $_SERVER['PATH_INFO'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed by WordPress next.
		$path = '' !== $info ? self::page_address( $info ) : null;
		if ( null !== $path ) {
			self::$request['PATH_INFO'] = $info;
			$_SERVER['PATH_INFO']       = $path;
		}

		return $parse;
	}

	/** The request's own address again, once WordPress has parsed the page's. */
	public static function restore(): void {
		foreach ( self::$request as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
		self::$request = array();
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
	 * The slots, slot => label: post:{type} (pages first), archive:{type} (the latest posts and the
	 * archives of other types), term:{taxonomy}.
	 *
	 * @return array<string, string>
	 */
	public static function slots(): array {
		$slots = array();
		$types = Simple_SEO_Fields::post_types();
		usort( $types, static fn( string $a, string $b ): int => (int) ( 'page' === $b ) - (int) ( 'page' === $a ) );

		foreach ( $types as $type ) {
			$object                   = get_post_type_object( $type );
			$slots[ 'post:' . $type ] = $object ? (string) $object->labels->name : $type;
		}
		foreach ( $types as $type ) {
			if ( self::known_slot( 'archive:' . $type ) ) {
				$object                      = get_post_type_object( $type );
				$slots[ 'archive:' . $type ] = 'post' === $type
					? __( 'Latest posts', 'simple-seo' )
					/* translators: %s: post type name, e.g. Products. */
					: sprintf( __( 'Archive of %s', 'simple-seo' ), $object ? (string) $object->labels->name : $type );
			}
		}
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			$object                       = get_taxonomy( $taxonomy );
			$slots[ 'term:' . $taxonomy ] = $object ? (string) $object->labels->name : $taxonomy;
		}

		return $slots;
	}

	/** Whether a slot is one the site has. */
	public static function known_slot( string $slot ): bool {
		list( $kind, $name ) = array_pad( explode( ':', $slot, 2 ), 2, '' );

		switch ( $kind ) {
			case 'post':
				return in_array( $name, Simple_SEO_Fields::post_types(), true );
			case 'term':
				return in_array( $name, Simple_SEO_Fields::taxonomies(), true );
			case 'archive':
				if ( 'post' === $name ) {
					// The latest posts: on the home page or on the posts page.
					return 'page' !== get_option( 'show_on_front' ) || (int) get_option( 'page_for_posts' ) > 0;
				}
				$object = get_post_type_object( $name );

				return in_array( $name, Simple_SEO_Fields::post_types(), true ) && $object instanceof WP_Post_Type && $object->has_archive;
		}

		return false;
	}

	/** The slot of a page; '' for the kinds of pages that have no Markdown version. */
	public static function slot_of( Simple_SEO_Context $context ): string {
		switch ( $context->kind ) {
			case Simple_SEO_Context::SINGULAR:
				return 'post:' . $context->subtype;
			case Simple_SEO_Context::HOME:
			case Simple_SEO_Context::POSTS_PAGE:
				return 'archive:post';
			case Simple_SEO_Context::POST_TYPE_ARCHIVE:
				return 'archive:' . $context->subtype;
			case Simple_SEO_Context::TERM:
				return 'term:' . $context->subtype;
		}

		return '';
	}

	/** Whether a slot has Markdown versions (it has, until it is switched off). */
	public static function slot_on( string $slot ): bool {
		$slots = (array) Simple_SEO_Settings::value( self::OPTION, 'slots' );

		return ! isset( $slots[ $slot ]['on'] ) || (bool) $slots[ $slot ]['on'];
	}

	/** Whether the Markdown versions of a slot show a part (they do, until it is switched off). */
	public static function part_on( string $slot, string $part ): bool {
		if ( in_array( $part, self::POST_PARTS, true ) && ! str_starts_with( $slot, 'post:' ) ) {
			return false;
		}

		$slots = (array) Simple_SEO_Settings::value( self::OPTION, 'slots' );

		return ! isset( $slots[ $slot ][ $part ] ) || (bool) $slots[ $slot ][ $part ];
	}

	/** Whether a page has a Markdown version. */
	public static function available( Simple_SEO_Context $context ): bool {
		if ( ! self::enabled() ) {
			return false;
		}

		$slot = self::slot_of( $context );
		if ( ! self::known_slot( $slot ) || ! self::is_public( $context ) || Simple_SEO_Indexing::is_noindex( $context ) ) {
			return false;
		}

		// A page that says it repeats another one is not in the sitemap either.
		$canonical = $context->field( 'canonical' );
		if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( Simple_SEO_Indexing::own_address( $context ) ) ) {
			return false;
		}

		$own       = $context->field( 'markdown' );
		$available = 'on' === $own || ( 'off' !== $own && self::slot_on( $slot ) );

		/**
		 * Whether a page has a Markdown version. Only pages search engines may show can have one.
		 *
		 * @param bool               $available
		 * @param Simple_SEO_Context $context
		 */
		return (bool) apply_filters( 'simple_seo_markdown_available', $available, $context );
	}

	/** The address of a page's Markdown version; '' when it has none. */
	public static function url( Simple_SEO_Context $context ): string {
		return self::available( $context ) ? self::address( Simple_SEO_Indexing::own_address( $context ) ) : '';
	}

	/** The address of the Markdown version that goes with the address of a page. */
	public static function address( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, '1', $url );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( '' === $path || str_ends_with( $path, '/' ) ) {
			$path .= '' === $path ? '/index.md' : 'index.md';
		} elseif ( preg_match( '/\.[A-Za-z0-9]{1,5}$/', $path ) ) {
			// A file name: page.html → page.html.md.
			$path .= '.md';
		} else {
			$path .= '/index.md';
		}

		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
			. $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/** Where a link to a post goes: its Markdown version, else the page itself. */
	public static function link_for_post( WP_Post $post ): string {
		$url = (string) get_permalink( $post );

		return self::available( Simple_SEO_Context::for_post( $post ) ) ? self::address( $url ) : $url;
	}

	/** Where a link to a term goes: its Markdown version, else the page itself. */
	public static function link_for_term( WP_Term $term ): string {
		$link = get_term_link( $term );
		$url  = is_string( $link ) ? $link : '';

		return self::available( Simple_SEO_Context::for_term( $term ) ) ? self::address( $url ) : $url;
	}

	public static function serve(): void {
		if ( ! self::requested() || ! self::enabled() ) {
			return;
		}

		$context = Simple_SEO_Context::current();
		if ( ! self::available( $context ) ) {
			if ( ! headers_sent() ) {
				status_header( 404 );
				nocache_headers();
				header( 'Content-Type: text/plain; charset=UTF-8' );
			}
			echo "Not found\n";
			exit;
		}

		$text = self::build( $context );
		if ( ! headers_sent() ) {
			status_header( 200 );
			foreach ( self::headers( $context ) as $header ) {
				// Its own type instead of the page's; Link headers others sent stay.
				header( $header, ! str_starts_with( $header, 'Link:' ) );
			}
		}
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput -- Markdown, built from cleaned parts.
		exit;
	}

	/**
	 * The headers of a Markdown version: its type, not for search results (the page itself is),
	 * the page as its canonical address, the llms.txt that covers it, and when it last changed.
	 *
	 * @return string[]
	 */
	public static function headers( Simple_SEO_Context $context ): array {
		$links = array( '<' . esc_url_raw( Simple_SEO_Indexing::own_address( $context ) ) . '>; rel="canonical"' );
		if ( Simple_SEO_Llms::enabled() ) {
			$links[] = '<' . esc_url_raw( Simple_SEO_Llms::url( Simple_SEO_WP_Loc::current_language() ) ) . '>; rel="describedby"';
		}

		$headers = array(
			'Content-Type: text/markdown; charset=UTF-8',
			'X-Robots-Tag: noindex, follow',
			'Link: ' . implode( ', ', $links ),
		);

		if ( Simple_SEO_Context::SINGULAR === $context->kind && $context->object instanceof WP_Post ) {
			$modified = strtotime( $context->object->post_modified_gmt . ' UTC' );
			if ( false !== $modified && $modified > 0 ) {
				$headers[] = 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT';
			}
		}

		return $headers;
	}

	/**
	 * The head tags of an HTML page: its Markdown version and the llms.txt that covers it.
	 *
	 * @return array<string, array{tag: string, attributes: array<string, string>}>
	 */
	public static function head_tags( Simple_SEO_Context $context ): array {
		// Pages search engines do not show are no part of llms.txt either.
		if ( ! self::enabled() || '' === Simple_SEO_Titles::slot_for( $context ) || Simple_SEO_Indexing::is_noindex( $context ) ) {
			return array();
		}

		$tags = array();
		$url  = self::url( $context );
		if ( '' !== $url ) {
			$tags['markdown_alternate'] = array(
				'tag'        => 'link',
				'attributes' => array(
					'rel'  => 'alternate',
					'type' => 'text/markdown',
					'href' => $url,
				),
			);
		}
		if ( Simple_SEO_Llms::enabled() ) {
			$tags['llms_describedby'] = array(
				'tag'        => 'link',
				'attributes' => array(
					'rel'  => 'describedby',
					'href' => Simple_SEO_Llms::url( Simple_SEO_WP_Loc::current_language() ),
				),
			);
		}

		return $tags;
	}

	/** The same links in the Link header of the HTML page, for clients that do not read it. */
	public static function link_header(): void {
		if ( headers_sent() || is_admin() || is_feed() || is_robots() || is_trackback() || is_embed() || ! self::enabled() ) {
			return;
		}

		$value = self::link_value( self::head_tags( Simple_SEO_Context::current() ) );
		if ( '' !== $value ) {
			header( 'Link: ' . $value, false );
		}
	}

	/**
	 * Head tags as the value of a Link header.
	 *
	 * @param array<string, array{tag: string, attributes: array<string, string>}> $tags
	 */
	public static function link_value( array $tags ): string {
		$links = array();
		foreach ( $tags as $tag ) {
			$attributes = $tag['attributes'];
			$link       = '<' . esc_url_raw( $attributes['href'] ) . '>; rel="' . $attributes['rel'] . '"';
			if ( isset( $attributes['type'] ) ) {
				$link .= '; type="' . $attributes['type'] . '"';
			}
			$links[] = $link;
		}

		return implode( ', ', $links );
	}

	/**
	 * The Markdown version of a page.
	 *
	 * @param WP_Post[]|null $posts The posts an archive lists: those of the main query when null.
	 */
	public static function build( Simple_SEO_Context $context, ?array $posts = null ): string {
		$slot   = self::slot_of( $context );
		$blocks = array();

		if ( self::part_on( $slot, 'breadcrumbs' ) ) {
			$blocks[] = self::trail( $context );
		}

		$title    = Simple_SEO_Variables::plain( Simple_SEO_Variables::object_title( $context ) );
		$blocks[] = '# ' . ( '' !== $title ? self::heading( $title ) : Simple_SEO_HTML_Markdown::escape( Simple_SEO_Indexing::own_address( $context ) ) );

		$post = Simple_SEO_Context::SINGULAR === $context->kind && $context->object instanceof WP_Post ? $context->object : null;
		$html = $post ? self::html( $post ) : self::archive_html( $context );

		if ( self::part_on( $slot, 'description' ) ) {
			$description = Simple_SEO_Variables::plain( Simple_SEO_Titles::description( $context ) );
			// A description made of the first words of the text would only repeat them.
			if ( '' !== $description && ! self::repeats( $description, $html ) ) {
				$blocks[] = '> ' . Simple_SEO_HTML_Markdown::escape( $description );
			}
		}

		$base = '' !== Simple_SEO_Indexing::own_address( $context ) ? Simple_SEO_Indexing::own_address( $context ) : home_url( '/' );
		if ( $post ) {
			if ( self::part_on( $slot, 'meta' ) ) {
				$blocks[] = self::details( $context, $post );
			}
			if ( self::part_on( $slot, 'image' ) ) {
				$blocks[] = self::image( $post );
			}

			/**
			 * The text of a post in its Markdown version.
			 *
			 * @param string  $markdown
			 * @param WP_Post $post
			 */
			$blocks[] = (string) apply_filters( 'simple_seo_markdown_content', Simple_SEO_HTML_Markdown::convert( $html, $base ), $post );
		} else {
			if ( null === $posts ) {
				global $wp_query;
				$posts = $wp_query instanceof WP_Query ? (array) $wp_query->posts : array();
			}

			$blocks[] = Simple_SEO_HTML_Markdown::convert( $html, $base );
			$blocks[] = self::listing( $posts );
			$blocks[] = self::pagination( $context );
		}

		$text = implode( "\n\n", array_filter( array_map( 'trim', $blocks ), static fn( string $block ): bool => '' !== $block ) ) . "\n";

		/**
		 * The Markdown version of a page.
		 *
		 * @param string             $text
		 * @param Simple_SEO_Context $context
		 */
		return (string) apply_filters( 'simple_seo_markdown', $text, $context );
	}

	/**
	 * The text of a post as the site renders its blocks, without menus, forms, lists of other posts
	 * and the like — but with blocks of code, which the analysis of the text leaves out.
	 */
	public static function html( WP_Post $post ): string {
		/**
		 * Blocks left out of the Markdown versions: menus, forms, lists of other posts and the like.
		 *
		 * @param string[] $blocks Block names.
		 */
		$skip = (array) apply_filters( 'simple_seo_markdown_skip_blocks', array_values( array_diff( Simple_SEO_Content::SKIP_BLOCKS, array( 'core/code' ) ) ) );

		return Simple_SEO_Content::render( $post, (string) $post->post_content, $skip );
	}

	/** Whether a description only repeats the first words of the text (the {excerpt} of a template). */
	public static function repeats( string $description, string $html ): bool {
		$start = (string) preg_replace( '/[\s.…]+$/u', '', $description );

		return '' !== $start && str_starts_with( Simple_SEO_Variables::plain( $html ), $start );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{enabled: bool, slots: array<string, array<string, bool>>}
	 */
	public static function sanitize( array $input ): array {
		$given = isset( $input['slots'] ) && is_array( $input['slots'] ) ? $input['slots'] : array();
		$slots = array();
		foreach ( array_keys( self::slots() ) as $slot ) {
			$row   = (array) ( $given[ $slot ] ?? array() );
			$clean = array();
			// The form sends '0' or '1'; a saved row (sanitized again when it is written) has true or false.
			foreach ( array_merge( array( 'on' ), self::PARTS ) as $key ) {
				$clean[ $key ] = ! array_key_exists( $key, $row ) || self::ticked( $row[ $key ] );
			}
			$slots[ $slot ] = $clean;
		}

		return array(
			'enabled' => isset( $input['enabled'] ) && self::ticked( $input['enabled'] ),
			'slots'   => $slots,
		);
	}

	/**
	 * Whether a box came ticked: '1' from the form, true from a saved row.
	 *
	 * @param mixed $value
	 */
	private static function ticked( $value ): bool {
		return true === $value || '1' === $value || 1 === $value;
	}

	/** The section of the llms.txt tab. */
	public static function render_section(): void {
		$settings = Simple_SEO_Settings::get( self::OPTION );
		$slots    = self::slots();
		$example  = self::url( Simple_SEO_Context::for_home() );

		require SIMPLE_SEO_DIR . 'includes/views/markdown.php';
	}

	/** The breadcrumbs, the page itself last as text; '' for a trail of one. */
	private static function trail( Simple_SEO_Context $context ): string {
		$items = Simple_SEO_Breadcrumbs::items( $context );
		if ( count( $items ) < 2 ) {
			return '';
		}

		$last  = count( $items ) - 1;
		$parts = array();
		foreach ( $items as $index => $item ) {
			$label   = Simple_SEO_HTML_Markdown::escape( Simple_SEO_Variables::plain( $item['label'] ) );
			$parts[] = $index < $last && '' !== $item['url'] ? '[' . $label . '](' . Simple_SEO_HTML_Markdown::url( $item['url'] ) . ')' : $label;
		}

		return Simple_SEO_HTML_Markdown::line( implode( ' ' . Simple_SEO_Breadcrumbs::separator() . ' ', $parts ) );
	}

	/** A title as the text of a heading. */
	private static function heading( string $title ): string {
		return (string) preg_replace( '/(\s)(#+)$/', '$1\\\\$2', Simple_SEO_HTML_Markdown::escape( $title ) );
	}

	/** The details of a post as a list: its address, dates, author and terms, then what filters add. */
	private static function details( Simple_SEO_Context $context, WP_Post $post ): string {
		$details = array(
			__( 'Address', 'simple-seo' )   => Simple_SEO_HTML_Markdown::url( Simple_SEO_Indexing::own_address( $context ) ),
			__( 'Published', 'simple-seo' ) => (string) get_the_date( 'Y-m-d', $post ),
		);

		$modified = (string) get_the_modified_date( 'Y-m-d', $post );
		if ( '' !== $modified && $modified !== $details[ __( 'Published', 'simple-seo' ) ] ) {
			$details[ __( 'Updated', 'simple-seo' ) ] = $modified;
		}

		// Pages have authors too, but they say nothing about a page.
		if ( 'page' !== $post->post_type && post_type_supports( $post->post_type, 'author' ) ) {
			$author = Simple_SEO_Variables::plain( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) );
			if ( '' !== $author ) {
				$details[ __( 'Author', 'simple-seo' ) ] = Simple_SEO_HTML_Markdown::escape( $author );
			}
		}

		foreach ( array_intersect( get_object_taxonomies( $post->post_type ), Simple_SEO_Fields::taxonomies() ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $terms ) || ! $terms ) {
				continue;
			}

			$links = array();
			foreach ( $terms as $term ) {
				$links[] = '[' . Simple_SEO_HTML_Markdown::escape( Simple_SEO_Variables::plain( $term->name ) ) . '](' . Simple_SEO_HTML_Markdown::url( self::link_for_term( $term ) ) . ')';
			}
			$object = get_taxonomy( $taxonomy );
			$label  = $object ? Simple_SEO_Variables::plain( (string) $object->labels->name ) : $taxonomy;

			$details[ $label ] = implode( ', ', $links );
		}

		/**
		 * The details of a post in its Markdown version, label => Markdown.
		 *
		 * @param array<string, string> $details
		 * @param Simple_SEO_Context    $context
		 */
		$details = (array) apply_filters( 'simple_seo_markdown_meta', $details, $context );

		$lines = array();
		foreach ( $details as $label => $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$lines[] = '- ' . Simple_SEO_HTML_Markdown::escape( (string) $label ) . ': ' . $value;
			}
		}

		return implode( "\n", $lines );
	}

	/** The featured image of a post. */
	private static function image( WP_Post $post ): string {
		$id  = (int) get_post_thumbnail_id( $post );
		$url = $id ? (string) wp_get_attachment_image_url( $id, 'large' ) : '';
		if ( '' === $url ) {
			return '';
		}

		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		return '![' . Simple_SEO_HTML_Markdown::escape( Simple_SEO_Variables::plain( '' !== $alt ? $alt : (string) $post->post_title ) ) . '](' . Simple_SEO_HTML_Markdown::url( $url ) . ')';
	}

	/** What an archive says of itself: a term's description, the text of the page of an archive. */
	private static function archive_html( Simple_SEO_Context $context ): string {
		switch ( $context->kind ) {
			case Simple_SEO_Context::TERM:
				$description = $context->object instanceof WP_Term ? trim( (string) $context->object->description ) : '';

				return '' !== $description ? wpautop( $description ) : '';
			case Simple_SEO_Context::POSTS_PAGE:
			case Simple_SEO_Context::POST_TYPE_ARCHIVE:
				$page = $context->object_id ? get_post( $context->object_id ) : null;
				if ( $page instanceof WP_Post ) {
					return self::html( $page );
				}

				$description = $context->object instanceof WP_Post_Type ? trim( (string) $context->object->description ) : '';

				return '' !== $description ? '<p>' . esc_html( $description ) . '</p>' : '';
		}

		return '';
	}

	/**
	 * The posts an archive lists: links with descriptions under the name of their type.
	 *
	 * @param array<int, mixed> $posts
	 */
	private static function listing( array $posts ): string {
		$posts = array_values( array_filter( $posts, static fn( $post ): bool => $post instanceof WP_Post ) );
		if ( ! $posts ) {
			return '';
		}

		$types  = array_unique( array_map( static fn( WP_Post $post ): string => $post->post_type, $posts ) );
		$object = 1 === count( $types ) ? get_post_type_object( (string) reset( $types ) ) : null;
		$label  = $object && 'post' !== $object->name ? (string) $object->labels->name : __( 'Posts', 'simple-seo' );

		$lines = array( '## ' . Simple_SEO_HTML_Markdown::escape( Simple_SEO_Variables::plain( $label ) ), '' );
		foreach ( $posts as $post ) {
			$title       = Simple_SEO_Variables::plain( get_the_title( $post ) );
			$url         = self::link_for_post( $post );
			$description = Simple_SEO_Variables::plain( Simple_SEO_Titles::description( Simple_SEO_Context::for_post( $post ) ) );
			$lines[]     = '- [' . Simple_SEO_HTML_Markdown::escape( '' !== $title ? $title : $url ) . '](' . Simple_SEO_HTML_Markdown::url( $url ) . ')'
				. ( '' !== $description ? ': ' . Simple_SEO_HTML_Markdown::escape( $description ) : '' );
		}

		return implode( "\n", $lines );
	}

	/** Where an archive of several pages is, and the Markdown versions of the pages next to it. */
	private static function pagination( Simple_SEO_Context $context ): string {
		if ( $context->pages < 2 ) {
			return '';
		}

		/* translators: 1: page number, 2: number of pages. */
		$parts = array( sprintf( __( 'Page %1$d of %2$d', 'simple-seo' ), $context->page, $context->pages ) );
		if ( $context->page > 1 ) {
			$parts[] = '[' . __( 'Previous page', 'simple-seo' ) . '](' . Simple_SEO_HTML_Markdown::url( self::page_url( $context, $context->page - 1 ) ) . ')';
		}
		if ( $context->page < $context->pages ) {
			$parts[] = '[' . __( 'Next page', 'simple-seo' ) . '](' . Simple_SEO_HTML_Markdown::url( self::page_url( $context, $context->page + 1 ) ) . ')';
		}

		return implode( ' · ', $parts );
	}

	/** The Markdown version of another page of an archive. */
	private static function page_url( Simple_SEO_Context $context, int $page ): string {
		$other       = clone $context;
		$other->page = $page;

		return self::address( Simple_SEO_Indexing::own_address( $other ) );
	}

	/** Whether a page is open to everyone: published, without a password, of a public type. */
	private static function is_public( Simple_SEO_Context $context ): bool {
		switch ( $context->kind ) {
			case Simple_SEO_Context::SINGULAR:
				$post = $context->object;

				return $post instanceof WP_Post && 1 === $context->page && '' === (string) $post->post_password && is_post_publicly_viewable( $post );
			case Simple_SEO_Context::POSTS_PAGE:
				return $context->object instanceof WP_Post && '' === (string) $context->object->post_password && is_post_publicly_viewable( $context->object );
			case Simple_SEO_Context::TERM:
				return $context->object instanceof WP_Term && is_taxonomy_viewable( $context->object->taxonomy );
			case Simple_SEO_Context::POST_TYPE_ARCHIVE:
				return '' !== $context->subtype && is_post_type_viewable( $context->subtype );
			case Simple_SEO_Context::HOME:
				return true;
		}

		return false;
	}
}
