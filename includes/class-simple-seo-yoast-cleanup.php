<?php
/**
 * Removing what Yoast SEO left in the database once its data is imported: its tables, its other
 * options with the cached data and scheduled tasks, its settings, the user meta and the post meta.
 *
 * Offered only with Yoast inactive and the import done. The person picks the parts and confirms a
 * backup; the meta goes in batches, a few seconds per request, as the import does. Yoast's settings
 * stay unticked when the active theme reads them (an organization's name and logo for its Schema,
 * say). Yoast's plugin files are not touched: switched on again, Yoast starts from its defaults.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Yoast_Cleanup {
	public const OPTION = 'simple_seo_yoast_cleanup';

	/** The parts, in the order they are removed. */
	public const GROUPS = array( 'tables', 'internals', 'settings', 'usermeta', 'postmeta' );

	/** Meta rows deleted per batch. */
	public const BATCH = 2000;

	/** Yoast's rows of settings: what the import takes from, and what themes may read. */
	private const SETTINGS = array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_taxonomy_meta' );

	/** What in a theme's code reads Yoast's settings. */
	private const THEME_READS = '/wpseo_titles|wpseo_social|wpseo_taxonomy_meta|WPSEO_Options|get_option\(\s*[\'"]wpseo[\'"]/';

	/**
	 * The state of a clean-up: status (running, done), groups still to go, report, started, finished.
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

	/** Why the clean-up cannot run now: yoast (it is active), import (not done); '' when it can. */
	public static function blocked(): string {
		if ( defined( 'WPSEO_VERSION' ) || is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
			return 'yoast';
		}

		return 'done' === ( Simple_SEO_Import::state()['status'] ?? '' ) ? '' : 'import';
	}

	/**
	 * What is left of Yoast's, by part: how many rows (tables, options and tasks, meta rows) and
	 * bytes. Parts with nothing left are not listed.
	 *
	 * @return array<string, array{rows: int, bytes: int}>
	 */
	public static function preview(): array {
		global $wpdb;

		$tables   = self::tables();
		$internal = self::options( false );
		$settings = self::options( true );
		$parts    = array(
			'tables'    => array(
				'rows'  => count( $tables ),
				'bytes' => (int) array_sum( $tables ),
			),
			'internals' => array(
				'rows'  => count( $internal ) + (int) array_sum( self::cron_hooks() ),
				'bytes' => (int) array_sum( $internal ),
			),
			'settings'  => array(
				'rows'  => count( $settings ),
				'bytes' => (int) array_sum( $settings ),
			),
		);
		foreach ( array(
			'usermeta' => 'user',
			'postmeta' => 'post',
		) as $group => $type ) {
			$row             = $wpdb->get_row( 'SELECT COUNT(*) AS n, COALESCE( SUM( LENGTH( meta_value ) ), 0 ) AS size FROM ' . self::meta_table( $type ) . ' WHERE ' . self::meta_sql( $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from constants.
			$parts[ $group ] = array(
				'rows'  => (int) ( $row->n ?? 0 ),
				'bytes' => (int) ( $row->size ?? 0 ),
			);
		}

		return array_filter( $parts, static fn( array $part ): bool => $part['rows'] > 0 );
	}

	/**
	 * Files of the active theme (and its parent) that read Yoast's settings, relative to the themes
	 * folder. vendor and node_modules are left out.
	 *
	 * @return string[]
	 */
	public static function theme_reads(): array {
		$found = array();
		$root  = trailingslashit( get_theme_root() );
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					static fn( SplFileInfo $file ): bool => ! in_array( $file->getFilename(), array( 'vendor', 'node_modules', '.git' ), true )
				)
			);
			foreach ( $files as $file ) {
				if ( preg_match( '/\.(?:php|twig)$/', $file->getFilename() ) && preg_match( self::THEME_READS, (string) file_get_contents( $file->getPathname() ) ) ) {
					$found[] = str_replace( $root, '', $file->getPathname() );
				}
			}
		}
		sort( $found );

		return $found;
	}

	/**
	 * Begin a clean-up of the parts given, in their fixed order.
	 *
	 * @param string[] $groups
	 */
	public static function start( array $groups ): void {
		self::store(
			array(
				'status'  => 'running',
				'groups'  => array_values( array_intersect( self::GROUPS, $groups ) ),
				'report'  => array(),
				'started' => time(),
			)
		);
	}

	/**
	 * Go on with a clean-up for at most $budget seconds, a part or a batch at a time, saving the state
	 * after each. Nothing happens while Yoast is active.
	 *
	 * @return array<string, mixed> The state afterwards.
	 */
	public static function run( float $budget = Simple_SEO_Import::BUDGET ): array {
		$state = self::state();
		$until = microtime( true ) + $budget;

		while ( 'running' === ( $state['status'] ?? '' ) && 'yoast' !== self::blocked() ) {
			$groups = array_values( (array) ( $state['groups'] ?? array() ) );
			$report = (array) ( $state['report'] ?? array() );
			if ( ! $groups || self::step( (string) $groups[0], $report ) ) {
				array_shift( $groups );
			}

			$state = array_merge( $state, array( 'groups' => $groups, 'report' => $report ) );
			if ( ! $groups ) {
				$state = array_merge( $state, array( 'status' => 'done', 'finished' => time() ) );
			}
			self::store( $state );

			if ( microtime( true ) >= $until ) {
				break;
			}
		}

		return $state;
	}

	/**
	 * Remove a part, or a batch of it.
	 *
	 * @param array<string, mixed> $report
	 * @return bool Whether the part is done.
	 */
	private static function step( string $group, array &$report ): bool {
		global $wpdb;

		switch ( $group ) {
			case 'tables':
				foreach ( array_keys( self::tables() ) as $table ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- a name read from the database, checked.
					self::add( $report, 'tables' );
				}
				return true;
			case 'internals':
				foreach ( array_keys( self::options( false ) ) as $name ) {
					delete_option( $name );
					self::add( $report, 'options' );
				}
				foreach ( self::cron_hooks() as $hook => $events ) {
					wp_unschedule_hook( $hook );
					self::add( $report, 'cron', $events );
				}
				return true;
			case 'settings':
				foreach ( array_keys( self::options( true ) ) as $name ) {
					delete_option( $name );
					self::add( $report, 'settings' );
				}
				return true;
			case 'usermeta':
			case 'postmeta':
				return self::delete_meta( 'usermeta' === $group ? 'user' : 'post', $report );
		}

		return true;
	}

	/**
	 * Delete a batch of Yoast's meta rows and drop the cached meta of their posts or users.
	 *
	 * @param array<string, mixed> $report
	 * @return bool Whether none is left.
	 */
	private static function delete_meta( string $type, array &$report ): bool {
		global $wpdb;

		$id_column = 'user' === $type ? 'umeta_id' : 'meta_id';
		$owner     = $type . '_id';
		$table     = self::meta_table( $type );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- built from constants.
		$rows = $wpdb->get_results( "SELECT {$id_column} AS id, {$owner} AS owner FROM {$table} WHERE " . self::meta_sql( $type ) . ' LIMIT ' . self::BATCH, ARRAY_A );
		if ( $rows ) {
			$wpdb->query( "DELETE FROM {$table} WHERE {$id_column} IN (" . implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) ) . ')' );
		}
		// phpcs:enable
		foreach ( array_unique( array_map( 'intval', array_column( $rows, 'owner' ) ) ) as $id ) {
			wp_cache_delete( $id, $type . '_meta' );
		}
		self::add( $report, $type . 'meta', count( $rows ) );

		return count( $rows ) < self::BATCH;
	}

	/**
	 * Yoast's tables by name, with their size in bytes.
	 *
	 * @return array<string, int>
	 */
	private static function tables(): array {
		global $wpdb;

		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT table_name AS name, data_length + index_length AS size FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'yoast_' ) . '%' ), ARRAY_A );
		$tables = array();
		foreach ( $rows as $row ) {
			if ( preg_match( '/^[A-Za-z0-9_]+$/', (string) $row['name'] ) ) {
				$tables[ (string) $row['name'] ] = (int) $row['size'];
			}
		}

		return $tables;
	}

	/**
	 * Yoast's options by name, with their size: its settings (the rows the import reads, with wp-loc's
	 * copies for other languages) or the rest (internal state, cached data).
	 *
	 * @return array<string, int>
	 */
	private static function options( bool $settings ): array {
		global $wpdb;

		$rows    = $wpdb->get_results(
			"SELECT option_name AS name, LENGTH( option_value ) AS size FROM {$wpdb->options} WHERE option_name = 'wpseo' OR option_name LIKE 'wpseo\\_%' OR option_name LIKE 'wpseo-%' OR option_name LIKE 'yoast\\_%' OR option_name LIKE 'yoast-%'"
			. " OR option_name LIKE '\\_transient\\_wpseo%' OR option_name LIKE '\\_transient\\_timeout\\_wpseo%' OR option_name LIKE '\\_transient\\_yoast%' OR option_name LIKE '\\_transient\\_timeout\\_yoast%'"
			. " OR option_name LIKE '\\_site\\_transient\\_wpseo%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_wpseo%'",
			ARRAY_A
		);
		$options = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['name'];
			if ( $settings === ( in_array( $name, self::SETTINGS, true ) || str_starts_with( $name, 'wpseo_titles_' ) ) ) {
				$options[ $name ] = (int) $row['size'];
			}
		}

		return $options;
	}

	/**
	 * Yoast's scheduled tasks: hook => how many events.
	 *
	 * @return array<string, int>
	 */
	private static function cron_hooks(): array {
		$hooks = array();
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				if ( preg_match( '/^(?:wpseo|yoast)/i', (string) $hook ) ) {
					$hooks[ (string) $hook ] = ( $hooks[ (string) $hook ] ?? 0 ) + count( (array) $instances );
				}
			}
		}

		return $hooks;
	}

	private static function meta_table( string $type ): string {
		global $wpdb;

		return 'user' === $type ? $wpdb->usermeta : $wpdb->postmeta;
	}

	/** SQL condition on a meta table for Yoast's rows. */
	private static function meta_sql( string $type ): string {
		global $wpdb;

		if ( 'post' === $type ) {
			return "meta_key LIKE '\\_yoast\\_%'";
		}

		// Users: SEO titles of author archives and editor settings (wpseo…), notices (_yoast…, {prefix}yoast_notifications).
		return "( meta_key LIKE 'wpseo%' OR meta_key LIKE '\\_yoast\\_%' OR meta_key = '" . esc_sql( $wpdb->get_blog_prefix() . 'yoast_notifications' ) . "' )";
	}

	/** @param array<string, mixed> $report */
	private static function add( array &$report, string $what, int $count = 1 ): void {
		$report[ $what ] = (int) ( $report[ $what ] ?? 0 ) + $count;
	}
}
