<?php
/**
 * Schema.org structured data: one JSON-LD @graph in <head> that says what the page is and who
 * stands behind the site.
 *
 * The pieces of the graph, keyed as the filters know them:
 *   website      — the site, with its search;
 *   organization — or person: whom the site represents, with the logo and the profiles elsewhere;
 *                  an organization is of the type chosen on the tab Local SEO, with its address,
 *                  phone and the rest (Simple_SEO_Local);
 *   webpage      — the page, of its kind: CollectionPage for archives, ProfilePage for authors,
 *                  SearchResultsPage for search results;
 *   mainimage    — the featured image of a post;
 *   article      — a post whose type of article is not None (its own, or its post type's);
 *   author       — the person who wrote the post, or whose archive the page is;
 *   breadcrumb   — the trail of Simple_SEO_Breadcrumbs.
 * The pieces refer to each other by @id. A 404 page gets the site and whom it represents only.
 * A post has a type of page (WebPage, AboutPage, FAQPage, …) and of article, its own or its post
 * type's; questions and answers on it (Simple_SEO_FAQ) make it a FAQPage too. A post or term may
 * have JSON-LD of its own (the field schema_custom): its nodes go last, past the filters, and are
 * printed in every mode — alone where Simple SEO's graph is not.
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

	/** The longest own JSON-LD of a page, in characters. */
	public const MAX_CUSTOM = 50000;

	/** Types of a web page (schema.org's WebPage and its kinds) a post may be. */
	public const PAGE_TYPES = array( 'WebPage', 'AboutPage', 'CheckoutPage', 'CollectionPage', 'ContactPage', 'FAQPage', 'ItemPage', 'MedicalWebPage', 'ProfilePage', 'QAPage', 'RealEstateListing', 'SearchResultsPage' );

	/** Types of an article (schema.org's Article and its kinds) a post may be; None: no article. */
	public const ARTICLE_TYPES = array( 'None', 'Article', 'BlogPosting', 'NewsArticle', 'Report', 'ScholarlyArticle', 'TechArticle', 'SatiricalArticle', 'AdvertiserContentArticle', 'SocialMediaPosting' );

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
				// Post type => its type of page and of article, where it differs from the default.
				'page_types'    => array(),
				'article_types' => array(),
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

		add_action( 'init', array( self::class, 'register_fields' ), 5 );
		// After the head tags of Simple_SEO_Head (also priority 1, added earlier).
		add_action( 'wp_head', array( self::class, 'output' ), 1 );
		// Before the theme's own filters (10), so a theme still has the last word.
		add_filter( 'builder_breadcrumbs_schema', array( self::class, 'builder_breadcrumbs' ), 5 );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	/** The types of page and of article in the SEO block of posts, under Schema. */
	public static function register_fields(): void {
		Simple_SEO_Fields::register_group( 'schema', __( 'Schema', 'simple-seo' ) );
		Simple_SEO_Fields::register(
			'schema_page_type',
			array(
				'label'    => __( 'Type of page', 'simple-seo' ),
				'type'     => 'select',
				'objects'  => array( 'post' ),
				'options'  => static fn( string $type, $object ): array => array( '' => self::default_label( self::page_type_labels(), $object instanceof WP_Post ? self::default_page_type( $object->post_type ) : 'WebPage' ) ) + self::page_type_labels(),
				'sanitize' => static fn( $value ): string => self::known( $value, self::PAGE_TYPES ),
				'order'    => 85,
				'group'    => 'schema',
				'preview'  => static fn( string $type, $object ): string => $object instanceof WP_Post ? self::page_type_now( $object ) : '',
			)
		);
		Simple_SEO_Fields::register(
			'schema_article_type',
			array(
				'label'    => __( 'Type of article', 'simple-seo' ),
				'type'     => 'select',
				'objects'  => array( 'post' ),
				'options'  => static fn( string $type, $object ): array => array( '' => self::default_label( self::article_type_labels(), $object instanceof WP_Post ? self::default_article_type( $object->post_type ) : 'None' ) ) + self::article_type_labels(),
				'sanitize' => static fn( $value ): string => self::known( $value, self::ARTICLE_TYPES ),
				'order'    => 86,
				'group'    => 'schema',
			)
		);
		Simple_SEO_Fields::register(
			'schema_custom',
			array(
				'label'       => __( 'Own JSON-LD', 'simple-seo' ),
				'description' => __( 'Structured data of this page only: a JSON-LD object, a list of them or a document with @graph. It goes into the page\'s graph as written, in every mode of the Schema tab.', 'simple-seo' ),
				'type'        => 'code',
				'objects'     => array( 'post', 'term' ),
				'level'       => Simple_SEO_Fields::LEVEL_ADVANCED,
				'sanitize'    => array( self::class, 'sanitize_custom' ),
				'order'       => 87,
				'group'       => 'schema',
				'preview'     => static fn( string $type, $object ): string => self::custom_now( $type, $object ),
			)
		);
	}

	/**
	 * Own JSON-LD as typed: the text itself, trimmed, so a mistake can be seen and mended; it is
	 * decoded and encoded again when printed. '' when too long.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_custom( $value ): string {
		$value = is_scalar( $value ) ? trim( wp_check_invalid_utf8( (string) $value ) ) : '';

		return mb_strlen( $value ) <= self::MAX_CUSTOM ? $value : '';
	}

	/**
	 * The nodes of own JSON-LD — an object, a list of objects or a document with @graph — each
	 * with its @type and without @context (the graph has one); or why there are none.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, error: string}
	 */
	public static function parse_custom( string $json ): array {
		$json = trim( $json );
		if ( '' === $json ) {
			return array(
				'nodes' => array(),
				'error' => '',
			);
		}

		$data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			/* translators: %s: what is wrong with the JSON, as PHP says it, e.g. Syntax error. */
			$error = sprintf( __( 'Not valid JSON: %s.', 'simple-seo' ), json_last_error_msg() );
		} elseif ( ! is_array( $data ) ) {
			$error = __( 'Not a JSON-LD object: a JSON object or a list of them is needed.', 'simple-seo' );
		}
		if ( isset( $error ) ) {
			return array(
				'nodes' => array(),
				'error' => $error,
			);
		}

		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			$list = $data['@graph'];
		} else {
			$list = array_is_list( $data ) ? $data : array( $data );
		}

		$nodes = array();
		foreach ( $list as $node ) {
			if ( is_array( $node ) && ! array_is_list( $node ) && isset( $node['@type'] ) ) {
				unset( $node['@context'] );
				$nodes[] = $node;
			}
		}

		return array(
			'nodes' => $nodes,
			'error' => $nodes ? '' : __( 'No node with a @type: each object of JSON-LD needs one.', 'simple-seo' ),
		);
	}

	/** The nodes of a page's own JSON-LD; none while the page asks for a password. */
	public static function custom_nodes( Simple_SEO_Context $context ): array {
		if ( $context->password_required ) {
			return array();
		}

		return self::parse_custom( $context->field( 'schema_custom' ) )['nodes'];
	}

	/**
	 * Under the field in the editor: the types of the nodes that go into the graph, or what is wrong.
	 *
	 * @param WP_Post|WP_Term $object
	 */
	private static function custom_now( string $type, $object ): string {
		$value  = 'term' === $type && $object instanceof WP_Term ? Simple_SEO_Fields::term_value( (int) $object->term_id, 'schema_custom' ) : ( $object instanceof WP_Post ? Simple_SEO_Fields::post_value( (int) $object->ID, 'schema_custom' ) : '' );
		$parsed = self::parse_custom( $value );
		if ( '' !== $parsed['error'] ) {
			return $parsed['error'];
		}

		$types = array_map( static fn( array $node ): string => implode( ', ', array_map( 'strval', array_filter( (array) $node['@type'], 'is_scalar' ) ) ), $parsed['nodes'] );

		/* translators: %s: the types of the nodes, e.g. Event, Product. */
		return $types ? sprintf( __( 'nodes of the graph: %s', 'simple-seo' ), implode( '; ', $types ) ) : '';
	}

	/** @return array<string, string> Type => its name. */
	public static function page_type_labels(): array {
		return array(
			'WebPage'           => __( 'Web page', 'simple-seo' ),
			'AboutPage'         => __( 'About page', 'simple-seo' ),
			'CheckoutPage'      => __( 'Checkout page', 'simple-seo' ),
			'CollectionPage'    => __( 'Collection page', 'simple-seo' ),
			'ContactPage'       => __( 'Contact page', 'simple-seo' ),
			'FAQPage'           => __( 'FAQ page', 'simple-seo' ),
			'ItemPage'          => __( 'Item page', 'simple-seo' ),
			'MedicalWebPage'    => __( 'Medical page', 'simple-seo' ),
			'ProfilePage'       => __( 'Profile page', 'simple-seo' ),
			'QAPage'            => __( 'Question and answer page', 'simple-seo' ),
			'RealEstateListing' => __( 'Real estate listing', 'simple-seo' ),
			'SearchResultsPage' => __( 'Search results page', 'simple-seo' ),
		);
	}

	/** @return array<string, string> Type => its name. */
	public static function article_type_labels(): array {
		return array(
			'None'                     => __( 'Not an article', 'simple-seo' ),
			'Article'                  => __( 'Article', 'simple-seo' ),
			'BlogPosting'              => __( 'Blog post', 'simple-seo' ),
			'NewsArticle'              => __( 'News article', 'simple-seo' ),
			'Report'                   => __( 'Report', 'simple-seo' ),
			'ScholarlyArticle'         => __( 'Scholarly article', 'simple-seo' ),
			'TechArticle'              => __( 'Technical article', 'simple-seo' ),
			'SatiricalArticle'         => __( 'Satirical article', 'simple-seo' ),
			'AdvertiserContentArticle' => __( 'Advertiser content', 'simple-seo' ),
			'SocialMediaPosting'       => __( 'Social media post', 'simple-seo' ),
		);
	}

	/** @param array<string, string> $labels */
	private static function default_label( array $labels, string $type ): string {
		/* translators: %s: name of a type of page or article, e.g. Web page. */
		return sprintf( __( 'As set for this post type: %s', 'simple-seo' ), $labels[ $type ] ?? $type );
	}

	/**
	 * A type as its constant spells it ("newsarticle" and "none" as Yoast and others write them
	 * too); '' for an unknown one.
	 *
	 * @param mixed    $value
	 * @param string[] $types
	 */
	public static function known( $value, array $types ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		foreach ( $types as $type ) {
			if ( strtolower( $type ) === $value ) {
				return $type;
			}
		}

		return '';
	}

	/** The type of page of a post type's posts: the settings', else Simple SEO's own. */
	public static function default_page_type( string $post_type ): string {
		$types = (array) Simple_SEO_Settings::value( self::OPTION, 'page_types' );

		return self::known( $types[ $post_type ] ?? '', self::PAGE_TYPES ) ?: self::builtin_page_type( $post_type );
	}

	/** Simple SEO's own type of page of a post type: ItemPage for products (WooCommerce), WebPage for the rest. */
	public static function builtin_page_type( string $post_type ): string {
		return 'product' === $post_type ? 'ItemPage' : 'WebPage';
	}

	/** Simple SEO's own type of article of a post type: Article for posts, None for the rest. */
	public static function builtin_article_type( string $post_type ): string {
		return 'post' === $post_type ? 'Article' : 'None';
	}

	/**
	 * The type of article of a post type's posts: the settings', else Simple SEO's own. The filter
	 * simple_seo_schema_article_types may still add or take out a post type.
	 */
	public static function default_article_type( string $post_type ): string {
		$types = (array) Simple_SEO_Settings::value( self::OPTION, 'article_types' );
		$type  = self::known( $types[ $post_type ] ?? '', self::ARTICLE_TYPES ) ?: self::builtin_article_type( $post_type );

		/**
		 * The post types whose posts are articles, asked for one type at a time.
		 *
		 * @param string[] $types     The post type, when its posts are articles; none when not.
		 * @param string   $post_type The post type asked about.
		 */
		$listed = in_array( $post_type, (array) apply_filters( 'simple_seo_schema_article_types', 'None' !== $type ? array( $post_type ) : array(), $post_type ), true );
		if ( ! $listed ) {
			return 'None';
		}

		return 'None' !== $type ? $type : 'Article';
	}

	/** A post's type of page: its own, else its post type's. */
	public static function post_page_type( WP_Post $post ): string {
		return self::known( Simple_SEO_Fields::post_value( (int) $post->ID, 'schema_page_type' ), self::PAGE_TYPES ) ?: self::default_page_type( $post->post_type );
	}

	/** A post's type of article, None for no article: its own, else its post type's. */
	public static function post_article_type( WP_Post $post ): string {
		return self::known( Simple_SEO_Fields::post_value( (int) $post->ID, 'schema_article_type' ), self::ARTICLE_TYPES ) ?: self::default_article_type( $post->post_type );
	}

	/**
	 * Under the type of page in the editor: the type the page has now, and what Simple_SEO_FAQ
	 * found — the questions, an accordion that could be marked up, or none where FAQ is chosen.
	 */
	public static function page_type_now( WP_Post $post ): string {
		$type   = self::post_page_type( $post );
		$labels = self::page_type_labels();
		$scan   = Simple_SEO_FAQ::scan( $post );
		$faq    = Simple_SEO_FAQ::questions( $post, 'FAQPage' === $type );

		if ( $faq ) {
			$name = 'FAQPage' === $type || 'WebPage' === $type ? $labels['FAQPage'] : $labels[ $type ] . ', ' . $labels['FAQPage'];
			/* translators: 1: type of page, 2: number of questions. */
			return sprintf( __( '%1$s with questions and answers: %2$s', 'simple-seo' ), $name, number_format_i18n( count( $faq ) ) );
		}
		if ( 'FAQPage' === $type ) {
			return __( 'Web page: no questions and answers were found. Add the block Questions and answers, or an accordion, to the page.', 'simple-seo' );
		}
		if ( $scan['accordion'] ) {
			/* translators: 1: type of page, 2: number of items of the accordion. */
			return sprintf( __( '%1$s. The page has an accordion (items: %2$s): choose FAQ page to mark it up as questions and answers.', 'simple-seo' ), $labels[ $type ] ?? $type, number_format_i18n( count( $scan['accordion'] ) ) );
		}

		return $labels[ $type ] ?? $type;
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab(
			'schema',
			__( 'Schema', 'simple-seo' ),
			array( self::OPTION, self::NAMES ),
			array( self::class, 'render_tab' ),
			20,
			'markup',
			static fn(): array => array(
				'general' => __( 'General', 'simple-seo' ),
				'names'   => __( 'Names', 'simple-seo' ),
				'types'   => __( 'Types of pages', 'simple-seo' ),
				'logo'    => __( 'Logo and profiles', 'simple-seo' ),
			)
		);
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
		} elseif ( '' === Simple_SEO_Plugin::conflicting_plugin() ) {
			// The page's own JSON-LD is printed whatever the mode: it is what the editor wrote for it.
			echo self::script( self::custom_nodes( $context ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with < and > escaped.
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

		// The page's own JSON-LD goes last, past the filters: a theme's filter of Yoast's graph
		// (wpseo_schema_graph) does not take out what the editor wrote for the page.
		$graph = array_merge( $graph, self::custom_nodes( $context ) );

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

		$post = $context->object instanceof WP_Post ? $context->object : null;
		$type = self::page_type( $context );
		$faq  = array();
		if ( $post && Simple_SEO_Context::SINGULAR === $context->kind && ! $context->password_required ) {
			$faq = Simple_SEO_FAQ::questions( $post, 'FAQPage' === $type );
		}
		if ( 'FAQPage' === $type && ! $faq ) {
			// FAQPage without questions is no valid FAQ page.
			$type = 'WebPage';
		} elseif ( $faq && 'FAQPage' !== $type ) {
			$type = 'WebPage' === $type ? 'FAQPage' : array_merge( (array) $type, array( 'FAQPage' ) );
		}

		$page = array(
			'@type'       => $type,
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
		if ( $faq ) {
			$page['mainEntity'] = array_map(
				static fn( array $item ): array => array(
					'@type'          => 'Question',
					'name'           => $item['question'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $item['answer'],
					),
				),
				$faq
			);
		}

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

		// Its type, address, phone, hours and the rest of the tab Local SEO.
		return self::clean( Simple_SEO_Local::organization( $organization ) );
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
		if ( Simple_SEO_Context::SINGULAR === $context->kind && $context->object instanceof WP_Post ) {
			return self::post_page_type( $context->object );
		}

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

	/** Whether a page is an article: a post, not the front page, whose type of article is not None. */
	public static function is_article( Simple_SEO_Context $context ): bool {
		return '' !== self::article_type( $context );
	}

	/** The type of article of a page; '' when it is none. */
	public static function article_type( Simple_SEO_Context $context ): string {
		if ( Simple_SEO_Context::SINGULAR !== $context->kind || $context->is_front_page || ! $context->object instanceof WP_Post ) {
			return '';
		}
		$type = self::post_article_type( $context->object );

		return 'None' !== $type ? $type : '';
	}

	/**
	 * @param array<string, mixed>|null $publisher
	 * @param array<string, mixed>|null $author
	 * @param array<string, mixed>|null $image
	 * @return array<string, mixed>
	 */
	private static function article( WP_Post $post, string $url, ?array $publisher, ?array $author, ?array $image ): array {
		$type    = self::post_article_type( $post );
		$article = array(
			'@type'            => 'None' !== $type ? $type : 'Article',
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
			'page_types'    => self::sanitize_types( $input['page_types'] ?? array(), self::PAGE_TYPES, array( self::class, 'builtin_page_type' ) ),
			'article_types' => self::sanitize_types( $input['article_types'] ?? array(), self::ARTICLE_TYPES, array( self::class, 'builtin_article_type' ) ),
		);
	}

	/**
	 * The types of the post types with SEO fields: a known type, kept only where it differs from
	 * the default, so a better default reaches the rest.
	 *
	 * @param mixed    $input
	 * @param string[] $types
	 * @return array<string, string>
	 */
	private static function sanitize_types( $input, array $types, callable $default ): array {
		$clean = array();
		foreach ( (array) $input as $post_type => $type ) {
			$post_type = sanitize_key( (string) $post_type );
			$type      = self::known( $type, $types );
			if ( '' !== $type && post_type_exists( $post_type ) && $type !== $default( $post_type ) ) {
				$clean[ $post_type ] = $type;
			}
		}

		return $clean;
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
		$types    = array();
		foreach ( Simple_SEO_Fields::post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object instanceof WP_Post_Type ) {
				$types[ $post_type ] = (string) $object->labels->name;
			}
		}

		require SIMPLE_SEO_DIR . 'includes/views/tab-schema.php';
	}
}
