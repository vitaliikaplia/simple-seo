<?php
/**
 * What the import takes from Rank Math: its settings (the rows rank-math-options-titles and
 * rank-math-options-general, the list of its modules) and the SEO fields of posts and terms (post
 * and term meta rank_math_*), in Simple SEO's form.
 *
 * Rank Math's data is read straight from the database and is never changed. Its %variables% become
 * Simple SEO's {variables}; one of Rank Math's that Simple SEO has no counterpart for is left out of
 * the text and named in the report, and a %word% that is no variable of Rank Math's stays as text.
 * What an import fills and what it leaves: Simple_SEO_Import_Source.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Rank_Math_Import extends Simple_SEO_Import_Source {
	public const STATE = 'simple_seo_import_rank_math';

	public const NAME = 'Rank Math';

	public const CLEANUP = 'Simple_SEO_Rank_Math_Cleanup';

	private const TITLES = 'rank-math-options-titles';

	private const GENERAL = 'rank-math-options-general';

	private const PREFIX = 'rank_math_';

	/** Rank Math's meta keys without the prefix => Simple SEO's field; the image by its ID before its address. */
	private const FIELDS = array(
		'title'                => 'title',
		'description'          => 'description',
		'facebook_title'       => 'og_title',
		'facebook_description' => 'og_description',
		'facebook_image_id'    => 'og_image',
		'facebook_image'       => 'og_image',
		'robots'               => 'index',
		'canonical_url'        => 'canonical',
	);

	/** Values that may hold variables, by Rank Math's name. */
	private const TEXTS = array( 'title', 'description', 'facebook_title', 'facebook_description' );

	/** Rank Math's variables => Simple SEO's; the rest of Rank Math's (VARIABLES_OF_RANK_MATH) Simple SEO has none for. */
	private const VARIABLES = array(
		'sitename'         => 'site',
		'sitedesc'         => 'tagline',
		'sep'              => 'sep',
		'title'            => 'title',
		'term'             => 'title',
		'excerpt'          => 'excerpt',
		'excerpt_only'     => 'excerpt',
		'term_description' => 'excerpt',
		'user_description' => 'excerpt',
		'category'         => 'category',
		'date'             => 'date',
		'name'             => 'author',
		'post_author'      => 'author',
		'page'             => 'page',
		'search_query'     => 'search',
		'currentyear'      => 'year',
		'pt_single'        => 'post_type',
	);

	/** All of Rank Math's variables: a %word% that is none of them is plain text. */
	private const VARIABLES_OF_RANK_MATH = array(
		'categories', 'category', 'count', 'currentdate', 'currentday', 'currentmonth', 'currenttime', 'currentyear', 'customfield',
		'customterm', 'customterm_desc', 'date', 'excerpt', 'excerpt_only', 'filename', 'focuskw', 'group_desc', 'group_name', 'id',
		'keywords', 'modified', 'name', 'org_logo', 'org_name', 'org_url', 'page', 'pagenumber', 'pagetotal', 'parent_title',
		'post_author', 'post_thumbnail', 'primary_taxonomy_terms', 'pt_plural', 'pt_single', 'search_query', 'seo_description',
		'seo_title', 'sep', 'sitedesc', 'sitename', 'tag', 'tags', 'term', 'term_description', 'title', 'url', 'user_description',
		'userid', 'wc_brand', 'wc_price', 'wc_shortdesc', 'wc_sku',
	);

	/** Rank Math's keyphrases of a post, without the prefix: "first,second,third", the first is the focus keyphrase. */
	private const KEYPHRASES = 'focus_keyword';

	/** Rank Math's post meta Simple SEO has no field for, by what the report calls it. */
	private const LEFT = array(
		'bctitle'    => array( 'rank_math_breadcrumb_title' ),
		'twitter'    => array( 'rank_math_twitter_title', 'rank_math_twitter_description', 'rank_math_twitter_image', 'rank_math_twitter_image_id' ),
		'robots_adv' => array( 'rank_math_advanced_robots' ),
	);

	/** Rank Math's codes for webmaster tools Simple SEO has a field for: its key => Simple SEO's. */
	private const VERIFICATION = array(
		'google_verify'    => 'google',
		'bing_verify'      => 'bing',
		'baidu_verify'     => 'baidu',
		'pinterest_verify' => 'pinterest',
	);

	/** Rank Math's codes Simple SEO has no field for. */
	private const VERIFICATION_LEFT = array( 'yandex_verify', 'norton_verify', 'custom_webmaster_tags' );

	/** Rank Math's switches that take a base out of addresses. */
	private const URL_BASES = array( 'strip_category_base', 'wc_remove_product_base', 'wc_remove_category_base', 'wc_remove_category_parent_slugs' );

	public static function found(): bool {
		return is_array( self::option( self::TITLES ) ) || self::next_ids( 'post', 0, 1 ) || self::next_ids( 'term', 0, 1 );
	}

	public static function summary(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- built from constants.
		return array(
			'settings' => is_array( self::option( self::TITLES ) ),
			'posts'    => (int) $wpdb->get_var( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE " . self::keys_sql( 'post' ) ),
			'terms'    => (int) $wpdb->get_var( "SELECT COUNT( DISTINCT term_id ) FROM {$wpdb->termmeta} WHERE " . self::keys_sql( 'term' ) ),
		);
		// phpcs:enable
	}

	public static function variables_note(): string {
		return __( 'Rank Math\'s %variables% become Simple SEO\'s {variables}. A variable Simple SEO has no counterpart for is left out of the text and named in the report.', 'simple-seo' );
	}

	public static function variable( string $name ): string {
		return '%' . $name . '%';
	}

	// ----------------------------------------------------------------------------------------
	// Settings.

	/**
	 * Take Rank Math's settings: separator, templates, indexing, social networks, the home and 404
	 * pages, breadcrumbs, the sitemap and robots.txt.
	 *
	 * @param array<string, mixed> $report
	 */
	public static function settings( array &$report ): void {
		$titles  = self::option( self::TITLES );
		$general = self::option( self::GENERAL );
		$modules = self::option( 'rank_math_modules' );
		$general = is_array( $general ) ? $general : array();

		if ( is_array( $titles ) ) {
			$separator = self::scalar( $titles['title_separator'] ?? '' );
			self::titles( in_array( $separator, Simple_SEO_Titles::SEPARATORS, true ) ? $separator : '', self::templates( $titles ), $report );
			self::take_indexing( $titles, $general, $report );
			self::special(
				'',
				array(
					'home' => array(
						'title'       => $titles['homepage_title'] ?? '',
						'description' => $titles['homepage_description'] ?? '',
					),
					'404'  => array( 'title' => $titles['404_title'] ?? '' ),
				),
				self::image( $titles, 'homepage_facebook_image' ),
				$report
			);
			self::social( self::image( $titles, 'open_graph_image' ), self::scalar( $titles['twitter_author_names'] ?? '' ), $report );
			self::take_schema( $titles, $report );
			self::take_local( $titles, $report );
		}
		if ( $general || is_array( $titles ) ) {
			self::take_breadcrumbs( $general, is_array( $titles ) ? $titles : array(), $report );
			self::labels(
				'',
				array(
					'home'   => self::scalar( $general['breadcrumbs_home_label'] ?? '' ),
					'prefix' => self::scalar( $general['breadcrumbs_prefix'] ?? '' ),
				),
				$report
			);
		}
		// Rank Math's sitemap is a module: switched off, the site had none.
		if ( is_array( $modules ) && ! in_array( 'sitemap', $modules, true ) ) {
			self::sitemaps_off( $report );
		}
		self::robots_txt( self::scalar( $general['robots_txt_content'] ?? '' ), $report );
		self::left_settings( $general, is_array( $titles ) ? $titles : array(), $report );
	}

	/**
	 * Rank Math's templates of titles and descriptions by Simple SEO's slot.
	 *
	 * @param array<string, mixed> $titles
	 * @return array<string, array<string, mixed>>
	 */
	private static function templates( array $titles ): array {
		$keys = static function ( string $kind, string $name ): array {
			switch ( $kind ) {
				case 'author':
				case 'date':
					return array(
						'title'       => $kind . '_archive_title',
						'description' => $kind . '_archive_description',
					);
				case 'search':
					return array( 'title' => 'search_title' );
				case 'post':
					return array(
						'title'       => 'pt_' . $name . '_title',
						'description' => 'pt_' . $name . '_description',
					);
				case 'archive':
					return array(
						'title'       => 'pt_' . $name . '_archive_title',
						'description' => 'pt_' . $name . '_archive_description',
					);
				default:
					return array(
						'title'       => 'tax_' . $name . '_title',
						'description' => 'tax_' . $name . '_description',
					);
			}
		};

		return array_map( static fn( array $slot ): array => array_map( static fn( string $key ) => $titles[ $key ] ?? '', $slot ), self::slots( $keys ) );
	}

	/**
	 * Which kinds of pages Rank Math kept from search engines, and where attachment pages go. A kind
	 * with robots of its own ("custom robots") follows them, the rest the global robots.
	 *
	 * @param array<string, mixed> $titles
	 * @param array<string, mixed> $general
	 */
	private static function take_indexing( array $titles, array $general, array &$report ): void {
		$noindex_in = static fn( $robots ): bool => is_array( $robots ) && in_array( 'noindex', $robots, true );
		$global     = $noindex_in( $titles['robots_global'] ?? array() );
		$hidden     = static fn( string $prefix ): bool => self::on( $titles[ $prefix . '_custom_robots' ] ?? '' ) ? $noindex_in( $titles[ $prefix . '_robots' ] ?? array() ) : $global;
		$on         = static fn( string $key ): bool => self::on( $titles[ $key ] ?? '' );

		$noindex = array();
		foreach ( array_merge( Simple_SEO_Fields::post_types(), array( 'attachment' ) ) as $type ) {
			if ( $hidden( 'pt_' . $type ) ) {
				$noindex[] = 'post:' . $type;
				// A post type's archive follows the same robots.
				if ( ! empty( get_post_type_object( $type )->has_archive ) ) {
					$noindex[] = 'archive:' . $type;
				}
			}
		}
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			if ( $hidden( 'tax_' . $taxonomy ) ) {
				$noindex[] = 'term:' . $taxonomy;
			}
		}
		// Archives Rank Math switched off entirely Simple SEO hides from search engines.
		if ( $on( 'disable_author_archives' ) || $hidden( 'author' ) ) {
			$noindex[] = 'author';
		}
		$date = $titles['date_archive_robots'] ?? array();
		if ( $on( 'disable_date_archives' ) || ( is_array( $date ) && $date ? $noindex_in( $date ) : $global ) ) {
			$noindex[] = 'date';
		}

		// Rank Math sent attachment pages to the post they belong to, or kept them.
		$attachments = '';
		if ( array_key_exists( 'attachment_redirect_urls', $general ) ) {
			$attachments = self::on( $general['attachment_redirect_urls'] ) ? Simple_SEO_Indexing::ATTACHMENTS_PARENT : Simple_SEO_Indexing::ATTACHMENTS_KEEP;
		}

		self::indexing( $noindex, $attachments, $report );
	}

	/**
	 * @param array<string, mixed> $general
	 * @param array<string, mixed> $titles
	 */
	private static function take_breadcrumbs( array $general, array $titles, array &$report ): void {
		$taxonomies = array();
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$taxonomies[ $type ] = self::scalar( $titles[ 'pt_' . $type . '_primary_taxonomy' ] ?? '' );
		}
		$switch = static fn( string $key ): ?bool => array_key_exists( $key, $general ) ? self::on( $general[ $key ] ) : null;
		$hide   = $switch( 'breadcrumbs_remove_post_title' );

		self::breadcrumbs( self::scalar( $general['breadcrumbs_separator'] ?? '' ), null === $hide ? null : ! $hide, $switch( 'breadcrumbs_blog_page' ), $taxonomies, $report );
	}

	/**
	 * Rank Math's text of robots.txt, served instead of WordPress's lines as Simple SEO does.
	 *
	 * @param array<string, mixed> $report
	 */
	private static function robots_txt( string $text, array &$report ): void {
		$text = Simple_SEO_Files::normalize( $text );
		// The lines of WordPress are what Simple SEO serves anyway.
		if ( '' === $text || Simple_SEO_Files::robots_default() === $text || '' !== Simple_SEO_Files::robots_option() ) {
			return;
		}
		update_option( Simple_SEO_Files::ROBOTS_OPTION, $text, false );
		self::add( $report, 'settings', 'robots_txt' );
	}

	/**
	 * Rank Math's settings Simple SEO has nothing for.
	 *
	 * @param array<string, mixed> $general
	 * @param array<string, mixed> $titles
	 */
	private static function left_settings( array $general, array $titles, array &$report ): void {
		$filled = static fn( array $row, array $keys ): bool => (bool) array_filter( $keys, static fn( string $key ): bool => '' !== self::scalar( $row[ $key ] ?? '' ) );

		if ( $filled( $titles, array( 'homepage_facebook_title', 'homepage_facebook_description' ) ) ) {
			$report['left']['social_templates'] = 1;
		}
		if ( $filled( $general, array( 'breadcrumbs_archive_format', 'breadcrumbs_search_format', 'breadcrumbs_404_label' ) ) ) {
			$report['left']['breadcrumb_texts'] = 1;
		}
		$codes = array();
		foreach ( self::VERIFICATION as $key => $field ) {
			$codes[ $field ] = $general[ $key ] ?? '';
		}
		self::verification( $codes, $report );
		if ( $filled( $general, self::VERIFICATION_LEFT ) ) {
			$report['left']['verification'] = 1;
		}
		if ( array_filter( self::URL_BASES, static fn( string $key ): bool => self::on( $general[ $key ] ?? '' ) ) ) {
			$report['left']['url_bases'] = 1;
		}
	}

	/**
	 * Whether a switch of Rank Math's is on: its installer writes 'on' and 'off', its settings
	 * screen saves true and false.
	 *
	 * @param mixed $value
	 */
	private static function on( $value ): bool {
		return in_array( $value, array( 'on', true, 1, '1' ), true );
	}

	/**
	 * Whom the site represents (an organization: Rank Math's person is a name, not a user, so the
	 * report asks to choose one), the names, the logo and the profiles elsewhere.
	 *
	 * @param array<string, mixed> $titles
	 * @param array<string, mixed> $report
	 */
	private static function take_schema( array $titles, array &$report ): void {
		$company = 'person' !== self::scalar( $titles['knowledgegraph_type'] ?? '' );
		if ( ! $company ) {
			$report['left']['schema_person'] = 1;
		}

		$common = $company ? array( 'represents' => 'organization' ) : array();
		// The type of article of each post type: its default rich snippet, an article of the type chosen.
		foreach ( $titles as $key => $value ) {
			if ( preg_match( '/^pt_(.+)_default_rich_snippet$/', (string) $key, $match ) && '' !== self::scalar( $value ) ) {
				$common['article_types'][ $match[1] ] = 'article' === self::scalar( $value )
					? ( self::scalar( $titles[ 'pt_' . $match[1] . '_default_article_type' ] ?? '' ) ?: ( 'post' === $match[1] ? 'BlogPosting' : 'Article' ) )
					: 'None';
			}
		}
		$logo   = self::image( $titles, 'knowledgegraph_logo' );
		if ( $logo ) {
			$common['logo'] = $logo;
		}
		$profiles = array_merge(
			array( self::scalar( $titles['social_url_facebook'] ?? '' ) ),
			(array) preg_split( '/\s+/', self::scalar( $titles['social_additional_profiles'] ?? '' ) )
		);
		$profiles = array_values( array_filter( array_map( 'strval', $profiles ) ) );
		if ( $profiles ) {
			$common['profiles'] = $profiles;
		}

		self::schema(
			'',
			$common,
			array(
				'site_name'      => self::scalar( $titles['website_name'] ?? '' ),
				'site_alternate' => self::scalar( $titles['website_alternate_name'] ?? '' ),
				'name'           => $company ? self::scalar( $titles['knowledgegraph_name'] ?? '' ) : '',
			),
			$report
		);
	}

	/**
	 * The organization of Rank Math's Local SEO, for the tab Local SEO: the type of business, the
	 * description, e-mail, phone (else the first of its phone numbers), address, coordinates, price
	 * range and opening hours. A person's site has none.
	 *
	 * @param array<string, mixed> $titles
	 * @param array<string, mixed> $report
	 */
	private static function take_local( array $titles, array &$report ): void {
		if ( 'person' === self::scalar( $titles['knowledgegraph_type'] ?? '' ) ) {
			return;
		}

		$phone = self::scalar( $titles['phone'] ?? '' );
		foreach ( is_array( $titles['phone_numbers'] ?? null ) ? $titles['phone_numbers'] : array() as $number ) {
			if ( '' === $phone && is_array( $number ) ) {
				$phone = self::scalar( $number['number'] ?? '' );
			}
		}
		$address = is_array( $titles['local_address'] ?? null ) ? $titles['local_address'] : array();

		self::local(
			'',
			array_filter(
				array(
					'type'        => self::scalar( $titles['local_business_type'] ?? '' ),
					'email'       => self::scalar( $titles['email'] ?? '' ),
					'phone'       => $phone,
					'postal_code' => self::scalar( $address['postalCode'] ?? '' ),
					'country'     => self::scalar( $address['addressCountry'] ?? '' ),
					'geo'         => self::scalar( $titles['geo'] ?? '' ),
					'hours'       => self::opening_hours( $titles['opening_hours'] ?? null ),
				)
			),
			array(
				'description' => self::scalar( $titles['organization_description'] ?? '' ),
				'street'      => self::scalar( $address['streetAddress'] ?? '' ),
				'locality'    => self::scalar( $address['addressLocality'] ?? '' ),
				'region'      => self::scalar( $address['addressRegion'] ?? '' ),
				'price_range' => self::scalar( $titles['price_range'] ?? '' ),
			),
			$report
		);
	}

	/**
	 * Rank Math's opening hours — rows of a day (Monday) and a time (09:00-17:00) — by Simple SEO's
	 * day. A day of several rows (a break at midday) keeps the first: Simple SEO has one per day.
	 *
	 * @param mixed $rows
	 * @return array<string, array{opens: string, closes: string}>
	 */
	private static function opening_hours( $rows ): array {
		$days  = array_flip( Simple_SEO_Local::DAYS );
		$hours = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$day = is_array( $row ) ? ( $days[ self::scalar( $row['day'] ?? '' ) ] ?? '' ) : '';
			if ( '' === $day || isset( $hours[ $day ] ) || ! preg_match( '/^\s*(\d{1,2}[:.]\d{2})\s*[-\x{2013}\x{2014}]\s*(\d{1,2}[:.]\d{2})\s*$/u', self::scalar( $row['time'] ?? '' ), $match ) ) {
				continue;
			}
			$hours[ $day ] = array(
				'opens'  => $match[1],
				'closes' => $match[2],
			);
		}

		return $hours;
	}

	/**
	 * An image of Rank Math's settings: by its ID, else by its address.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function image( array $row, string $key ): int {
		$id = Simple_SEO_Fields::image_attachment( $row[ $key . '_id' ] ?? 0 );
		if ( ! $id ) {
			$url = self::scalar( $row[ $key ] ?? '' );
			$id  = '' !== $url ? Simple_SEO_Fields::image_attachment( attachment_url_to_postid( $url ) ) : 0;
		}

		return $id;
	}

	// ----------------------------------------------------------------------------------------
	// Posts and terms.

	public static function posts( int $after, int $limit, array &$report ): int {
		$ids = self::next_ids( 'post', $after, $limit );
		if ( ! $ids ) {
			return 0;
		}

		$values = self::values( 'post', $ids );
		$types  = Simple_SEO_Fields::post_types();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $types, true ) ) {
				continue;
			}
			self::take( 'post', $id, self::fields( 'post', $post->post_type, $values[ $id ] ?? array() ), $report );
		}

		return (int) end( $ids );
	}

	/**
	 * @param int                  $cursor The last term ID taken.
	 * @param array<string, mixed> $report
	 */
	public static function terms( int $cursor, int $limit, array &$report ): int {
		$ids = self::next_ids( 'term', $cursor, $limit );
		if ( ! $ids ) {
			return 0;
		}

		$values     = self::values( 'term', $ids );
		$taxonomies = Simple_SEO_Fields::taxonomies();
		foreach ( $ids as $id ) {
			$term = get_term( $id );
			if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, $taxonomies, true ) ) {
				continue;
			}
			self::take( 'term', $id, self::fields( 'term', '', $values[ $id ] ?? array() ), $report );
		}

		return (int) end( $ids );
	}

	/**
	 * Rank Math's values of a post or term by Simple SEO's field, in the order they are tried: the
	 * image by its ID before its address. The robots list gives both indexing and nofollow.
	 *
	 * @param array<string, string> $values Rank Math's name => value.
	 * @return array<string, array<int, array{0: string, 1: string}>>
	 */
	private static function fields( string $object_type, string $post_type, array $values ): array {
		$fields = array();
		foreach ( self::FIELDS as $name => $key ) {
			if ( isset( $values[ $name ] ) && '' !== self::field_for( $key, $object_type, $post_type ) ) {
				$fields[ $key ][] = array( $name, $values[ $name ] );
			}
		}
		if ( isset( $values['robots'] ) && '' !== self::field_for( 'nofollow', $object_type, $post_type ) ) {
			$fields['nofollow'][] = array( 'robots', $values['robots'] );
		}
		if ( isset( $values[ self::KEYPHRASES ] ) ) {
			$phrases = explode( ',', $values[ self::KEYPHRASES ] );
			self::keyphrases( $fields, self::KEYPHRASES, array_shift( $phrases ), $phrases, $object_type, $post_type );
		}
		foreach ( $values as $name => $value ) {
			if ( str_starts_with( $name, 'primary_' ) && '' !== self::field_for( $name, $object_type, $post_type ) ) {
				$fields[ $name ][] = array( $name, $value );
			}
		}

		return $fields;
	}

	protected static function value( string $name, string $key, string $value, array &$report ) {
		if ( 'robots' === $name ) {
			$robots = is_serialized( $value ) ? unserialize( $value, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- no objects.
			if ( ! is_array( $robots ) ) {
				return null;
			}
			if ( 'nofollow' === $key ) {
				return in_array( 'nofollow', $robots, true ) ? true : '';
			}
			$value = in_array( 'noindex', $robots, true ) ? 'noindex' : ( in_array( 'index', $robots, true ) ? 'index' : '' );
		} elseif ( 'facebook_image' === $name ) {
			$value = self::image_by_url( $value );
		} elseif ( 'facebook_image_id' === $name || str_starts_with( $name, 'primary_' ) ) {
			// An ID of 0 is no image, no term.
			$value = '0' !== $value ? $value : '';
		} elseif ( in_array( $name, self::TEXTS, true ) ) {
			$value = self::text( $value, '', $report );
		}

		return self::field_value( $key, $value );
	}

	public static function left(): array {
		global $wpdb;

		$left = array();
		foreach ( self::LEFT as $what => $keys ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE meta_key IN (" . self::in( $keys ) . ") AND meta_value NOT IN ( '', '0', 'a:0:{}' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- keys from a constant.
			if ( $count ) {
				$left[ $what ] = $count;
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from constants and the table prefix.
		$schema = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank\\_math\\_schema\\_%'" );
		$users  = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT user_id ) FROM {$wpdb->usermeta} WHERE meta_key IN ( 'rank_math_title', 'rank_math_description', 'rank_math_robots' ) AND meta_value NOT IN ( '', 'a:0:{}' )" );
		// Redirections worked only with their module on.
		$modules      = self::option( 'rank_math_modules' );
		$table        = $wpdb->prefix . 'rank_math_redirections';
		$redirections = is_array( $modules ) && in_array( 'redirections', $modules, true ) && $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active'" )
			: 0;
		// phpcs:enable

		return $left + array_filter(
			array(
				'schema'       => $schema,
				'authors'      => $users,
				'redirections' => $redirections,
			)
		);
	}

	// ----------------------------------------------------------------------------------------
	// Helpers.

	/**
	 * A text of Rank Math's in Simple SEO's syntax. %date(F j, Y)% is {date} in the site's format; on
	 * a post type archive %pt_plural% is the archive's {title}.
	 *
	 * @param array<string, mixed> $report
	 */
	protected static function text( string $value, string $slot, array &$report ): string {
		if ( ! str_contains( $value, '%' ) ) {
			return trim( $value );
		}

		$own   = Simple_SEO_Variables::labels();
		$value = (string) preg_replace_callback(
			'/%([A-Za-z_]+)(?:\([^)%]*\))?%/',
			static function ( array $match ) use ( $slot, $own, &$report ): string {
				$name = strtolower( $match[1] );
				if ( ! in_array( $name, self::VARIABLES_OF_RANK_MATH, true ) ) {
					return $match[0];
				}
				if ( 'pt_plural' === $name && str_starts_with( $slot, 'archive:' ) ) {
					return '{title}';
				}
				$simple = self::VARIABLES[ $name ] ?? '';
				if ( isset( $own[ $simple ] ) ) {
					return '{' . $simple . '}';
				}
				self::add( $report, 'dropped', $name );

				return '';
			},
			$value
		);

		return trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $value ) );
	}

	/**
	 * The next posts or terms after an ID that have a field the import takes.
	 *
	 * @return int[]
	 */
	private static function next_ids( string $type, int $after, int $limit ): array {
		global $wpdb;

		$table  = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = $type . '_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table and the key condition are built from constants.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} > %d AND ", $after ) . self::keys_sql( $type ) . $wpdb->prepare( " ORDER BY {$column} LIMIT %d", $limit ) ) );
	}

	/**
	 * Rank Math's values of the posts or terms: ID => its name of the value => the first row's value,
	 * as get_metadata( …, true ) reads it.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, string>>
	 */
	private static function values( string $type, array $ids ): array {
		global $wpdb;

		$table  = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = $type . '_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are integers, the rest built from constants.
		$rows = $wpdb->get_results( "SELECT {$column} AS id, meta_key, meta_value FROM {$table} WHERE {$column} IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') AND ' . self::keys_sql( $type ) . ' ORDER BY meta_id', ARRAY_A );

		$values = array();
		foreach ( $rows as $row ) {
			$values[ (int) $row['id'] ][ substr( (string) $row['meta_key'], strlen( self::PREFIX ) ) ] ??= (string) $row['meta_value'];
		}

		return $values;
	}

	/** SQL condition on post or term meta: the keys the import takes, with a value. Terms have no keyphrases. */
	private static function keys_sql( string $type ): string {
		$names   = array_keys( self::FIELDS );
		$primary = '';
		if ( 'post' === $type ) {
			$names[] = self::KEYPHRASES;
			$primary = " OR meta_key LIKE 'rank\\_math\\_primary\\_%'";
		}
		$keys = array_map( static fn( string $name ): string => self::PREFIX . $name, $names );

		return '( meta_key IN (' . self::in( $keys ) . ')' . $primary . " ) AND meta_value NOT IN ( '', '0', 'a:0:{}' )";
	}
}
