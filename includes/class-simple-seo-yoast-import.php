<?php
/**
 * What the import takes from Yoast SEO: its settings, the SEO fields of posts (post meta
 * _yoast_wpseo_*) and of terms (the option wpseo_taxonomy_meta), in Simple SEO's form.
 *
 * Yoast's data is read straight from the database, past the meta aliases and wp-loc's language
 * filters, and is never changed. Yoast's %%variables%% become Simple SEO's {variables}; one Simple
 * SEO has no counterpart for is left out of the text and named in the report. What an import fills
 * and what it leaves: Simple_SEO_Import_Source.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The import shares the meta aliases' reading of Yoast's values; it runs next to Yoast too, without them.
require_once SIMPLE_SEO_DIR . 'includes/compat/class-simple-seo-yoast-meta.php';

final class Simple_SEO_Yoast_Import extends Simple_SEO_Import_Source {
	public const STATE = 'simple_seo_import_yoast';

	public const NAME = 'Yoast SEO';

	public const CLEANUP = 'Simple_SEO_Yoast_Cleanup';

	/** Yoast's separators by name => Simple SEO's. */
	private const SEPARATORS = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	);

	/** Yoast's crawl settings => Simple SEO's clean-up switches. Only what Yoast removed carries over. */
	private const CLEANUP_SWITCHES = array(
		'remove_generator'              => 'generator',
		'remove_shortlinks'             => 'shortlink',
		'remove_rsd_wlw_links'          => 'rsd',
		'remove_rest_api_links'         => 'rest',
		'remove_oembed_links'           => 'oembed',
		'remove_emoji_scripts'          => 'emoji',
		'remove_feed_global'            => 'feed_posts',
		'remove_feed_global_comments'   => 'feed_comments',
		'remove_feed_post_comments'     => 'feed_post_comments',
		'remove_feed_categories'        => 'feed_categories',
		'remove_feed_tags'              => 'feed_tags',
		'remove_feed_custom_taxonomies' => 'feed_taxonomies',
		'remove_feed_authors'           => 'feed_authors',
		'remove_feed_search'            => 'feed_search',
		'remove_feed_post_types'        => 'feed_post_types',
	);

	/** Yoast's keys of a term's SEO => the same thing under the name of Yoast's post meta key. */
	private const TERM_KEYS = array(
		'wpseo_title'                 => 'title',
		'wpseo_desc'                  => 'metadesc',
		'wpseo_opengraph-title'       => 'opengraph-title',
		'wpseo_opengraph-description' => 'opengraph-description',
		'wpseo_opengraph-image-id'    => 'opengraph-image-id',
		'wpseo_opengraph-image'       => 'opengraph-image',
		'wpseo_noindex'               => 'meta-robots-noindex',
		'wpseo_canonical'             => 'canonical',
	);

	/** Texts that may hold variables, under the names of Yoast's post meta keys. */
	private const TEXTS = array( 'title', 'metadesc', 'opengraph-title', 'opengraph-description' );

	/** Yoast Premium's more keyphrases of a post: a JSON list. */
	private const MORE_KEYPHRASES = '_yoast_wpseo_focuskeywords';

	/** Yoast's post meta Simple SEO has no field for, by what the report calls it. */
	private const LEFT = array(
		'bctitle'    => array( '_yoast_wpseo_bctitle' ),
		'twitter'    => array( '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description', '_yoast_wpseo_twitter-image', '_yoast_wpseo_twitter-image-id' ),
		'robots_adv' => array( '_yoast_wpseo_meta-robots-adv' ),
	);

	public static function found(): bool {
		global $wpdb;

		return is_array( self::option( 'wpseo_titles' ) )
			|| (bool) self::term_list()
			|| (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%' LIMIT 1" );
	}

	public static function summary(): array {
		global $wpdb;

		return array(
			'settings' => is_array( self::option( 'wpseo_titles' ) ),
			'posts'    => (int) $wpdb->get_var( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE " . self::keys_sql() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from constants.
			'terms'    => count( self::term_list() ),
		);
	}

	public static function variables_note(): string {
		return __( 'Yoast\'s %%variables%% become Simple SEO\'s {variables}. A variable Simple SEO has no counterpart for is left out of the text and named in the report.', 'simple-seo' );
	}

	public static function variable( string $name ): string {
		return '%%' . $name . '%%';
	}

	// ----------------------------------------------------------------------------------------
	// Settings.

	/**
	 * Take Yoast's settings: separator, templates, indexing, social networks, the home and 404
	 * pages, breadcrumbs, sitemaps and clean-up; with wp-loc, the home and 404 pages and the
	 * breadcrumb labels of the other languages too.
	 *
	 * @param array<string, mixed> $report
	 */
	public static function settings( array &$report ): void {
		$titles = self::option( 'wpseo_titles' );
		$social = self::option( 'wpseo_social' );
		$main   = self::option( 'wpseo' );
		$social = is_array( $social ) ? $social : array();

		if ( is_array( $titles ) ) {
			self::titles( self::SEPARATORS[ self::scalar( $titles['separator'] ?? '' ) ] ?? '', self::templates( $titles ), $report );
			self::take_indexing( $titles, $report );
			self::take_breadcrumbs( $titles, $report );
			// Newer Yoast keeps the front page's image with the titles, older with the social settings.
			self::special( '', self::special_templates( $titles ), $titles['open_graph_frontpage_image_id'] ?? ( $social['og_frontpage_image_id'] ?? 0 ), $report );
			self::labels( '', self::label_texts( $titles ), $report );
			self::left_settings( $titles, $report );
		}
		if ( $social ) {
			self::social( $social['og_default_image_id'] ?? 0, self::scalar( $social['twitter_site'] ?? '' ), $report );
		}
		if ( is_array( $titles ) || $social ) {
			$titles = is_array( $titles ) ? $titles : array();
			self::schema( '', self::schema_common( $titles, $social, $report ), self::schema_names( $titles ), $report );
			self::take_local( '', $titles, $report );
		}
		if ( is_array( $main ) ) {
			if ( array_key_exists( 'enable_xml_sitemap', $main ) && empty( $main['enable_xml_sitemap'] ) ) {
				self::sitemaps_off( $report );
			}
			self::take_cleanup( $main, $report );
		}

		// wp-loc keeps Yoast's settings of another language in a copy of the row named after it.
		foreach ( array_slice( Simple_SEO_WP_Loc::codes(), 1 ) as $code ) {
			$row = self::language_option( 'wpseo_titles', $code );
			if ( null !== $row ) {
				self::special( $code, self::special_templates( $row ), $row['open_graph_frontpage_image_id'] ?? 0, $report );
				self::labels( $code, self::label_texts( $row ), $report );
				self::schema( $code, array(), self::schema_names( $row ), $report );
				self::take_local( $code, $row, $report );
				$report['languages'][] = $code;
			}
		}
	}

	/**
	 * Yoast's templates of titles and descriptions by Simple SEO's slot.
	 *
	 * @param array<string, mixed> $titles
	 * @return array<string, array<string, mixed>>
	 */
	private static function templates( array $titles ): array {
		$keys = static function ( string $kind, string $name ): array {
			switch ( $kind ) {
				case 'author':
				case 'date':
					$yoast = 'author' === $kind ? 'author' : 'archive';
					return array(
						'title'       => 'title-' . $yoast . '-wpseo',
						'description' => 'metadesc-' . $yoast . '-wpseo',
					);
				case 'search':
					return array( 'title' => 'title-search-wpseo' );
				case 'post':
					return array(
						'title'       => 'title-' . $name,
						'description' => 'metadesc-' . $name,
					);
				case 'archive':
					return array(
						'title'       => 'title-ptarchive-' . $name,
						'description' => 'metadesc-ptarchive-' . $name,
					);
				default:
					return array(
						'title'       => 'title-tax-' . $name,
						'description' => 'metadesc-tax-' . $name,
					);
			}
		};

		return array_map( static fn( array $slot ): array => array_map( static fn( string $key ) => $titles[ $key ] ?? '', $slot ), self::slots( $keys ) );
	}

	/**
	 * @param array<string, mixed> $titles
	 * @return array<string, array<string, mixed>>
	 */
	private static function special_templates( array $titles ): array {
		return array(
			'home' => array(
				'title'       => $titles['title-home-wpseo'] ?? '',
				'description' => $titles['metadesc-home-wpseo'] ?? '',
			),
			'404'  => array( 'title' => $titles['title-404-wpseo'] ?? '' ),
		);
	}

	/**
	 * @param array<string, mixed> $titles
	 * @return array<string, string>
	 */
	private static function label_texts( array $titles ): array {
		return array(
			'home'   => self::scalar( $titles['breadcrumbs-home'] ?? '' ),
			'prefix' => self::scalar( $titles['breadcrumbs-prefix'] ?? '' ),
		);
	}

	/**
	 * Which kinds of pages search engines should leave out, and where attachment pages go.
	 *
	 * @param array<string, mixed> $titles
	 */
	private static function take_indexing( array $titles, array &$report ): void {
		$on      = static fn( string $key ): bool => ! empty( $titles[ $key ] );
		$noindex = array();
		foreach ( array_merge( Simple_SEO_Fields::post_types(), array( 'attachment' ) ) as $type ) {
			if ( $on( 'noindex-' . $type ) || ( 'attachment' === $type && $on( 'disable-attachment' ) ) ) {
				$noindex[] = 'post:' . $type;
			}
			if ( $on( 'noindex-ptarchive-' . $type ) ) {
				$noindex[] = 'archive:' . $type;
			}
		}
		foreach ( Simple_SEO_Fields::taxonomies() as $taxonomy ) {
			if ( $on( 'noindex-tax-' . $taxonomy ) ) {
				$noindex[] = 'term:' . $taxonomy;
			}
		}
		// Archives Yoast switched off entirely Simple SEO hides from search engines.
		if ( $on( 'noindex-author-wpseo' ) || $on( 'disable-author' ) ) {
			$noindex[] = 'author';
		}
		if ( $on( 'noindex-archive-wpseo' ) || $on( 'disable-date' ) ) {
			$noindex[] = 'date';
		}

		// Yoast sent attachment pages to the file itself, or kept them.
		$attachments = '';
		if ( array_key_exists( 'disable-attachment', $titles ) ) {
			$attachments = $on( 'disable-attachment' ) ? Simple_SEO_Indexing::ATTACHMENTS_FILE : Simple_SEO_Indexing::ATTACHMENTS_KEEP;
		}

		self::indexing( $noindex, $attachments, $report );
	}

	/** @param array<string, mixed> $titles */
	private static function take_breadcrumbs( array $titles, array &$report ): void {
		$taxonomies = array();
		foreach ( Simple_SEO_Fields::post_types() as $type ) {
			$taxonomies[ $type ] = self::scalar( $titles[ 'post_types-' . $type . '-maintax' ] ?? '' );
		}

		// Yoast's 0 is "none chosen"; the sanitizer drops it, as a taxonomy the type does not have.
		self::breadcrumbs(
			self::scalar( $titles['breadcrumbs-sep'] ?? '' ),
			null,
			array_key_exists( 'breadcrumbs-display-blog-page', $titles ) ? ! empty( $titles['breadcrumbs-display-blog-page'] ) : null,
			$taxonomies,
			$report
		);
	}

	/** @param array<string, mixed> $main */
	private static function take_cleanup( array $main, array &$report ): void {
		$keys = array();
		foreach ( self::CLEANUP_SWITCHES as $yoast => $key ) {
			if ( ! empty( $main[ $yoast ] ) ) {
				$keys[] = $key;
			}
		}
		self::cleanup( $keys, $report );
	}

	/**
	 * Whom the site represents, its logo and its profiles elsewhere. A person needs the user Yoast
	 * names; without one Simple SEO's choice stays and the report says so.
	 *
	 * @param array<string, mixed> $titles
	 * @param array<string, mixed> $social
	 * @param array<string, mixed> $report
	 * @return array<string, mixed>
	 */
	private static function schema_common( array $titles, array $social, array &$report ): array {
		$common = array();
		$person = 'person' === self::scalar( $titles['company_or_person'] ?? '' );
		$user   = absint( $titles['company_or_person_user_id'] ?? 0 );
		if ( ! $person ) {
			$common['represents'] = 'organization';
		} elseif ( $user && get_userdata( $user ) ) {
			$common['represents'] = 'person';
			$common['person']     = $user;
		} else {
			$report['left']['schema_person'] = 1;
		}

		$which = $person ? 'person_logo' : 'company_logo';
		$logo  = Simple_SEO_Fields::image_attachment( $titles[ $which . '_id' ] ?? 0 );
		if ( ! $logo && '' !== self::scalar( $titles[ $which ] ?? '' ) ) {
			$logo = Simple_SEO_Fields::image_attachment( attachment_url_to_postid( self::scalar( $titles[ $which ] ) ) );
		}
		if ( $logo ) {
			$common['logo'] = $logo;
		}

		$profiles = array();
		foreach ( array( 'facebook_site', 'instagram_url', 'linkedin_url', 'myspace_url', 'pinterest_url', 'youtube_url', 'wikipedia_url', 'mastodon_url' ) as $key ) {
			$profiles[] = self::scalar( $social[ $key ] ?? '' );
		}
		foreach ( (array) ( $social['other_social_urls'] ?? array() ) as $url ) {
			$profiles[] = self::scalar( $url );
		}
		$profiles = array_values( array_filter( $profiles ) );
		if ( $profiles ) {
			$common['profiles'] = $profiles;
		}

		// The type of page and of article of each post type: schema-page-type-{type}, schema-article-type-{type}.
		foreach ( $titles as $key => $value ) {
			if ( preg_match( '/^schema-(page|article)-type-(.+)$/', (string) $key, $match ) && '' !== self::scalar( $value ) ) {
				$common[ $match[1] . '_types' ][ $match[2] ] = self::scalar( $value );
			}
		}

		return $common;
	}

	/**
	 * The organization's description, e-mail and phone of Yoast's site representation, for the tab
	 * Local SEO; a person's site has none. The e-mail and phone are common: local() takes them from
	 * the default language only.
	 *
	 * @param array<string, mixed> $titles
	 * @param array<string, mixed> $report
	 */
	private static function take_local( string $code, array $titles, array &$report ): void {
		if ( 'person' === self::scalar( $titles['company_or_person'] ?? '' ) ) {
			return;
		}

		$common = array_filter(
			array(
				'email' => self::scalar( $titles['org-email'] ?? '' ),
				'phone' => self::scalar( $titles['org-phone'] ?? '' ),
			),
			'strlen'
		);
		self::local( $code, $common, array( 'description' => self::scalar( $titles['org-description'] ?? '' ) ), $report );
	}

	/**
	 * The names of the site and of the organization; a person's site has no organization name.
	 *
	 * @param array<string, mixed> $titles
	 * @return array<string, string>
	 */
	private static function schema_names( array $titles ): array {
		$company = 'person' !== self::scalar( $titles['company_or_person'] ?? '' );

		return array(
			'site_name'      => self::scalar( $titles['website_name'] ?? '' ),
			'site_alternate' => self::scalar( $titles['alternate_website_name'] ?? '' ),
			'name'           => $company ? self::scalar( $titles['company_name'] ?? '' ) : '',
			'alternate'      => $company ? self::scalar( $titles['company_alternate_name'] ?? '' ) : '',
		);
	}

	/**
	 * Yoast's settings Simple SEO has nothing for: templates for social networks, the texts
	 * Yoast's breadcrumbs put before archives and search results and on 404.
	 *
	 * @param array<string, mixed> $titles
	 */
	private static function left_settings( array $titles, array &$report ): void {
		foreach ( $titles as $key => $value ) {
			$key = (string) $key;
			if ( '' === self::scalar( $value ) ) {
				continue;
			}
			if ( preg_match( '/^(?:social-(?:title|description)-|open_graph_frontpage_(?:title|desc)$)/', $key ) ) {
				$report['left']['social_templates'] = 1;
			} elseif ( in_array( $key, array( 'breadcrumbs-archiveprefix', 'breadcrumbs-searchprefix', 'breadcrumbs-404crumb' ), true ) ) {
				$report['left']['breadcrumb_texts'] = 1;
			}
		}
	}

	// ----------------------------------------------------------------------------------------
	// Posts and terms.

	public static function posts( int $after, int $limit, array &$report ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the key condition is built from constants.
		$ids = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id > %d AND ", $after ) . self::keys_sql() . $wpdb->prepare( ' ORDER BY post_id LIMIT %d', $limit ) )
		);
		if ( ! $ids ) {
			return 0;
		}
		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ') AND ' . self::keys_sql() . ' ORDER BY meta_id', ARRAY_A );
		// phpcs:enable

		$values = array();
		foreach ( $rows as $row ) {
			// The first row of a key, as get_post_meta( …, true ) reads it.
			$values[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] ??= (string) $row['meta_value'];
		}

		$types = Simple_SEO_Fields::post_types();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $types, true ) ) {
				continue;
			}

			$fields = array();
			foreach ( self::post_keys( array_keys( $values[ $id ] ?? array() ) ) as $meta_key ) {
				$key = Simple_SEO_Yoast_Meta::field( $meta_key, $id );
				if ( '' !== $key ) {
					$fields[ $key ][] = array( substr( $meta_key, 13 ), $values[ $id ][ $meta_key ] );
				}
			}
			// Entries are {keyword, score}, or plain words as Yoast's own import from SEOPressor writes them.
			$more = json_decode( $values[ $id ][ self::MORE_KEYPHRASES ] ?? '', true );
			if ( is_array( $more ) ) {
				self::keyphrases( $fields, 'focuskeywords', '', array_map( static fn( $entry ) => is_array( $entry ) ? ( $entry['keyword'] ?? '' ) : $entry, $more ), 'post', $post->post_type );
			}
			self::take( 'post', $id, $fields, $report );
		}

		return (int) end( $ids );
	}

	/**
	 * Take the SEO fields of the next terms of Yoast's list.
	 *
	 * @param int                  $cursor Where the batch starts in the list.
	 * @param array<string, mixed> $report
	 */
	public static function terms( int $cursor, int $limit, array &$report ): int {
		$batch = array_slice( self::term_list(), $cursor, $limit );
		if ( ! $batch ) {
			return 0;
		}

		$taxonomies = Simple_SEO_Fields::taxonomies();
		foreach ( $batch as [ $taxonomy, $id, $meta ] ) {
			$term = in_array( $taxonomy, $taxonomies, true ) ? get_term( $id, $taxonomy ) : null;
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$fields = array();
			foreach ( self::TERM_KEYS as $yoast => $name ) {
				$value = self::scalar( $meta[ $yoast ] ?? '' );
				if ( 'meta-robots-noindex' === $name ) {
					// Yoast says noindex, index or default for a term.
					$value = array_search( $value, array( '1' => 'noindex', '2' => 'index' ), true );
				}
				// Every one of these fields is a term's too.
				if ( '' !== (string) $value ) {
					$fields[ Simple_SEO_Yoast_Meta::field_of( $name ) ][] = array( $name, (string) $value );
				}
			}
			self::take( 'term', $id, $fields, $report );
		}

		return $cursor + count( $batch );
	}

	protected static function value( string $name, string $key, string $value, array &$report ) {
		return Simple_SEO_Yoast_Meta::clean( $name, $key, in_array( $name, self::TEXTS, true ) ? self::text( $value, '', $report ) : $value );
	}

	public static function left(): array {
		global $wpdb;

		$left = array();
		foreach ( self::LEFT as $what => $keys ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE meta_key IN (" . self::in( $keys ) . ") AND meta_value NOT IN ( '', '0' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- keys from a constant.
			if ( $count ) {
				$left[ $what ] = $count;
			}
		}

		return $left;
	}

	// ----------------------------------------------------------------------------------------
	// Helpers.

	/**
	 * A text of Yoast's in Simple SEO's syntax. A variable Simple SEO has no counterpart for goes
	 * away and is counted; on a post type archive %%pt_plural%% is the archive's {title}.
	 *
	 * @param array<string, mixed> $report
	 */
	protected static function text( string $value, string $slot, array &$report ): string {
		$value = (string) preg_replace_callback(
			'/%%([A-Za-z0-9_-]+)%%/',
			static function ( array $match ) use ( $slot, &$report ): string {
				$name = strtolower( $match[1] );
				if ( 'pt_plural' === $name && str_starts_with( $slot, 'archive:' ) ) {
					return '{title}';
				}
				self::add( $report, 'dropped', $name );

				return '';
			},
			Simple_SEO_Yoast_Compat::to_simple( $value )
		);

		return trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $value ) );
	}

	/**
	 * The keys of a post in the order they are taken: the image by its ID before its address,
	 * primary terms last.
	 *
	 * @param string[] $present
	 * @return string[]
	 */
	private static function post_keys( array $present ): array {
		$keys = array_values( array_intersect( Simple_SEO_Yoast_Meta::keys(), $present ) );
		foreach ( $present as $key ) {
			if ( str_starts_with( $key, '_yoast_wpseo_primary_' ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/** SQL condition on postmeta: the keys the import takes, with a value other than Yoast's default. */
	private static function keys_sql(): string {
		return '( meta_key IN (' . self::in( array_merge( Simple_SEO_Yoast_Meta::keys(), array( self::MORE_KEYPHRASES ) ) ) . ") OR meta_key LIKE '\\_yoast\\_wpseo\\_primary\\_%' ) AND meta_value NOT IN ( '', '0', '[]' )";
	}

	/**
	 * Yoast's terms with SEO data, in a fixed order: taxonomy, term ID, Yoast's values.
	 *
	 * @return array<int, array{0: string, 1: int, 2: array<string, mixed>}>
	 */
	private static function term_list(): array {
		$option = self::option( 'wpseo_taxonomy_meta' );
		$list   = array();
		foreach ( is_array( $option ) ? $option : array() as $taxonomy => $terms ) {
			foreach ( is_array( $terms ) ? $terms : array() as $id => $meta ) {
				if ( is_array( $meta ) && (int) $id > 0 ) {
					$list[] = array( (string) $taxonomy, (int) $id, $meta );
				}
			}
		}
		usort( $list, static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );

		return $list;
	}

	/**
	 * Yoast's copy of a row for a language (wp-loc names it by the language's code or slug).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function language_option( string $name, string $code ): ?array {
		$suffixes = array( $code );
		if ( method_exists( 'WP_LOC_DB', 'from_db_language_code' ) ) {
			$suffixes[] = (string) WP_LOC_DB::from_db_language_code( $code );
		}
		foreach ( array_unique( array_filter( $suffixes ) ) as $suffix ) {
			$row = self::option( $name . '_' . $suffix );
			if ( is_array( $row ) ) {
				return $row;
			}
		}

		return null;
	}
}
