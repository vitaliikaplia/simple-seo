<?php
/**
 * WP-CLI commands: wp simple-seo. They do what the screens do — the SEO fields of a post or term,
 * the analysis, the import from another SEO plugin and the removal of its data, IndexNow — with the
 * same code, for whoever runs the site from a shell. Loaded only under WP-CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage the SEO of the site with Simple SEO.
 *
 * ## EXAMPLES
 *
 *     # The SEO fields there are.
 *     $ wp simple-seo fields
 *
 *     # Change the SEO title of a post.
 *     $ wp simple-seo post update 12 --title="Prices {sep} {site}"
 *
 *     # Take over the data of Yoast SEO.
 *     $ wp simple-seo import yoast
 */
final class Simple_SEO_CLI {
	/** The plugins of the import by their names in a command. */
	private const SOURCES = array(
		'yoast'     => 'yoast',
		'rank-math' => 'rank_math',
		'aioseo'    => 'aioseo',
	);

	public static function register(): void {
		require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-cli-fields.php';
		require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-cli-indexnow.php';

		WP_CLI::add_command( 'simple-seo', self::class );
		WP_CLI::add_command( 'simple-seo post', new Simple_SEO_CLI_Fields( 'post' ) );
		WP_CLI::add_command( 'simple-seo term', new Simple_SEO_CLI_Fields( 'term' ) );
		WP_CLI::add_command( 'simple-seo indexnow', Simple_SEO_CLI_IndexNow::class );
	}

