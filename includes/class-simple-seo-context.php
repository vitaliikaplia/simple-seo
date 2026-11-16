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

	/**
	 * Post, term or user ID; 0 when the page has no single object. A post type archive that has a
	 * page of its own (the shop of WooCommerce: archive_page()) has that page's ID: its SEO fields.
	 */
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

	/** Number of archive pages, or of parts of a split post. */
	public int $pages = 1;

	/** What was searched for, on search results. */
	public string $search = '';

	/** Date archives: Unix time of the first day of the archived year, month or day (UTC). */
	public int $timestamp = 0;

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
			$context->kind   = self::SEARCH;
			$context->search = trim( (string) $query->get( 's' ) );
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
			$context   = self::for_post_type_archive( $post_type );
		} elseif ( $query->is_author() ) {
			$user = $query->get_queried_object();
			if ( $user instanceof WP_User ) {
				$context->kind      = self::AUTHOR;
				$context->object    = $user;
				$context->object_id = (int) $user->ID;
			}
		} elseif ( $query->is_date() ) {
			$context->kind      = self::DATE;
			$context->subtype   = $query->is_day() ? 'day' : ( $query->is_month() ? 'month' : 'year' );
			$context->timestamp = self::archive_timestamp( $query );
		}

		if ( self::SINGULAR === $context->kind ) {
			$context->page  = max( 1, (int) $query->get( 'page' ) );
			$context->pages = $context->object instanceof WP_Post ? 1 + substr_count( $context->object->post_content, '<!--nextpage-->' ) : 1;
		} else {
			$context->page  = max( 1, (int) $query->get( 'paged' ) );
			$context->pages = max( 1, (int) $query->max_num_pages );
		}

		return $context;
	}

	/**
	 * The context a post would have as the page it is shown on: for previews in the editor,
	 * sitemaps and the like.
	 */
	public static function for_post( WP_Post $post ): self {
		$context            = new self();
		$context->object    = $post;
		$context->object_id = (int) $post->ID;
		$context->subtype   = $post->post_type;

		if ( 'page' === $post->post_type && (int) $post->ID === (int) get_option( 'page_for_posts' ) && 'page' === get_option( 'show_on_front' ) ) {
			$context->kind = self::POSTS_PAGE;
			return $context;
		}

		$context->kind              = self::SINGULAR;
		$context->is_front_page     = 'page' === $post->post_type && 'page' === get_option( 'show_on_front' ) && (int) $post->ID === (int) get_option( 'page_on_front' );
		$context->password_required = '' !== (string) $post->post_password;
		$context->pages             = 1 + substr_count( $post->post_content, '<!--nextpage-->' );

		return $context;
	}

	public static function for_term( WP_Term $term ): self {
		$context            = new self();
		$context->kind      = self::TERM;
		$context->object    = $term;
		$context->object_id = (int) $term->term_id;
		$context->subtype   = $term->taxonomy;

		return $context;
	}

	/** The site's home page: the static front page, or the list of latest posts. */
	public static function for_home(): self {
		$front = 'page' === get_option( 'show_on_front' ) ? get_post( (int) get_option( 'page_on_front' ) ) : null;
		if ( $front instanceof WP_Post ) {
			return self::for_post( $front );
		}

		$context                = new self();
		$context->kind          = self::HOME;
		$context->is_front_page = true;

		return $context;
	}

	public static function for_post_type_archive( string $post_type ): self {
		$context = new self();
		$object  = get_post_type_object( $post_type );
		if ( $object instanceof WP_Post_Type ) {
			$context->kind      = self::POST_TYPE_ARCHIVE;
			$context->object    = $object;
			$context->subtype   = $post_type;
			$context->object_id = (int) ( self::archive_page( $post_type )->ID ?? 0 );
		}

		return $context;
	}

	/**
	 * The page that stands for a post type's archive, as the shop page of WooCommerce stands for
	 * the archive of products: its SEO fields, title and image are the archive's. Published only.
	 * Filter: simple_seo_archive_page.
	 */
	public static function archive_page( string $post_type ): ?WP_Post {
		/**
		 * The ID of the page that stands for a post type's archive; 0 for none.
		 *
		 * @param int    $page_id
		 * @param string $post_type
		 */
		$page = get_post( (int) apply_filters( 'simple_seo_archive_page', 0, $post_type ) );

		return $page instanceof WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status ? $page : null;
	}

	public static function for_author( WP_User $user ): self {
		$context            = new self();
		$context->kind      = self::AUTHOR;
		$context->object    = $user;
		$context->object_id = (int) $user->ID;

		return $context;
	}

	private static function archive_timestamp( WP_Query $query ): int {
		$m     = preg_replace( '/\D/', '', (string) $query->get( 'm' ) );
		$year  = '' !== $m ? (int) substr( $m, 0, 4 ) : (int) $query->get( 'year' );
		$month = '' !== $m ? (int) substr( $m, 4, 2 ) : (int) $query->get( 'monthnum' );
		$day   = '' !== $m ? (int) substr( $m, 6, 2 ) : (int) $query->get( 'day' );

		if ( $year < 1 ) {
			return 0;
		}

		return (int) gmmktime( 0, 0, 0, max( 1, $month ), max( 1, $day ), $year );
	}

	/** Whether the page has its own SEO fields: a post or a term, or the page of an archive. */
	public function has_fields(): bool {
		return $this->object_id > 0 && in_array( $this->kind, array( self::SINGULAR, self::POSTS_PAGE, self::TERM, self::POST_TYPE_ARCHIVE ), true );
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
