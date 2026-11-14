<?php
/**
 * What the current request shows, worked out once and shared by everything that prints SEO tags.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Context {
	/** The front page lists the latest posts. */
	public const HOME = 'home';

	/** The page chosen as the posts page (Settings → Reading). */
	public const POSTS_PAGE = 'posts_page';

	/** A post, page, attachment or custom post type, including a static front page. */
	public const SINGULAR = 'singular';

	/** A category, tag or custom taxonomy term archive. */
	public const TERM = 'term';

	public const POST_TYPE_ARCHIVE = 'post_type_archive';
	public const AUTHOR            = 'author';
	public const DATE              = 'date';
	public const SEARCH            = 'search';
	public const NOT_FOUND         = '404';
	public const OTHER             = 'other';

	/** One of the constants above. */
	public string $kind = self::OTHER;

	/** Post, term or user ID; 0 when the page has no single object. */
	public int $object_id = 0;

	/** @var WP_Post|WP_Term|WP_User|WP_Post_Type|null */
	public $object = null;

	/** Post type or taxonomy name; year, month or day for date archives. */
	public string $subtype = '';

	public bool $is_front_page = false;

	/** A password-protected post whose password the visitor has not entered. */
	public bool $password_required = false;

	/** Archive page number, or the part number of a post split with <!--nextpage-->. */
	public int $page = 1;

	private static ?self $current = null;

	/** The context of the main query, resolved once the query has run. */
	public static function current(): self {
		if ( null !== self::$current ) {
			return self::$current;
		}

		global $wp_query;
		$context = $wp_query instanceof WP_Query ? self::from_query( $wp_query ) : new self();

		// Before the main query runs there is nothing to remember yet.
		if ( did_action( 'wp' ) ) {
			self::$current = $context;
		}

		return $context;
	}

	public static function reset(): void {
		self::$current = null;
	}

	public static function from_query( WP_Query $query ): self {
		$context = new self();

		if ( $query->is_404() ) {
			$context->kind = self::NOT_FOUND;
		} elseif ( $query->is_search() ) {
			$context->kind = self::SEARCH;
		} elseif ( $query->is_home() && $query->is_front_page() ) {
			$context->kind          = self::HOME;
			$context->is_front_page = true;
		} elseif ( $query->is_home() ) {
			$context->kind = self::POSTS_PAGE;
			$page          = get_post( (int) get_option( 'page_for_posts' ) );
			if ( $page instanceof WP_Post ) {
				$context->object    = $page;
				$context->object_id = (int) $page->ID;
				$context->subtype   = $page->post_type;
			}
		} elseif ( $query->is_singular() ) {
			$post = $query->get_queried_object();
			if ( $post instanceof WP_Post ) {
				$context->kind              = self::SINGULAR;
				$context->object            = $post;
				$context->object_id         = (int) $post->ID;
				$context->subtype           = $post->post_type;
				$context->is_front_page     = $query->is_front_page();
				$context->password_required = post_password_required( $post );
			}
		} elseif ( $query->is_category() || $query->is_tag() || $query->is_tax() ) {
			$term = $query->get_queried_object();
			if ( $term instanceof WP_Term ) {
				$context->kind      = self::TERM;
				$context->object    = $term;
				$context->object_id = (int) $term->term_id;
				$context->subtype   = $term->taxonomy;
			}
		} elseif ( $query->is_post_type_archive() ) {
			$post_type = $query->get( 'post_type' );
			$post_type = is_array( $post_type ) ? (string) reset( $post_type ) : (string) $post_type;
			$object    = get_post_type_object( $post_type );
			if ( $object instanceof WP_Post_Type ) {
				$context->kind    = self::POST_TYPE_ARCHIVE;
				$context->object  = $object;
				$context->subtype = $post_type;
			}
		} elseif ( $query->is_author() ) {
			$user = $query->get_queried_object();
			if ( $user instanceof WP_User ) {
				$context->kind      = self::AUTHOR;
				$context->object    = $user;
				$context->object_id = (int) $user->ID;
			}
		} elseif ( $query->is_date() ) {
			$context->kind    = self::DATE;
			$context->subtype = $query->is_day() ? 'day' : ( $query->is_month() ? 'month' : 'year' );
		}

		$context->page = self::SINGULAR === $context->kind
			? max( 1, (int) $query->get( 'page' ) )
			: max( 1, (int) $query->get( 'paged' ) );

		return $context;
	}

	/** Whether the page has its own SEO fields: a post or a term. */
	public function has_fields(): bool {
		return $this->object_id > 0 && in_array( $this->kind, array( self::SINGULAR, self::POSTS_PAGE, self::TERM ), true );
	}

	/** A stored SEO field of the page's post or term, '' when it has none. */
	public function field( string $key ): string {
		if ( ! $this->has_fields() ) {
			return '';
		}

		return self::TERM === $this->kind
			? Simple_SEO_Fields::term_value( $this->object_id, $key )
			: Simple_SEO_Fields::post_value( $this->object_id, $key );
	}
}
