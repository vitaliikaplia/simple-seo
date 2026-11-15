<?php
/**
 * Compatibility with themes written for Yoast SEO: the public functions and the class they call
 * (wpseo_replace_vars(), yoast_breadcrumb(), WPSEO_Primary_Term and the primary term helpers),
 * answered with Simple SEO's own data, so a theme keeps working once Yoast is gone.
 *
 * Interface compatibility only: the implementation is Simple SEO's. Nothing is defined while Yoast
 * itself or another SEO plugin is active, and nothing that other plugins take as a sign of a real
 * Yoast is defined (WPSEO_VERSION, WPSEO_Meta, WPSEO_Options, WPSEO_Frontend, YoastSEO()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Yoast_Compat {
	private static bool $loaded = false;

	public static function init(): void {
		// Every plugin is loaded by then (Yoast, if present, has declared its functions), themes not yet.
		add_action( 'plugins_loaded', array( self::class, 'load' ), 20 );
	}

	/** Whether the layer serves Yoast's functions: no Yoast and no other SEO plugin. */
	public static function enabled(): bool {
		/**
		 * Whether Simple SEO answers the functions of Yoast SEO that themes call.
		 *
		 * @param bool $enabled
		 */
		return '' === Simple_SEO_Plugin::conflicting_plugin() && (bool) apply_filters( 'simple_seo_yoast_compat', true );
	}

	/**
	 * Whether this request may activate a plugin: the Plugins and Update screens, and WP-CLI's
	 * plugin activate/install/toggle. Activating Yoast includes its files in that same request,
	 * and its functions would collide with ours ("Cannot redeclare"), so the layer stays out.
	 */
	public static function activation_request(): bool {
		global $pagenow;

		if ( is_admin() && in_array( (string) $pagenow, array( 'plugins.php', 'update.php' ), true ) ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$argv = array_map( 'strval', (array) ( $GLOBALS['argv'] ?? array() ) );

			return in_array( 'plugin', $argv, true ) && (bool) array_intersect( array( 'activate', 'install', 'toggle' ), $argv );
		}

		return false;
	}

	public static function load(): void {
		if ( self::$loaded || ! self::enabled() || self::activation_request() ) {
			return;
		}
		self::$loaded = true;

		require_once SIMPLE_SEO_DIR . 'includes/compat/yoast-functions.php';
		if ( ! class_exists( 'WPSEO_Primary_Term', false ) ) {
			require_once SIMPLE_SEO_DIR . 'includes/compat/class-wpseo-primary-term.php';
		}
	}

	public static function loaded(): bool {
		return self::$loaded;
	}

	/**
	 * wpseo_replace_vars(): %%name%% replaced for the post or term in $args (the current page when
	 * it names none). A name in $omit stays as written; a name Simple SEO does not know goes away.
	 *
	 * @param mixed    $args A post or term, as an object or an array of its fields.
	 * @param string[] $omit
	 */
	public static function replace_vars( string $text, $args = array(), array $omit = array() ): string {
		if ( ! str_contains( $text, '%%' ) ) {
			return $text;
		}

		$context = self::context_of( $args );
		$text    = (string) preg_replace_callback(
			'/%%([A-Za-z0-9_-]+)%%/',
			static function ( array $match ) use ( $context, $omit ): string {
				if ( in_array( $match[1], $omit, true ) ) {
					return $match[0];
				}

				return (string) self::variable( strtolower( $match[1] ), $context );
			},
			$text
		);

		return trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $text ) );
	}

	/** The page a call is about: the post or term it names, otherwise what the request shows. */
	public static function context_of( $args ): Simple_SEO_Context {
		if ( $args instanceof WP_Post ) {
			return Simple_SEO_Context::for_post( $args );
		}
		if ( $args instanceof WP_Term ) {
			return Simple_SEO_Context::for_term( $args );
		}

		$fields = is_object( $args ) ? get_object_vars( $args ) : ( is_array( $args ) ? $args : array() );
		if ( ! empty( $fields['term_id'] ) && ! empty( $fields['taxonomy'] ) ) {
			$term = get_term( (int) $fields['term_id'], (string) $fields['taxonomy'] );
			if ( $term instanceof WP_Term ) {
				return Simple_SEO_Context::for_term( $term );
			}
		}
		if ( ! empty( $fields['ID'] ) ) {
			$post = get_post( (int) $fields['ID'] );
			if ( $post instanceof WP_Post ) {
				return Simple_SEO_Context::for_post( $post );
			}
		}

		return Simple_SEO_Context::current();
	}

	/**
	 * The value of a Yoast variable for a page; null for one Simple SEO has no value for.
	 * Most are Simple SEO's own variables under Yoast's names.
	 */
	public static function variable( string $name, Simple_SEO_Context $context ): ?string {
		$own = array(
			'sitename'             => 'site',
			'sitedesc'             => 'tagline',
			'sep'                  => 'sep',
			'title'                => 'title',
			'term_title'           => 'title',
			'excerpt'              => 'excerpt',
			'excerpt_only'         => 'excerpt',
			'term_description'     => 'excerpt',
			'category_description' => 'excerpt',
			'tag_description'      => 'excerpt',
			'category'             => 'category',
			'primary_category'     => 'category',
			'date'                 => 'date',
			'name'                 => 'author',
			'page'                 => 'page',
			'searchphrase'         => 'search',
			'currentyear'          => 'year',
		);
		if ( isset( $own[ $name ] ) ) {
			return Simple_SEO_Variables::value( $own[ $name ], $context );
		}

		$post = $context->object instanceof WP_Post ? $context->object : null;
		$type = $post ? get_post_type_object( $post->post_type ) : ( $context->object instanceof WP_Post_Type ? $context->object : null );

		switch ( $name ) {
			case 'id':
				return $post ? (string) $post->ID : '';
			case 'pt_single':
				return $type ? (string) $type->labels->singular_name : '';
			case 'pt_plural':
				return $type ? (string) $type->labels->name : '';
			case 'modified':
				return $post ? (string) get_the_modified_date( '', $post ) : '';
			case 'parent_title':
				return $post && $post->post_parent ? Simple_SEO_Variables::plain( get_the_title( $post->post_parent ) ) : '';
			case 'caption':
				return $post && 'attachment' === $post->post_type ? Simple_SEO_Variables::plain( $post->post_excerpt ) : '';
			case 'tag':
				return $post ? self::term_names( $post, 'post_tag' ) : '';
			case 'user_description':
				$user = $context->object instanceof WP_User ? (int) $context->object->ID : ( $post ? (int) $post->post_author : 0 );
				return $user ? Simple_SEO_Variables::plain( (string) get_the_author_meta( 'description', $user ) ) : '';
			case 'pagenumber':
				return (string) $context->page;
			case 'pagetotal':
				return (string) $context->pages;
			case 'currentdate':
				return (string) wp_date( (string) get_option( 'date_format' ) );
			case 'currenttime':
				return (string) wp_date( (string) get_option( 'time_format' ) );
			case 'currentmonth':
				return (string) wp_date( 'F' );
			case 'currentday':
				return (string) wp_date( 'j' );
			case 'focuskw':
				return '';
		}

		// %%ct_genre%%: the terms of a taxonomy; %%cf_price%%: a custom field.
		if ( str_starts_with( $name, 'ct_' ) ) {
			return $post ? self::term_names( $post, substr( $name, 3 ) ) : '';
		}
		if ( str_starts_with( $name, 'cf_' ) ) {
			$value = $post ? get_post_meta( $post->ID, substr( $name, 3 ), true ) : '';

			return is_scalar( $value ) ? Simple_SEO_Variables::plain( (string) $value ) : '';
		}

		return null;
	}

	/**
	 * yoast_breadcrumb(): Simple SEO's trail in the markup themes made for Yoast style —
	 * <span><span><a>Home</a></span> › … <span class="breadcrumb_last" aria-current="page">Page</span></span>.
	 * '' where there is no trail (the home page).
	 */
	public static function breadcrumb( string $before = '', string $after = '' ): string {
		$items = Simple_SEO_Breadcrumbs::items( Simple_SEO_Context::current() );
		if ( count( $items ) < 2 ) {
			return '';
		}

		$show_current = ! empty( Simple_SEO_Settings::value( Simple_SEO_Breadcrumbs::OPTION, 'show_current' ) );
		if ( ! $show_current ) {
			array_pop( $items );
		}

		$last  = count( $items ) - 1;
		$parts = array();
		foreach ( $items as $index => $item ) {
			if ( $index === $last && $show_current ) {
				$parts[] = '<span class="breadcrumb_last" aria-current="page">' . esc_html( $item['label'] ) . '</span>';
			} elseif ( '' !== $item['url'] ) {
				$parts[] = '<span><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a></span>';
			} else {
				$parts[] = '<span>' . esc_html( $item['label'] ) . '</span>';
			}
		}

		$prefix = (string) Simple_SEO_Settings::value( Simple_SEO_Breadcrumbs::LABELS, 'prefix' );

		return $before
			. '<span>' . ( '' !== $prefix ? esc_html( $prefix ) . ' ' : '' )
			. implode( ' ' . esc_html( Simple_SEO_Breadcrumbs::separator() ) . ' ', $parts )
			. '</span>'
			. $after;
	}

	/** The ID of a post's primary term in a taxonomy; 0 when it has no terms there. */
	public static function primary_term_id( string $taxonomy, $post ): int {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$term = Simple_SEO_Primary_Term::get( $post, $taxonomy );

		return $term instanceof WP_Term ? (int) $term->term_id : 0;
	}

	/** Make a term the primary one of a post (the SEO box's choice). */
	public static function set_primary_term( string $taxonomy, int $post_id, int $term_id ): void {
		update_post_meta( $post_id, Simple_SEO_Fields::meta_key( Simple_SEO_Primary_Term::field( $taxonomy ) ), (string) $term_id );
	}

	private static function term_names( WP_Post $post, string $taxonomy ): string {
		$terms = taxonomy_exists( $taxonomy ) ? get_the_terms( $post, $taxonomy ) : false;

		return is_array( $terms ) ? Simple_SEO_Variables::plain( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) : '';
	}
}
