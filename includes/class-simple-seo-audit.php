<?php
/**
 * The audit of pages: the pages of the sitemap, opened as a visitor sees them — an HTTP request of
 * the site to itself, so what the theme prints is seen too — and read for what search engines
 * read: the title, the meta description, the canonical address, noindex, the H1 headings, images
 * without alt text and the links (kept for the checks of links).
 *
 * A run goes in the background, a few pages a step: a task of WP-Cron takes the next step while
 * there is work left, and the open page of the audit takes steps too, so a site without WP-Cron
 * gets its audit as well. One step at a time: a lock in the options table. Once the pages are
 * read, the same run checks the links on them (Simple_SEO_Links).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Audit {
	/** The table after the prefix of the site's tables: one row a page of the last run. */
	public const TABLE = 'simple_seo_audit';

	/** The version of the table made, in a row of its own. */
	public const DB_OPTION  = 'simple_seo_audit_db';
	public const DB_VERSION = '1';

	/** The state of the last run. */
	public const RUN = 'simple_seo_audit_run';

	/** Held while a step works. */
	public const LOCK = 'simple_seo_audit_lock';

	public const PAGE = 'simple-seo-audit';

	/** The task of WP-Cron that takes the next step. */
	public const EVENT = 'simple_seo_audit_step';

	/** Pages a run checks at most. */
	public const MAX_PAGES = 2000;

	/** Pages a step takes from the queue at a time. */
	public const BATCH = 5;

	/** Seconds a step works before it leaves the rest to the next one. */
	public const BUDGET = 12;

	/** Seconds a page has to answer. */
	public const TIMEOUT = 10;

	/** Bytes of a page read. */
	public const MAX_SIZE = 2097152;

	/** What is kept of a page at most: links, images without alt, H1 headings. */
	public const MAX_LINKS  = 1000;
	public const MAX_IMAGES = 20;
	public const MAX_H1     = 5;

	public const PER_PAGE = 50;

	/** The problems the report lists, in its order. */
	public const ISSUES = array( 'failed', 'no_title', 'same_title', 'no_description', 'same_description', 'no_h1', 'many_h1', 'images_alt', 'canonical', 'noindex' );

	private const NONCE = 'simple_seo_audit';

	/** @var string The page's hook suffix. */
	private static string $hook = '';

	public static function init(): void {
		add_action( self::EVENT, array( self::class, 'step' ) );

		if ( is_admin() ) {
			// After the page of 404s (22).
			add_action( 'admin_menu', array( self::class, 'add_menu' ), 23 );
			add_action( 'admin_post_' . self::NONCE, array( self::class, 'handle' ) );
			add_action( 'wp_ajax_' . self::NONCE, array( self::class, 'ajax_step' ) );
		}
	}

	public static function table(): string {
		return $GLOBALS['wpdb']->prefix . self::TABLE;
	}

	/** Make the table, once for each version of it. */
	public static function install(): void {
		global $wpdb;

		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(32) NOT NULL,
  url varchar(2048) NOT NULL,
  done tinyint(1) NOT NULL DEFAULT 0,
  code smallint(5) unsigned NOT NULL DEFAULT 0,
  error varchar(2048) NOT NULL DEFAULT '',
  title text NOT NULL,
  title_hash char(32) NOT NULL DEFAULT '',
  description text NOT NULL,
  description_hash char(32) NOT NULL DEFAULT '',
  canonical varchar(2048) NOT NULL DEFAULT '',
  noindex tinyint(1) NOT NULL DEFAULT 0,
  h1 text NOT NULL,
  h1_count smallint(5) unsigned NOT NULL DEFAULT 0,
  images text NOT NULL,
  images_missing smallint(5) unsigned NOT NULL DEFAULT 0,
  links mediumtext NOT NULL,
  checked_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY done (done),
  KEY title_hash (title_hash),
  KEY description_hash (description_hash)
) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/*
	 * The run.
	 */

	/**
	 * The state of the last run: idle (never run), running, done or stopped; when it started and
	 * finished, how many pages it has, whether the sitemap had more than max_pages(); how many links
	 * it checks once its pages are read, and whether the pages had more than max_links().
	 *
	 * @return array{state: string, started: int, finished: int, total: int, capped: bool, links: int, links_queued: bool, links_capped: bool}
	 */
	public static function run(): array {
		$run = get_option( self::RUN );
		$run = is_array( $run ) ? $run : array();

		return array(
			'state'        => in_array( $run['state'] ?? '', array( 'running', 'done', 'stopped' ), true ) ? (string) $run['state'] : 'idle',
			'started'      => (int) ( $run['started'] ?? 0 ),
			'finished'     => (int) ( $run['finished'] ?? 0 ),
			'total'        => (int) ( $run['total'] ?? 0 ),
			'capped'       => ! empty( $run['capped'] ),
			'links'        => (int) ( $run['links'] ?? 0 ),
			'links_queued' => ! empty( $run['links_queued'] ),
			'links_capped' => ! empty( $run['links_capped'] ),
		);
	}

	/** Pages a run checks at most. */
	public static function max_pages(): int {
		/**
		 * Pages an audit checks at most (2000 by default).
		 *
		 * @param int $pages
		 */
		return max( 1, (int) apply_filters( 'simple_seo_audit_max_pages', self::MAX_PAGES ) );
	}

	/**
	 * The pages of the audit: the addresses of the sitemaps, language by language, at most
	 * max_pages() of them (one more tells there are more).
	 *
	 * @return string[]
	 */
	public static function pages(): array {
		$max  = self::max_pages();
		$urls = array();
		foreach ( Simple_SEO_Sitemaps::sitemaps() as $sitemap ) {
			for ( $page = 1; $page <= $sitemap['pages']; $page++ ) {
				foreach ( (array) Simple_SEO_Sitemaps::entries( $sitemap['name'], $page, $sitemap['language'] ) as $entry ) {
					$urls[ (string) $entry['loc'] ] = true;
					if ( count( $urls ) > $max ) {
						break 3;
					}
				}
			}
		}

		/**
		 * The pages an audit checks: the addresses of the sitemaps. Only addresses of this site's
		 * own pages belong here.
		 *
		 * @param string[] $urls
		 */
		$urls = (array) apply_filters( 'simple_seo_audit_pages', array_keys( $urls ) );

		return array_values( array_unique( array_filter( array_map( 'strval', $urls ), static fn( string $url ): bool => (bool) preg_match( '#^https?://#i', $url ) ) ) );
	}

	/** Start a run: the pages of the sitemap in a queue, the report of the last run gone. */
	public static function start(): int {
		global $wpdb;

		self::install();
		$urls   = self::pages();
		$capped = count( $urls ) > self::max_pages();
		$urls   = array_slice( $urls, 0, self::max_pages() );
		$table  = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table, rows prepared one by one.
		$wpdb->query( "DELETE FROM {$table}" );
		Simple_SEO_Links::reset();
		foreach ( array_chunk( $urls, 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $url ) {
				$values[] = $wpdb->prepare( "( %s, %s, '', '', '', '', '' )", md5( $url ), $url );
			}
			$wpdb->query( "INSERT IGNORE INTO {$table} ( url_hash, url, title, description, h1, images, links ) VALUES " . implode( ', ', $values ) );
		}
		// phpcs:enable

		$total = self::count( 'all' );
		update_option(
			self::RUN,
			array(
				'state'        => $total ? 'running' : 'done',
				'started'      => time(),
				'finished'     => $total ? 0 : time(),
				'total'        => $total,
				'capped'       => $capped,
				'links'        => 0,
				'links_queued' => false,
				'links_capped' => false,
			),
			false
		);
		self::unlock();
		wp_clear_scheduled_hook( self::EVENT );
		if ( $total ) {
			self::schedule();
		}

		return $total;
	}

	/** Stop a run: the pages not checked yet leave the queue, those checked stay in the report. */
	public static function stop(): void {
		global $wpdb;

		$run = self::run();
		if ( 'running' !== $run['state'] ) {
			return;
		}

		$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE done = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.
		Simple_SEO_Links::stop();
		update_option( self::RUN, array_merge( $run, array( 'state' => 'stopped', 'finished' => time() ) ), false );
		wp_clear_scheduled_hook( self::EVENT );
	}

	/**
	 * Check the next pages of the queue for up to $budget seconds, then the links on them; the next
	 * step is scheduled while pages or links are left, and the run is done when none are (the links
	 * that did not answer wait for their check a day later).
	 *
	 * @return array{state: string, total: int, done: int, links_total: int, links_done: int}
	 */
	public static function step( int $budget = self::BUDGET ): array {
		global $wpdb;

		if ( 'running' !== self::run()['state'] || ! self::lock() ) {
			return self::progress();
		}

		$start = microtime( true );
		try {
			do {
				$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, url FROM ' . self::table() . ' WHERE done = 0 ORDER BY id LIMIT %d', self::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
				foreach ( $rows as $row ) {
					self::check( (int) $row->id, (string) $row->url );
					if ( microtime( true ) - $start >= $budget ) {
						break 2;
					}
				}
			} while ( $rows );

			// The pages read: the links on them.
			if ( 0 === self::count( 'queued' ) ) {
				if ( ! self::run()['links_queued'] ) {
					$links = Simple_SEO_Links::queue();
					update_option(
						self::RUN,
						array_merge(
							self::run(),
							array(
								'links'        => $links['total'],
								'links_queued' => true,
								'links_capped' => $links['capped'],
							)
						),
						false
					);
				}
				Simple_SEO_Links::step( $start + $budget );
			}
		} finally {
			self::unlock();
		}

		if ( 0 === self::count( 'queued' ) && self::run()['links_queued'] && 0 === Simple_SEO_Links::count( 'queued' ) ) {
			update_option( self::RUN, array_merge( self::run(), array( 'state' => 'done', 'finished' => time() ) ), false );
			Simple_SEO_Links::schedule_retry();
		} else {
			self::schedule();
		}

		return self::progress();
	}

	/** @return array{state: string, total: int, done: int, links_total: int, links_done: int} */
	public static function progress(): array {
		$run = self::run();

		return array(
			'state'       => $run['state'],
			'total'       => $run['total'],
			'done'        => self::count( 'done' ),
			'links_total' => $run['links'],
			'links_done'  => $run['links_queued'] ? Simple_SEO_Links::count( 'checked' ) : 0,
		);
	}

	private static function schedule(): void {
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time(), self::EVENT );
		}
	}

	/**
	 * Take the lock of the steps (and of the checks of links a day later). add_option() is no lock:
	 * it inserts or updates. A lock older than a step can last is one of a step that died, and is
	 * taken over.
	 */
	public static function lock(): bool {
		global $wpdb;

		$now = time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", self::LOCK, (string) $now ) );
		$taken = 1 === (int) $wpdb->rows_affected;
		if ( ! $taken ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
			if ( (int) $held < $now - 2 * ( self::BUDGET + self::TIMEOUT ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, self::LOCK, $held ) );
				$taken = 1 === (int) $wpdb->rows_affected;
			}
		}
		// phpcs:enable
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return $taken;
	}

	public static function unlock(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( self::LOCK, 'options' );
	}

	/*
	 * A page.
	 */

	/** Open a page of the queue and keep what it says. */
	public static function check( int $id, string $url ): void {
		global $wpdb;

		$page = self::fetch( $url );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			self::table(),
			array(
				'done'             => 1,
				'code'             => $page['code'],
				'error'            => mb_substr( $page['error'], 0, 2048 ),
				'title'            => $page['title'],
				'title_hash'       => '' === $page['title'] ? '' : md5( $page['title'] ),
				'description'      => $page['description'],
				'description_hash' => '' === $page['description'] ? '' : md5( $page['description'] ),
				'canonical'        => mb_substr( $page['canonical'], 0, 2048 ),
				'noindex'          => $page['noindex'] ? 1 : 0,
				'h1'               => (string) wp_json_encode( $page['h1'] ),
				'h1_count'         => $page['h1_count'],
				'images'           => (string) wp_json_encode( $page['images'] ),
				'images_missing'   => $page['images_missing'],
				'links'            => (string) wp_json_encode( $page['links'] ),
				'checked_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * A page as a visitor gets it: no cookies, no redirects followed. What it answers and, for a page
	 * of HTML, what parse() reads in it; noindex of the X-Robots-Tag header too.
	 *
	 * @return array{code: int, error: string, title: string, description: string, canonical: string, noindex: bool, h1: string[], h1_count: int, images: string[], images_missing: int, links: string[]}
	 */
	public static function fetch( string $url ): array {
		$page     = array(
			'code'  => 0,
			'error' => '',
		) + self::parse( '', $url );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_SIZE,
				'user-agent'          => 'Simple SEO audit; ' . home_url( '/' ),
				'headers'             => array( 'Accept' => 'text/html' ),
				// The site's own address, checked as WordPress checks itself (Site Health).
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$page['error'] = $response->get_error_message();
			return $page;
		}

		$page['code'] = (int) wp_remote_retrieve_response_code( $response );
		if ( $page['code'] >= 300 && $page['code'] < 400 ) {
			$location      = (string) wp_remote_retrieve_header( $response, 'location' );
			$page['error'] = '' !== $location ? WP_Http::make_absolute_url( $location, $url ) : '';
			return $page;
		}
		if ( 200 !== $page['code'] ) {
			return $page;
		}

		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' === $type || str_contains( $type, 'html' ) ) {
			$page = array_merge( $page, self::parse( (string) wp_remote_retrieve_body( $response ), $url ) );
		}
		// The same address, its letters encoded otherwise (%D1 or %d1), is no other address.
		if ( '' !== $page['canonical'] && rawurldecode( $page['canonical'] ) === rawurldecode( $url ) ) {
			$page['canonical'] = $url;
		}
		foreach ( (array) wp_remote_retrieve_header( $response, 'x-robots-tag' ) as $header ) {
			// "googlebot: noindex" or "noindex, nofollow"; a header for another bot is not about all of them.
			$header = strtolower( (string) $header );
			if ( preg_match( '/^\s*(?:([a-z0-9_-]+)\s*:)?(.*)$/', $header, $match ) && in_array( $match[1], array( '', 'googlebot' ), true ) && preg_match( '/\bnoindex\b/', $match[2] ) ) {
				$page['noindex'] = true;
			}
		}

		return $page;
	}

	/**
	 * What search engines read in a page of HTML: the title (not that of an SVG), the meta
	 * description, the canonical address, noindex of the robots meta tags, the H1 headings, the
	 * images without an alt attribute (not a pixel of a tracker in <noscript>, nor one of 1×1) and the
	 * addresses of the links, absolute, without their fragment. Read with WordPress's own HTML
	 * tokenizer: the contents of scripts, styles and text areas are no tags.
	 *
	 * @return array{title: string, description: string, canonical: string, noindex: bool, h1: string[], h1_count: int, images: string[], images_missing: int, links: string[]}
	 */
	public static function parse( string $html, string $url ): array {
		$found = array(
			'title'          => '',
			'description'    => '',
			'canonical'      => '',
			'noindex'        => false,
			'h1'             => array(),
			'h1_count'       => 0,
			'images'         => array(),
			'images_missing' => 0,
			'links'          => array(),
		);
		if ( '' === $html ) {
			return $found;
		}

		$processor   = new WP_HTML_Tag_Processor( $html );
		$foreign     = 0;
		$noscript    = 0;
		$heading     = null;
		$title       = null;
		$description = null;
		$links       = array();
		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();
			if ( '#text' === $type ) {
				if ( null !== $heading ) {
					$heading .= $processor->get_modifiable_text();
				}
				continue;
			}
			if ( '#tag' !== $type ) {
				continue;
			}

			$tag    = (string) $processor->get_tag();
			$closer = $processor->is_tag_closer();
			switch ( $tag ) {
				case 'SVG':
				case 'MATH':
					if ( $closer ) {
						$foreign = max( 0, $foreign - 1 );
					} elseif ( ! $processor->has_self_closing_flag() ) {
						++$foreign;
					}
					break;
				case 'NOSCRIPT':
					$noscript = $closer ? max( 0, $noscript - 1 ) : $noscript + 1;
					break;
				case 'TITLE':
					if ( ! $closer && ! $foreign && null === $title ) {
						$title = self::text( $processor->get_modifiable_text() );
					}
					break;
				case 'META':
					$name    = strtolower( trim( (string) $processor->get_attribute( 'name' ) ) );
					$content = $processor->get_attribute( 'content' );
					$content = is_string( $content ) ? $content : '';
					if ( 'description' === $name && null === $description ) {
						$description = self::text( $content );
					} elseif ( in_array( $name, array( 'robots', 'googlebot' ), true ) && preg_match( '/\bnoindex\b/i', $content ) ) {
						$found['noindex'] = true;
					}
					break;
				case 'LINK':
					$rel  = preg_split( '/\s+/', strtolower( trim( (string) $processor->get_attribute( 'rel' ) ) ) );
					$href = $processor->get_attribute( 'href' );
					if ( '' === $found['canonical'] && in_array( 'canonical', (array) $rel, true ) && is_string( $href ) && '' !== trim( $href ) ) {
						$found['canonical'] = self::absolute( $href, $url );
					}
					break;
				case 'H1':
					if ( $closer ) {
						if ( null !== $heading && count( $found['h1'] ) < self::MAX_H1 ) {
							$found['h1'][] = mb_substr( self::text( $heading ), 0, 200 );
						}
						$heading = null;
					} elseif ( ! $foreign ) {
						++$found['h1_count'];
						$heading = '';
					}
					break;
				case 'BR':
					if ( null !== $heading ) {
						$heading .= ' ';
					}
					break;
				case 'IMG':
					if ( ! $noscript && null === $processor->get_attribute( 'alt' ) && ! ( '1' === (string) $processor->get_attribute( 'width' ) && '1' === (string) $processor->get_attribute( 'height' ) ) ) {
						++$found['images_missing'];
						$src = $processor->get_attribute( 'src' );
						if ( is_string( $src ) && '' !== trim( $src ) && count( $found['images'] ) < self::MAX_IMAGES ) {
							$found['images'][] = self::absolute( $src, $url );
						}
					}
					break;
				case 'A':
					$href = $closer ? null : $processor->get_attribute( 'href' );
					if ( is_string( $href ) && count( $links ) < self::MAX_LINKS ) {
						$link = self::absolute( $href, $url );
						if ( preg_match( '#^https?://#i', $link ) ) {
							$links[ $link ] = true;
						}
					}
					break;
			}
		}
		// A heading the page did not close.
		if ( null !== $heading && count( $found['h1'] ) < self::MAX_H1 ) {
			$found['h1'][] = mb_substr( self::text( $heading ), 0, 200 );
		}

		$found['title']       = (string) $title;
		$found['description'] = (string) $description;
		$found['links']       = array_keys( $links );

		return $found;
	}

	/** Text as it reads: white space of any kind as one space, none at the ends. */
	private static function text( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** An address of a page as an absolute one, without its fragment. */
	private static function absolute( string $href, string $base ): string {
		$href = trim( $href );
		if ( preg_match( '/^(?:mailto|tel|javascript|data|sms):/i', $href ) ) {
			return $href;
		}

		return (string) preg_replace( '/#.*$/s', '', WP_Http::make_absolute_url( $href, $base ) );
	}

	/*
	 * The report.
	 */

	/**
	 * The title and description hashes more than one page shares, with how many share each.
	 *
	 * @return array{title: array<string, int>, description: array<string, int>}
	 */
	public static function duplicates(): array {
		global $wpdb;

		$table = self::table();
		$found = array();
		foreach ( array( 'title', 'description' ) as $field ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table and columns.
			$rows            = (array) $wpdb->get_results( "SELECT {$field}_hash AS hash, COUNT(*) AS pages FROM {$table} WHERE done = 1 AND code = 200 AND {$field}_hash <> '' GROUP BY {$field}_hash HAVING COUNT(*) > 1", ARRAY_A );
			$found[ $field ] = array_map( 'intval', array_column( $rows, 'pages', 'hash' ) );
		}

		return $found;
	}

	/**
	 * The SQL condition of the pages with a problem (or: all, queued, done, problems — any of them).
	 *
	 * @param array{title: array<string, int>, description: array<string, int>} $duplicates
	 */
	private static function where( string $issue, array $duplicates ): string {
		global $wpdb;

		$ok = 'done = 1 AND code = 200';
		// No shared hash: no page (an empty list would match the pages without any).
		$in = static function ( string $column, array $hashes ) use ( $wpdb, $ok ): string {
			return $hashes ? "{$ok} AND {$column} IN ( " . implode( ', ', array_map( static fn( $hash ): string => $wpdb->prepare( '%s', (string) $hash ), array_keys( $hashes ) ) ) . ' )' : '0 = 1';
		};
		switch ( $issue ) {
			case 'all':
				return '1 = 1';
			case 'queued':
				return 'done = 0';
			case 'done':
				return 'done = 1';
			case 'failed':
				return 'done = 1 AND code <> 200';
			case 'no_title':
				return "{$ok} AND title_hash = ''";
			case 'same_title':
				return $in( 'title_hash', $duplicates['title'] );
			case 'no_description':
				return "{$ok} AND description_hash = ''";
			case 'same_description':
				return $in( 'description_hash', $duplicates['description'] );
			case 'no_h1':
				return "{$ok} AND h1_count = 0";
			case 'many_h1':
				return "{$ok} AND h1_count > 1";
			case 'images_alt':
				return "{$ok} AND images_missing > 0";
			case 'canonical':
				return "{$ok} AND canonical <> '' AND canonical <> url";
			case 'noindex':
				return "{$ok} AND noindex = 1";
		}

		// Any problem.
		$any = array();
		foreach ( self::ISSUES as $one ) {
			$any[] = '( ' . self::where( $one, $duplicates ) . ' )';
		}

		return implode( ' OR ', $any );
	}

	/**
	 * How many pages: all, queued, done, with any problem (problems) or with one of ISSUES.
	 *
	 * @param array{title: array<string, int>, description: array<string, int>}|null $duplicates
	 */
	public static function count( string $issue, ?array $duplicates = null ): int {
		global $wpdb;

		// Only the shared titles and descriptions need the duplicates.
		if ( null === $duplicates ) {
			$duplicates = in_array( $issue, array( 'same_title', 'same_description', 'problems' ), true ) ? self::duplicates() : array(
				'title'       => array(),
				'description' => array(),
			);
		}
		$where    = self::where( $issue, $duplicates );
		$suppress = $wpdb->suppress_errors();
		$count    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table, values prepared in where().
		$wpdb->suppress_errors( $suppress );

		return $count;
	}

	/**
	 * How many pages have each problem.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		$duplicates = self::duplicates();
		$counts     = array();
		foreach ( self::ISSUES as $issue ) {
			$counts[ $issue ] = self::count( $issue, $duplicates );
		}

		return $counts;
	}

	/**
	 * A page of the report: the checked pages with a problem (all of them, or those of one), in the
	 * order of the sitemap, each with its problems.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function rows( string $issue = '', int $page = 1, int $per_page = self::PER_PAGE ): array {
		global $wpdb;

		$duplicates = self::duplicates();
		$issue      = in_array( $issue, self::ISSUES, true ) ? $issue : 'problems';
		$where      = self::where( $issue, $duplicates );
		$table      = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table, values prepared in where().
		$suppress = $wpdb->suppress_errors();
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id LIMIT %d OFFSET %d", $per_page, max( 0, $page - 1 ) * $per_page ), ARRAY_A );
		$wpdb->suppress_errors( $suppress );
		// phpcs:enable

		return array(
			'rows'  => array_map( static fn( array $row ): array => self::row( $row, $duplicates ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * A page of the report as the screen shows it, with its problems (ISSUES keys) and the number of
	 * pages that share its title or description.
	 *
	 * @param array<string, mixed>                                              $row
	 * @param array{title: array<string, int>, description: array<string, int>} $duplicates
	 * @return array<string, mixed>
	 */
	private static function row( array $row, array $duplicates ): array {
		$code   = (int) $row['code'];
		$page   = array(
			'id'               => (int) $row['id'],
			'url'              => (string) $row['url'],
			'code'             => $code,
			'error'            => (string) $row['error'],
			'title'            => (string) $row['title'],
			'description'      => (string) $row['description'],
			'canonical'        => (string) $row['canonical'],
			'noindex'          => (bool) $row['noindex'],
			'h1'               => array_map( 'strval', (array) json_decode( (string) $row['h1'], true ) ),
			'h1_count'         => (int) $row['h1_count'],
			'images'           => array_map( 'strval', (array) json_decode( (string) $row['images'], true ) ),
			'images_missing'   => (int) $row['images_missing'],
			'same_title'       => (int) ( $duplicates['title'][ (string) $row['title_hash'] ] ?? 0 ),
			'same_description' => (int) ( $duplicates['description'][ (string) $row['description_hash'] ] ?? 0 ),
		);
		$issues = array();
		if ( 200 !== $code ) {
			$issues[] = 'failed';
		} else {
			$checks = array(
				'no_title'         => '' === $page['title'],
				'same_title'       => $page['same_title'] > 1,
				'no_description'   => '' === $page['description'],
				'same_description' => $page['same_description'] > 1,
				'no_h1'            => 0 === $page['h1_count'],
				'many_h1'          => $page['h1_count'] > 1,
				'images_alt'       => $page['images_missing'] > 0,
				'canonical'        => '' !== $page['canonical'] && $page['canonical'] !== $page['url'],
				'noindex'          => $page['noindex'],
			);
			$issues = array_keys( array_filter( $checks ) );
		}
		$page['issues'] = $issues;

		return $page;
	}

	/**
	 * Why the pages did not open, when none of them did: the site cannot reach itself (local
	 * addresses, a firewall), or it is closed to visitors (a password, a maintenance mode, the login
	 * page). '' while some pages open, or none is checked yet.
	 */
	public static function explanation(): string {
		global $wpdb;

		$done = self::count( 'done' );
		if ( 0 === $done || self::count( 'failed' ) < $done ) {
			return '';
		}

		$first = $wpdb->get_row( 'SELECT code, error FROM ' . self::table() . ' WHERE done = 1 ORDER BY id LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.
		$code  = (int) ( $first['code'] ?? 0 );
		$error = (string) ( $first['error'] ?? '' );

		if ( 0 === $code ) {
			return sprintf(
				/* translators: %s: the error of the request. */
				__( 'This site could not open its own pages: %s.', 'simple-seo' ),
				rtrim( $error, '.' )
			) . ' ' . ( Simple_SEO_IndexNow::local()
				? __( 'Its address is local: the audit opens the pages from this server, which works only when the server can reach and trust that address itself.', 'simple-seo' )
				: __( 'The host or a firewall may keep the server from opening its own site.', 'simple-seo' ) );
		}
		if ( $code >= 300 && $code < 400 && str_contains( $error, 'wp-login.php' ) ) {
			return __( 'This site is closed to visitors: its pages send them to the login page.', 'simple-seo' );
		}
		if ( in_array( $code, array( 401, 403 ), true ) ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'This site is closed to visitors: its pages answer %d (a password, a firewall or a plugin keeps them closed).', 'simple-seo' ), $code );
		}
		if ( 503 === $code ) {
			return __( 'This site answers 503 to visitors: it is in a maintenance mode.', 'simple-seo' );
		}

		return '';
	}

	/*
	 * The screens.
	 */

	public static function add_menu(): void {
		$hook = add_submenu_page(
			Simple_SEO_Admin::PAGE,
			__( 'Audit of pages', 'simple-seo' ),
			__( 'Audit', 'simple-seo' ),
			Simple_SEO_Access::CAP_REPORTS,
			self::PAGE,
			array( self::class, 'render_page' )
		);
		if ( is_string( $hook ) ) {
			self::$hook = $hook;
		}
	}

	/** The page's hook suffix; '' before the menu is built. */
	public static function hook(): string {
		return self::$hook;
	}

	/** The address of the page with a problem chosen and a page number. */
	public static function page_url( string $issue = '', int $paged = 1 ): string {
		$args = array( 'page' => self::PAGE );
		if ( in_array( $issue, self::ISSUES, true ) ) {
			$args['issue'] = $issue;
		}
		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_REPORTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-seo' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation and a message only.
		$issue = isset( $_GET['issue'] ) ? sanitize_key( wp_unslash( $_GET['issue'] ) ) : '';
		$issue = in_array( $issue, self::ISSUES, true ) ? $issue : '';
		$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$done  = isset( $_GET['simple-seo-done'] ) ? sanitize_key( wp_unslash( $_GET['simple-seo-done'] ) ) : '';
		// phpcs:enable

		$run         = self::run();
		$progress    = self::progress();
		$counts      = self::counts();
		$problems    = self::count( 'problems' );
		$found       = self::rows( $issue, $paged );
		$explanation = self::explanation();
		$hidden      = '0' === (string) get_option( 'blog_public' );
		$nonce       = self::NONCE;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$link_view   = isset( $_GET['links'] ) ? sanitize_key( wp_unslash( $_GET['links'] ) ) : '';
		$link_view   = in_array( $link_view, Simple_SEO_Links::VIEWS, true ) ? $link_view : 'broken';
		$link_paged  = max( 1, (int) ( $_GET['lpaged'] ?? 1 ) );
		// phpcs:enable
		$link_counts = Simple_SEO_Links::counts();
		$link_found  = Simple_SEO_Links::rows( $link_view, $link_paged );
		$link_total  = Simple_SEO_Links::count( 'checked' );

		require SIMPLE_SEO_DIR . 'includes/views/audit.php';
	}

	/** The forms of the page: start a run, or stop it. */
	public static function handle(): void {
		if ( ! current_user_can( Simple_SEO_Access::CAP_REPORTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-seo' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$do = sanitize_key( (string) wp_unslash( $_POST['do'] ?? '' ) );
		if ( 'start' === $do ) {
			$done = self::start() ? 'started' : 'empty';
		} elseif ( 'stop' === $do ) {
			self::stop();
			$done = 'stopped';
		} else {
			$done = 'nothing';
		}

		wp_safe_redirect( add_query_arg( 'simple-seo-done', $done, self::page_url() ) );
		exit;
	}

	/** A step from the open page of the audit: its progress back, for the page to show. */
	public static function ajax_step(): void {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( Simple_SEO_Access::CAP_REPORTS ) ) {
			wp_send_json_error( null, 403 );
		}

		$progress = self::step();
		wp_send_json_success(
			$progress + array(
				'text' => self::progress_text( $progress ),
			)
		);
	}

	/**
	 * "Checked 3 of 10 pages.", and once the pages are read, the links too.
	 *
	 * @param array{state: string, total: int, done: int, links_total?: int, links_done?: int} $progress
	 */
	public static function progress_text( array $progress ): string {
		if ( ! empty( $progress['links_total'] ) ) {
			return sprintf(
				/* translators: 1: pages checked, 2: pages of the run, 3: links checked, 4: links of the run. */
				__( 'Checked %1$s of %2$s pages and %3$s of %4$s links.', 'simple-seo' ),
				number_format_i18n( $progress['done'] ),
				number_format_i18n( $progress['total'] ),
				number_format_i18n( (int) ( $progress['links_done'] ?? 0 ) ),
				number_format_i18n( (int) $progress['links_total'] )
			);
		}

		return sprintf(
			/* translators: 1: pages checked, 2: pages of the run. */
			__( 'Checked %1$s of %2$s pages.', 'simple-seo' ),
			number_format_i18n( $progress['done'] ),
			number_format_i18n( $progress['total'] )
		);
	}
}
