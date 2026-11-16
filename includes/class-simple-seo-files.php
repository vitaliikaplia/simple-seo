<?php
/**
 * robots.txt and .htaccess, edited on the Files tab.
 *
 * robots.txt is the virtual one WordPress serves (its text kept in an option), or the robots.txt
 * file in the site root when there is one: the server sends that file itself, so the file is what
 * gets edited. .htaccess is the file in the site root.
 *
 * Every change keeps the previous text in a history first (the last ten, in the database). Files
 * on disk need the core permission to edit files (edit_files: administrators, not under
 * DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS, only a super admin on a network); the virtual
 * robots.txt needs only the plugin settings. After writing .htaccess the plugin opens the home page
 * and the REST API from the server and puts the previous file back if either stopped answering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Files {
	public const ROBOTS   = 'robots';
	public const HTACCESS = 'htaccess';

	/** The text of the virtual robots.txt; '' serves the WordPress default. */
	public const ROBOTS_OPTION = 'simple_seo_robots_txt';

	/** Previous texts of both files: file => list, newest first. */
	public const BACKUPS_OPTION = 'simple_seo_file_backups';

	/**
	 * A copy of the previous .htaccess next to it, for a restore by hand (FTP, SSH) when a change
	 * takes the whole site down. Only where .htaccess works: Apache and LiteSpeed refuse to serve
	 * files whose names start with .ht. uninstall.php names it too.
	 */
	public const DISK_COPY = '.htaccess.simple-seo-backup';

	/** Versions kept in the history of each file. */
	private const HISTORY = 10;

	/** Longest text accepted (Google reads 500 KiB of robots.txt). */
	private const MAX_BYTES = 512000;

	/** @var array<string, string> Text a refused change leaves in the editor: file => text. */
	private static array $drafts = array();

	/** @var array<string, bool> Files whose last change was refused because they changed meanwhile. */
	private static array $conflicts = array();

	public static function init(): void {
		// Before other filters: the text replaces the lines of WordPress, what other plugins add stays.
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), -10, 2 );

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		}
	}

	public static function add_admin_tab(): void {
		// No option rows: the tab prints its own forms, the page no options.php form around them.
		Simple_SEO_Admin::add_tab(
			'files',
			__( 'Files', 'simple-seo' ),
			array(),
			array( self::class, 'render_tab' ),
			40,
			'crawl',
			static fn(): array => array(
				self::ROBOTS   => 'robots.txt',
				self::HTACCESS => '.htaccess',
			)
		);
		add_action( 'load-toplevel_page_' . Simple_SEO_Admin::PAGE, array( self::class, 'handle' ) );
	}

	/**
	 * The virtual robots.txt: the text saved on the Files tab instead of the lines of WordPress.
	 *
	 * @param mixed $output
	 * @param mixed $is_public
	 */
	public static function robots_txt( $output, $is_public ): string {
		$text = self::robots_option();

		// While another SEO plugin is active, the site gets nothing from Simple SEO.
		if ( '' === $text || '' !== Simple_SEO_Plugin::conflicting_plugin() ) {
			return (string) $output;
		}

		return $text;
	}

	public static function robots_option(): string {
		$text = get_option( self::ROBOTS_OPTION, '' );

		return is_string( $text ) ? $text : '';
	}

	/** What WordPress itself puts into robots.txt (do_robots()), before the filters. */
	public static function robots_default(): string {
		return "User-agent: *\n"
			. 'Disallow: ' . wp_parse_url( admin_url(), PHP_URL_PATH ) . "\n"
			. 'Allow: ' . wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ) . "\n";
	}

	/** The virtual robots.txt as the site serves it: with what Simple SEO and other plugins add. */
	public static function robots_served(): string {
		return (string) apply_filters( 'robots_txt', self::robots_default(), (bool) get_option( 'blog_public' ) );
	}

	public static function path( string $file ): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return get_home_path() . ( self::ROBOTS === $file ? 'robots.txt' : '.htaccess' );
	}

	/** Whether a robots.txt file in the site root takes the place of the virtual one. */
	public static function robots_is_file(): bool {
		return is_file( self::path( self::ROBOTS ) );
	}

	/** Whether the server reads .htaccess at all: Apache and LiteSpeed do, nginx and others do not. */
	public static function server_reads_htaccess(): bool {
		return ! empty( $GLOBALS['is_apache'] );
	}

	/**
	 * The text being edited: the file, or the virtual robots.txt (the WordPress lines when nothing is
	 * saved). '' for a missing .htaccess, null when the file is there but cannot be read.
	 */
	public static function current( string $file ): ?string {
		if ( self::ROBOTS === $file && ! self::robots_is_file() ) {
			$text = self::robots_option();

			return '' !== $text ? $text : self::robots_default();
		}

		$path = self::path( $file );
		if ( ! file_exists( $path ) ) {
			return '';
		}

		$text = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.

		return false === $text ? null : $text;
	}

	/** Fingerprint of the text the form was opened with: a change made meanwhile is not overwritten. */
	public static function fingerprint( ?string $text ): string {
		return null === $text ? '' : md5( $text );
	}

	/** One kind of line ending, no NUL bytes, one line break at the end; nothing else is touched. */
	public static function normalize( string $text ): string {
		$text = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $text );

		return '' === trim( $text ) ? '' : rtrim( $text ) . "\n";
	}

	/** Why the current user may not change the file; '' when they may. */
	public static function refusal( string $file ): string {
		if ( ! current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			return __( 'You may not change the Simple SEO settings.', 'simple-seo' );
		}

		// The virtual robots.txt is an option, not a file.
		if ( self::ROBOTS === $file && ! self::robots_is_file() ) {
			return '';
		}

		if ( ! wp_is_file_mod_allowed( 'simple_seo_files' ) ) {
			return __( 'Changing files is switched off on this site (DISALLOW_FILE_MODS in wp-config.php).', 'simple-seo' );
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return __( 'Editing files is switched off on this site (DISALLOW_FILE_EDIT in wp-config.php).', 'simple-seo' );
		}
		if ( ! current_user_can( 'edit_files' ) ) {
			return is_multisite()
				? __( 'On a network of sites only a super admin may edit files.', 'simple-seo' )
				: __( 'Your role may not edit files; an administrator can.', 'simple-seo' );
		}

		$path = self::path( $file );
		if ( file_exists( $path ) ) {
			if ( ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				/* translators: 1: file path, 2: its permissions, e.g. 0444. */
				return sprintf( __( 'The web server may not write %1$s (its permissions are %2$s).', 'simple-seo' ), $path, substr( sprintf( '%o', fileperms( $path ) ), -4 ) );
			}
		} elseif ( ! is_writable( dirname( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			/* translators: %s: folder path. */
			return sprintf( __( 'The web server may not create files in %s.', 'simple-seo' ), dirname( $path ) );
		}

		return '';
	}

	/**
	 * Save the text of a file.
	 *
	 * @param string $loaded Fingerprint of the text the form showed.
	 * @return array{ok: bool, code: string, message: string, type: string, detail: string}
	 */
	public static function save( string $file, string $text, string $loaded ): array {
		$refusal = self::refusal( $file );
		if ( '' !== $refusal ) {
			return self::result( false, 'refused', $file, $refusal );
		}

		$text = self::normalize( $text );
		if ( strlen( $text ) > self::MAX_BYTES ) {
			return self::result( false, 'too-long', $file );
		}

		$current = self::current( $file );
		if ( null === $current ) {
			return self::result( false, 'unreadable', $file );
		}
		if ( ! hash_equals( self::fingerprint( $current ), $loaded ) ) {
			self::$conflicts[ $file ] = true;
			return self::result( false, 'conflict', $file );
		}

		$virtual = self::ROBOTS === $file && ! self::robots_is_file();
		if ( $virtual ? self::stored_form( $text ) === self::robots_option() : $text === $current ) {
			return self::result( true, 'unchanged', $file );
		}

		return self::write( $file, $text, $current, 'saved' );
	}

	/**
	 * Put a version from the history back.
	 *
	 * @return array{ok: bool, code: string, message: string, type: string, detail: string}
	 */
	public static function restore( string $file, string $id, string $loaded ): array {
		$refusal = self::refusal( $file );
		if ( '' !== $refusal ) {
			return self::result( false, 'refused', $file, $refusal );
		}

		$entry = null;
		foreach ( self::history( $file ) as $version ) {
			if ( $version['id'] === $id ) {
				$entry = $version;
				break;
			}
		}
		if ( null === $entry ) {
			return self::result( false, 'missing', $file );
		}

		$current = self::current( $file );
		if ( null === $current ) {
			return self::result( false, 'unreadable', $file );
		}
		if ( ! hash_equals( self::fingerprint( $current ), $loaded ) ) {
			self::$conflicts[ $file ] = true;
			return self::result( false, 'conflict', $file );
		}
		if ( $entry['content'] === $current ) {
			return self::result( true, 'unchanged', $file );
		}

		return self::write( $file, $entry['content'], $current, 'restored' );
	}

	/**
	 * Replace the robots.txt file with the virtual robots.txt: its text moves into the editor, the
	 * file goes away (its text stays in the history).
	 *
	 * @return array{ok: bool, code: string, message: string, type: string, detail: string}
	 */
	public static function to_virtual( string $loaded ): array {
		if ( ! self::robots_is_file() ) {
			return self::result( true, 'unchanged', self::ROBOTS );
		}

		$refusal = self::refusal( self::ROBOTS );
		if ( '' !== $refusal ) {
			return self::result( false, 'refused', self::ROBOTS, $refusal );
		}

		$current = self::current( self::ROBOTS );
		if ( null === $current ) {
			return self::result( false, 'unreadable', self::ROBOTS );
		}
		if ( ! hash_equals( self::fingerprint( $current ), $loaded ) ) {
			self::$conflicts[ self::ROBOTS ] = true;
			return self::result( false, 'conflict', self::ROBOTS );
		}

		$previous = self::robots_option();
		self::remember( self::ROBOTS, $current, 'file' );
		update_option( self::ROBOTS_OPTION, self::stored_form( self::normalize( $current ) ), false );

		if ( ! wp_delete_file_from_directory( self::path( self::ROBOTS ), dirname( self::path( self::ROBOTS ) ) ) || self::robots_is_file() ) {
			update_option( self::ROBOTS_OPTION, $previous, false );
			self::forget( self::ROBOTS );
			return self::result( false, 'failed', self::ROBOTS );
		}

		return self::result( true, 'virtual', self::ROBOTS );
	}

	/**
	 * Previous versions of a file, newest first.
	 *
	 * @return array<int, array{id: string, time: int, user: int, source: string, content: string}>
	 */
	public static function history( string $file ): array {
		$all  = get_option( self::BACKUPS_OPTION, array() );
		$list = is_array( $all ) && isset( $all[ $file ] ) && is_array( $all[ $file ] ) ? $all[ $file ] : array();

		$history = array();
		foreach ( $list as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'], $entry['content'] ) ) {
				$history[] = array(
					'id'      => (string) $entry['id'],
					'time'    => (int) ( $entry['time'] ?? 0 ),
					'user'    => (int) ( $entry['user'] ?? 0 ),
					'source'  => (string) ( $entry['source'] ?? '' ),
					'content' => (string) $entry['content'],
				);
			}
		}

		return $history;
	}

	/**
	 * Request the home page and the REST API from the server itself.
	 *
	 * @return array<string, int> URL => status code, 0 for no answer; [] when nothing answered at all
	 *                            (the server cannot reach itself, so nothing can be checked).
	 */
	public static function probe(): array {
		$headers = array( 'Cache-Control' => 'no-cache' );
		// Sites behind HTTP authentication (staging) answer the server with the same credentials.
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- credentials as sent.
		}

		$codes    = array();
		$answered = false;
		foreach ( array( home_url( '/' ), rest_url() ) as $url ) {
			$response = wp_remote_get(
				add_query_arg( 'simple-seo-check', (string) microtime( true ), $url ),
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'headers'     => $headers,
					/** This filter is documented in wp-includes/class-wp-http-streams.php */
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				)
			);
			$code          = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$codes[ $url ] = $code;
			$answered      = $answered || $code > 0;
		}

		return $answered ? $codes : array();
	}

	/**
	 * What stopped working between two probes: "URL (status)"; null when nothing did. An address
	 * that failed already before the change is not held against it.
	 *
	 * @param array<string, int> $before
	 * @param array<string, int> $after
	 */
	public static function broken( array $before, array $after ): ?string {
		foreach ( $before as $url => $code ) {
			if ( $code < 1 || $code >= 400 ) {
				continue;
			}

			$now = (int) ( $after[ $url ] ?? 0 );
			if ( $now < 1 || $now >= 400 ) {
				return $url . ' (' . ( $now > 0 ? (string) $now : __( 'no answer', 'simple-seo' ) ) . ')';
			}
		}

		return null;
	}

	/** Handle a form of the Files tab: redirect after a change, otherwise show what went wrong. */
	public static function handle(): void {
		$url = self::process();
		if ( null !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}

		// After the redirect: the message of what was done.
		$file = isset( $_GET['simple-seo-file'] ) ? sanitize_key( wp_unslash( $_GET['simple-seo-file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message only.
		$code = isset( $_GET['simple-seo-done'] ) ? sanitize_key( wp_unslash( $_GET['simple-seo-done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message only.
		if ( in_array( $file, array( self::ROBOTS, self::HTACCESS ), true ) && '' !== $code ) {
			$result = self::result( true, $code, $file );
			if ( '' !== $result['message'] ) {
				add_settings_error( 'simple-seo-files', 'simple-seo-' . $code, $result['message'], $result['type'] );
			}
		}
	}

	/**
	 * Run the change a form of the Files tab asks for.
	 *
	 * @return string|null Where to go after a change; null when nothing was posted or the change was
	 *                     refused (the message is added to the page, the text stays in the editor).
	 */
	public static function process(): ?string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['simple_seo_file'] ) ) {
			return null;
		}

		$file = sanitize_key( wp_unslash( $_POST['simple_seo_file'] ) );
		if ( ! in_array( $file, array( self::ROBOTS, self::HTACCESS ), true ) ) {
			return null;
		}

		$do     = isset( $_POST['simple_seo_do'] ) ? sanitize_key( wp_unslash( $_POST['simple_seo_do'] ) ) : 'save';
		$loaded = isset( $_POST['simple_seo_loaded'] ) ? sanitize_key( wp_unslash( $_POST['simple_seo_loaded'] ) ) : '';
		// The text of the file as typed: no sanitizing, which would strip <IfModule> and other tags.
		$text = isset( $_POST['simple_seo_content'] ) && is_string( $_POST['simple_seo_content'] ) ? wp_unslash( $_POST['simple_seo_content'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'simple_seo_file_' . $file ) ) {
			$result = self::result( false, 'expired', $file );
		} elseif ( 'restore' === $do ) {
			$id     = isset( $_POST['simple_seo_backup'] ) ? sanitize_key( wp_unslash( $_POST['simple_seo_backup'] ) ) : '';
			$result = self::restore( $file, $id, $loaded );
		} elseif ( 'virtual' === $do && self::ROBOTS === $file ) {
			$result = self::to_virtual( $loaded );
		} elseif ( 'default' === $do && self::ROBOTS === $file ) {
			$result = self::save( $file, '', $loaded );
		} else {
			$result = self::save( $file, $text, $loaded );
		}

		if ( $result['ok'] ) {
			return add_query_arg(
				array(
					'simple-seo-file' => $file,
					'simple-seo-done' => $result['code'],
					'section'         => $file,
				),
				Simple_SEO_Admin::url( 'files' )
			) . '#simple-seo-' . $file;
		}

		add_settings_error( 'simple-seo-files', 'simple-seo-' . $result['code'], $result['message'], 'error' );
		if ( 'save' === $do ) {
			self::$drafts[ $file ] = $text;
		}

		return null;
	}

	public static function render_tab(): void {
		$files = array();
		foreach ( array( self::ROBOTS, self::HTACCESS ) as $file ) {
			$current        = self::current( $file );
			$files[ $file ] = array(
				'path'     => self::path( $file ),
				'exists'   => file_exists( self::path( $file ) ),
				'current'  => $current,
				'text'     => self::$drafts[ $file ] ?? (string) $current,
				'loaded'   => self::fingerprint( $current ),
				'refusal'  => null === $current ? __( 'The file cannot be read.', 'simple-seo' ) : self::refusal( $file ),
				'conflict' => ! empty( self::$conflicts[ $file ] ),
				'history'  => self::history( $file ),
			);
		}

		$robots_file = self::robots_is_file();
		$sitemap     = Simple_SEO_Sitemaps::enabled() ? esc_url_raw( Simple_SEO_Sitemaps::index_url() ) : '';
		$home_path   = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$disk_copy   = is_file( dirname( self::path( self::HTACCESS ) ) . '/' . self::DISK_COPY ) ? dirname( self::path( self::HTACCESS ) ) . '/' . self::DISK_COPY : '';
		$server      = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';

		require SIMPLE_SEO_DIR . 'includes/views/tab-files.php';
	}

	/** How the virtual robots.txt keeps a text: the WordPress lines (or nothing) as ''. */
	private static function stored_form( string $text ): string {
		return '' === $text || $text === self::robots_default() ? '' : $text;
	}

	/**
	 * Write a file (or the option of the virtual robots.txt), keeping the previous text first.
	 *
	 * @return array{ok: bool, code: string, message: string, type: string, detail: string}
	 */
	private static function write( string $file, string $text, string $current, string $code ): array {
		if ( self::ROBOTS === $file && ! self::robots_is_file() ) {
			self::remember( $file, $current, 'virtual' );
			update_option( self::ROBOTS_OPTION, self::stored_form( $text ), false );

			return self::result( true, $code, $file );
		}

		$path     = self::path( $file );
		$existed  = file_exists( $path );
		// .htaccess can take the site down only where the server reads it.
		$htaccess = self::HTACCESS === $file && self::server_reads_htaccess();
		$before   = $htaccess ? self::probe() : array();

		self::remember( $file, $current, 'file' );
		if ( $htaccess && $existed ) {
			self::put( dirname( $path ) . '/' . self::DISK_COPY, $current );
		}

		if ( ! self::put( $path, $text ) ) {
			self::undo( $path, $current, $existed );
			self::forget( $file );
			return self::result( false, 'failed', $file );
		}

		if ( $htaccess ) {
			if ( ! $before ) {
				return self::result( true, 'unverified', $file );
			}

			$broken = self::broken( $before, self::probe() );
			if ( null !== $broken ) {
				self::undo( $path, $current, $existed );
				self::forget( $file );
				return self::result( false, 'rolled-back', $file, $broken );
			}
		}

		return self::result( true, $code, $file );
	}

	/** Put the previous text back; a file that was not there goes away again. */
	private static function undo( string $path, string $previous, bool $existed ): void {
		if ( $existed ) {
			self::put( $path, $previous );
		} else {
			wp_delete_file( $path );
		}
	}

	/** Write a file in place under a lock, as WordPress writes .htaccess (insert_with_markers()). */
	private static function put( string $path, string $text ): bool {
		$handle = @fopen( $path, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- false is handled.
		if ( ! $handle ) {
			return false;
		}

		$ok = flock( $handle, LOCK_EX ) && ftruncate( $handle, 0 ) && rewind( $handle );
		if ( $ok && '' !== $text ) {
			$ok = strlen( $text ) === fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fflush( $handle );
		flock( $handle, LOCK_UN );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		clearstatcache( true, $path );

		return $ok;
	}

	private static function remember( string $file, string $content, string $source ): void {
		$all = get_option( self::BACKUPS_OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$history = self::history( $file );
		array_unshift(
			$history,
			array(
				'id'      => strtolower( wp_generate_password( 12, false ) ),
				'time'    => time(),
				'user'    => get_current_user_id(),
				'source'  => $source,
				'content' => $content,
			)
		);
		$all[ $file ] = array_slice( $history, 0, self::HISTORY );

		update_option( self::BACKUPS_OPTION, $all, false );
	}

	/** Drop the version remembered last: the change it was kept for did not happen. */
	private static function forget( string $file ): void {
		$all = get_option( self::BACKUPS_OPTION, array() );
		if ( is_array( $all ) && ! empty( $all[ $file ] ) && is_array( $all[ $file ] ) ) {
			array_shift( $all[ $file ] );
			update_option( self::BACKUPS_OPTION, $all, false );
		}
	}

	/**
	 * A result and its message for the page.
	 *
	 * @param string $detail The reason of a refusal, the address that broke.
	 * @return array{ok: bool, code: string, message: string, type: string, detail: string}
	 */
	private static function result( bool $ok, string $code, string $file, string $detail = '' ): array {
		$name = self::HTACCESS === $file ? '.htaccess' : 'robots.txt';

		switch ( $code ) {
			case 'saved':
				/* translators: %s: robots.txt or .htaccess. */
				$message = sprintf( __( '%s is saved. The previous version is in the history.', 'simple-seo' ), $name );
				$type    = 'success';
				break;
			case 'restored':
				/* translators: %s: robots.txt or .htaccess. */
				$message = sprintf( __( '%s is restored from the history.', 'simple-seo' ), $name );
				$type    = 'success';
				break;
			case 'virtual':
				$message = __( 'The robots.txt file is deleted: WordPress now serves its text. The file is in the history.', 'simple-seo' );
				$type    = 'success';
				break;
			case 'unverified':
				$message = __( '.htaccess is saved, but the server could not open the site to check it. Open the site now; if it does not work, restore the previous version from the history.', 'simple-seo' );
				$type    = 'warning';
				break;
			case 'unchanged':
				$message = __( 'Nothing to save: the text is the same.', 'simple-seo' );
				$type    = 'info';
				break;
			case 'conflict':
				/* translators: %s: robots.txt or .htaccess. */
				$message = sprintf( __( '%s changed after you opened it (WordPress, for one, rewrites its own rules in .htaccess). Nothing is saved. Your text is in the editor and the file as it is now below it; save again to replace the file.', 'simple-seo' ), $name );
				$type    = 'error';
				break;
			case 'rolled-back':
				/* translators: %s: address that stopped answering, with its status, e.g. https://example.com/ (500). */
				$message = sprintf( __( 'With the new .htaccess the site stopped answering: %s. The previous file is back, nothing has changed; your text is in the editor.', 'simple-seo' ), $detail );
				$type    = 'error';
				break;
			case 'too-long':
				/* translators: 1: robots.txt or .htaccess, 2: size, e.g. 500 KB. */
				$message = sprintf( __( 'The text of %1$s is too long: at most %2$s.', 'simple-seo' ), $name, size_format( self::MAX_BYTES ) );
				$type    = 'error';
				break;
			case 'failed':
				/* translators: %s: robots.txt or .htaccess. */
				$message = sprintf( __( '%s could not be written; nothing has changed.', 'simple-seo' ), $name );
				$type    = 'error';
				break;
			case 'unreadable':
				/* translators: %s: robots.txt or .htaccess. */
				$message = sprintf( __( '%s cannot be read, so it is not changed.', 'simple-seo' ), $name );
				$type    = 'error';
				break;
			case 'missing':
				$message = __( 'That version is no longer in the history.', 'simple-seo' );
				$type    = 'error';
				break;
			case 'expired':
				$message = __( 'The page has expired. Open it again and repeat.', 'simple-seo' );
				$type    = 'error';
				break;
			default:
				$message = $detail;
				$type    = $ok ? 'success' : 'error';
		}

		return array(
			'ok'      => $ok,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
			'detail'  => $detail,
		);
	}
}
