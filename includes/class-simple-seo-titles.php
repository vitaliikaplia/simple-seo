<?php
/**
 * SEO title and meta description: templates per kind of page, the separator, the per-post and
 * per-term fields, and their output — <title> through core's document title filters and
 * <meta name="description"> through the head output.
 *
 * A "slot" names the kind of page a template is for: post:{post type}, term:{taxonomy},
 * archive:{post type}, author, date, search, home, 404.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Titles {
	public const OPTION = 'simple_seo_titles';

	/** The separators to choose from. */
	public const SEPARATORS = array( '-', '–', '—', ':', '·', '•', '*', '⋆', '|', '~', '«', '»', '<', '>' );

	public const DEFAULT_SEPARATOR = '–';

	/** @var WeakMap<Simple_SEO_Context, array<string, string>>|null */
	private static ?WeakMap $resolved = null;

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'separator' => self::DEFAULT_SEPARATOR,
				'templates' => array(),
			),
			array( self::class, 'sanitize' ),
			true
		);

		add_action( 'init', array( self::class, 'register_fields' ), 5 );
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 15 );
		add_filter( 'wp_title', array( self::class, 'wp_title' ), 15 );
		Simple_SEO_Head::add_provider( 'description', array( self::class, 'head_tags' ), 10 );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function register_fields(): void {
		Simple_SEO_Fields::register(
			'title',
			array(
				'label'       => __( 'SEO title', 'simple-seo' ),
				'description' => __( 'The title search engines show. Leave empty to use the template; variables like {title}, {sep} and {site} work here too.', 'simple-seo' ),
				'order'       => 10,
				'mask'        => static fn( string $type, $object ): string => self::template( self::slot_for( self::object_context( $type, $object ) ), 'title' ),
				'preview'     => static fn( string $type, $object ): string => self::title( self::object_context( $type, $object ) ),
			)
		);
		Simple_SEO_Fields::register(
			'description',
			array(
				'label'       => __( 'Meta description', 'simple-seo' ),
				'description' => __( 'The text under the title in search results. Leave empty to use the template.', 'simple-seo' ),
				'type'        => 'textarea',
				'order'       => 20,
				'mask'        => static fn( string $type, $object ): string => self::template( self::slot_for( self::object_context( $type, $object ) ), 'description' ),
				'preview'     => static fn( string $type, $object ): string => self::description( self::object_context( $type, $object ) ),
			)
		);
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'titles', __( 'Titles', 'simple-seo' ), array( self::OPTION ), array( self::class, 'render_tab' ), 10 );
	}

	public static function separator(): string {
		$separator = (string) Simple_SEO_Settings::value( self::OPTION, 'separator' );

		return in_array( $separator, self::SEPARATORS, true ) ? $separator : self::DEFAULT_SEPARATOR;
	}

	/** The slot of a page; '' when Simple SEO leaves the page alone (feeds, embeds, …). */
	public static function slot_for( Simple_SEO_Context $context ): string {
		switch ( $context->kind ) {
			case Simple_SEO_Context::HOME:
				return 'home';
			case Simple_SEO_Context::SINGULAR:
				// A static front page is the site's home: the home template, unless its own fields say otherwise.
				return $context->is_front_page ? 'home' : 'post:' . $context->subtype;
			case Simple_SEO_Context::POSTS_PAGE:
				return 'post:page';
			case Simple_SEO_Context::TERM:
				return 'term:' . $context->subtype;
			case Simple_SEO_Context::POST_TYPE_ARCHIVE:
				return 'archive:' . $context->subtype;
			case Simple_SEO_Context::AUTHOR:
				return 'author';
			case Simple_SEO_Context::DATE:
				return 'date';
			case Simple_SEO_Context::SEARCH:
				return 'search';
			case Simple_SEO_Context::NOT_FOUND:
				return '404';
		}

		return '';
	}

	/**
	 * The template of a slot: what is saved, else the plugin's default. A template saved empty stays
	 * empty — the page then keeps the theme's title and gets no description.
	 *
	 * @param string $which title or description.
	 */
	public static function template( string $slot, string $which ): string {
		if ( '' === $slot ) {
			return '';
		}

		$saved = Simple_SEO_Settings::value( self::OPTION, 'templates' );
		if ( is_array( $saved ) && isset( $saved[ $slot ] ) && is_array( $saved[ $slot ] ) && array_key_exists( $which, $saved[ $slot ] ) ) {
			return (string) $saved[ $slot ][ $which ];
		}

		return self::default_template( $slot, $which );
	}

	/** The recommended structure of a slot: the default, and what "Insert structure" puts in. */
	public static function default_template( string $slot, string $which ): string {
		$kind = strtok( $slot, ':' );

		if ( 'title' === $which ) {
			switch ( $kind ) {
				case 'home':
					return '{site} {page} {sep} {tagline}';
				case '404':
					return '{title} {sep} {site}';
				default:
					return '{title} {page} {sep} {site}';
			}
		}

		switch ( $kind ) {
			case 'post':
			case 'term':
			case 'archive':
			case 'author':
				return '{excerpt}';
			case 'home':
				return '{tagline}';
			default:
				return '';
		}
	}

	/** The SEO title of a page, plain text. Filter: simple_seo_title. */
	public static function title( Simple_SEO_Context $context ): string {
		return self::resolve( $context, 'title' );
	}

	/** The meta description of a page, plain text. Filter: simple_seo_description. */
	public static function description( Simple_SEO_Context $context ): string {
		return self::resolve( $context, 'description' );
	}

	private static function resolve( Simple_SEO_Context $context, string $which ): string {
		self::$resolved ??= new WeakMap();
		// One level at a time: reading a WeakMap key it does not hold throws.
		$stored = isset( self::$resolved[ $context ] ) ? self::$resolved[ $context ] : array();
		if ( isset( $stored[ $which ] ) ) {
			return $stored[ $which ];
		}

		$slot = self::slot_for( $context );
		$text = '';
		if ( '' !== $slot ) {
			// The post's or term's own field wins over the template, and may use variables as well.
			$own  = $context->field( $which );
			$text = Simple_SEO_Variables::replace( '' !== $own ? $own : self::template( $slot, $which ), $context, self::separator() );
		}

		/**
		 * Change the SEO title or the meta description of a page (plain text, not escaped).
		 *
		 * @param string             $text    The title or the description; '' leaves the page without ours.
		 * @param Simple_SEO_Context $context What the page shows.
		 */
		$text = (string) apply_filters( "simple_seo_{$which}", $text, $context );

		$stored[ $which ]           = $text;
		self::$resolved[ $context ] = $stored;

		return $text;
	}

	/** <title> of themes with title-tag support (and of wp_get_document_title() anywhere). */
	public static function document_title( $title ) {
		if ( is_admin() || '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return $title;
		}

		$ours = self::title( Simple_SEO_Context::current() );

		// Core returns this value as it is, without escaping it.
		return '' !== $ours ? esc_html( $ours ) : $title;
	}

	/** Old themes that print <title><?php wp_title(); ?></title>. */
	public static function wp_title( $title ) {
		return self::document_title( $title );
	}

	/** @return array<string, array{tag: string, attributes: array<string, string>}> */
	public static function head_tags( Simple_SEO_Context $context ): array {
		$description = self::description( $context );
		if ( '' === $description ) {
			return array();
		}

		return array(
			'description' => array(
				'tag'        => 'meta',
				'attributes' => array(
					'name'    => 'description',
					'content' => $description,
				),
			),
		);
	}

	/**
	 * The slots that have templates in the settings, grouped.
	 *
	 * @return array<string, array{label: string, slots: array<string, string>}>
	 */
	public static function slot_groups(): array {
		$posts = array();
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$object                  = get_post_type_object( $type );
			$posts[ 'post:' . $type ] = $object ? (string) $object->labels->name : $type;
		}

		$terms = array();
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			$object                      = get_taxonomy( $taxonomy );
			$terms[ 'term:' . $taxonomy ] = $object ? (string) $object->labels->name : $taxonomy;
		}

		$archives = array();
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( $object && $object->has_archive ) {
				/* translators: %s: post type name, plural. */
				$archives[ 'archive:' . $type ] = sprintf( __( 'Archive: %s', 'simple-seo' ), $object->labels->name );
			}
		}
		$archives['author'] = __( 'Author archives', 'simple-seo' );
		$archives['date']   = __( 'Date archives', 'simple-seo' );
		$archives['search'] = __( 'Search results', 'simple-seo' );

		return array(
			'posts'    => array(
				'label' => __( 'Post types', 'simple-seo' ),
				'slots' => $posts,
			),
			'terms'    => array(
				'label' => __( 'Taxonomies', 'simple-seo' ),
				'slots' => $terms,
			),
			'archives' => array(
				'label' => __( 'Archives and search', 'simple-seo' ),
				'slots' => $archives,
			),
		);
	}

	/**
	 * Keep a known separator and well-formed slots; a template equal to the default is not stored,
	 * so a better default in a later version reaches it.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$separator = isset( $input['separator'] ) ? (string) $input['separator'] : self::DEFAULT_SEPARATOR;
		$clean     = array(
			'separator' => in_array( $separator, self::SEPARATORS, true ) ? $separator : self::DEFAULT_SEPARATOR,
			'templates' => array(),
		);

		$templates = isset( $input['templates'] ) && is_array( $input['templates'] ) ? $input['templates'] : array();
		foreach ( $templates as $slot => $values ) {
			$slot = (string) $slot;
			if ( ! is_array( $values ) || ! preg_match( '/^(?:(?:post|term|archive):[a-z0-9_-]+|author|date|search|home|404)$/', $slot ) ) {
				continue;
			}

			foreach ( array( 'title', 'description' ) as $which ) {
				if ( ! array_key_exists( $which, $values ) || ! is_scalar( $values[ $which ] ) ) {
					continue;
				}
				$value = trim( sanitize_text_field( (string) $values[ $which ] ) );
				if ( $value !== self::default_template( $slot, $which ) ) {
					$clean['templates'][ $slot ][ $which ] = $value;
				}
			}
		}

		return $clean;
	}

	public static function render_tab(): void {
		$separator = self::separator();
		$groups    = self::slot_groups();
		$variables = Simple_SEO_Variables::labels();

		require SIMPLE_SEO_DIR . 'includes/views/tab-titles.php';
	}

	/** @param WP_Post|WP_Term $object */
	private static function object_context( string $type, $object ): Simple_SEO_Context {
		return 'term' === $type && $object instanceof WP_Term ? Simple_SEO_Context::for_term( $object ) : Simple_SEO_Context::for_post( $object );
	}
}
