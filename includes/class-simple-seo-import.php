<?php
/**
 * The imports from other SEO plugins in the admin: the Import tab, the notice that offers an import
 * once a plugin's data is found, and the run itself — settings, then posts, then terms, in batches
 * that a request goes through for a few seconds before the page continues with the next request.
 * Each plugin's state and report are kept in a row of their own (not autoloaded), so a run broken
 * off goes on where it stopped. The tab also offers removing a plugin's data afterwards.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Import {
	/** The plugins the import takes data from: key => import class (a Simple_SEO_Import_Source). */
	public const SOURCES = array(
		'yoast'     => 'Simple_SEO_Yoast_Import',
		'rank_math' => 'Simple_SEO_Rank_Math_Import',
		'aioseo'    => 'Simple_SEO_Aioseo_Import',
	);

	/** Posts per batch; terms come from one row, so more of them. */
	public const POSTS = 100;
	public const TERMS = 300;

	/** Seconds one request works before the page continues with the next. */
	public const BUDGET = 10.0;

	private const NONCE = 'simple_seo_import';

	public static function init(): void {
		add_action( 'init', array( self::class, 'add_admin_tab' ), 6 );
		add_action( 'admin_init', array( self::class, 'detect' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'load-toplevel_page_' . Simple_SEO_Admin::PAGE, array( self::class, 'handle' ) );
	}

	public static function add_admin_tab(): void {
		Simple_SEO_Admin::add_tab( 'import', __( 'Import', 'simple-seo' ), array(), array( self::class, 'render_tab' ), 95 );
	}

	/**
	 * The state of the import from a plugin: status (pending, none, running, done, dismissed), phase
	 * (settings, posts, terms), cursor, report, started, finished.
	 *
	 * @return array<string, mixed>
	 */
	public static function state( string $source ): array {
		$state = get_option( self::SOURCES[ $source ]::STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	/** @param array<string, mixed> $state */
	private static function store( string $source, array $state ): void {
		$name = self::SOURCES[ $source ]::STATE;
		if ( null === get_option( $name, null ) ) {
			add_option( $name, $state, '', false );
		} else {
			update_option( $name, $state, false );
		}
	}

	/** Once per plugin, on the first admin request: whether there is anything of its to offer. */
	public static function detect(): void {
		// Not autoloaded (they hold the reports): one query for all of them on each admin screen.
		wp_prime_option_caches( array_map( static fn( string $class ): string => $class::STATE, array_values( self::SOURCES ) ) );

		foreach ( self::SOURCES as $source => $class ) {
			if ( ! array_key_exists( 'status', self::state( $source ) ) ) {
				self::store( $source, array( 'status' => $class::found() ? 'pending' : 'none' ) );
			}
		}
	}

	/**
	 * The plugins whose import is offered and neither run nor put off.
	 *
	 * @return string[] Source keys.
	 */
	private static function pending(): array {
		return array_keys( array_filter( self::SOURCES, static fn( string $class, string $source ): bool => 'pending' === ( self::state( $source )['status'] ?? '' ), ARRAY_FILTER_USE_BOTH ) );
	}

	/** Offer the import on the admin screens until it is run or put off. */
	public static function notice(): void {
		$pending = self::pending();
		if ( ! $pending || ! current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_' . Simple_SEO_Admin::PAGE === $screen->id && 'import' === Simple_SEO_Admin::current_tab() ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s</p><form method="post" action="%2$s"><p><a class="button button-primary" href="%3$s">%4$s</a> %5$s<button type="submit" class="button-link" name="simple_seo_import" value="dismiss">%6$s</button></p></form></div>',
			/* translators: %s: names of SEO plugins, e.g. Yoast SEO and Rank Math. */
			esc_html( sprintf( __( 'Simple SEO found the data of %s on this site: the settings and the titles, descriptions and other SEO fields of posts and terms. Import them, so the pages keep what was set for them.', 'simple-seo' ), wp_sprintf( '%l', array_map( static fn( string $source ): string => self::SOURCES[ $source ]::NAME, $pending ) ) ) ),
			esc_url( Simple_SEO_Admin::url( 'import' ) ),
			esc_url( Simple_SEO_Admin::url( 'import' ) ),
			esc_html__( 'Go to the import', 'simple-seo' ),
			wp_nonce_field( self::NONCE, '_wpnonce', true, false ),
			esc_html__( 'Not now', 'simple-seo' )
		);
	}

	/** A form of the Import tab or of the notice; then back to the tab, with what went wrong if anything. */
	public static function handle(): void {
		$do = isset( $_POST['simple_seo_import'] ) ? sanitize_key( wp_unslash( $_POST['simple_seo_import'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		if ( '' === $do || ! current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$input   = wp_unslash( $_POST );
		$refused = self::act( $do, $input );
		$url     = Simple_SEO_Admin::url( 'import' );
		if ( '' !== $refused ) {
			$url = add_query_arg(
				array(
					'simple-seo-refused' => $refused,
					'simple-seo-source'  => sanitize_key( (string) ( $input['simple_seo_source'] ?? '' ) ),
				),
				$url
			);
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * What a form asks for: put the offers off; for the plugin named in the form, start (again) or
	 * continue a run, remove its data (the parts ticked, a backup confirmed) or continue removing it.
	 *
	 * @param array<string, mixed> $input The form, unslashed.
	 * @return string Why a clean-up was refused (backup, groups, blocked); '' otherwise.
	 */
	public static function act( string $do, array $input = array() ): string {
		if ( 'dismiss' === $do ) {
			foreach ( self::pending() as $source ) {
				self::store( $source, array_merge( self::state( $source ), array( 'status' => 'dismissed' ) ) );
			}
			return '';
		}

		$source = sanitize_key( (string) ( $input['simple_seo_source'] ?? '' ) );
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			return '';
		}
		if ( in_array( $do, array( 'clean', 'clean_continue' ), true ) ) {
			return self::clean( $do, $source, $input );
		}

		if ( 'start' === $do && 'running' !== ( self::state( $source )['status'] ?? '' ) ) {
			self::start( $source );
		}
		if ( in_array( $do, array( 'start', 'continue' ), true ) ) {
			self::run( $source );
		}

		return '';
	}

	/**
	 * Begin removing a plugin's data, or go on with it.
	 *
	 * @param array<string, mixed> $input
	 */
	private static function clean( string $do, string $source, array $input ): string {
		$cleanup = self::SOURCES[ $source ]::CLEANUP;
		if ( '' !== $cleanup::blocked() ) {
			return 'blocked';
		}

		if ( 'clean' === $do && 'running' !== ( $cleanup::state()['status'] ?? '' ) ) {
			$groups = array_intersect( $cleanup::GROUPS, array_map( 'strval', (array) ( $input['simple_seo_groups'] ?? array() ) ) );
			if ( ! $groups ) {
				return 'groups';
			}
			if ( '1' !== (string) ( $input['simple_seo_backup'] ?? '' ) ) {
				return 'backup';
			}
			$cleanup::start( $groups );
		}
		$cleanup::run();

		return '';
	}

	/** Begin a run from the start; a report of an earlier one goes. */
	public static function start( string $source ): void {
		self::store(
			$source,
			array(
				'status'  => 'running',
				'phase'   => 'settings',
				'cursor'  => 0,
				'report'  => array(),
				'started' => time(),
			)
		);
	}

	/**
	 * Go on with a run for at most $budget seconds, a batch at a time, saving the state after each.
	 *
	 * @return array<string, mixed> The state afterwards.
	 */
	public static function run( string $source, float $budget = self::BUDGET ): array {
		$class = self::SOURCES[ $source ];
		$state = self::state( $source );
		$until = microtime( true ) + $budget;

		while ( 'running' === ( $state['status'] ?? '' ) ) {
			$report = is_array( $state['report'] ?? null ) ? $state['report'] : array();
			$cursor = (int) ( $state['cursor'] ?? 0 );

			switch ( $state['phase'] ?? 'settings' ) {
				case 'settings':
					$class::settings( $report );
					$state = array_merge( $state, array( 'phase' => 'posts', 'cursor' => 0 ) );
					break;
				case 'posts':
					$next  = $class::posts( $cursor, self::POSTS, $report );
					$state = array_merge( $state, $next ? array( 'cursor' => $next ) : array( 'phase' => 'terms', 'cursor' => 0 ) );
					break;
				default:
					$next  = $class::terms( $cursor, self::TERMS, $report );
					$state = array_merge( $state, $next ? array( 'cursor' => $next ) : array( 'status' => 'done', 'finished' => time() ) );
					if ( ! $next ) {
						$report['left'] = array_merge( $class::left(), (array) ( $report['left'] ?? array() ) );
					}
			}

			$state['report'] = $report;
			self::store( $source, $state );

			if ( microtime( true ) >= $until ) {
				break;
			}
		}

		return $state;
	}

	/**
	 * What the settings part of a report counts: key => label (%s: how many, where it counts).
	 *
	 * @return array<string, string>
	 */
	public static function settings_labels(): array {
		return array(
			'separator'     => __( 'the separator', 'simple-seo' ),
			/* translators: %s: number. */
			'templates'     => __( 'templates: %s', 'simple-seo' ),
			'indexing'      => __( 'what search engines leave out', 'simple-seo' ),
			'attachments'   => __( 'where attachment pages go', 'simple-seo' ),
			'default_image' => __( 'the default image', 'simple-seo' ),
			'x_account'     => __( 'the X account', 'simple-seo' ),
			/* translators: %s: number. */
			'special'       => __( 'the home and 404 pages: %s', 'simple-seo' ),
			/* translators: %s: number. */
			'breadcrumbs'   => __( 'breadcrumbs: %s', 'simple-seo' ),
			/* translators: %s: number. */
			'labels'        => __( 'breadcrumb labels: %s', 'simple-seo' ),
			'sitemaps'      => __( 'the XML sitemap switched off', 'simple-seo' ),
			/* translators: %s: number. */
			'cleanup'       => __( 'clean-up: %s', 'simple-seo' ),
			'robots_txt'    => __( 'the text of robots.txt', 'simple-seo' ),
			/* translators: %s: number. */
			'schema'        => __( 'Schema: %s', 'simple-seo' ),
			/* translators: %s: number. */
			'local'         => __( 'Local SEO: %s', 'simple-seo' ),
			/* translators: %s: number. */
			'verification'  => __( 'codes of webmaster tools: %s', 'simple-seo' ),
		);
	}

	/**
	 * What a report lists as not taken: key => label (%s: how many, where it counts).
	 *
	 * @return array<string, string>
	 */
	public static function left_labels(): array {
		return array(
			/* translators: %s: number of posts. */
			'bctitle'          => __( 'titles for breadcrumbs (posts: %s)', 'simple-seo' ),
			/* translators: %s: number of posts. */
			'twitter'          => __( 'titles, descriptions and images for X (posts: %s)', 'simple-seo' ),
			/* translators: %s: number of posts. */
			'robots_adv'       => __( 'advanced robots settings (posts: %s)', 'simple-seo' ),
			/* translators: %s: number of posts. */
			'schema'           => __( 'Schema of posts (posts: %s)', 'simple-seo' ),
			/* translators: %s: number of users. */
			'authors'          => __( 'SEO titles and descriptions of author archives (authors: %s)', 'simple-seo' ),
			'social_templates' => __( 'templates of titles and descriptions for social networks', 'simple-seo' ),
			'breadcrumb_texts' => __( 'the texts the breadcrumbs put before archives and search results and on the 404 page', 'simple-seo' ),
			'schema_person'    => __( 'the person the site represents: choose the user on the Schema tab', 'simple-seo' ),
			'verification'     => __( 'codes of webmaster tools Simple SEO has no field for: put those still needed into the custom code of the theme, or they stop working', 'simple-seo' ),
			'url_bases'        => __( 'addresses without the category base: with the plugin off, the addresses of categories get it back', 'simple-seo' ),
			'robots_rules'     => __( 'the rules added to robots.txt: put them on the Files tab', 'simple-seo' ),
			/* translators: %s: number of redirections. */
			'redirections'     => __( 'redirections (%s): with the plugin off they stop working; move them to a redirection plugin first', 'simple-seo' ),
		);
	}

	public static function render_tab(): void {
		$nonce   = self::NONCE;
		$sources = self::SOURCES;
		// While one import runs, the tab shows only its progress.
		foreach ( self::SOURCES as $source => $class ) {
			if ( 'running' === ( self::state( $source )['status'] ?? '' ) ) {
				$sources = array( $source => $class );
				break;
			}
		}

		$shown = 0;
		foreach ( $sources as $source => $class ) {
			$state   = self::state( $source );
			$status  = (string) ( $state['status'] ?? '' );
			$summary = 'running' === $status ? null : $class::summary();
			$report  = is_array( $state['report'] ?? null ) ? $state['report'] : array();
			$name    = $class::NAME;
			$cleaner = $class::CLEANUP;

			$cleanup = $cleaner::state();
			// Nothing to count while a run or a clean-up goes on.
			$parts = 'running' === $status || 'running' === ( $cleanup['status'] ?? '' ) ? array() : $cleaner::preview();
			$found = null === $summary || $summary['settings'] || $summary['posts'] || $summary['terms'];
			// A plugin the site never had is not named; one whose data is gone shows what was done.
			if ( ! $found && ! $parts && ! $cleanup && 'done' !== $status ) {
				continue;
			}
			++$shown;

			require SIMPLE_SEO_DIR . 'includes/views/tab-import.php';

			if ( 'running' !== $status ) {
				$blocked = $cleaner::blocked();
				$labels  = $cleaner::labels();
				// The theme is read only where removing is offered.
				$reads   = '' === $blocked ? $cleaner::theme_reads( array_keys( $parts ) ) : array();
				$refused = isset( $_GET['simple-seo-refused'], $_GET['simple-seo-source'] ) && $source === sanitize_key( wp_unslash( $_GET['simple-seo-source'] ) ) ? sanitize_key( wp_unslash( $_GET['simple-seo-refused'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message only.

				require SIMPLE_SEO_DIR . 'includes/views/tab-import-cleanup.php';
			}
		}

		if ( ! $shown ) {
			printf(
				'<p>%s</p>',
				/* translators: %s: names of SEO plugins, e.g. Yoast SEO and Rank Math. */
				esc_html( sprintf( __( 'There is no data of %s on this site.', 'simple-seo' ), wp_sprintf( '%l', array_map( static fn( string $class ): string => $class::NAME, array_values( self::SOURCES ) ) ) ) )
			);
		}
	}
}
