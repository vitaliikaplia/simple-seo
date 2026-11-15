<?php
/**
 * Breadcrumbs: the trail from the home page to the page shown, for themes to print.
 *
 *   PHP:       <?php simple_seo_the_breadcrumbs(); ?>  or  echo simple_seo_breadcrumbs();
 *   Twig:      {{ simple_seo_breadcrumbs() }}  (registered with Timber)
 *   Shortcode: [simple_seo_breadcrumbs]
 *
 * The trail: home → blog page (posts) or post type archive → primary term with its parents
 * (Simple_SEO_Primary_Term) → parent pages → the page itself, and its page number from the second
 * page on. Terms, archives, authors, dates, search results and 404 have their own trails. With
 * wp-loc everything is in the language of the page: home_url(), links and options are wp-loc's.
 * The items are open to themes and the Yoast compatibility layer: Simple_SEO_Breadcrumbs::items()
 * and the simple_seo_breadcrumbs filter. BreadcrumbList Schema comes in phase 4.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Breadcrumbs {
	public const OPTION = 'simple_seo_breadcrumbs';

	/** Labels, kept per language with wp-loc; the rest of the settings is common. */
	public const LABELS = 'simple_seo_breadcrumb_labels';

	public const DEFAULT_SEPARATOR = '›';

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'separator'    => self::DEFAULT_SEPARATOR,
				'show_current' => true,
				'show_blog'    => true,
				'taxonomies'   => array(),
			),
			array( self::class, 'sanitize' ),
			true
		);
		Simple_SEO_Settings::register(
			self::LABELS,
			array(
				'home'   => '',
				'prefix' => '',
			),
			array( self::class, 'sanitize_labels' ),
			true,
			true
		);

		add_action( 'init', array( self::class, 'register_group' ), 5 );
		add_shortcode( 'simple_seo_breadcrumbs', array( self::class, 'shortcode' ) );
		add_filter( 'timber/twig', array( self::class, 'twig' ) );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function register_group(): void {
		Simple_SEO_Fields::register_group( 'breadcrumbs', __( 'Breadcrumbs', 'simple-seo' ) );
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'breadcrumbs', __( 'Breadcrumbs', 'simple-seo' ), array( self::OPTION, self::LABELS ), array( self::class, 'render_tab' ), 25 );
	}

	/**
	 * The trail of a page: home first, the page itself last.
	 *
	 * @return array<int, array{label: string, url: string}>
	 */
	public static function items( Simple_SEO_Context $context ): array {
		$items = array( self::item( self::home_label(), home_url( '/' ) ) );

		switch ( $context->kind ) {
			case Simple_SEO_Context::SINGULAR:
				if ( $context->object instanceof WP_Post && ! $context->is_front_page ) {
					$items = array_merge( $items, self::post_trail( $context->object ) );
				}
				break;
			case Simple_SEO_Context::POSTS_PAGE:
				if ( $context->object instanceof WP_Post ) {
					$items[] = self::item( Simple_SEO_Variables::plain( $context->object->post_title ), (string) get_permalink( $context->object ) );
				}
				break;
			case Simple_SEO_Context::TERM:
				if ( $context->object instanceof WP_Term ) {
					$items = array_merge( $items, self::term_trail( $context->object ) );
				}
				break;
			case Simple_SEO_Context::POST_TYPE_ARCHIVE:
				$items[] = self::item( Simple_SEO_Variables::object_title( $context ), (string) get_post_type_archive_link( $context->subtype ) );
				break;
			case Simple_SEO_Context::AUTHOR:
				$items[] = self::item( Simple_SEO_Variables::object_title( $context ), get_author_posts_url( $context->object_id ) );
				break;
			case Simple_SEO_Context::DATE:
				$items = array_merge( $items, self::date_trail( $context ) );
				break;
			case Simple_SEO_Context::SEARCH:
			case Simple_SEO_Context::NOT_FOUND:
				$items[] = self::item( Simple_SEO_Variables::object_title( $context ), '' );
				break;
		}

		// From the second page on, the page number closes the trail.
		if ( $context->page > 1 && count( $items ) > 1 ) {
			/* translators: %d: page number. */
			$items[] = self::item( sprintf( __( 'Page %d', 'simple-seo' ), $context->page ), Simple_SEO_Indexing::own_address( $context ) );
		}

		/**
		 * The breadcrumb trail of a page: label and address of each item, home first, the page itself last.
		 *
		 * @param array<int, array{label: string, url: string}> $items
		 * @param Simple_SEO_Context                             $context
		 */
		$items = (array) apply_filters( 'simple_seo_breadcrumbs', $items, $context );

		return array_values(
			array_filter(
				array_map(
					static fn( $item ): array => array(
						'label' => is_array( $item ) ? (string) ( $item['label'] ?? '' ) : '',
						'url'   => is_array( $item ) ? (string) ( $item['url'] ?? '' ) : '',
					),
					$items
				),
				static fn( array $item ): bool => '' !== $item['label']
			)
		);
	}

	/**
	 * The breadcrumbs as HTML: links, separators, the page itself as text. '' on the home page
	 * (a trail of one says nothing).
	 *
	 * @param array<string, string> $args before, after (HTML around), class (extra class).
	 */
	public static function render( array $args = array(), ?Simple_SEO_Context $context = null ): string {
		$items = self::items( $context ?? Simple_SEO_Context::current() );
		if ( count( $items ) < 2 ) {
			return '';
		}

		$settings = Simple_SEO_Settings::get( self::OPTION );
		if ( empty( $settings['show_current'] ) ) {
			array_pop( $items );
		}

		$last      = count( $items ) - 1;
		$separator = ' <span class="simple-seo-breadcrumbs__separator" aria-hidden="true">' . esc_html( self::separator() ) . '</span> ';
		$parts     = array();
		foreach ( $items as $index => $item ) {
			$parts[] = $index === $last && ! empty( $settings['show_current'] )
				? '<span class="simple-seo-breadcrumbs__current" aria-current="page">' . esc_html( $item['label'] ) . '</span>'
				: ( '' !== $item['url']
					? '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>'
					: '<span>' . esc_html( $item['label'] ) . '</span>' );
		}

		$prefix = (string) Simple_SEO_Settings::value( self::LABELS, 'prefix' );
		$class  = trim( 'simple-seo-breadcrumbs ' . sanitize_html_class( (string) ( $args['class'] ?? '' ) ) );

		return (string) ( $args['before'] ?? '' )
			. '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Breadcrumbs', 'simple-seo' ) . '">'
			. ( '' !== $prefix ? '<span class="simple-seo-breadcrumbs__prefix">' . esc_html( $prefix ) . '</span> ' : '' )
			. implode( $separator, $parts )
			. '</nav>'
			. (string) ( $args['after'] ?? '' );
	}

	/** The trail as plain text, for previews: Home › News › Post. */
	public static function text( Simple_SEO_Context $context ): string {
		$items = self::items( $context );

		return count( $items ) < 2 ? '' : implode( ' ' . self::separator() . ' ', array_column( $items, 'label' ) );
	}

	/**
	 * [simple_seo_breadcrumbs class="…"]
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'class' => '' ), is_array( $atts ) ? $atts : array(), 'simple_seo_breadcrumbs' );

		return self::render( array( 'class' => (string) $atts['class'] ) );
	}

	/**
	 * Timber: {{ simple_seo_breadcrumbs() }} in Twig templates.
	 *
	 * @param mixed $twig
	 * @return mixed
	 */
	public static function twig( $twig ) {
		if ( is_object( $twig ) && method_exists( $twig, 'addFunction' ) && class_exists( '\Twig\TwigFunction' ) ) {
			$twig->addFunction( new \Twig\TwigFunction( 'simple_seo_breadcrumbs', 'simple_seo_breadcrumbs', array( 'is_safe' => array( 'html' ) ) ) );
		}

		return $twig;
	}

	public static function separator(): string {
		$separator = trim( (string) Simple_SEO_Settings::value( self::OPTION, 'separator' ) );

		return '' !== $separator ? $separator : self::DEFAULT_SEPARATOR;
	}

	public static function home_label(): string {
		$label = trim( (string) Simple_SEO_Settings::value( self::LABELS, 'home' ) );

		return '' !== $label ? $label : __( 'Home', 'simple-seo' );
	}

	/**
	 * The taxonomy whose primary term a post type's trail goes through; '' for none.
	 * Posts: categories; a hierarchical type: its parents instead; others: their first hierarchical taxonomy.
	 */
	public static function taxonomy_for( string $post_type ): string {
		$saved = Simple_SEO_Settings::value( self::OPTION, 'taxonomies' );
		if ( is_array( $saved ) && array_key_exists( $post_type, $saved ) ) {
			$taxonomy = (string) $saved[ $post_type ];
			return '' === $taxonomy || in_array( $post_type, Simple_SEO_Primary_Term::taxonomies()[ $taxonomy ] ?? array(), true ) ? $taxonomy : self::default_taxonomy( $post_type );
		}

		return self::default_taxonomy( $post_type );
	}

	public static function default_taxonomy( string $post_type ): string {
		$taxonomies = array_keys( array_filter( Simple_SEO_Primary_Term::taxonomies(), static fn( array $types ): bool => in_array( $post_type, $types, true ) ) );
		if ( ! $taxonomies || is_post_type_hierarchical( $post_type ) ) {
			return '';
		}

		return in_array( 'category', $taxonomies, true ) ? 'category' : (string) $taxonomies[0];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$separator = trim( sanitize_text_field( (string) ( $input['separator'] ?? '' ) ) );
		$clean     = array(
			'separator'    => '' !== $separator ? mb_substr( $separator, 0, 10 ) : self::DEFAULT_SEPARATOR,
			'show_current' => ! isset( $input['show_current'] ) || '1' === (string) $input['show_current'],
			'show_blog'    => ! isset( $input['show_blog'] ) || '1' === (string) $input['show_blog'],
			'taxonomies'   => array(),
		);

		$known = Simple_SEO_Primary_Term::taxonomies();
		foreach ( (array) ( $input['taxonomies'] ?? array() ) as $type => $taxonomy ) {
			$type     = sanitize_key( (string) $type );
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( '' !== $taxonomy && ! in_array( $type, $known[ $taxonomy ] ?? array(), true ) ) {
				continue;
			}
			// Only a choice that differs from the default is kept, so a better default reaches the rest.
			if ( $taxonomy !== self::default_taxonomy( $type ) ) {
				$clean['taxonomies'][ $type ] = $taxonomy;
			}
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, string>
	 */
	public static function sanitize_labels( array $input ): array {
		return array(
			'home'   => trim( sanitize_text_field( (string) ( $input['home'] ?? '' ) ) ),
			'prefix' => trim( sanitize_text_field( (string) ( $input['prefix'] ?? '' ) ) ),
		);
	}

	public static function render_tab(): void {
		$settings = Simple_SEO_Settings::get( self::OPTION );
		$labels   = Simple_SEO_Settings::get( self::LABELS );
		$language = Simple_SEO_WP_Loc::admin_language();
		$types    = array();
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$choices = array_keys( array_filter( Simple_SEO_Primary_Term::taxonomies(), static fn( array $types ): bool => in_array( $type, $types, true ) ) );
			if ( $choices ) {
				$types[ $type ] = $choices;
			}
		}
		$example = self::example();

		require SIMPLE_SEO_DIR . 'includes/views/tab-breadcrumbs.php';
	}

	/** The trail of the newest post, for the tab; with wp-loc, of a post in the admin language. */
	private static function example(): string {
		$posts = get_posts(
			array(
				'post_type'   => 'post',
				'numberposts' => 10,
				'post_status' => 'publish',
			)
		);
		$code      = Simple_SEO_WP_Loc::admin_language_code();
		$languages = Simple_SEO_WP_Loc::element_languages( wp_list_pluck( $posts, 'ID' ), 'post_post' );
		foreach ( $posts as $post ) {
			$language = $languages[ (int) $post->ID ] ?? '';
			if ( '' === $code || '' === $language || $code === $language ) {
				// In the post's language, as the site shows it: labels, links, translations. In the
				// admin wp-loc leaves the locale alone (it is the user's), so switch it here.
				$locale   = Simple_SEO_WP_Loc::locale_of( $language );
				$switched = '' !== $locale && switch_to_locale( $locale );
				try {
					return (string) Simple_SEO_WP_Loc::in_language( $language, static fn(): string => self::text( Simple_SEO_Context::for_post( $post ) ) );
				} finally {
					if ( $switched ) {
						restore_previous_locale();
					}
				}
			}
		}

		return '';
	}

	/** @return array<int, array{label: string, url: string}> */
	private static function post_trail( WP_Post $post ): array {
		$items = array();

		if ( 'attachment' === $post->post_type ) {
			$parent = $post->post_parent ? get_post( (int) $post->post_parent ) : null;
			if ( $parent instanceof WP_Post && 'publish' === $parent->post_status ) {
				$items = self::post_trail( $parent );
			}
			$items[] = self::item( Simple_SEO_Variables::plain( $post->post_title ), (string) get_permalink( $post ) );
			return $items;
		}

		if ( 'post' === $post->post_type ) {
			$blog = self::blog_item();
			if ( $blog ) {
				$items[] = $blog;
			}
		} else {
			$archive = self::archive_item( $post->post_type );
			if ( $archive ) {
				$items[] = $archive;
			}
		}

		$taxonomy = self::taxonomy_for( $post->post_type );
		$term     = '' !== $taxonomy ? Simple_SEO_Primary_Term::get( $post, $taxonomy ) : null;
		if ( $term ) {
			$items = array_merge( $items, self::term_chain( $term ) );
		}

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
				$ancestor = get_post( $ancestor );
				if ( $ancestor instanceof WP_Post && 'publish' === $ancestor->post_status ) {
					$items[] = self::item( Simple_SEO_Variables::plain( $ancestor->post_title ), (string) get_permalink( $ancestor ) );
				}
			}
		}

		$items[] = self::item( Simple_SEO_Variables::plain( $post->post_title ), (string) get_permalink( $post ) );

		return $items;
	}

	/** @return array<int, array{label: string, url: string}> */
	private static function term_trail( WP_Term $term ): array {
		$items    = array();
		$taxonomy = get_taxonomy( $term->taxonomy );
		$types    = $taxonomy instanceof WP_Taxonomy ? (array) $taxonomy->object_type : array();

		if ( in_array( 'post', $types, true ) ) {
			$blog = self::blog_item();
			if ( $blog ) {
				$items[] = $blog;
			}
		} elseif ( 1 === count( $types ) ) {
			// A taxonomy of one post type sits under that type's archive.
			$archive = self::archive_item( (string) $types[0] );
			if ( $archive ) {
				$items[] = $archive;
			}
		}

		return array_merge( $items, self::term_chain( $term ) );
	}

	/**
	 * A term with its parents, the top one first.
	 *
	 * @return array<int, array{label: string, url: string}>
	 */
	private static function term_chain( WP_Term $term ): array {
		$items = array();
		foreach ( array_reverse( get_ancestors( (int) $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor ) {
			$ancestor = get_term( (int) $ancestor, $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) {
				$link    = get_term_link( $ancestor );
				$items[] = self::item( Simple_SEO_Variables::plain( $ancestor->name ), is_string( $link ) ? $link : '' );
			}
		}
		$link    = get_term_link( $term );
		$items[] = self::item( Simple_SEO_Variables::plain( $term->name ), is_string( $link ) ? $link : '' );

		return $items;
	}

	/** @return array<int, array{label: string, url: string}> */
	private static function date_trail( Simple_SEO_Context $context ): array {
		if ( ! $context->timestamp ) {
			return array();
		}

		$year  = (int) gmdate( 'Y', $context->timestamp );
		$month = (int) gmdate( 'n', $context->timestamp );
		$items = array( self::item( (string) $year, (string) get_year_link( $year ) ) );
		if ( in_array( $context->subtype, array( 'month', 'day' ), true ) ) {
			$items[] = self::item( (string) date_i18n( 'F', $context->timestamp ), (string) get_month_link( $year, $month ) );
		}
		if ( 'day' === $context->subtype ) {
			$items[] = self::item( (string) date_i18n( 'j', $context->timestamp ), (string) get_day_link( $year, $month, (int) gmdate( 'j', $context->timestamp ) ) );
		}

		return $items;
	}

	/** The posts page, when the settings ask for it and the site has one. */
	private static function blog_item(): ?array {
		if ( empty( Simple_SEO_Settings::value( self::OPTION, 'show_blog' ) ) || 'page' !== get_option( 'show_on_front' ) ) {
			return null;
		}

		$page = get_post( (int) get_option( 'page_for_posts' ) );

		return $page instanceof WP_Post && 'publish' === $page->post_status
			? self::item( Simple_SEO_Variables::plain( $page->post_title ), (string) get_permalink( $page ) )
			: null;
	}

	private static function archive_item( string $post_type ): ?array {
		$object = get_post_type_object( $post_type );
		$link   = $object instanceof WP_Post_Type && $object->has_archive ? get_post_type_archive_link( $post_type ) : false;

		return is_string( $link ) && '' !== $link ? self::item( Simple_SEO_Variables::plain( (string) $object->labels->name ), $link ) : null;
	}

	/** @return array{label: string, url: string} */
	private static function item( string $label, string $url ): array {
		return array(
			'label' => $label,
			'url'   => $url,
		);
	}
}
