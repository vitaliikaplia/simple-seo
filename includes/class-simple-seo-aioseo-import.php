<?php
/**
 * What the import takes from All in One SEO: its settings (the JSON rows aioseo_options and
 * aioseo_options_dynamic) and the SEO fields of posts (its table aioseo_posts) and of terms (the
 * table aioseo_terms of AIOSEO Pro, where the site has it), in Simple SEO's form.
 *
 * AIOSEO's data is read straight from the database and is never changed. Its #tags become Simple
 * SEO's {variables}; one of AIOSEO's that Simple SEO has no counterpart for is left out of the text
 * and named in the report, and a #word that is no tag of AIOSEO's stays as text. What an import
 * fills and what it leaves: Simple_SEO_Import_Source.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Aioseo_Import extends Simple_SEO_Import_Source {
	public const STATE = 'simple_seo_import_aioseo';

	public const NAME = 'All in One SEO';

	public const CLEANUP = 'Simple_SEO_Aioseo_Cleanup';

	/** Columns of AIOSEO's tables => Simple SEO's field; the robots columns give two fields. */
	private const FIELDS = array(
		'title'               => 'title',
		'description'         => 'description',
		'og_title'            => 'og_title',
		'og_description'      => 'og_description',
		'og_image_custom_url' => 'og_image',
		'canonical_url'       => 'canonical',
	);

	/** Values that may hold tags, by column. */
	private const TEXTS = array( 'title', 'description', 'og_title', 'og_description' );

	/** AIOSEO's tags => Simple SEO's variables; #taxonomy_title is a term's title on its archive, else the post's category. */
	private const TAGS = array(
		'site_title'           => 'site',
		'blog_title'           => 'site',
		'tagline'              => 'tagline',
		'site_description'     => 'tagline',
		'separator_sa'         => 'sep',
		'post_title'           => 'title',
		'archive_title'        => 'title',
		'post_excerpt'         => 'excerpt',
		'post_excerpt_only'    => 'excerpt',
		'post_content'         => 'excerpt',
		'taxonomy_description' => 'excerpt',
		'author_bio'           => 'excerpt',
		'category'             => 'category',
		'author_name'          => 'author',
		'post_date'            => 'date',
		'archive_date'         => 'date',
		'search_term'          => 'search',
		'current_year'         => 'year',
	);

	/** All of AIOSEO's tags: a #word that is none of them is plain text. */
	private const TAGS_OF_AIOSEO = array(
		'alt_tag', 'archive_date', 'archive_title', 'attachment_caption', 'attachment_description', 'author_bio', 'author_first_name',
		'author_last_name', 'author_link', 'author_link_alt', 'author_name', 'author_url', 'blog_link', 'blog_title', 'categories',
		'category', 'category_link', 'category_link_alt', 'current_date', 'current_day', 'current_month', 'current_year', 'description',
		'event_end_date', 'event_start_date', 'featured_image', 'featured_image_url', 'page_number', 'parent_title', 'permalink',
		'post_content', 'post_date', 'post_day', 'post_excerpt', 'post_excerpt_only', 'post_link', 'post_link_alt', 'post_modified_date',
		'post_month', 'post_title', 'post_year', 'search_term', 'separator_sa', 'site_description', 'site_link', 'site_link_alt',
		'site_title', 'tagline', 'tax_parent_name', 'taxonomy_description', 'taxonomy_title',
	);

	/** AIOSEO's codes for webmaster tools. */
	/** AIOSEO's codes for webmaster tools Simple SEO has a field for (the same names). */
	private const VERIFICATION = array( 'google', 'bing', 'baidu', 'pinterest' );

	/** AIOSEO's codes Simple SEO has no field for. */
	private const VERIFICATION_LEFT = array( 'yandex', 'norton', 'microsoftClarityProjectId', 'miscellaneousVerification' );

	/** AIOSEO's feeds (kept when on) => Simple SEO's switches that remove them. */
	private const FEEDS = array(
		'global'         => 'feed_posts',
		'globalComments' => 'feed_comments',
		'postComments'   => 'feed_post_comments',
		'authors'        => 'feed_authors',
		'search'         => 'feed_search',
	);

	public static function found(): bool {
		return null !== self::option( 'aioseo_options' ) || self::next_ids( 'post', 0, 1 ) || self::next_ids( 'term', 0, 1 );
	}

	public static function summary(): array {
		return array(
			'settings' => null !== self::option( 'aioseo_options' ),
			'posts'    => self::count( 'post' ),
			'terms'    => self::count( 'term' ),
		);
	}

	public static function variables_note(): string {
		return __( 'AIOSEO\'s #tags become Simple SEO\'s {variables}. A tag Simple SEO has no counterpart for is left out of the text and named in the report.', 'simple-seo' );
	}

	public static function variable( string $name ): string {
		return '#' . $name;
	}

	// ----------------------------------------------------------------------------------------
	// Settings.

	/**
	 * Take AIOSEO's settings: separator, templates, indexing, social networks, the home page,
	 * breadcrumbs, the sitemap and clean-up.
	 *
	 * @param array<string, mixed> $report
	 */
	public static function settings( array &$report ): void {
		$options = self::json( 'aioseo_options' );
		$dynamic = self::json( 'aioseo_options_dynamic' );
		if ( ! $options ) {
			return;
		}
		$get = static fn( string $path ) => self::get( $options, $path );

		$separator = self::scalar( html_entity_decode( self::scalar( $get( 'searchAppearance.global.separator' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		self::titles( in_array( $separator, Simple_SEO_Titles::SEPARATORS, true ) ? $separator : '', self::templates( $options, $dynamic ), $report );
		self::take_indexing( $options, $dynamic, $report );
		self::special(
			'',
			array(
				'home' => array(
					'title'       => $get( 'searchAppearance.global.siteTitle' ) ?? '',
					'description' => $get( 'searchAppearance.global.metaDescription' ) ?? '',
				),
			),
			self::image( self::scalar( $get( 'social.facebook.homePage.image' ) ) ),
			$report
		);
		$image = 'default' === $get( 'social.facebook.general.defaultImageSourcePosts' ) ? self::image( self::scalar( $get( 'social.facebook.general.defaultImagePosts' ) ) ) : 0;
		self::social( $image, self::scalar( $get( 'social.profiles.urls.twitterUrl' ) ), $report );
		self::take_schema( $get, $dynamic, $report );

		$switch = static fn( string $path ): ?bool => is_bool( $get( $path ) ) ? $get( $path ) : null;
		self::breadcrumbs( self::scalar( $get( 'breadcrumbs.separator' ) ), $switch( 'breadcrumbs.showCurrentItem' ), $switch( 'breadcrumbs.showBlogHome' ), array(), $report );
		self::labels(
			'',
			array(
				'home'   => self::scalar( $get( 'breadcrumbs.homepageLabel' ) ),
				'prefix' => self::scalar( $get( 'breadcrumbs.breadcrumbPrefix' ) ),
			),
			$report
		);
		if ( false === $get( 'sitemap.general.enable' ) ) {
			self::sitemaps_off( $report );
		}
		self::take_cleanup( (array) $get( 'searchAppearance.advanced.crawlCleanup' ), $report );
		self::left_settings( $options, $report );
	}

	/**
	 * AIOSEO's templates of titles and descriptions by Simple SEO's slot: those of post types, their
	 * archives and taxonomies are dynamic options, the author, date and search ones the main ones.
	 *
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $dynamic
	 * @return array<string, array<string, mixed>>
	 */
	private static function templates( array $options, array $dynamic ): array {
		$keys = static function ( string $kind, string $name ): array {
			$paths = array(
				'author'  => 'options:searchAppearance.archives.author',
				'date'    => 'options:searchAppearance.archives.date',
				'search'  => 'options:searchAppearance.archives.search',
				'post'    => 'dynamic:searchAppearance.postTypes.' . $name,
				'archive' => 'dynamic:searchAppearance.archives.' . $name,
				'term'    => 'dynamic:searchAppearance.taxonomies.' . $name,
			);
			$path  = $paths[ $kind ];

			return 'search' === $kind ? array( 'title' => $path . '.title' ) : array(
				'title'       => $path . '.title',
				'description' => $path . '.metaDescription',
			);
		};
		$value = static function ( string $key ) use ( $options, $dynamic ) {
			[ $row, $path ] = explode( ':', $key, 2 );

			return self::get( 'options' === $row ? $options : $dynamic, $path ) ?? '';
		};

		return array_map( static fn( array $slot ): array => array_map( $value, $slot ), self::slots( $keys ) );
	}

	/**
	 * Which kinds of pages AIOSEO kept from search engines, and where attachment pages go. A kind
	 * not shown in search results is hidden; a kind with robots of its own follows them, the rest
	 * the global robots.
	 *
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $dynamic
	 */
	private static function take_indexing( array $options, array $dynamic, array &$report ): void {
		$global = self::get( $options, 'searchAppearance.advanced.globalRobotsMeta' );
		$global = is_array( $global ) && empty( $global['default'] ) && ! empty( $global['noindex'] );
		$hidden = static function ( $node ) use ( $global ): bool {
			if ( ! is_array( $node ) ) {
				return $global;
			}
			if ( array_key_exists( 'show', $node ) && false === $node['show'] ) {
				return true;
			}
			$robots = $node['advanced']['robotsMeta'] ?? null;

			return is_array( $robots ) && empty( $robots['default'] ) ? ! empty( $robots['noindex'] ) : $global;
		};

		$noindex = array();
		foreach ( array_merge( Simple_SEO_Fields::post_types(), array( 'attachment' ) ) as $type ) {
			if ( $hidden( self::get( $dynamic, 'searchAppearance.postTypes.' . $type ) ) ) {
				$noindex[] = 'post:' . $type;
			}
			if ( ! empty( get_post_type_object( $type )->has_archive ) && $hidden( self::get( $dynamic, 'searchAppearance.archives.' . $type ) ) ) {
				$noindex[] = 'archive:' . $type;
			}
		}
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			if ( $hidden( self::get( $dynamic, 'searchAppearance.taxonomies.' . $taxonomy ) ) ) {
				$noindex[] = 'term:' . $taxonomy;
			}
		}
		foreach ( array( 'author', 'date' ) as $slot ) {
			if ( $hidden( self::get( $options, 'searchAppearance.archives.' . $slot ) ) ) {
				$noindex[] = $slot;
			}
		}

		// AIOSEO sent attachment pages to the file, to the post they belong to, or kept them.
		$modes = array(
			'attachment'        => Simple_SEO_Indexing::ATTACHMENTS_FILE,
			'attachment_parent' => Simple_SEO_Indexing::ATTACHMENTS_PARENT,
			'disabled'          => Simple_SEO_Indexing::ATTACHMENTS_KEEP,
		);

		self::indexing( $noindex, $modes[ self::scalar( self::get( $dynamic, 'searchAppearance.postTypes.attachment.redirectAttachmentUrls' ) ) ] ?? '', $report );
	}

	/**
	 * AIOSEO's crawl clean-up: with it on, a feed switched off is removed by Simple SEO too.
	 *
	 * @param array<string, mixed> $crawl
	 * @param array<string, mixed> $report
	 */
	private static function take_cleanup( array $crawl, array &$report ): void {
		if ( empty( $crawl['enable'] ) ) {
			return;
		}
		$feeds = (array) ( $crawl['feeds'] ?? array() );
		$keys  = array();
		foreach ( self::FEEDS as $feed => $key ) {
			if ( array_key_exists( $feed, $feeds ) && false === $feeds[ $feed ] ) {
				$keys[] = $key;
			}
		}
		$taxonomies = (array) ( $feeds['taxonomies'] ?? array() );
		if ( array_key_exists( 'all', $taxonomies ) && ! $taxonomies['all'] ) {
			foreach ( array( 'category' => 'feed_categories', 'post_tag' => 'feed_tags' ) as $taxonomy => $key ) {
				if ( ! in_array( $taxonomy, (array) ( $taxonomies['included'] ?? array() ), true ) ) {
					$keys[] = $key;
				}
			}
		}
		self::cleanup( $keys, $report );
	}

	/**
	 * AIOSEO's settings Simple SEO has nothing for.
	 *
	 * @param array<string, mixed> $options
	 */
	private static function left_settings( array $options, array &$report ): void {
		$filled = static fn( array $paths ): bool => (bool) array_filter( $paths, static fn( string $path ): bool => '' !== self::scalar( self::get( $options, $path ) ) );

		if ( $filled( array( 'social.facebook.homePage.title', 'social.facebook.homePage.description', 'social.twitter.homePage.title', 'social.twitter.homePage.description' ) ) ) {
			$report['left']['social_templates'] = 1;
		}
		if ( $filled( array( 'breadcrumbs.archiveFormat', 'breadcrumbs.searchResultFormat', 'breadcrumbs.errorFormat404' ) ) ) {
			$report['left']['breadcrumb_texts'] = 1;
		}
		$codes = array();
		foreach ( self::VERIFICATION as $key ) {
			$codes[ $key ] = self::get( $options, 'webmasterTools.' . $key );
		}
		self::verification( $codes, $report );
		if ( $filled( array_map( static fn( string $key ): string => 'webmasterTools.' . $key, self::VERIFICATION_LEFT ) ) ) {
			$report['left']['verification'] = 1;
		}
		if ( true === self::get( $options, 'searchAppearance.advanced.removeCategoryBase' ) ) {
			$report['left']['url_bases'] = 1;
		}
		if ( true === self::get( $options, 'tools.robots.enable' ) && self::get( $options, 'tools.robots.rules' ) ) {
			$report['left']['robots_rules'] = 1;
		}
	}

	// ----------------------------------------------------------------------------------------
	// Posts and terms.

	public static function posts( int $after, int $limit, array &$report ): int {
		$ids = self::next_ids( 'post', $after, $limit );
		if ( ! $ids ) {
			return 0;
		}

		$rows  = self::rows( 'post', $ids );
		$types = Simple_SEO_Fields::post_types();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $types, true ) ) {
				continue;
			}
			self::take( 'post', $id, self::fields( 'post', $post->post_type, $rows[ $id ] ?? array() ), $report );
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

		$rows       = self::rows( 'term', $ids );
		$taxonomies = Simple_SEO_Fields::taxonomies();
		foreach ( $ids as $id ) {
			$term = get_term( $id );
			if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, $taxonomies, true ) ) {
				continue;
			}
			self::take( 'term', $id, self::fields( 'term', '', $rows[ $id ] ?? array() ), $report );
		}

		return (int) end( $ids );
	}

	/**
	 * AIOSEO's values of a post or term by Simple SEO's field. The image counts only when AIOSEO
	 * used a custom one; robots only when the post does not follow its type's.
	 *
	 * @param array<string, mixed> $row A row of AIOSEO's table.
	 * @return array<string, array<int, array{0: string, 1: string}>>
	 */
	private static function fields( string $object_type, string $post_type, array $row ): array {
		$fields = array();
		foreach ( self::FIELDS as $column => $key ) {
			$value = self::scalar( $row[ $column ] ?? '' );
			if ( 'og_image_custom_url' === $column && 'custom_image' !== ( $row['og_image_type'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $value && '' !== self::field_for( $key, $object_type, $post_type ) ) {
				// A term's texts are read as a term archive's: #taxonomy_title is its own title.
				$fields[ $key ][] = array( ( 'term' === $object_type ? 'term:' : '' ) . $column, $value );
			}
		}
		if ( isset( $row['robots_default'] ) && '0' === (string) $row['robots_default'] ) {
			foreach ( array( 'robots_noindex' => 'index', 'robots_nofollow' => 'nofollow' ) as $column => $key ) {
				if ( '' !== self::field_for( $key, $object_type, $post_type ) ) {
					$fields[ $key ][] = array( $column, (string) ( $row[ $column ] ?? '0' ) );
				}
			}
		}
		$primary = json_decode( (string) ( $row['primary_term'] ?? '' ), true );
		foreach ( is_array( $primary ) ? $primary : array() as $taxonomy => $id ) {
			$key = Simple_SEO_Primary_Term::field( (string) $taxonomy );
			if ( is_scalar( $id ) && '' !== self::field_for( $key, $object_type, $post_type ) ) {
				$fields[ $key ][] = array( 'primary_term', (string) $id );
			}
		}
		// AIOSEO 5.0 keeps the keyphrases in focus_keyword and additional_keywords ([{"word":…}]); rows
		// written before have them only in keyphrases ({"focus":{"keyphrase":…},"additional":[{"keyphrase":…}]}).
		// As AIOSEO itself does, each falls back on the old column on its own.
		$legacy = json_decode( (string) ( $row['keyphrases'] ?? '' ), true );
		$focus  = self::scalar( $row['focus_keyword'] ?? '' );
		$more   = array_column( (array) json_decode( (string) ( $row['additional_keywords'] ?? '' ), true ), 'word' );
		self::keyphrases(
			$fields,
			'keyphrases',
			'' !== $focus ? $focus : ( $legacy['focus']['keyphrase'] ?? '' ),
			$more ? $more : array_column( (array) ( $legacy['additional'] ?? array() ), 'keyphrase' ),
			$object_type,
			$post_type
		);

		return $fields;
	}

	protected static function value( string $name, string $key, string $value, array &$report ) {
		$slot = '';
		if ( str_starts_with( $name, 'term:' ) ) {
			$name = substr( $name, 5 );
			$slot = 'term:';
		}
		switch ( $name ) {
			case 'robots_noindex':
				$value = '1' === $value ? 'noindex' : 'index';
				break;
			case 'robots_nofollow':
				return '1' === $value ? true : '';
			case 'og_image_custom_url':
				$value = self::image_by_url( $value );
				break;
			case 'primary_term':
				$value = '0' !== $value ? $value : '';
				break;
			default:
				if ( in_array( $name, self::TEXTS, true ) ) {
					$value = self::text( $value, $slot, $report );
				}
		}

		return self::field_value( $key, $value );
	}

	public static function left(): array {
		global $wpdb;

		$table = self::table( 'post' );
		if ( '' === $table ) {
			return array();
		}
		$left = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is checked, the rest constants.
		foreach ( array(
			'twitter' => "( COALESCE( twitter_title, '' ) <> '' OR COALESCE( twitter_description, '' ) <> '' )",
			'schema'  => "( `schema` LIKE '%\"graphs\":[{%' OR `schema` LIKE '%\"customGraphs\":[{%' )",
		) as $what => $sql ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$sql}" );
			if ( $count ) {
				$left[ $what ] = $count;
			}
		}
		// phpcs:enable

		return $left;
	}

	// ----------------------------------------------------------------------------------------
	// Helpers.

	/**
	 * A text of AIOSEO's in Simple SEO's syntax.
	 *
	 * @param string               $slot Where the text is used: #taxonomy_title is the title of a term's archive, else the post's category.
	 * @param array<string, mixed> $report
	 */
	protected static function text( string $value, string $slot, array &$report ): string {
		if ( ! str_contains( $value, '#' ) ) {
			return trim( $value );
		}

		$own   = Simple_SEO_Variables::labels();
		$value = (string) preg_replace_callback(
			'/#(?:(custom_field|tax_name)-[A-Za-z0-9_-]+|([A-Za-z_]+)(?![A-Za-z0-9_]))/',
			static function ( array $match ) use ( $slot, $own, &$report ): string {
				$name = strtolower( '' !== $match[1] ? $match[1] : $match[2] );
				if ( '' === $match[1] && ! in_array( $name, self::TAGS_OF_AIOSEO, true ) ) {
					return $match[0];
				}
				$simple = 'taxonomy_title' === $name ? ( str_starts_with( $slot, 'term:' ) ? 'title' : 'category' ) : ( self::TAGS[ $name ] ?? '' );
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
	 * Whom the site represents, the names, the logo and the profiles elsewhere. A name made of
	 * AIOSEO's tags (#site_title, the default) is not taken: an empty name is the site's title.
	 *
	 * @param callable             $get     Reads a path of AIOSEO's options.
	 * @param array<string, mixed> $dynamic AIOSEO's options of post types and taxonomies.
	 * @param array<string, mixed> $report
	 */
	private static function take_schema( callable $get, array $dynamic, array &$report ): void {
		$person = 'person' === self::scalar( $get( 'searchAppearance.global.schema.siteRepresents' ) );
		$user   = absint( self::scalar( $get( 'searchAppearance.global.schema.person' ) ) );
		$common = array();
		if ( ! $person ) {
			$common['represents'] = 'organization';
		} elseif ( $user && get_userdata( $user ) ) {
			$common['represents'] = 'person';
			$common['person']     = $user;
		} else {
			$report['left']['schema_person'] = 1;
		}

		$logo = self::image( self::scalar( $get( $person ? 'searchAppearance.global.schema.personLogo' : 'searchAppearance.global.schema.organizationLogo' ) ) );
		if ( $logo ) {
			$common['logo'] = $logo;
		}
		$profiles = array_values( array_map( static fn( $url ): string => self::scalar( $url ), array_filter( (array) $get( 'social.profiles.urls' ), 'is_scalar' ) ) );
		$profiles = array_merge( $profiles, (array) preg_split( '/\s+/', self::scalar( $get( 'social.profiles.additionalUrls' ) ) ) );
		// The X account goes to the Social networks tab.
		$profiles = array_values( array_filter( array_map( 'strval', $profiles ), static fn( string $url ): bool => '' !== $url && $url !== self::scalar( $get( 'social.profiles.urls.twitterUrl' ) ) ) );
		if ( $profiles ) {
			$common['profiles'] = $profiles;
		}

		// The type of page and of article of each post type: an Article, or the type of web page.
		foreach ( (array) self::get( $dynamic, 'searchAppearance.postTypes' ) as $post_type => $options ) {
			if ( ! is_array( $options ) ) {
				continue;
			}
			$schema = self::scalar( $options['schemaType'] ?? '' );
			if ( '' !== self::scalar( $options['webPageType'] ?? '' ) ) {
				$common['page_types'][ $post_type ] = self::scalar( $options['webPageType'] );
			}
			if ( '' !== $schema ) {
				$common['article_types'][ $post_type ] = 'Article' === $schema ? ( self::scalar( $options['articleType'] ?? '' ) ?: 'BlogPosting' ) : 'None';
			}
		}

		$name = static function ( string $path ) use ( $get ): string {
			$value = self::scalar( $get( $path ) );

			return preg_match( '/#[a-z_]+/i', $value ) ? '' : $value;
		};
		self::schema(
			'',
			$common,
			array(
				'site_name'      => $name( 'searchAppearance.global.schema.websiteName' ),
				'site_alternate' => $name( 'searchAppearance.global.schema.websiteAlternateName' ),
				'name'           => $person ? '' : $name( 'searchAppearance.global.schema.organizationName' ),
			),
			$report
		);

		// The organization's description, phone and e-mail, for the tab Local SEO.
		if ( ! $person ) {
			self::local(
				'',
				array_filter(
					array(
						'phone' => self::scalar( $get( 'searchAppearance.global.schema.phone' ) ),
						'email' => self::scalar( $get( 'searchAppearance.global.schema.email' ) ),
					),
					'strlen'
				),
				array( 'description' => $name( 'searchAppearance.global.schema.organizationDescription' ) ),
				$report
			);
		}
	}

	/** An image from the media library by its address; 0 for none. */
	private static function image( string $url ): int {
		return '' !== $url ? Simple_SEO_Fields::image_attachment( attachment_url_to_postid( $url ) ) : 0;
	}

	/**
	 * One of AIOSEO's JSON rows of settings as an array.
	 *
	 * @return array<string, mixed>
	 */
	private static function json( string $name ): array {
		$value = self::option( $name );
		$value = is_string( $value ) ? json_decode( $value, true ) : $value;

		return is_array( $value ) ? $value : array();
	}

	/**
	 * A value of nested settings by a dotted path; null when there is none.
	 *
	 * @param array<string, mixed> $tree
	 * @return mixed
	 */
	private static function get( array $tree, string $path ) {
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $tree ) || ! array_key_exists( $key, $tree ) ) {
				return null;
			}
			$tree = $tree[ $key ];
		}

		return $tree;
	}

	/** AIOSEO's table of posts or terms; '' when the site does not have it. */
	private static function table( string $type ): string {
		global $wpdb;

		$table = $wpdb->prefix . ( 'term' === $type ? 'aioseo_terms' : 'aioseo_posts' );

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ? $table : '';
	}

	/**
	 * SQL condition on AIOSEO's table: a row with something the import takes. Only the columns the
	 * table has (AIOSEO Pro's table of terms may differ).
	 */
	private static function rows_sql( string $type ): string {
		global $wpdb;

		$table   = self::table( $type );
		$columns = '' !== $table ? array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a name built from the prefix.
		$sql     = array();
		foreach ( array( 'title', 'description', 'og_title', 'og_description', 'canonical_url' ) as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				$sql[] = "COALESCE( `{$column}`, '' ) <> ''";
			}
		}
		if ( in_array( 'og_image_type', $columns, true ) && in_array( 'og_image_custom_url', $columns, true ) ) {
			$sql[] = "( og_image_type = 'custom_image' AND COALESCE( og_image_custom_url, '' ) <> '' )";
		}
		if ( in_array( 'robots_default', $columns, true ) ) {
			$sql[] = 'robots_default = 0';
		}
		if ( in_array( 'primary_term', $columns, true ) ) {
			$sql[] = "primary_term LIKE '%:%'";
		}
		// A keyphrase that is not empty, in AIOSEO's columns or in the JSON of older rows; terms have none.
		if ( 'post' === $type ) {
			if ( in_array( 'focus_keyword', $columns, true ) ) {
				$sql[] = "COALESCE( focus_keyword, '' ) <> ''";
			}
			foreach ( array( 'keyphrases' => 'keyphrase', 'additional_keywords' => 'word' ) as $column => $key ) {
				if ( in_array( $column, $columns, true ) ) {
					$sql[] = "`{$column}` REGEXP '\"{$key}\":[[:space:]]*\"[^\"]'";
				}
			}
		}

		return $sql ? '( ' . implode( ' OR ', $sql ) . ' )' : '0 = 1';
	}

	/**
	 * The next posts or terms after an ID that have something the import takes.
	 *
	 * @return int[]
	 */
	private static function next_ids( string $type, int $after, int $limit ): array {
		global $wpdb;

		$table = self::table( $type );
		if ( '' === $table ) {
			return array();
		}
		$column = $type . '_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table is checked, the condition built from constants.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT {$column} FROM `{$table}` WHERE {$column} > %d AND ", $after ) . self::rows_sql( $type ) . $wpdb->prepare( " ORDER BY {$column} LIMIT %d", $limit ) ) );
	}

	private static function count( string $type ): int {
		global $wpdb;

		$table = self::table( $type );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table is checked, the condition built from constants.
		return '' !== $table ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE " . self::rows_sql( $type ) ) : 0;
	}

	/**
	 * AIOSEO's rows of the posts or terms by ID.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( string $type, array $ids ): array {
		global $wpdb;

		$table  = self::table( $type );
		$column = $type . '_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table is checked, IDs are integers.
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE {$column} IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY id', ARRAY_A );

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row[ $column ] ] ??= $row;
		}

		return $by_id;
	}
}
