<?php
/**
 * Variables in title and description templates: {title}, {site}, {sep} and the rest.
 *
 * The syntax is Simple SEO's own: a name in curly braces. Only registered names are replaced, so
 * other text in braces stays as typed. Yoast's %%var%% syntax is understood only by the Yoast
 * compatibility layer and the import, never here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Variables {
	/** Stands for the separator while the template is filled, so empty parts can be tidied away. */
	private const SEPARATOR_MARK = "\x1F";

	/** @var array<string, array{label: string, callback: callable}> */
	private static array $variables = array();

	public static function init(): void {
		add_action( 'init', array( self::class, 'register_builtin' ), 5 );
	}

	/**
	 * Register a variable, on init or later (the label is translated).
	 *
	 * @param callable $callback Receives Simple_SEO_Context, returns plain text ('' when it does not apply).
	 */
	public static function register( string $name, string $label, callable $callback ): void {
		self::$variables[ sanitize_key( $name ) ] = array(
			'label'    => $label,
			'callback' => $callback,
		);
	}

	/** @return array<string, string> Name => label, for the help next to the templates. */
	public static function labels(): array {
		return array_map( static fn( array $variable ): string => $variable['label'], self::$variables );
	}

	/**
	 * Short names of the variables, for a chip whose value is empty on the page ({page} on its
	 * first page); one registered by another plugin is shown by its name.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		$builtin = array(
			'title'     => __( 'title', 'simple-seo' ),
			'site'      => __( 'site title', 'simple-seo' ),
			'tagline'   => __( 'tagline', 'simple-seo' ),
			'sep'       => __( 'separator', 'simple-seo' ),
			'page'      => __( 'page number', 'simple-seo' ),
			'excerpt'   => __( 'excerpt', 'simple-seo' ),
			'category'  => __( 'category', 'simple-seo' ),
			'author'    => __( 'author', 'simple-seo' ),
			'date'      => __( 'date', 'simple-seo' ),
			'post_type' => __( 'post type', 'simple-seo' ),
			'search'    => __( 'search words', 'simple-seo' ),
			'year'      => __( 'year', 'simple-seo' ),
		);
		$names   = array();
		foreach ( array_keys( self::$variables ) as $name ) {
			$names[ $name ] = $builtin[ $name ] ?? $name;
		}

		return $names;
	}

	/**
	 * What each variable becomes on a page, on one line, the separator as chosen: what the chips of
	 * a field with variables show.
	 *
	 * @return array<string, string>
	 */
	public static function values( Simple_SEO_Context $context ): array {
		$values = array();
		foreach ( array_keys( self::$variables ) as $name ) {
			$values[ $name ] = (string) self::value( $name, $context );
		}

		return $values;
	}

	/**
	 * The values of the variables as the attribute that turns a field into chips (admin.js).
	 *
	 * @param array<string, string> $values
	 */
	public static function attribute( array $values ): string {
		return sprintf( ' data-simple-seo-vars="%s"', esc_attr( (string) wp_json_encode( $values, JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * An example page of a slot of the templates, for the chips of the settings: the latest post of
	 * the type, the term with most posts, the archive, an author with posts, this month, a search, a
	 * page not found, the front page. A page with no example (no post yet) has the site's values only.
	 */
	public static function example( string $slot ): Simple_SEO_Context {
		[ $kind, $subtype ] = array_pad( explode( ':', $slot, 2 ), 2, '' );
		$context            = null;
		if ( 'post' === $kind || 'password' === $kind ) {
			$posts   = get_posts(
				array(
					'post_type'   => 'password' === $kind ? 'post' : $subtype,
					'post_status' => 'publish',
					'numberposts' => 1,
				)
			);
			$context = $posts ? Simple_SEO_Context::for_post( $posts[0] ) : null;
		} elseif ( 'term' === $kind ) {
			$terms   = get_terms(
				array(
					'taxonomy'   => $subtype,
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'hide_empty' => false,
				)
			);
			$context = is_array( $terms ) && $terms ? Simple_SEO_Context::for_term( $terms[0] ) : null;
		} elseif ( 'archive' === $kind ) {
			$context = Simple_SEO_Context::for_post_type_archive( $subtype );
		} elseif ( 'author' === $kind ) {
			$users   = get_users(
				array(
					'has_published_posts' => true,
					'number'              => 1,
				)
			);
			$context = $users ? Simple_SEO_Context::for_author( $users[0] ) : null;
		} elseif ( 'home' === $kind ) {
			$context = Simple_SEO_Context::for_home();
		} elseif ( in_array( $kind, array( 'date', 'search', '404' ), true ) ) {
			$context = new Simple_SEO_Context();
			if ( 'date' === $kind ) {
				$context->kind      = Simple_SEO_Context::DATE;
				$context->subtype   = 'month';
				$context->timestamp = (int) gmmktime( 0, 0, 0, (int) gmdate( 'n' ), 1, (int) gmdate( 'Y' ) );
			} elseif ( 'search' === $kind ) {
				$context->kind   = Simple_SEO_Context::SEARCH;
				$context->search = __( 'search words', 'simple-seo' );
			} else {
				$context->kind = Simple_SEO_Context::NOT_FOUND;
			}
		}

		return $context ?? new Simple_SEO_Context();
	}

	public static function register_builtin(): void {
		self::register( 'title', __( 'Title of the post, term, archive or author; on search results and 404 a ready phrase', 'simple-seo' ), array( self::class, 'object_title' ) );
		self::register( 'site', __( 'Site title', 'simple-seo' ), static fn(): string => (string) get_option( 'blogname' ) );
		self::register( 'tagline', __( 'Tagline', 'simple-seo' ), static fn(): string => (string) get_option( 'blogdescription' ) );
		self::register( 'sep', __( 'Separator chosen in the settings', 'simple-seo' ), static fn(): string => self::SEPARATOR_MARK );
		self::register( 'page', __( 'Page number, only from the second page on: "Page 2 of 5"', 'simple-seo' ), array( self::class, 'page' ) );
		self::register( 'excerpt', __( 'Excerpt of the post (or the start of its text), description of the term, author or post type', 'simple-seo' ), array( self::class, 'excerpt' ) );
		self::register( 'category', __( 'Category of the post', 'simple-seo' ), array( self::class, 'category' ) );
		self::register( 'author', __( 'Author name', 'simple-seo' ), array( self::class, 'author' ) );
		self::register( 'date', __( 'Publication date of the post, or the date of a date archive', 'simple-seo' ), array( self::class, 'date' ) );
		self::register( 'post_type', __( 'Name of the post type', 'simple-seo' ), array( self::class, 'post_type' ) );
		self::register( 'search', __( 'What was searched for', 'simple-seo' ), static fn( Simple_SEO_Context $context ): string => $context->search );
		self::register( 'year', __( 'Current year', 'simple-seo' ), static fn(): string => (string) wp_date( 'Y' ) );
	}

	/**
	 * Fill a template for a page: variables replaced, spaces tidied, separators left over by
	 * empty variables removed. Returns plain text, not escaped.
	 */
	public static function replace( string $template, Simple_SEO_Context $context, string $separator ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}

		$text = preg_replace_callback(
			'/\{([a-z][a-z0-9_]*)\}/',
			static function ( array $match ) use ( $context ): string {
				if ( ! isset( self::$variables[ $match[1] ] ) ) {
					return $match[0];
				}
				if ( 'sep' === $match[1] ) {
					return self::SEPARATOR_MARK;
				}

				return str_replace( self::SEPARATOR_MARK, '', self::plain( (string) call_user_func( self::$variables[ $match[1] ]['callback'], $context ) ) );
			},
			$template
		);

		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		// Separators with nothing between them collapse to one; none at the start or the end.
		$text = preg_replace( '/' . self::SEPARATOR_MARK . '(?:\s*' . self::SEPARATOR_MARK . ')+/u', self::SEPARATOR_MARK, (string) $text );
		$text = preg_replace( '/^[\s' . self::SEPARATOR_MARK . ']+|[\s' . self::SEPARATOR_MARK . ']+$/u', '', (string) $text );

		return trim( str_replace( self::SEPARATOR_MARK, $separator, (string) $text ) );
	}

	/**
	 * The value of one variable for a page, plain text; null for a name that is not registered.
	 * {sep} has no value of its own here: it is Simple_SEO_Titles::separator().
	 */
	public static function value( string $name, Simple_SEO_Context $context ): ?string {
		if ( ! isset( self::$variables[ $name ] ) ) {
			return null;
		}
		if ( 'sep' === $name ) {
			return Simple_SEO_Titles::separator();
		}

		return str_replace( self::SEPARATOR_MARK, '', self::plain( (string) call_user_func( self::$variables[ $name ]['callback'], $context ) ) );
	}

	/** Text without tags and entities, on one line. */
	public static function plain( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	public static function object_title( Simple_SEO_Context $context ): string {
		switch ( $context->kind ) {
			case Simple_SEO_Context::SINGULAR:
			case Simple_SEO_Context::POSTS_PAGE:
				return $context->object instanceof WP_Post ? (string) $context->object->post_title : '';
			case Simple_SEO_Context::TERM:
				return $context->object instanceof WP_Term ? (string) $context->object->name : '';
			case Simple_SEO_Context::POST_TYPE_ARCHIVE:
				if ( $context->object_id ) {
					// The page of the archive: the title of the shop page, not "Products".
					return (string) get_post_field( 'post_title', $context->object_id );
				}
				return $context->object instanceof WP_Post_Type ? (string) $context->object->labels->name : '';
			case Simple_SEO_Context::AUTHOR:
				return $context->object instanceof WP_User ? (string) $context->object->display_name : '';
			case Simple_SEO_Context::DATE:
				return self::archive_date( $context );
			case Simple_SEO_Context::SEARCH:
				/* translators: %s: what was searched for. */
				return sprintf( __( 'Search results for "%s"', 'simple-seo' ), $context->search );
			case Simple_SEO_Context::NOT_FOUND:
				return __( 'Page not found', 'simple-seo' );
			case Simple_SEO_Context::HOME:
				return (string) get_option( 'blogname' );
		}

		return '';
	}

	public static function page( Simple_SEO_Context $context ): string {
		if ( $context->page < 2 ) {
			return '';
		}

		return $context->pages >= $context->page
			/* translators: 1: page number, 2: number of pages. */
			? sprintf( __( 'Page %1$d of %2$d', 'simple-seo' ), $context->page, $context->pages )
			/* translators: %d: page number. */
			: sprintf( __( 'Page %d', 'simple-seo' ), $context->page );
	}

	public static function excerpt( Simple_SEO_Context $context ): string {
		$object = $context->object;

		if ( $object instanceof WP_Post ) {
			// A protected post shows nothing of its text to whoever has not entered the password.
			if ( $context->password_required ) {
				return '';
			}
			if ( '' !== trim( $object->post_excerpt ) ) {
				return $object->post_excerpt;
			}

			// As wide as a description in search results on a computer, not a number of characters:
			// Cyrillic letters are wider than Latin ones.
			return Simple_SEO_Text_Width::cut( self::plain( strip_shortcodes( excerpt_remove_blocks( $object->post_content ) ) ), Simple_SEO_Text_Width::DESCRIPTION );
		}
		if ( $object instanceof WP_Term ) {
			return (string) $object->description;
		}
		if ( $object instanceof WP_User ) {
			return (string) $object->description;
		}
		if ( $object instanceof WP_Post_Type ) {
			// The excerpt of the page of the archive, when it has one.
			$page = $context->object_id ? get_post( $context->object_id ) : null;

			return $page instanceof WP_Post && '' !== trim( $page->post_excerpt ) ? $page->post_excerpt : (string) $object->description;
		}

		return '';
	}

	/** The post's primary category (Simple_SEO_Primary_Term). */
	public static function category( Simple_SEO_Context $context ): string {
		if ( ! $context->object instanceof WP_Post || ! is_object_in_taxonomy( $context->object->post_type, 'category' ) ) {
			return '';
		}

		$term = Simple_SEO_Primary_Term::get( $context->object, 'category' );

		return $term ? (string) $term->name : '';
	}

	public static function author( Simple_SEO_Context $context ): string {
		if ( $context->object instanceof WP_User ) {
			return (string) $context->object->display_name;
		}
		if ( $context->object instanceof WP_Post && post_type_supports( $context->object->post_type, 'author' ) ) {
			$user = get_userdata( (int) $context->object->post_author );
			return $user ? (string) $user->display_name : '';
		}

		return '';
	}

	public static function date( Simple_SEO_Context $context ): string {
		if ( Simple_SEO_Context::DATE === $context->kind ) {
			return self::archive_date( $context );
		}
		if ( $context->object instanceof WP_Post ) {
			return (string) get_the_date( '', $context->object );
		}

		return '';
	}

	public static function post_type( Simple_SEO_Context $context ): string {
		$type = $context->object instanceof WP_Post_Type ? $context->object : null;
		if ( $context->object instanceof WP_Post ) {
			$type = get_post_type_object( $context->object->post_type );
		}

		return $type instanceof WP_Post_Type ? (string) $type->labels->singular_name : '';
	}

	private static function archive_date( Simple_SEO_Context $context ): string {
		if ( ! $context->timestamp ) {
			return '';
		}

		$formats = array(
			'year'  => 'Y',
			/* translators: format of a month archive date, see https://www.php.net/manual/datetime.format.php */
			'month' => _x( 'F Y', 'month archive date format', 'simple-seo' ),
			'day'   => (string) get_option( 'date_format' ),
		);

		return (string) date_i18n( $formats[ $context->subtype ] ?? 'Y', $context->timestamp );
	}
}
