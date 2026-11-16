<?php
/**
 * IndexNow (decisions/integrations): search engines are told at once when a page is published,
 * changed, moved or taken down, so they visit it sooner than their crawl would.
 *
 * The addresses go to Bing's endpoint in a batch a minute after the change, from WordPress's
 * scheduled tasks, never in the request of the editor; by the protocol the search engines that
 * take part share what any of them gets. Google does not take part. The key is made here and its
 * file is answered at the root of the site, as the protocol checks. Nothing is sent while the
 * switch is off, the site asks search engines not to index it, its address is local, or another
 * SEO plugin is active. A short log of the answers is kept for the tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_IndexNow {
	public const OPTION = 'simple_seo_indexnow';

	/** The addresses waiting to be sent, and how many times the batch has failed. */
	public const QUEUE = 'simple_seo_indexnow_queue';

	/** The last answers, the newest first. */
	public const LOG = 'simple_seo_indexnow_log';

	public const ENDPOINT = 'https://www.bing.com/indexnow';

	/** The scheduled task that sends the batch. */
	public const EVENT = 'simple_seo_indexnow_send';

	/** Seconds from the first change to the batch: the changes of a minute go together. */
	public const DELAY = 60;

	/** Seconds before a batch that failed on the way is tried again. */
	public const RETRY = 600;

	/** Tries of a batch before it is given up. */
	public const ATTEMPTS = 3;

	/** Addresses in one request at most, as the protocol allows. */
	public const BATCH = 10000;

	/** Answers kept in the log. */
	public const LOG_SIZE = 20;

	public static function init(): void {
		Simple_SEO_Settings::register(
			self::OPTION,
			array(
				'enabled' => false,
				'key'     => '',
			),
			array( self::class, 'sanitize' ),
			false
		);

		add_action( 'parse_request', array( self::class, 'serve_key' ), 0 );
		add_action( 'transition_post_status', array( self::class, 'published' ), 10, 3 );
		add_action( 'post_updated', array( self::class, 'updated' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'deleted' ), 10, 2 );
		add_action( self::EVENT, array( self::class, 'send' ) );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'indexnow', __( 'IndexNow', 'simple-seo' ), array( self::OPTION ), array( self::class, 'render_tab' ), 44 );
	}

	/*
	 * The settings and the key.
	 */

	/**
	 * The switch, and the key: the one kept (or sent as stored), a new one when asked for or when
	 * there is none.
	 *
	 * @param array<string, mixed> $input
	 * @return array{enabled: bool, key: string}
	 */
	public static function sanitize( array $input ): array {
		$key = self::clean_key( $input['key'] ?? '' );
		if ( '' === $key ) {
			$key = self::clean_key( Simple_SEO_Settings::value( self::OPTION, 'key' ) );
		}
		if ( '' === $key || ! empty( $input['new_key'] ) ) {
			$key = self::new_key();
		}

		return array(
			'enabled' => ! empty( $input['enabled'] ),
			'key'     => $key,
		);
	}

	/**
	 * A key as the protocol has it: 8 to 128 letters, digits and dashes; '' for anything else.
	 *
	 * @param mixed $key
	 */
	public static function clean_key( $key ): string {
		return is_string( $key ) && preg_match( '/^[a-zA-Z0-9\-]{8,128}$/', $key ) ? $key : '';
	}

	public static function new_key(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/** The key in use; '' while there is none. */
	public static function key(): string {
		return self::clean_key( Simple_SEO_Settings::value( self::OPTION, 'key' ) );
	}

	/**
	 * The address of the key file for an address of the site: on its host, under the home of the
	 * site, where IndexNow looks for it.
	 */
	public static function key_location( string $url = '' ): string {
		$home = (string) get_option( 'home' );
		$base = '' !== $url ? $url : $home;
		$port = wp_parse_url( $base, PHP_URL_PORT );

		return wp_parse_url( $base, PHP_URL_SCHEME ) . '://' . wp_parse_url( $base, PHP_URL_HOST ) . ( $port ? ':' . $port : '' )
			. trailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) ) . self::key() . '.txt';
	}

	/**
	 * The text of the key file for the path of a request: the key, when the path is the key file
	 * under the home of the site and IndexNow is on; null for any other path.
	 */
	public static function key_file( string $path ): ?string {
		$key = self::key();
		if ( '' === $key || ! Simple_SEO_Settings::value( self::OPTION, 'enabled' ) ) {
			return null;
		}

		$home = trailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );

		return $home . $key . '.txt' === $path ? $key : null;
	}

	/** Answer the key file: WordPress has no page there. */
	public static function serve_key(): void {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		// Read the option only for what looks like a key file.
		if ( ! str_ends_with( $path, '.txt' ) ) {
			return;
		}

		$text = self::key_file( $path );
		if ( null === $text ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $text );
		exit;
	}

	/*
	 * What is sent.
	 */

	/**
	 * Why nothing is sent now; [] when IndexNow works.
	 *
	 * @return array<string, string> reason => what it means
	 */
	public static function blockers(): array {
		$reasons = array();
		if ( ! Simple_SEO_Settings::value( self::OPTION, 'enabled' ) ) {
			$reasons['off'] = __( 'IndexNow is off.', 'simple-seo' );
		}
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$reasons['private'] = __( 'WordPress asks search engines not to index this site (Settings, Reading): nothing is sent.', 'simple-seo' );
		}
		if ( self::local() ) {
			$reasons['local'] = __( 'The address of this site is local or for development: search engines cannot reach it, so nothing is sent.', 'simple-seo' );
		}
		$plugin = Simple_SEO_Plugin::conflicting_plugin();
		if ( '' !== $plugin ) {
			/* translators: %s: name of an SEO plugin. */
			$reasons['plugin'] = sprintf( __( '%s is active: Simple SEO steps aside while it is, and sends nothing.', 'simple-seo' ), $plugin );
		}

		return $reasons;
	}

	/** Whether the site is one search engines cannot reach: a local or development one. */
	public static function local(): bool {
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return true;
		}

		$host = strtolower( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) );

		return '' === $host || 'localhost' === $host || false !== filter_var( $host, FILTER_VALIDATE_IP )
			|| (bool) preg_match( '/\.(?:test|local|localhost|invalid|example)$/', $host );
	}

	/**
	 * The hosts of the site's addresses: of its home and, with wp-loc, of each language's home.
	 *
	 * @return string[]
	 */
	public static function hosts(): array {
		$homes = array( (string) get_option( 'home' ) );
		foreach ( Simple_SEO_WP_Loc::codes() as $code ) {
			if ( '' !== $code ) {
				$homes[] = (string) apply_filters( 'wpml_home_url', '', $code );
			}
		}

		return array_values( array_unique( array_filter( array_map( static fn( string $url ): string => strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), $homes ) ) ) );
	}

	/** Whether an address is one of this site's: http or https, on one of its hosts. */
	public static function own( string $url ): bool {
		return (bool) preg_match( '#^https?://#i', $url ) && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), self::hosts(), true );
	}

	/** Whether changes of a post are sent: a published post of a type with SEO fields, no password. */
	public static function watched( WP_Post $post ): bool {
		return in_array( $post->post_type, Simple_SEO_Fields::post_types(), true ) && is_post_type_viewable( $post->post_type ) && '' === $post->post_password;
	}

	/**
	 * The address of a post that search engines may show, as the sitemap lists it: its own
	 * canonical address ('' for a page hidden from search engines or not published); '' otherwise.
	 */
	public static function post_address( WP_Post $post ): string {
		$url = (string) get_permalink( $post );

		return Simple_SEO_Indexing::canonical( Simple_SEO_Context::for_post( $post ) ) === $url ? $url : '';
	}

	/** A post published, or saved while published; drafts, revisions and autosaves cost nothing. */
	public static function published( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status || ! self::watched( $post ) ) {
			return;
		}

		self::add( array( self::post_address( $post ) ) );
	}

	/** A post taken off the site (drafted, made private, trashed), or moved to another address. */
	public static function updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( 'publish' !== $before->post_status || ! self::watched( $before ) ) {
			return;
		}

		$old = (string) get_permalink( $before );
		if ( 'publish' !== $after->post_status || (string) get_permalink( $after ) !== $old ) {
			self::add( array( $old ) );
		}
	}

	/** A published post deleted at once. */
	public static function deleted( int $post_id, ?WP_Post $post = null ): void {
		if ( $post instanceof WP_Post && 'publish' === $post->post_status && self::watched( $post ) ) {
			self::add( array( (string) get_permalink( $post ) ) );
		}
	}

	/**
	 * The batch waiting: addresses and the failed tries.
	 *
	 * @return array{urls: array<string, bool>, attempts: int}
	 */
	public static function queue(): array {
		$queue = get_option( self::QUEUE, array() );

		return array(
			'urls'     => is_array( $queue ) && is_array( $queue['urls'] ?? null ) ? array_map( 'boolval', $queue['urls'] ) : array(),
			'attempts' => is_array( $queue ) ? (int) ( $queue['attempts'] ?? 0 ) : 0,
		);
	}

	/**
	 * Add addresses to the batch and have it sent a minute later; nothing while IndexNow does not
	 * work.
	 *
	 * @param string[] $urls
	 */
	public static function add( array $urls ): void {
		$urls = array_filter( $urls, static fn( $url ): bool => is_string( $url ) && (bool) preg_match( '#^https?://#i', $url ) );
		if ( ! $urls || self::blockers() ) {
			return;
		}

		$queue = self::queue();
		foreach ( $urls as $url ) {
			if ( count( $queue['urls'] ) >= self::BATCH ) {
				break;
			}
			$queue['urls'][ $url ] = true;
		}
		update_option( self::QUEUE, $queue, false );

		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::EVENT );
		}
	}

	/**
	 * Send the batch, a request for each host; what failed on the way is tried again later.
	 *
	 * @return array<int, array{time: int, count: int, url: string, code: int, ok: bool, message: string}> The answers, as the log keeps them.
	 */
	public static function send(): array {
		$queue = self::queue();
		$urls  = array_keys( $queue['urls'] );
		if ( ! $urls ) {
			return array();
		}
		if ( self::blockers() ) {
			delete_option( self::QUEUE );
			return array();
		}

		$by_host = array();
		foreach ( $urls as $url ) {
			$by_host[ strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ][] = $url;
		}

		$again   = array();
		$answers = array();
		foreach ( $by_host as $host => $list ) {
			$result = self::submit( (string) $host, $list );
			if ( $result['retry'] && $queue['attempts'] + 1 < self::ATTEMPTS ) {
				$again              = array_merge( $again, $list );
				$result['message'] .= ' ' . __( 'It will be tried again in ten minutes.', 'simple-seo' );
			} elseif ( $result['retry'] ) {
				$result['message'] .= ' ' . __( 'Given up after three tries.', 'simple-seo' );
			}
			$answers[] = self::log( $list, $result );
		}

		// Addresses added while the batch was on its way stay for the next one.
		$now = self::queue();
		foreach ( $urls as $url ) {
			unset( $now['urls'][ $url ] );
		}
		foreach ( $again as $url ) {
			$now['urls'][ $url ] = true;
		}
		$now['attempts'] = $again ? $queue['attempts'] + 1 : 0;

		if ( ! $now['urls'] ) {
			delete_option( self::QUEUE );
			return $answers;
		}
		update_option( self::QUEUE, $now, false );
		wp_schedule_single_event( time() + ( $again ? self::RETRY : self::DELAY ), self::EVENT );

		return $answers;
	}

	/**
	 * The addresses of targets: of a post by its ID, when search engines may show it (as the
	 * sitemap lists it), or an address of this site; the rest left out, each with why.
	 *
	 * @param array<int, int|string> $targets IDs of posts or addresses.
	 * @return array{urls: string[], left: array<string, string>} left: target => why.
	 */
	public static function resolve( array $targets ): array {
		$urls = array();
		$left = array();
		foreach ( $targets as $target ) {
			$target = (string) $target;
			if ( ctype_digit( $target ) ) {
				$post = Simple_SEO_Fields::object( 'post', (int) $target );
				$url  = $post instanceof WP_Post && self::watched( $post ) ? self::post_address( $post ) : '';
				if ( '' === $url ) {
					/* translators: %s: ID of a post. */
					$left[ $target ] = sprintf( __( 'Post %s is not a page search engines may show: left out.', 'simple-seo' ), $target );
					continue;
				}
				$urls[] = $url;
			} elseif ( self::own( $target ) ) {
				$urls[] = $target;
			} else {
				/* translators: %s: an address. */
				$left[ $target ] = sprintf( __( 'Not an address of this site: %s', 'simple-seo' ), $target );
			}
		}

		return array(
			'urls' => $urls,
			'left' => $left,
		);
	}

	/**
	 * Send the batch now, the scheduled sending of it dropped: one scheduled within ten minutes
	 * would keep a retry from being scheduled.
	 *
	 * @return array<int, array{time: int, count: int, url: string, code: int, ok: bool, message: string}> The answers.
	 */
	public static function send_now(): array {
		wp_clear_scheduled_hook( self::EVENT );

		return self::send();
	}

	/**
	 * Whether IndexNow sends, its key, the batch waiting and the last answers, for WP-CLI and the
	 * abilities: times in the site's time zone, addresses readable.
	 *
	 * @return array{enabled: bool, key: string, key_file: string, waiting: int, next: string, blockers: string[], answers: array<int, array<string, mixed>>}
	 */
	public static function status(): array {
		$key  = self::key();
		$next = wp_next_scheduled( self::EVENT );

		return array(
			'enabled'  => (bool) Simple_SEO_Settings::value( self::OPTION, 'enabled' ),
			'key'      => $key,
			'key_file' => '' !== $key ? self::key_location() : '',
			'waiting'  => count( self::queue()['urls'] ),
			'next'     => false !== $next ? (string) wp_date( 'Y-m-d H:i:s P', (int) $next ) : '',
			'blockers' => array_values( self::blockers() ),
			'answers'  => array_map(
				static fn( array $entry ): array => array(
					'time'    => (string) wp_date( 'Y-m-d H:i:s P', (int) ( $entry['time'] ?? 0 ) ),
					'count'   => (int) ( $entry['count'] ?? 0 ),
					'url'     => rawurldecode( (string) ( $entry['url'] ?? '' ) ),
					'code'    => (int) ( $entry['code'] ?? 0 ),
					'ok'      => ! empty( $entry['ok'] ),
					'message' => (string) ( $entry['message'] ?? '' ),
				),
				self::entries()
			),
		);
	}

	/**
	 * One request: the addresses of a host with the key and where its file is.
	 *
	 * @param string[] $urls
	 * @return array{code: int, ok: bool, retry: bool, message: string}
	 */
	public static function submit( string $host, array $urls ): array {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => (string) wp_json_encode(
					array(
						'host'        => $host,
						'key'         => self::key(),
						'keyLocation' => self::key_location( $urls[0] ?? '' ),
						'urlList'     => array_values( $urls ),
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code'    => 0,
				'ok'      => false,
				'retry'   => true,
				/* translators: %s: the error. */
				'message' => sprintf( __( 'Bing cannot be reached from this server: %s', 'simple-seo' ), $response->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'code'    => $code,
			'ok'      => in_array( $code, array( 200, 202 ), true ),
			'retry'   => 429 === $code || $code >= 500,
			'message' => self::explain( $code ),
		);
	}

	/** What an answer of IndexNow means. */
	private static function explain( int $code ): string {
		switch ( $code ) {
			case 200:
				return __( 'Taken.', 'simple-seo' );
			case 202:
				return __( 'Taken; Bing checks the key file first.', 'simple-seo' );
			case 400:
				return __( 'Bing found the request wrong.', 'simple-seo' );
			case 403:
				/* translators: %s: address of the key file. */
				return sprintf( __( 'The key is not valid: Bing could not read the key file at %s.', 'simple-seo' ), self::key_location() );
			case 422:
				return __( 'The addresses do not belong to the host of the site or to its key.', 'simple-seo' );
			case 429:
				return __( 'Too many requests: Bing asks to wait.', 'simple-seo' );
		}

		/* translators: %s: HTTP status. */
		return sprintf( __( 'Bing answered %s.', 'simple-seo' ), (string) $code );
	}

	/**
	 * Keep an answer in the log, the newest first.
	 *
	 * @param string[]                                                     $urls
	 * @param array{code: int, ok: bool, retry: bool, message: string} $result
	 * @return array{time: int, count: int, url: string, code: int, ok: bool, message: string}
	 */
	private static function log( array $urls, array $result ): array {
		$entry = array(
			'time'    => time(),
			'count'   => count( $urls ),
			'url'     => (string) ( $urls[0] ?? '' ),
			'code'    => $result['code'],
			'ok'      => $result['ok'],
			'message' => $result['message'],
		);
		$log   = get_option( self::LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		update_option( self::LOG, array_slice( $log, 0, self::LOG_SIZE ), false );

		return $entry;
	}

	/**
	 * The answers kept, the newest first.
	 *
	 * @return array<int, array{time: int, count: int, url: string, code: int, ok: bool, message: string}>
	 */
	public static function entries(): array {
		$log = get_option( self::LOG, array() );

		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}

	/*
	 * The tab.
	 */

	public static function render_tab(): void {
		$settings = Simple_SEO_Settings::get( self::OPTION );
		$blockers = self::blockers();
		unset( $blockers['off'] );
		$queue   = self::queue();
		$next    = wp_next_scheduled( self::EVENT );
		$entries = self::entries();

		require SIMPLE_SEO_DIR . 'includes/views/tab-indexnow.php';
	}
}
