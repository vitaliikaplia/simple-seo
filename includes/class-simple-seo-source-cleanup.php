<?php
/**
 * Removing what another SEO plugin left in the database once its data is imported: its tables, its
 * other options with the cached data, scheduled tasks and the capabilities it gave to roles, its
 * settings, and its user, term and post meta.
 *
 * Offered only with the plugin inactive and the import from it done. The person picks the parts and
 * confirms a backup; the meta goes in batches, a few seconds per request, as the import does. The
 * plugin's settings stay unticked when the active theme reads them (an organization's name and logo
 * for its Schema, say). The plugin's files are not touched: switched on again, it starts from its
 * defaults. A source names its rows; the work is the same for every one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Simple_SEO_Source_Cleanup {
	/** The row that keeps the state of the clean-up. */
	public const OPTION = '';

	/** The import from the same plugin (a Simple_SEO_Import_Source). */
	public const IMPORT = '';

	/** The parts, in the order they are removed. */
	public const GROUPS = array( 'tables', 'internals', 'settings', 'usermeta', 'postmeta' );

	/** Meta rows deleted per batch. */
	public const BATCH = 2000;

	/** The start of the plugin's table names after the site's prefix. */
	protected const TABLES = '';

	/** The plugin's scheduled tasks by hook. */
	protected const CRON = '';

	/** The capabilities the plugin gave to roles; '' when it leaves none. */
	protected const CAPS = '';

	/** What in a theme's code reads a part of the plugin's data: part => pattern. */
	protected const THEME_READS = array();

	/** Whether the plugin is active. */
	abstract protected static function active(): bool;

	/** SQL condition on the options table for every row of the plugin's. */
	abstract protected static function options_sql(): string;

	/** Whether a row of the plugin's is one of its settings: what the import reads, what themes may read. */
	abstract protected static function is_setting( string $name ): bool;

	/** SQL condition on a meta table (user, term, post) for the plugin's rows. */
	abstract protected static function meta_sql( string $type ): string;

	/**
	 * What each part holds, for the form.
	 *
	 * @return array<string, string>
	 */
	abstract public static function labels(): array;

	/**
	 * The state of a clean-up: status (running, done), groups still to go, report, started, finished.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( static::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/** @param array<string, mixed> $state */
	private static function store( array $state ): void {
		if ( null === get_option( static::OPTION, null ) ) {
			add_option( static::OPTION, $state, '', false );
		} else {
			update_option( static::OPTION, $state, false );
		}
	}

	/** Why the clean-up cannot run now: active (the plugin is), import (not done); '' when it can. */
	public static function blocked(): string {
		if ( static::active() ) {
			return 'active';
		}
		$import = get_option( constant( static::IMPORT . '::STATE' ), array() );

		return 'done' === ( is_array( $import ) ? ( $import['status'] ?? '' ) : '' ) ? '' : 'import';
	}

	/**
	 * What is left of the plugin's, by part: how many rows (tables, options, tasks and capabilities,
	 * meta rows) and bytes. Parts with nothing left are not listed.
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
				'rows'  => count( $internal ) + (int) array_sum( self::cron_hooks() ) + count( self::caps() ),
				'bytes' => (int) array_sum( $internal ),
			),
			'settings'  => array(
				'rows'  => count( $settings ),
				'bytes' => (int) array_sum( $settings ),
			),
		);
		foreach ( array_intersect( array( 'usermeta', 'termmeta', 'postmeta' ), static::GROUPS ) as $group ) {
			$type            = substr( $group, 0, -4 );
			$row             = $wpdb->get_row( 'SELECT COUNT(*) AS n, COALESCE( SUM( LENGTH( meta_value ) ), 0 ) AS size FROM ' . self::meta_table( $type ) . ' WHERE ' . static::meta_sql( $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from constants.
			$parts[ $group ] = array(
				'rows'  => (int) ( $row->n ?? 0 ),
				'bytes' => (int) ( $row->size ?? 0 ),
			);
		}

		return array_filter( $parts, static fn( array $part ): bool => $part['rows'] > 0 );
	}

	/**
	 * Files of the active theme (and its parent) that read the parts given of the plugin's data,
	 * relative to the themes folder: part => files. vendor and node_modules are left out.
	 *
	 * @param string[] $parts
	 * @return array<string, string[]>
	 */
	public static function theme_reads( array $parts ): array {
		$patterns = array_intersect_key( static::THEME_READS, array_flip( $parts ) );
		$found    = array();
		$root     = trailingslashit( get_theme_root() );
		foreach ( $patterns ? array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) : array() as $dir ) {
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
				if ( ! preg_match( '/\.(?:php|twig)$/', $file->getFilename() ) ) {
					continue;
				}
				$code = (string) file_get_contents( $file->getPathname() );
				foreach ( $patterns as $part => $pattern ) {
					if ( preg_match( $pattern, $code ) ) {
						$found[ $part ][] = str_replace( $root, '', $file->getPathname() );
					}
				}
			}
		}
		foreach ( $found as $part => $list ) {
			sort( $list );
			$found[ $part ] = $list;
		}

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
				'groups'  => array_values( array_intersect( static::GROUPS, $groups ) ),
				'report'  => array(),
				'started' => time(),
			)
		);
	}

	/**
	 * Go on with a clean-up for at most $budget seconds, a part or a batch at a time, saving the state
	 * after each. Nothing happens while the plugin is active.
	 *
	 * @return array<string, mixed> The state afterwards.
	 */
	public static function run( float $budget = Simple_SEO_Import::BUDGET ): array {
		$state = self::state();
		$until = microtime( true ) + $budget;

		while ( 'running' === ( $state['status'] ?? '' ) && 'active' !== self::blocked() ) {
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
				foreach ( self::caps() as [ $role, $cap ] ) {
					get_role( $role )->remove_cap( $cap );
					self::add( $report, 'caps' );
				}
				return true;
			case 'settings':
				foreach ( array_keys( self::options( true ) ) as $name ) {
					delete_option( $name );
					self::add( $report, 'settings' );
				}
				return true;
			case 'usermeta':
			case 'termmeta':
			case 'postmeta':
				return self::delete_meta( substr( $group, 0, -4 ), $report );
		}

		return true;
	}

	/**
	 * Delete a batch of the plugin's meta rows and drop the cached meta of their owners.
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
		$rows = $wpdb->get_results( "SELECT {$id_column} AS id, {$owner} AS owner FROM {$table} WHERE " . static::meta_sql( $type ) . ' LIMIT ' . self::BATCH, ARRAY_A );
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
	 * The plugin's tables by name, with their size in bytes.
	 *
	 * @return array<string, int>
	 */
	private static function tables(): array {
		global $wpdb;

		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT table_name AS name, data_length + index_length AS size FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s', $wpdb->esc_like( $wpdb->prefix . static::TABLES ) . '%' ), ARRAY_A );
		$tables = array();
		foreach ( $rows as $row ) {
			if ( preg_match( '/^[A-Za-z0-9_]+$/', (string) $row['name'] ) ) {
				$tables[ (string) $row['name'] ] = (int) $row['size'];
			}
		}

		return $tables;
	}

	/**
	 * The plugin's options by name, with their size: its settings or the rest (internal state,
	 * cached data).
	 *
	 * @return array<string, int>
	 */
	private static function options( bool $settings ): array {
		global $wpdb;

		$rows    = $wpdb->get_results( "SELECT option_name AS name, LENGTH( option_value ) AS size FROM {$wpdb->options} WHERE " . static::options_sql(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from constants.
		$options = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['name'];
			if ( $settings === static::is_setting( $name ) ) {
				$options[ $name ] = (int) $row['size'];
			}
		}

		return $options;
	}

	/**
	 * The plugin's scheduled tasks: hook => how many events.
	 *
	 * @return array<string, int>
	 */
	private static function cron_hooks(): array {
		$hooks = array();
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				if ( preg_match( static::CRON, (string) $hook ) ) {
					$hooks[ (string) $hook ] = ( $hooks[ (string) $hook ] ?? 0 ) + count( (array) $instances );
				}
			}
		}

		return $hooks;
	}

	/**
	 * The capabilities the plugin gave to roles: role, capability.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function caps(): array {
		$caps = array();
		if ( '' === static::CAPS ) {
			return $caps;
		}
		foreach ( wp_roles()->role_objects as $name => $role ) {
			foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
				if ( preg_match( static::CAPS, (string) $cap ) ) {
					$caps[] = array( (string) $name, (string) $cap );
				}
			}
		}

		return $caps;
	}

	private static function meta_table( string $type ): string {
		global $wpdb;

		return array(
			'user' => $wpdb->usermeta,
			'term' => $wpdb->termmeta,
		)[ $type ] ?? $wpdb->postmeta;
	}

	/** @param array<string, mixed> $report */
	private static function add( array &$report, string $what, int $count = 1 ): void {
		$report[ $what ] = (int) ( $report[ $what ] ?? 0 ) + $count;
	}
}
