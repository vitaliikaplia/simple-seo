<?php
/**
 * Schema.org structured data: one JSON-LD @graph in <head> that says what the page is and who
 * stands behind the site.
 *
 * The pieces of the graph, keyed as the filters know them:
 *   website      — the site, with its search;
 *   organization — or person: whom the site represents, with the logo and the profiles elsewhere;
 *   webpage      — the page, of its kind: CollectionPage for archives, ProfilePage for authors,
 *                  SearchResultsPage for search results;
 *   mainimage    — the featured image of a post;
 *   article      — a post (post types of the filter simple_seo_schema_article_types);
 *   author       — the person who wrote the post, or whose archive the page is;
 *   breadcrumb   — the trail of Simple_SEO_Breadcrumbs.
 * The pieces refer to each other by @id. A 404 page gets the site and whom it represents only.
 *
 * Who prints the graph (decisions/schema): in the mode Auto Simple SEO stays silent where the theme
 * switches off the graph of SEO plugins with the filter wpseo_json_ld_output, as themes with a graph
 * of their own do; Always prints regardless, Off never. Yoast's filters of the graph and of its
 * pieces (wpseo_schema_*) are applied by the compatibility layer, Simple_SEO_Yoast_Filters.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Schema {
	public const OPTION = 'simple_seo_schema';

	/** Names of the site and of whom it represents, kept per language with wp-loc. */
	public const NAMES = 'simple_seo_schema_names';

	public const MODES = array( 'auto', 'always', 'off' );

	public const MAX_PROFILES = 20;

	/** @var WeakMap<Simple_SEO_Context, array<int, array<string, mixed>>>|null */
	private static ?WeakMap $graphs = null;

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'mode'        => 'auto',
				'represents'  => 'organization',
				'person'      => 0,
				'logo'        => 0,
				'profiles'    => array(),
				'breadcrumbs' => true,
			),
			array( self::class, 'sanitize' ),
			true
		);
		Simple_SEO_Settings::register(
			self::NAMES,
			array(
				'site_name'      => '',
				'site_alternate' => '',
				'name'           => '',
				'alternate'      => '',
			),
			array( self::class, 'sanitize_names' ),
			true,
			true
		);

		// After the head tags of Simple_SEO_Head (also priority 1, added earlier).
		add_action( 'wp_head', array( self::class, 'output' ), 1 );
		// Before the theme's own filters (10), so a theme still has the last word.
		add_filter( 'builder_breadcrumbs_schema', array( self::class, 'builder_breadcrumbs' ), 5 );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'schema', __( 'Schema', 'simple-seo' ), array( self::OPTION, self::NAMES ), array( self::class, 'render_tab' ), 17 );
	}

	public static function mode(): string {
		$mode = (string) Simple_SEO_Settings::value( self::OPTION, 'mode' );

		return in_array( $mode, self::MODES, true ) ? $mode : 'auto';
	}

	/**
	 * Whether the theme lets an SEO plugin print its graph. Themes with a graph of their own switch
	 * off Yoast's with wpseo_json_ld_output; in the mode Auto Simple SEO heeds it the same way.
	 */
	public static function theme_allows(): bool {
		return (bool) apply_filters( 'wpseo_json_ld_output', true );
	}

	/** Whether the page gets Simple SEO's graph. */
	public static function prints( Simple_SEO_Context $context ): bool {
		if ( '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return false;
		}

		$mode  = self::mode();
		$print = 'always' === $mode || ( 'auto' === $mode && self::theme_allows() );

		/**
		 * Whether Simple SEO prints its Schema graph on a page.
		 *
		 * @param bool               $print
		 * @param Simple_SEO_Context $context What the page shows.
		 */
		return (bool) apply_filters( 'simple_seo_schema_output', $print, $context );
	}

	public static function output(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}

		$context = Simple_SEO_Context::current();
		if ( self::prints( $context ) ) {
			echo self::script( self::graph( $context ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with < and > escaped.
		}
	}

	/**
	 * The graph as a script tag; '' for an empty graph. < and > are escaped inside the JSON, so no
	 * text of a page can close the script.
	 *
	 * @param array<int, array<string, mixed>> $graph
	 */
	public static function script( array $graph ): string {
		if ( ! $graph ) {
			return '';
		}

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
		);

		return is_string( $json ) ? '<script type="application/ld+json" class="simple-seo-schema">' . $json . "</script>\n" : '';
	}

	/**
	 * The graph of a page, after the filters; worked out once per page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function graph( Simple_SEO_Context $context ): array {
		self::$graphs ??= new WeakMap();
		if ( isset( self::$graphs[ $context ] ) ) {
			return self::$graphs[ $context ];
		}

		/**
		 * The pieces of a page's graph by key (website, organization or person, webpage, mainimage,
		 * article, author, breadcrumb); an empty piece is left out.
		 *
		 * @param array<string, array<string, mixed>> $pieces
		 * @param Simple_SEO_Context                   $context What the page shows.
		 */
		$pieces = apply_filters( 'simple_seo_schema_pieces', self::pieces( $context ), $context );
		$graph  = self::nodes( is_array( $pieces ) ? $pieces : array() );

		/**
		 * The graph of a page: its nodes, in order.
		 *
		 * @param array<int, array<string, mixed>> $graph
		 * @param Simple_SEO_Context                $context What the page shows.
		 */
		$graph = apply_filters( 'simple_seo_schema_graph', $graph, $context );
		$graph = self::nodes( is_array( $graph ) ? $graph : array() );

		self::$graphs[ $context ] = $graph;

		return $graph;
	}

	/** Whether the page's graph is printed with a BreadcrumbList. */
	public static function has_breadcrumbs( Simple_SEO_Context $context ): bool {
		if ( ! self::prints( $context ) ) {
			return false;
		}
		foreach ( self::graph( $context ) as $node ) {
			if ( in_array( 'BreadcrumbList', (array) ( $node['@type'] ?? array() ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The theme Builder prints a BreadcrumbList of its own unless an SEO plugin does (Simple SEO is
	 * one: this class exists). Where Simple SEO's graph goes without one, Builder may print its own.
	 *
	 * @param mixed $print
	 * @return mixed
	 */
	public static function builder_breadcrumbs( $print ) {
		if ( is_admin() || ! did_action( 'wp' ) || '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return $print;
		}

		return ! self::has_breadcrumbs( Simple_SEO_Context::current() );
	}

	// ----------------------------------------------------------------------------------------
	// The pieces.

	/**
	 * The pieces of a page's graph before the filters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function pieces( Simple_SEO_Context $context ): array {
		$site      = self::site();
		$publisher = self::publisher();
		$pieces    = array( 'website' => self::website( $publisher ) );
		if ( $publisher ) {
			$pieces[ self::represents() ] = $publisher;
		}

		$url = Simple_SEO_Context::NOT_FOUND === $context->kind ? '' : self::url( $context );
		if ( '' === $url ) {
			return $pieces;
		}

		$page = array(
			'@type'       => self::page_type( $context ),
			'@id'         => $url,
			'url'         => $url,
			'name'        => self::page_name( $context ),
			'description' => Simple_SEO_Titles::description( $context ),
			'isPartOf'    => array( '@id' => $site . '#website' ),
			'inLanguage'  => self::language(),
		);
		if ( $context->is_front_page && $publisher ) {
			$page['about'] = array( '@id' => $publisher['@id'] );
		}

		$post = $context->object instanceof WP_Post ? $context->object : null;
		if ( $post && in_array( $context->kind, array( Simple_SEO_Context::SINGULAR, Simple_SEO_Context::POSTS_PAGE ), true ) ) {
			$page['datePublished'] = (string) get_post_time( 'c', true, $post );
			$page['dateModified']  = (string) get_post_modified_time( 'c', true, $post );
		}

		$image = self::image( $context, $url . '#primaryimage' );
		if ( $image ) {
			$pieces['mainimage']        = $image;
			$page['primaryImageOfPage'] = array( '@id' => $image['@id'] );
			$page['image']              = array( '@id' => $image['@id'] );
			$page['thumbnailUrl']       = $image['url'];
		}

		$author = self::author( $context );
		if ( $author && Simple_SEO_Context::AUTHOR === $context->kind ) {
			$page['mainEntity'] = array( '@id' => $author['@id'] );
		}

		$trail = self::breadcrumb( $context, $url . '#breadcrumb' );
		if ( $trail ) {
			$page['breadcrumb'] = array( '@id' => $trail['@id'] );
		}

		$pieces['webpage'] = self::clean( $page );

		if ( $post && self::is_article( $context ) ) {
			$pieces['article'] = self::article( $post, $url, $publisher, $author, $image );
		}
		// The same person twice is one node: the publisher already is.
		if ( $author && ( ! $publisher || $author['@id'] !== $publisher['@id'] ) ) {
			$pieces['author'] = $author;
		}
		if ( $trail ) {
			$pieces['breadcrumb'] = $trail;
		}

		return $pieces;
	}

	/** The address of the site, with wp-loc in the language of the page. */
	public static function site(): string {
		return home_url( '/' );
	}

	/** organization or person. */
	public static function represents(): string {
		return 'person' === Simple_SEO_Settings::value( self::OPTION, 'represents' ) ? 'person' : 'organization';
	}

	/** The language of the page, as BCP 47 (uk, en-US). */
	public static function language(): string {
		return (string) get_bloginfo( 'language' );
	}

	/**
	 * The site.
	 *
	 * @param array<string, mixed>|null $publisher
	 * @return array<string, mixed>
	 */
	private static function website( ?array $publisher ): array {
		$names = Simple_SEO_Settings::get( self::NAMES );
		$site  = self::site();

		$website = array(
			'@type'           => 'WebSite',
			'@id'             => $site . '#website',
			'url'             => $site,
			'name'            => self::name_or( (string) ( $names['site_name'] ?? '' ), (string) get_option( 'blogname' ) ),
			'alternateName'   => (string) ( $names['site_alternate'] ?? '' ),
			'description'     => Simple_SEO_Variables::plain( (string) get_option( 'blogdescription' ) ),
			'publisher'       => $publisher ? array( '@id' => $publisher['@id'] ) : null,
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => $site . '?s={search_term_string}',
					),
					'query-input' => 'required name=search_term_string',
				),
			),
			'inLanguage'      => self::language(),
		);

		return self::clean( $website );
	}

	/**
	 * Whom the site represents: the organization, or the person chosen in the settings. null when
	 * there is no name for the organization or no such user.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function publisher(): ?array {
		$names    = Simple_SEO_Settings::get( self::NAMES );
		$site     = self::site();
		$logo_id  = self::logo_id();
		$profiles = self::profiles_with_x();

		if ( 'person' === self::represents() ) {
			$user = get_userdata( (int) Simple_SEO_Settings::value( self::OPTION, 'person' ) );
			if ( ! $user instanceof WP_User ) {
				return null;
			}
			$person          = self::person( $user );
			$person['@type'] = array( 'Person', 'Organization' );
			$logo            = $logo_id ? self::image_object( $logo_id, $site . '#logo', (string) $person['name'] ) : null;
			if ( $logo ) {
				$person['logo']  = $logo;
				$person['image'] = array( '@id' => $logo['@id'] );
			}
			$person['sameAs'] = array_values( array_unique( array_merge( (array) ( $person['sameAs'] ?? array() ), $profiles ) ) );

			return self::clean( $person );
		}

		$name = self::name_or( (string) ( $names['name'] ?? '' ), (string) get_option( 'blogname' ) );
		if ( '' === $name ) {
			return null;
		}
		$organization = array(
			'@type'         => 'Organization',
			'@id'           => $site . '#organization',
			'name'          => $name,
			'alternateName' => (string) ( $names['alternate'] ?? '' ),
			'url'           => $site,
			'sameAs'        => $profiles,
		);
		$logo = $logo_id ? self::image_object( $logo_id, $site . '#logo', $name ) : null;
		if ( $logo ) {
			$organization['logo']  = $logo;
			$organization['image'] = array( '@id' => $logo['@id'] );
		}

		return self::clean( $organization );
	}

	/**
	 * A user as a Person: name, the author archive while search engines may see it, the avatar,
	 * the biography and the user's website.
	 *
	 * @return array<string, mixed>
	 */
	public static function person( WP_User $user ): array {
		$id     = self::person_id( $user );
		$name   = Simple_SEO_Variables::plain( (string) $user->display_name );
		$person = array(
			'@type'       => 'Person',
			'@id'         => $id,
			'name'        => $name,
			'url'         => Simple_SEO_Indexing::canonical( Simple_SEO_Context::for_author( $user ) ),
			'description' => Simple_SEO_Variables::plain( (string) $user->description ),
			'sameAs'      => '' !== (string) $user->user_url ? array( esc_url_raw( (string) $user->user_url ) ) : array(),
		);

		$avatar = get_option( 'show_avatars' ) ? (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ) : '';
		if ( '' !== $avatar ) {
			$person['image'] = array(
				'@type'      => 'ImageObject',
				'@id'        => $id . '-image',
				'url'        => $avatar,
				'contentUrl' => $avatar,
				'caption'    => $name,
				'inLanguage' => self::language(),
			);
		}

		return self::clean( $person );
	}

	/** A person's @id: the site's address and a hash of the user, not the login. */
	public static function person_id( WP_User $user ): string {
		return self::site() . '#/schema/person/' . substr( wp_hash( 'simple-seo-person-' . $user->ID ), 0, 16 );
	}

	/**
	 * The logo: the one in the settings, else the theme's custom logo, else the site icon.
	 */
	public static function logo_id(): int {
		foreach ( array( Simple_SEO_Settings::value( self::OPTION, 'logo' ), get_theme_mod( 'custom_logo' ), get_option( 'site_icon' ) ) as $candidate ) {
			$id = Simple_SEO_Fields::image_attachment( $candidate );
			if ( $id ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * The profiles of the settings and the X account of the social settings, as addresses.
	 *
	 * @return string[]
	 */
	public static function profiles_with_x(): array {
		$profiles = (array) Simple_SEO_Settings::value( self::OPTION, 'profiles' );
		$account  = (string) Simple_SEO_Settings::value( Simple_SEO_Social::OPTION, 'x_account' );
		if ( '' !== $account ) {
			$profiles[] = 'https://x.com/' . ltrim( $account, '@' );
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $profiles ) ) ) );
	}

	/** The address of the page the graph describes; '' for pages without one (unpublished posts). */
	private static function url( Simple_SEO_Context $context ): string {
		if ( Simple_SEO_Context::SEARCH === $context->kind ) {
			return (string) get_search_link( $context->search );
		}

		$url = Simple_SEO_Indexing::canonical( $context );

		return '' !== $url ? $url : Simple_SEO_Indexing::own_address( $context );
	}

	/** @return string|string[] */
	private static function page_type( Simple_SEO_Context $context ) {
		switch ( $context->kind ) {
			case Simple_SEO_Context::HOME:
			case Simple_SEO_Context::POSTS_PAGE:
			case Simple_SEO_Context::TERM:
			case Simple_SEO_Context::POST_TYPE_ARCHIVE:
			case Simple_SEO_Context::DATE:
				return 'CollectionPage';
			case Simple_SEO_Context::AUTHOR:
				return 'ProfilePage';
			case Simple_SEO_Context::SEARCH:
				return array( 'CollectionPage', 'SearchResultsPage' );
		}

		return 'WebPage';
	}

	private static function page_name( Simple_SEO_Context $context ): string {
		$title = Simple_SEO_Titles::title( $context );

		return '' !== $title ? $title : Simple_SEO_Variables::plain( Simple_SEO_Variables::object_title( $context ) );
	}

	/**
	 * The main image of a post: its featured image, not shown while the post asks for a password.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function image( Simple_SEO_Context $context, string $id ): ?array {
		if ( ! $context->object instanceof WP_Post || $context->password_required ) {
			return null;
		}
		$attachment = Simple_SEO_Fields::image_attachment( get_post_thumbnail_id( $context->object ) );

		return $attachment ? self::image_object( $attachment, $id ) : null;
	}

	/**
	 * An image as an ImageObject; null when it has no address.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function image_object( int $attachment, string $id, string $caption = '' ): ?array {
		$source = wp_get_attachment_image_src( $attachment, 'full' );
		if ( ! is_array( $source ) || empty( $source[0] ) ) {
			return null;
		}
		if ( '' === $caption ) {
			$caption = Simple_SEO_Variables::plain( (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );
		}

		return self::clean(
			array(
				'@type'      => 'ImageObject',
				'@id'        => $id,
				'url'        => (string) $source[0],
				'contentUrl' => (string) $source[0],
				'width'      => (int) ( $source[1] ?? 0 ),
				'height'     => (int) ( $source[2] ?? 0 ),
				'caption'    => $caption,
				'inLanguage' => self::language(),
			)
		);
	}

	/**
	 * The person of the page: the author of an article, or the user whose archive it is.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function author( Simple_SEO_Context $context ): ?array {
		$user = null;
		if ( Simple_SEO_Context::AUTHOR === $context->kind && $context->object instanceof WP_User ) {
			$user = $context->object;
		} elseif ( $context->object instanceof WP_Post && self::is_article( $context ) ) {
			$user = get_userdata( (int) $context->object->post_author );
		}

		return $user instanceof WP_User ? self::person( $user ) : null;
	}

	/** The user behind a piece: the author for author, the chosen person for person; 0 for none. */
	public static function piece_user( string $key, Simple_SEO_Context $context ): int {
		if ( 'person' === $key ) {
			return (int) Simple_SEO_Settings::value( self::OPTION, 'person' );
		}
		if ( 'author' !== $key ) {
			return 0;
		}
		if ( Simple_SEO_Context::AUTHOR === $context->kind ) {
			return $context->object_id;
		}

		return $context->object instanceof WP_Post ? (int) $context->object->post_author : 0;
	}

	/** Whether a page is an article: a post of a type the filter names, not the front page. */
	public static function is_article( Simple_SEO_Context $context ): bool {
		if ( Simple_SEO_Context::SINGULAR !== $context->kind || $context->is_front_page || ! $context->object instanceof WP_Post ) {
			return false;
		}

		/**
		 * The post types whose posts are articles in the graph.
		 *
		 * @param string[] $types
		 */
		$types = (array) apply_filters( 'simple_seo_schema_article_types', array( 'post' ) );

		return in_array( $context->object->post_type, $types, true );
	}

	/**
	 * @param array<string, mixed>|null $publisher
	 * @param array<string, mixed>|null $author
	 * @param array<string, mixed>|null $image
	 * @return array<string, mixed>
	 */
	private static function article( WP_Post $post, string $url, ?array $publisher, ?array $author, ?array $image ): array {
		$article = array(
			'@type'            => 'Article',
			'@id'              => $url . '#article',
			'isPartOf'         => array( '@id' => $url ),
			'mainEntityOfPage' => array( '@id' => $url ),
			'headline'         => Simple_SEO_Variables::plain( (string) $post->post_title ),
			'datePublished'    => (string) get_post_time( 'c', true, $post ),
			'dateModified'     => (string) get_post_modified_time( 'c', true, $post ),
			'author'           => $author ? array(
				'@id'  => $author['@id'],
				'name' => $author['name'] ?? '',
			) : null,
			'publisher'        => $publisher ? array( '@id' => $publisher['@id'] ) : null,
			'image'            => $image ? array( '@id' => $image['@id'] ) : null,
			'thumbnailUrl'     => $image['url'] ?? '',
			'articleSection'   => self::term_names( $post, 'category' ),
			'keywords'         => self::term_names( $post, 'post_tag' ),
			'inLanguage'       => self::language(),
		);

		return self::clean( $article );
	}

	/** @return string[] */
	private static function term_names( WP_Post $post, string $taxonomy ): array {
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $post, $taxonomy );

		return is_array( $terms ) ? array_values( array_filter( array_map( static fn( WP_Term $term ): string => Simple_SEO_Variables::plain( $term->name ), $terms ) ) ) : array();
	}

	/**
	 * The breadcrumb trail as a BreadcrumbList; null on the front page, with one item, or when the
	 * settings leave it out.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function breadcrumb( Simple_SEO_Context $context, string $id ): ?array {
		if ( $context->is_front_page || empty( Simple_SEO_Settings::value( self::OPTION, 'breadcrumbs' ) ) ) {
			return null;
		}
		$items = Simple_SEO_Breadcrumbs::items( $context );
		if ( count( $items ) < 2 ) {
			return null;
		}

		$list = array();
		foreach ( $items as $index => $item ) {
			$list[] = self::clean(
				array(
					'@type'    => 'ListItem',
					'position' => $index + 1,
					'name'     => $item['label'],
					'item'     => $item['url'],
				)
			);
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $id,
			'itemListElement' => $list,
		);
	}

	private static function name_or( string $name, string $fallback ): string {
		$name = Simple_SEO_Variables::plain( $name );

		return '' !== $name ? $name : Simple_SEO_Variables::plain( $fallback );
	}

	/**
	 * A node without empty values: '', null and empty lists go, nested arrays are cleaned too.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function clean( array $node ): array {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::clean( $value );
			}
			if ( '' === $value || null === $value || array() === $value || 0 === $value ) {
				unset( $node[ $key ] );
				continue;
			}
			$node[ $key ] = $value;
		}

		return $node;
	}

	/**
	 * The non-empty arrays of a list, renumbered.
	 *
	 * @param array<mixed> $nodes
	 * @return array<int, array<string, mixed>>
	 */
	private static function nodes( array $nodes ): array {
		return array_values( array_filter( $nodes, static fn( $node ): bool => is_array( $node ) && array() !== $node ) );
	}

	// ----------------------------------------------------------------------------------------
	// Settings.

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$mode       = (string) ( $input['mode'] ?? '' );
		$represents = (string) ( $input['represents'] ?? '' );
		$person     = absint( $input['person'] ?? 0 );

		return array(
			'mode'        => in_array( $mode, self::MODES, true ) ? $mode : 'auto',
			'represents'  => 'person' === $represents ? 'person' : 'organization',
			'person'      => $person && get_userdata( $person ) ? $person : 0,
			'logo'        => Simple_SEO_Fields::image_attachment( $input['logo'] ?? 0 ),
			'profiles'    => self::sanitize_profiles( $input['profiles'] ?? array() ),
			'breadcrumbs' => ! isset( $input['breadcrumbs'] ) || '1' === (string) $input['breadcrumbs'],
		);
	}

	/**
	 * Addresses of the profiles elsewhere, from a list or from text with one per line: whole
	 * http(s) addresses whose host is words with dots, nothing else (esc_url_raw() would make
	 * "not a link" an address), each once, twenty at most.
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	public static function sanitize_profiles( $value ): array {
		$lines    = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', (string) ( is_scalar( $value ) ? $value : '' ) );
		$profiles = array();
		foreach ( (array) $lines as $line ) {
			$line = is_scalar( $line ) ? trim( (string) $line ) : '';
			// Not parse_url(): under a UTF-8 locale it turns the bytes of a Cyrillic host into "_".
			if ( ! preg_match( '#^https?://[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)+(?::\d+)?(?:[/?\#]\S*)?$#iu', $line ) ) {
				continue;
			}
			$url = esc_url_raw( $line, array( 'http', 'https' ) );
			if ( '' !== $url && ! in_array( $url, $profiles, true ) ) {
				$profiles[] = $url;
			}
		}

		return array_slice( $profiles, 0, self::MAX_PROFILES );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, string>
	 */
	public static function sanitize_names( array $input ): array {
		$clean = array();
		foreach ( array_keys( Simple_SEO_Settings::defaults( self::NAMES ) ) as $key ) {
			$value         = $input[ $key ] ?? '';
			$clean[ $key ] = mb_substr( trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) ), 0, 200 );
		}

		return $clean;
	}

	public static function render_tab(): void {
		$settings = Simple_SEO_Settings::get( self::OPTION );
		$names    = Simple_SEO_Settings::get( self::NAMES );
		$language = Simple_SEO_WP_Loc::admin_language();
		$example  = self::graph( Simple_SEO_Context::for_home() );

		require SIMPLE_SEO_DIR . 'includes/views/tab-schema.php';
	}
}
