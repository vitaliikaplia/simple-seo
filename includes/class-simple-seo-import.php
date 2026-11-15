<?php
/**
 * The import from Yoast SEO in the admin: the Import tab, the notice that offers it once Yoast's
 * data is found, and the run itself — settings, then posts, then terms, in batches that a request
 * goes through for a few seconds before the page continues with the next request. The state and
 * the report are kept in one row (not autoloaded), so a run broken off goes on where it stopped.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Import {
	public const OPTION = 'simple_seo_import_yoast';

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
	 * The state of the import: status (pending, none, running, done, dismissed), phase (settings,
	 * posts, terms), cursor, report, started, finished.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/** @param array<string, mixed> $state */
	private static function store( array $state ): void {
		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, $state, '', false );
		} else {
			update_option( self::OPTION, $state, false );
		}
	}

	/** Once, on the first admin request: whether there is anything of Yoast's to offer. */
	public static function detect(): void {
		if ( ! array_key_exists( 'status', self::state() ) ) {
			self::store( array( 'status' => Simple_SEO_Yoast_Import::found() ? 'pending' : 'none' ) );
		}
	}

	/** Offer the import on the admin screens until it is run or put off. */
	public static function notice(): void {
		if ( 'pending' !== ( self::state()['status'] ?? '' ) || ! current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_' . Simple_SEO_Admin::PAGE === $screen->id && 'import' === Simple_SEO_Admin::current_tab() ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s</p><form method="post" action="%2$s"><p><a class="button button-primary" href="%3$s">%4$s</a> %5$s<button type="submit" class="button-link" name="simple_seo_import" value="dismiss">%6$s</button></p></form></div>',
			esc_html__( 'Simple SEO found the data of Yoast SEO on this site: its settings and the titles, descriptions and other SEO fields of posts and terms. Import them, so the pages keep what was set for them.', 'simple-seo' ),
			esc_url( Simple_SEO_Admin::url( 'import' ) ),
			esc_url( Simple_SEO_Admin::url( 'import' ) ),
			esc_html__( 'Go to the import', 'simple-seo' ),
			wp_nonce_field( self::NONCE, '_wpnonce', true, false ),
			esc_html__( 'Not now', 'simple-seo' )
		);
	}

	/** A form of the Import tab or of the notice: start, continue or put off; then back to the tab. */
	public static function handle(): void {
		$do = isset( $_POST['simple_seo_import'] ) ? sanitize_key( wp_unslash( $_POST['simple_seo_import'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		if ( '' === $do || ! current_user_can( Simple_SEO_Access::CAP_SETTINGS ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		self::act( $do );
		wp_safe_redirect( Simple_SEO_Admin::url( 'import' ) );
		exit;
	}

	/** What a form asks for: start (again), continue a run, or put the offer off. */
	public static function act( string $do ): void {
		$state = self::state();
		if ( 'dismiss' === $do ) {
			if ( 'pending' === ( $state['status'] ?? '' ) ) {
				self::store( array_merge( $state, array( 'status' => 'dismissed' ) ) );
			}
			return;
		}
		if ( 'start' === $do && 'running' !== ( $state['status'] ?? '' ) ) {
			self::start();
		}
		if ( in_array( $do, array( 'start', 'continue' ), true ) ) {
			self::run();
		}
	}

	/** Begin a run from the start; a report of an earlier one goes. */
	public static function start(): void {
		self::store(
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
	public static function run( float $budget = self::BUDGET ): array {
		$state = self::state();
		$until = microtime( true ) + $budget;

		while ( 'running' === ( $state['status'] ?? '' ) ) {
			$report = is_array( $state['report'] ?? null ) ? $state['report'] : array();
			$cursor = (int) ( $state['cursor'] ?? 0 );

			switch ( $state['phase'] ?? 'settings' ) {
				case 'settings':
					Simple_SEO_Yoast_Import::settings( $report );
					$state = array_merge( $state, array( 'phase' => 'posts', 'cursor' => 0 ) );
					break;
				case 'posts':
					$next  = Simple_SEO_Yoast_Import::posts( $cursor, self::POSTS, $report );
					$state = array_merge( $state, $next ? array( 'cursor' => $next ) : array( 'phase' => 'terms', 'cursor' => 0 ) );
					break;
				default:
					$next  = Simple_SEO_Yoast_Import::terms( $cursor, self::TERMS, $report );
					$state = array_merge( $state, $next ? array( 'cursor' => $next ) : array( 'status' => 'done', 'finished' => time() ) );
					if ( ! $next ) {
						$report['left'] = array_merge( Simple_SEO_Yoast_Import::left(), (array) ( $report['left'] ?? array() ) );
					}
			}

			$state['report'] = $report;
			self::store( $state );

			if ( microtime( true ) >= $until ) {
				break;
			}
		}

		return $state;
	}

	public static function render_tab(): void {
		$state   = self::state();
		$status  = (string) ( $state['status'] ?? '' );
		$summary = 'running' === $status ? null : Simple_SEO_Yoast_Import::summary();
		$report  = is_array( $state['report'] ?? null ) ? $state['report'] : array();
		$nonce   = self::NONCE;

		require SIMPLE_SEO_DIR . 'includes/views/tab-import.php';
	}
}