	/**
	 * List the SEO fields of posts and terms.
	 *
	 * A field of advanced level changes indexing: in the editor only roles with the right to edit
	 * such fields see it. A field with post types is there only on posts of those types.
	 *
	 * ## OPTIONS
	 *
	 * [--object=<object>]
	 * : Only the fields of posts or only those of terms.
	 * ---
	 * options:
	 *   - post
	 *   - term
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp simple-seo fields --object=term
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function fields( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( Simple_SEO_Fields::all( (string) ( $assoc_args['object'] ?? '' ) ) as $key => $field ) {
			$rows[] = array(
				'field'      => $key,
				'objects'    => implode( ', ', (array) $field['objects'] ),
				'type'       => (string) $field['type'],
				'level'      => (string) $field['level'],
				'post_types' => implode( ', ', (array) $field['post_types'] ),
				'label'      => (string) $field['label'],
			);
		}

		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'field', 'objects', 'type', 'level', 'post_types', 'label' ) );
	}

	/**
	 * Analyse a post as saved: the SEO checks of its focus keyphrase and its page, and readability.
	 *
	 * The title and the description are those the page shows, the templates filled in.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the post.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp simple-seo analyze 12
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function analyze( array $args, array $assoc_args ): void {
		$post = Simple_SEO_Fields::object( 'post', absint( $args[0] ?? 0 ) );
		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error( __( 'There is no such post with SEO fields.', 'simple-seo' ) );
		}

		$result  = Simple_SEO_Analysis::run( $post );
		$context = Simple_SEO_Context::for_post( $post );
		$summary = array(
			'url'                => (string) get_permalink( $post ),
			'title'              => Simple_SEO_Titles::title( $context ),
			'description'        => Simple_SEO_Titles::description( $context ),
			'words'              => (int) $result['words'],
			'score'              => (int) $result['score'],
			'rating'             => (string) $result['rating'],
			'readability_score'  => (int) $result['readability']['score'],
			'readability_rating' => (string) $result['readability']['rating'],
		);
		$checks  = array();
		foreach ( array(
			'seo'         => $result['checks'],
			'readability' => $result['readability']['checks'],
		) as $area => $list ) {
			foreach ( $list as $check ) {
				$checks[] = array(
					'area'   => $area,
					'id'     => (string) $check['id'],
					'status' => (string) $check['status'],
					'text'   => (string) $check['text'],
				);
			}
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' !== $format ) {
			WP_CLI::print_value( $summary + array( 'checks' => $checks ), array( 'format' => $format ) );
			return;
		}

		$formatter = new WP_CLI\Formatter( $assoc_args, array_keys( $summary ) );
		$formatter->display_item( $summary );
		WP_CLI\Utils\format_items( 'table', $checks, array( 'area', 'status', 'text' ) );
	}

	/**
	 * Import the settings and the SEO fields of posts and terms from another SEO plugin.
	 *
	 * The import fills only what Simple SEO does not have yet: the settings of a tab saved here, and a
	 * template or a field with a value, stay as they are. The plugin's data is not changed, so the
	 * import can be run again. A run broken off goes on where it stopped.
	 *
	 * ## OPTIONS
	 *
	 * <plugin>
	 * : The plugin to import from.
	 * ---
	 * options:
	 *   - yoast
	 *   - rank-math
	 *   - aioseo
	 * ---
	 *
	 * [--restart]
	 * : Start from the beginning, even where a run was broken off.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp simple-seo import yoast
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$source = self::source( (string) ( $args[0] ?? '' ) );
		$class  = Simple_SEO_Import::SOURCES[ $source ];
		$state  = Simple_SEO_Import::state( $source );

		if ( 'running' !== ( $state['status'] ?? '' ) || ! empty( $assoc_args['restart'] ) ) {
			if ( ! $class::found() ) {
				/* translators: %s: names of SEO plugins, e.g. Yoast SEO and Rank Math. */
				WP_CLI::error( sprintf( __( 'There is no data of %s on this site.', 'simple-seo' ), $class::NAME ) );
			}
			Simple_SEO_Import::start( $source );
		}

		// A run that moves no further stops the loop: no command spins for ever.
		do {
			$before = $state;
			$state  = Simple_SEO_Import::run( $source );
			$report = (array) ( $state['report'] ?? array() );
			/* translators: 1: number of posts, 2: number of terms. */
			WP_CLI::log( sprintf( __( 'Posts: %1$s, terms: %2$s.', 'simple-seo' ), number_format_i18n( (int) ( $report['post']['seen'] ?? 0 ) ), number_format_i18n( (int) ( $report['term']['seen'] ?? 0 ) ) ) );
		} while ( 'running' === ( $state['status'] ?? '' ) && $state !== $before );
		self::stop_if_unfinished( $state );

		foreach ( self::import_report( $class, $report ) as $line ) {
			WP_CLI::log( $line );
		}
		/* translators: %s: name of an SEO plugin. */
		WP_CLI::success( sprintf( __( 'The import from %s is done.', 'simple-seo' ), $class::NAME ) );
	}

	/**
	 * The report of an import as lines, as the Import tab gives it.
	 *
	 * @param array<string, mixed> $report
	 * @return string[]
	 */
	private static function import_report( string $class, array $report ): array {
		$n     = static fn( $value ): string => number_format_i18n( (int) $value );
		$lines = array();

		$settings = array();
		foreach ( Simple_SEO_Import::settings_labels() as $what => $label ) {
			if ( ! empty( $report['settings'][ $what ] ) ) {
				$settings[] = sprintf( $label, $n( $report['settings'][ $what ] ) );
			}
		}
		$lines[] = $settings
			/* translators: %s: list of settings. */
			? sprintf( __( 'Settings taken: %s.', 'simple-seo' ), implode( ', ', $settings ) )
			: __( 'Settings: nothing to take, Simple SEO has its own.', 'simple-seo' );
		if ( ! empty( $report['languages'] ) ) {
			/* translators: %s: language codes. */
			$lines[] = sprintf( __( 'Also the home and 404 pages, the breadcrumb labels and the Schema names of the languages: %s.', 'simple-seo' ), implode( ', ', (array) $report['languages'] ) );
		}

		foreach ( array(
			/* translators: 1: posts with the plugin's fields, 2: of them changed, 3: fields filled, 4: fields kept, 5: values not taken. */
			'post' => __( 'Posts with SEO fields: %1$s, %2$s of them changed. Fields filled: %3$s; kept as Simple SEO had them: %4$s; not taken, as Simple SEO cannot hold the value: %5$s.', 'simple-seo' ),
			/* translators: 1: terms with the plugin's fields, 2: of them changed, 3: fields filled, 4: fields kept, 5: values not taken. */
			'term' => __( 'Terms with SEO fields: %1$s, %2$s of them changed. Fields filled: %3$s; kept as Simple SEO had them: %4$s; not taken, as Simple SEO cannot hold the value: %5$s.', 'simple-seo' ),
		) as $type => $line ) {
			$counts  = (array) ( $report[ $type ] ?? array() );
			$lines[] = sprintf( $line, $n( $counts['seen'] ?? 0 ), $n( $counts['changed'] ?? 0 ), $n( $counts['fields'] ?? 0 ), $n( $counts['kept'] ?? 0 ), $n( $counts['refused'] ?? 0 ) );
		}

		if ( ! empty( $report['dropped'] ) ) {
			$dropped = array();
			foreach ( (array) $report['dropped'] as $name => $times ) {
				$dropped[] = $class::variable( (string) $name ) . ' (' . $n( $times ) . ')';
			}
			/* translators: %s: list of variables with how often each was found. */
			$lines[] = sprintf( __( 'Left out of the texts, as Simple SEO has no such variable: %s.', 'simple-seo' ), implode( ', ', $dropped ) );
		}

		$left = array();
		foreach ( Simple_SEO_Import::left_labels() as $what => $label ) {
			if ( ! empty( $report['left'][ $what ] ) ) {
				$left[] = '- ' . sprintf( $label, $n( $report['left'][ $what ] ) );
			}
		}
		if ( $left ) {
			$lines[] = __( 'Not taken, as Simple SEO has nothing for it yet:', 'simple-seo' );
			$lines   = array_merge( $lines, $left );
		}

		return $lines;
	}

	/**
	 * Remove what another SEO plugin left in the database, once the import from it is done and the
	 * plugin is off.
	 *
	 * Removing cannot be undone: make a backup of the database first. The plugin's files stay: delete
	 * the plugin with wp plugin delete. Parts the active theme reads are kept unless named.
	 *
	 * ## OPTIONS
	 *
	 * <plugin>
	 * : The plugin whose data goes.
	 * ---
	 * options:
	 *   - yoast
	 *   - rank-math
	 *   - aioseo
	 * ---
	 *
	 * [--parts=<parts>]
	 * : The parts to remove, separated by commas: tables, internals, settings, usermeta, termmeta,
	 * postmeta. By default all that is left, but for the parts the active theme reads.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp simple-seo cleanup yoast --yes
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$class   = Simple_SEO_Import::SOURCES[ self::source( (string) ( $args[0] ?? '' ) ) ];
		$cleanup = $class::CLEANUP;
		$name    = (string) $class::NAME;

		$blocked = $cleanup::blocked();
		if ( 'active' === $blocked ) {
			/* translators: %s: name of an SEO plugin. */
			WP_CLI::error( sprintf( __( '%s is active, so its data is in use. Deactivate it first.', 'simple-seo' ), $name ) );
		}
		if ( 'import' === $blocked ) {
			WP_CLI::error( __( 'Run the import first: removing comes after it.', 'simple-seo' ) );
		}

		if ( 'running' !== ( $cleanup::state()['status'] ?? '' ) ) {
			$groups = self::cleanup_groups( $cleanup, $name, $assoc_args );
			if ( ! $groups ) {
				/* translators: %s: names of SEO plugins, e.g. Yoast SEO and Rank Math. */
				WP_CLI::success( sprintf( __( 'There is no data of %s on this site.', 'simple-seo' ), $name ) );
				return;
			}
			/* translators: %s: name of an SEO plugin. */
			WP_CLI::confirm( sprintf( __( 'Remove these parts of the data of %s? Removing cannot be undone: have a backup of the database.', 'simple-seo' ), $name ), $assoc_args );
			$cleanup::start( $groups );
		}

		$state = $cleanup::state();
		do {
			$before = $state;
			$state  = $cleanup::run();
		} while ( 'running' === ( $state['status'] ?? '' ) && $state !== $before );
		self::stop_if_unfinished( $state );

		$done  = (array) ( $state['report'] ?? array() );
		$items = array();
		foreach ( Simple_SEO_Source_Cleanup::removed_labels() as $what => $label ) {
			if ( ! empty( $done[ $what ] ) ) {
				$items[] = sprintf( $label, number_format_i18n( (int) $done[ $what ] ) );
			}
		}
		WP_CLI::success(
			$items
				/* translators: %s: list of what was removed. */
				? sprintf( __( 'Removed: %s.', 'simple-seo' ), implode( '; ', $items ) )
				: __( 'Removed: nothing, it was gone already.', 'simple-seo' )
		);
	}

	/**
	 * The parts a clean-up removes, after showing what they hold: those named, or all that is left
	 * but for those the active theme reads.
	 *
	 * @param array<string, mixed> $assoc_args
	 * @return string[]
	 */
	private static function cleanup_groups( string $cleanup, string $name, array $assoc_args ): array {
		$parts = $cleanup::preview();
		$named = isset( $assoc_args['parts'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['parts'] ) ), 'strlen' ) : null;
		if ( null !== $named ) {
			$unknown = array_diff( $named, $cleanup::GROUPS );
			if ( $unknown || ! $named ) {
				/* translators: 1: parts named, 2: the parts there are. */
				WP_CLI::error( sprintf( __( 'No such part: %1$s. The parts: %2$s.', 'simple-seo' ), $unknown ? implode( ', ', $unknown ) : '""', implode( ', ', $cleanup::GROUPS ) ) );
			}
		}

		$groups = array_values( array_intersect( $cleanup::GROUPS, null === $named ? array_keys( $parts ) : $named, array_keys( $parts ) ) );
		$reads  = $cleanup::theme_reads( $groups );
		foreach ( $reads as $part => $files ) {
			if ( null === $named ) {
				/* translators: 1: a part of a plugin's data, e.g. postmeta; 2: files of the theme. */
				WP_CLI::log( sprintf( __( 'Kept, as the active theme reads it: %1$s (%2$s).', 'simple-seo' ), $part, implode( ', ', $files ) ) );
			} else {
				/* translators: 1: a part of a plugin's data, e.g. postmeta; 2: files of the theme. */
				WP_CLI::warning( sprintf( __( 'The active theme reads %1$s (%2$s): removed, it is gone for the theme too.', 'simple-seo' ), $part, implode( ', ', $files ) ) );
			}
		}
		if ( null === $named ) {
			$groups = array_values( array_diff( $groups, array_keys( $reads ) ) );
		}

		$labels = $cleanup::labels();
		$rows   = array();
		foreach ( $groups as $group ) {
			$rows[] = array(
				'part'  => $group,
				'rows'  => $parts[ $group ]['rows'],
				'size'  => size_format( $parts[ $group ]['bytes'] ) ?: '0 B',
				'holds' => (string) ( $labels[ $group ] ?? '' ),
			);
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'part', 'rows', 'size', 'holds' ) );
		}

		return $groups;
	}

	/**
	 * An error for a run that stopped short of its end (the plugin switched on meanwhile, say).
	 *
	 * @param array<string, mixed> $state
	 */
	private static function stop_if_unfinished( array $state ): void {
		if ( 'running' === ( $state['status'] ?? '' ) ) {
			WP_CLI::error( __( 'It stopped before the end: run the command again to go on.', 'simple-seo' ) );
		}
	}

	/** The key of the import of a plugin named in a command; WP-CLI takes only the names of its synopsis. */
	private static function source( string $plugin ): string {
		return self::SOURCES[ $plugin ];
	}
}
