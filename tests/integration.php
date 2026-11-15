<?php
/**
 * Integration test of Simple SEO. Runs inside a throwaway WordPress with WP-CLI:
 *
 *   wp --path=<wp-it> eval-file <wp-it>/wp-content/plugins/simple-seo/tests/integration.php
 *
 * It creates and deletes users, posts, terms, roles and options, so it refuses to run anywhere
 * but the test database simple_seo_it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'simple_seo_it' !== DB_NAME ) {
	WP_CLI::error( 'tests/integration.php runs only in the throwaway WordPress (database simple_seo_it).' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-admin.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-editor.php';
require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-snippet.php';

$GLOBALS['sseo_results'] = array( 'pass' => 0, 'fail' => 0 );

function sseo_section( string $title ): void {
	echo "\n== {$title}\n";
}

/** @param mixed $detail */
function sseo_check( string $name, bool $ok, $detail = null ): void {
	if ( $ok ) {
		++$GLOBALS['sseo_results']['pass'];
		echo "  ok    {$name}\n";
		return;
	}

	++$GLOBALS['sseo_results']['fail'];
	echo "  FAIL  {$name}" . ( null === $detail ? '' : ' — ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) ) . "\n";
}

/** A query parsed and run like the main query of a front-end request. */
function sseo_query( array $vars ): WP_Query {
	$query = new WP_Query();
	$query->query( $vars );

	return $query;
}

/** Capture what a callable prints. */
function sseo_capture( callable $callback ): string {
	ob_start();
	try {
		$callback();
	} finally {
		$html = (string) ob_get_clean();
	}

	return $html;
}

/** Submit the editor form fields the way PHP hands them to WordPress (slashed). */
function sseo_post_form( int $post_id, array $fields, bool $nonce = true ): void {
	$_POST = wp_slash(
		array(
			'post_ID'                 => (string) $post_id,
			'simple_seo_fields_nonce' => $nonce ? wp_create_nonce( 'simple_seo_save_fields' ) : 'bad',
			'simple_seo'              => $fields,
		)
	);
}

function sseo_term_form( int $term_id, array $fields ): void {
	$_POST = wp_slash(
		array(
			'tag_ID'                  => (string) $term_id,
			'simple_seo_fields_nonce' => wp_create_nonce( 'simple_seo_save_fields' ),
			'simple_seo'              => $fields,
		)
	);
}

$sseo = array(
	'users' => array(),
	'posts' => array(),
	'terms' => array(),
);

$sseo_saved_options = array(
	'show_on_front'  => get_option( 'show_on_front' ),
	'page_on_front'  => get_option( 'page_on_front' ),
	'page_for_posts' => get_option( 'page_for_posts' ),
	'access'         => get_option( Simple_SEO_Access::OPTION ),
	'titles'         => get_option( Simple_SEO_Titles::OPTION ),
	'indexing'       => get_option( Simple_SEO_Indexing::OPTION ),
	'social'         => get_option( Simple_SEO_Social::OPTION ),
	'special'        => get_option( Simple_SEO_Special::OPTION ),
	'sitemaps'       => get_option( Simple_SEO_Sitemaps::OPTION ),
	'breadcrumbs'    => get_option( Simple_SEO_Breadcrumbs::OPTION ),
	'bc_labels'      => get_option( Simple_SEO_Breadcrumbs::LABELS ),
	'blog_public'    => get_option( 'blog_public' ),
	'robots_txt'     => get_option( Simple_SEO_Files::ROBOTS_OPTION ),
	'cleanup'        => get_option( Simple_SEO_Cleanup::OPTION ),
	'llms'           => get_option( Simple_SEO_Llms::OPTION ),
	'llms_text'      => get_option( Simple_SEO_Llms::TEXT ),
	'blogname'       => get_option( 'blogname' ),
	'blogdescription' => get_option( 'blogdescription' ),
	'file_backups'   => get_option( Simple_SEO_Files::BACKUPS_OPTION ),
);

// Yoast's tables, options, user meta and scheduled tasks as the stand has them (from Yoast's own
// activation in an earlier check): the import and clean-up sections change them, the end puts them back.
$sseo_yoast_options = "option_name = 'wpseo' OR option_name LIKE 'wpseo\\_%' OR option_name LIKE 'wpseo-%' OR option_name LIKE 'yoast\\_%' OR option_name LIKE 'yoast-%' OR option_name LIKE '\\_transient\\_%wpseo%' OR option_name LIKE '\\_transient\\_%yoast%'";
$sseo_yoast_users   = "meta_key LIKE 'wpseo%' OR meta_key LIKE '\\_yoast\\_%' OR meta_key = '" . esc_sql( $GLOBALS['wpdb']->get_blog_prefix() . 'yoast_notifications' ) . "'";
$sseo_saved_yoast   = array(
	'tables'   => array(),
	'options'  => $GLOBALS['wpdb']->get_results( "SELECT option_name, option_value, autoload FROM {$GLOBALS['wpdb']->options} WHERE {$sseo_yoast_options}", ARRAY_A ),
	'usermeta' => $GLOBALS['wpdb']->get_results( "SELECT user_id, meta_key, meta_value FROM {$GLOBALS['wpdb']->usermeta} WHERE {$sseo_yoast_users}", ARRAY_A ),
	'cron'     => get_option( 'cron' ),
);
foreach ( (array) $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $GLOBALS['wpdb']->esc_like( $GLOBALS['wpdb']->prefix . 'yoast_' ) . '%' ) ) as $sseo_name ) {
	$sseo_saved_yoast['tables'][ $sseo_name ] = array(
		'create' => $GLOBALS['wpdb']->get_row( "SHOW CREATE TABLE `{$sseo_name}`", ARRAY_N )[1],
		'rows'   => $GLOBALS['wpdb']->get_results( "SELECT * FROM `{$sseo_name}`", ARRAY_A ),
	);
}

// robots.txt, .htaccess and the copy next to it as they were: the Files section changes them.
require_once ABSPATH . 'wp-admin/includes/file.php';
$sseo_saved_files = array();
foreach ( array( 'robots.txt', '.htaccess', Simple_SEO_Files::DISK_COPY, 'llms.txt' ) as $sseo_name ) {
	$sseo_path                      = get_home_path() . $sseo_name;
	$sseo_saved_files[ $sseo_path ] = is_file( $sseo_path ) ? (string) file_get_contents( $sseo_path ) : null;
}
$sseo_saved_apache = $GLOBALS['is_apache'] ?? false;

// Everything the test changes goes back here: in finally, and at shutdown when something in the
// code under test exits (a served file, or a mutation that makes it serve one), so a broken run
// never leaves users, posts, options or files behind for the next one.
$sseo_cleaned = false;
$sseo_cleanup = static function () use ( &$sseo, &$sseo_saved_options, &$sseo_saved_files, $sseo_saved_apache, &$sseo_cleaned, $sseo_saved_yoast, $sseo_yoast_options, $sseo_yoast_users ): void {
	if ( $sseo_cleaned ) {
		return;
	}
	$sseo_cleaned = true;

	$_POST = array();
	wp_set_current_user( 0 );
	foreach ( $sseo['posts'] as $sseo_id ) {
		wp_delete_post( (int) $sseo_id, true );
	}
	foreach ( $sseo['terms'] as $sseo_term ) {
		wp_delete_term( $sseo_term[0], $sseo_term[1] );
		// wp-loc keeps a term of another language out of reach: delete it in its own language.
		foreach ( Simple_SEO_WP_Loc::codes() as $sseo_code ) {
			if ( '' !== $sseo_code && $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT term_id FROM {$GLOBALS['wpdb']->terms} WHERE term_id = %d", (int) $sseo_term[0] ) ) ) {
				Simple_SEO_WP_Loc::in_language( $sseo_code, static fn() => wp_delete_term( $sseo_term[0], $sseo_term[1] ) );
			}
		}
	}
	foreach ( $sseo['users'] as $sseo_id ) {
		if ( is_int( $sseo_id ) ) {
			wp_delete_user( $sseo_id );
		}
	}
	remove_role( 'sseo_seo_manager' );
	foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts' ) as $sseo_name ) {
		update_option( $sseo_name, $sseo_saved_options[ $sseo_name ] );
	}
	// A run broken by a mutation can leave the row empty; the next run then starts from the defaults.
	update_option( Simple_SEO_Access::OPTION, is_array( $sseo_saved_options['access'] ) && $sseo_saved_options['access'] ? $sseo_saved_options['access'] : Simple_SEO_Access::defaults() );
	// Rows that were not there go away (back to the defaults); a sanitizer keeps a saved state as it was.
	foreach ( array( Simple_SEO_Titles::OPTION => 'titles', Simple_SEO_Indexing::OPTION => 'indexing', Simple_SEO_Social::OPTION => 'social', Simple_SEO_Special::OPTION => 'special', Simple_SEO_Sitemaps::OPTION => 'sitemaps', Simple_SEO_Breadcrumbs::OPTION => 'breadcrumbs', Simple_SEO_Breadcrumbs::LABELS => 'bc_labels' ) as $sseo_name => $sseo_key ) {
		delete_option( $sseo_name );
		if ( is_array( $sseo_saved_options[ $sseo_key ] ) && $sseo_saved_options[ $sseo_key ] ) {
			add_option( $sseo_name, $sseo_saved_options[ $sseo_key ] );
		}
	}
	update_option( 'blog_public', '0' === (string) $sseo_saved_options['blog_public'] ? '0' : '1' );
	update_option( 'blogname', (string) $sseo_saved_options['blogname'] );
	update_option( 'blogdescription', (string) $sseo_saved_options['blogdescription'] );
	foreach ( array( Simple_SEO_Files::ROBOTS_OPTION => 'robots_txt', Simple_SEO_Files::BACKUPS_OPTION => 'file_backups', Simple_SEO_Cleanup::OPTION => 'cleanup', Simple_SEO_Llms::OPTION => 'llms', Simple_SEO_Llms::TEXT => 'llms_text' ) as $sseo_name => $sseo_key ) {
		delete_option( $sseo_name );
		if ( false !== $sseo_saved_options[ $sseo_key ] ) {
			add_option( $sseo_name, $sseo_saved_options[ $sseo_key ], '', false );
		}
	}
	// A run broken in the middle can leave the site root read-only.
	if ( ! is_writable( ABSPATH ) ) {
		chmod( ABSPATH, 0755 );
	}
	foreach ( $sseo_saved_files as $sseo_path => $sseo_text ) {
		if ( is_file( $sseo_path ) ) {
			chmod( $sseo_path, 0644 );
			unlink( $sseo_path );
		}
		if ( null !== $sseo_text ) {
			file_put_contents( $sseo_path, $sseo_text );
		}
	}
	$GLOBALS['is_apache'] = $sseo_saved_apache;
	unset( $_SERVER['REQUEST_METHOD'] );
	flush_rewrite_rules( false );
	// Rows of other languages that a broken run may have left behind.
	unset( $_COOKIE['admin_lang'] );
	foreach ( (array) $GLOBALS['wpdb']->get_col( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'simple\\_seo\\_special\\_%' OR option_name LIKE 'simple\\_seo\\_breadcrumb\\_labels\\_%' OR option_name LIKE 'simple\\_seo\\_llms\\_text\\_%'" ) as $sseo_name ) {
		delete_option( (string) $sseo_name );
	}
	// Yoast's data as the stand had it: what the import and clean-up sections made up or removed.
	$sseo_db = $GLOBALS['wpdb'];
	foreach ( (array) $sseo_db->get_col( $sseo_db->prepare( 'SHOW TABLES LIKE %s', $sseo_db->esc_like( $sseo_db->prefix . 'yoast_' ) . '%' ) ) as $sseo_name ) {
		$sseo_db->query( "DROP TABLE IF EXISTS `{$sseo_name}`" );
	}
	foreach ( $sseo_saved_yoast['tables'] as $sseo_name => $sseo_table ) {
		$sseo_db->query( $sseo_table['create'] );
		foreach ( $sseo_table['rows'] as $sseo_row ) {
			$sseo_db->insert( $sseo_name, $sseo_row );
		}
	}
	$sseo_db->query( "DELETE FROM {$sseo_db->options} WHERE {$sseo_yoast_options}" );
	foreach ( $sseo_saved_yoast['options'] as $sseo_row ) {
		$sseo_db->insert( $sseo_db->options, $sseo_row );
	}
	$sseo_db->query( "DELETE FROM {$sseo_db->usermeta} WHERE {$sseo_yoast_users}" );
	foreach ( $sseo_saved_yoast['usermeta'] as $sseo_row ) {
		$sseo_db->insert( $sseo_db->usermeta, $sseo_row );
	}
	wp_cache_flush();
	update_option( 'cron', $sseo_saved_yoast['cron'] );
	foreach ( array( 'simple_seo_import_yoast', 'simple_seo_yoast_cleanup', 'simple_seo_import_rank_math', 'simple_seo_rank_math_cleanup', 'simple_seo_import_aioseo', 'simple_seo_aioseo_cleanup' ) as $sseo_name ) {
		delete_option( $sseo_name );
	}
	// The stand has no data of Rank Math's or AIOSEO's: whatever their sections made up goes.
	foreach ( array( 'rank_math_', 'aioseo_' ) as $sseo_prefix ) {
		foreach ( (array) $sseo_db->get_col( $sseo_db->prepare( 'SHOW TABLES LIKE %s', $sseo_db->esc_like( $sseo_db->prefix . $sseo_prefix ) . '%' ) ) as $sseo_name ) {
			$sseo_db->query( "DROP TABLE IF EXISTS `{$sseo_name}`" );
		}
	}
	$sseo_db->query( "DELETE FROM {$sseo_db->options} WHERE option_name LIKE 'rank\\_math\\_%' OR option_name LIKE 'rank-math-%' OR option_name LIKE '\\_transient\\_%rank\\_math%' OR option_name LIKE 'aioseo\\_%' OR option_name LIKE '\\_transient\\_%aioseo%'" );
	foreach ( array( $sseo_db->usermeta, $sseo_db->termmeta, $sseo_db->postmeta ) as $sseo_name ) {
		$sseo_db->query( "DELETE FROM {$sseo_name} WHERE meta_key LIKE 'rank\\_math\\_%' OR meta_key LIKE '\\_aioseo%' OR meta_key LIKE 'aioseo%'" );
	}
	wp_cache_flush();
	foreach ( wp_roles()->role_objects as $sseo_role ) {
		foreach ( array_keys( (array) $sseo_role->capabilities ) as $sseo_name ) {
			if ( str_starts_with( (string) $sseo_name, 'rank_math_' ) || str_starts_with( (string) $sseo_name, 'aioseo_' ) ) {
				$sseo_role->remove_cap( $sseo_name );
			}
		}
	}
	foreach ( array( 'rank_math/sseo_daily', 'rank_math_sseo_once', 'aioseo_sseo_daily', 'aioseo_sseo_once' ) as $sseo_name ) {
		wp_unschedule_hook( $sseo_name );
	}
};
register_shutdown_function( $sseo_cleanup );

try {
	// ------------------------------------------------------------------------------------------
	sseo_section( 'Bootstrap' );

	sseo_check( 'plugin is active', is_plugin_active( 'simple-seo/simple-seo.php' ) );
	$sseo_data = get_plugin_data( SIMPLE_SEO_FILE, false, false );
	sseo_check( 'header requires WordPress 7.0', '7.0' === $sseo_data['RequiresWP'], $sseo_data['RequiresWP'] );
	sseo_check( 'header requires PHP 8.1', '8.1' === $sseo_data['RequiresPHP'], $sseo_data['RequiresPHP'] );
	sseo_check( 'header version matches SIMPLE_SEO_VERSION', SIMPLE_SEO_VERSION === $sseo_data['Version'], $sseo_data['Version'] );
	sseo_check( 'maybe_upgrade stored the version', SIMPLE_SEO_VERSION === get_option( Simple_SEO_Plugin::VERSION_OPTION ) );
	sseo_check( 'the access row exists after install', null !== get_option( Simple_SEO_Access::OPTION, null ) );
	$sseo_autoload = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Simple_SEO_Access::OPTION ) );
	sseo_check( 'the access row is autoloaded', in_array( $sseo_autoload, wp_autoload_values_to_autoload(), true ), $sseo_autoload );
	sseo_check( 'head output hooked at priority 1', 1 === has_action( 'wp_head', array( Simple_SEO_Head::class, 'output' ) ) );
	sseo_check( 'admin classes are not loaded outside the admin', ! has_action( 'admin_menu', array( Simple_SEO_Admin::class, 'add_menu' ) ) );
	sseo_check( 'translated field labels are registered on init, not when plugins load', 5 === has_action( 'init', array( Simple_SEO_Titles::class, 'register_fields' ) ) && 5 === has_action( 'init', array( Simple_SEO_Variables::class, 'register_builtin' ) ) );
	$sseo_hook = $GLOBALS['wp_filter']['sanitize_post_meta__simple_seo_title'] ?? null;
	sseo_check( 'field meta is registered once', $sseo_hook instanceof WP_Hook && 1 === count( $sseo_hook->callbacks[10] ?? array() ), $sseo_hook instanceof WP_Hook ? count( $sseo_hook->callbacks[10] ?? array() ) : 'no hook' );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Settings registry' );

	delete_option( Simple_SEO_Access::OPTION );
	Simple_SEO_Settings::flush();
	sseo_check( 'a missing row reads as its defaults', Simple_SEO_Access::defaults() === Simple_SEO_Settings::get( Simple_SEO_Access::OPTION ) );
	Simple_SEO_Settings::install();
	sseo_check( 'install() creates the missing row empty', array() === get_option( Simple_SEO_Access::OPTION ) );
	update_option( Simple_SEO_Access::OPTION, array( 'fields' => array( 'editor' ) ) );
	$sseo_row = Simple_SEO_Settings::get( Simple_SEO_Access::OPTION );
	sseo_check( 'a stored key wins over its default', array( 'editor' ) === $sseo_row['fields'], $sseo_row );
	sseo_check( 'keys the row does not have keep their defaults', array( 'administrator', 'editor' ) === $sseo_row['advanced'], $sseo_row );
	sseo_check( 'value() reads one key', array( 'editor' ) === Simple_SEO_Settings::value( Simple_SEO_Access::OPTION, 'fields' ) );
	Simple_SEO_Settings::install();
	sseo_check( 'install() leaves an existing row alone', array( 'editor' ) === Simple_SEO_Settings::get( Simple_SEO_Access::OPTION )['fields'] );
	sseo_check( 'an unregistered option sanitizes to nothing', array() === Simple_SEO_Settings::sanitize( 'simple_seo_nope', array( 'a' => 1 ) ) );
	Simple_SEO_Settings::get( Simple_SEO_Access::OPTION );
	delete_option( Simple_SEO_Access::OPTION );
	sseo_check( 'deleting the row empties the cache too', Simple_SEO_Access::defaults() === Simple_SEO_Settings::get( Simple_SEO_Access::OPTION ) );
	Simple_SEO_Settings::install();
	update_option( Simple_SEO_Access::OPTION, array() );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Access' );

	foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $sseo_role ) {
		$sseo['users'][ $sseo_role ] = wp_insert_user(
			array(
				'user_login' => 'sseo-' . $sseo_role,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'sseo-' . $sseo_role . '@example.test',
				'role'       => $sseo_role,
			)
		);
	}
	$sseo_u = $sseo['users'];

	$sseo_matrix = array(
		'administrator' => array( true, true, true ),
		'editor'        => array( false, true, true ),
		'author'        => array( false, true, false ),
		'contributor'   => array( false, true, false ),
		'subscriber'    => array( false, false, false ),
	);
	foreach ( $sseo_matrix as $sseo_role => $sseo_expected ) {
		$sseo_actual = array(
			user_can( $sseo_u[ $sseo_role ], Simple_SEO_Access::CAP_SETTINGS ),
			user_can( $sseo_u[ $sseo_role ], Simple_SEO_Access::CAP_FIELDS ),
			user_can( $sseo_u[ $sseo_role ], Simple_SEO_Access::CAP_ADVANCED ),
		);
		sseo_check( "default capabilities of {$sseo_role} (settings, fields, advanced)", $sseo_expected === $sseo_actual, $sseo_actual );
	}

	update_option( Simple_SEO_Access::OPTION, array( 'fields' => array( 'editor' ), 'advanced' => array(), 'settings' => array( 'editor' ) ) );
	sseo_check( 'a saved change applies at once: author loses fields', ! user_can( $sseo_u['author'], Simple_SEO_Access::CAP_FIELDS ) );
	sseo_check( 'editor gains the settings when chosen', user_can( $sseo_u['editor'], Simple_SEO_Access::CAP_SETTINGS ) );
	sseo_check( 'nobody has advanced when no role is chosen', ! user_can( $sseo_u['editor'], Simple_SEO_Access::CAP_ADVANCED ) );

	// Stored past the sanitizer: even an empty list cannot lock out a site administrator.
	$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->options, array( 'option_value' => maybe_serialize( array( 'settings' => array() ) ) ), array( 'option_name' => Simple_SEO_Access::OPTION ) );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( Simple_SEO_Access::OPTION, 'options' );
	Simple_SEO_Settings::flush();
	sseo_check( 'manage_options always keeps the settings', user_can( $sseo_u['administrator'], Simple_SEO_Access::CAP_SETTINGS ) );

	add_role( 'sseo_seo_manager', 'SEO manager', array( 'read' => true ) );
	$sseo['users']['sseo_seo_manager'] = wp_insert_user(
		array(
			'user_login' => 'sseo-manager',
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'sseo-manager@example.test',
			'role'       => 'sseo_seo_manager',
		)
	);
	update_option( Simple_SEO_Access::OPTION, array( 'settings' => array( 'sseo_seo_manager' ) ) );
	sseo_check( 'a custom role can be given the settings', user_can( $sseo['users']['sseo_seo_manager'], Simple_SEO_Access::CAP_SETTINGS ) );

	$sseo_clean = Simple_SEO_Access::sanitize(
		array(
			'settings' => array( 'editor', 'ghost' ),
			'fields'   => 'author',
			'advanced' => array( 'Editor<script>' ),
		)
	);
	sseo_check( 'sanitize adds administrator to the settings', in_array( 'administrator', $sseo_clean['settings'], true ), $sseo_clean );
	sseo_check( 'sanitize drops roles that do not exist', ! in_array( 'ghost', $sseo_clean['settings'], true ), $sseo_clean );
	sseo_check( 'sanitize turns a non-list into an empty list', array() === $sseo_clean['fields'], $sseo_clean );
	sseo_check( 'sanitize drops a mangled role name', array() === $sseo_clean['advanced'], $sseo_clean );
	sseo_check( 'sanitize keeps exactly the three keys', array( 'settings', 'fields', 'advanced' ) === array_keys( $sseo_clean ), array_keys( $sseo_clean ) );

	update_option( Simple_SEO_Access::OPTION, array() );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Fields and their meta' );

	$sseo_post_meta = get_registered_meta_keys( 'post' );
	$sseo_term_meta = get_registered_meta_keys( 'term' );
	foreach ( array( 'title', 'description' ) as $sseo_key ) {
		$sseo_meta_key = Simple_SEO_Fields::meta_key( $sseo_key );
		sseo_check( "{$sseo_meta_key} is registered for posts, single, in REST", isset( $sseo_post_meta[ $sseo_meta_key ] ) && $sseo_post_meta[ $sseo_meta_key ]['single'] && $sseo_post_meta[ $sseo_meta_key ]['show_in_rest'] && 'string' === $sseo_post_meta[ $sseo_meta_key ]['type'] );
		sseo_check( "{$sseo_meta_key} is registered for terms", isset( $sseo_term_meta[ $sseo_meta_key ] ) );
	}
	sseo_check( 'meta keys are protected (hidden from Custom Fields)', is_protected_meta( '_simple_seo_title', 'post' ) );

	register_post_type(
		'sseo_book',
		array(
			'public'      => true,
			'has_archive' => true,
			'label'       => 'Books',
		)
	);
	register_post_type(
		'sseo_private',
		array(
			'public' => false,
			'label'  => 'Private',
		)
	);
	register_taxonomy(
		'sseo_genre',
		array( 'post', 'sseo_book' ),
		array(
			'public' => true,
			'label'  => 'Genres',
		)
	);
	Simple_SEO_Fields::flush_types();
	$sseo_types = Simple_SEO_Fields::post_types();
	sseo_check( 'post types: post, page and a public custom type', ! array_diff( array( 'post', 'page', 'sseo_book' ), $sseo_types ), $sseo_types );
	sseo_check( 'post types: no attachments, no private types', ! in_array( 'attachment', $sseo_types, true ) && ! in_array( 'sseo_private', $sseo_types, true ), $sseo_types );
	$sseo_taxes = Simple_SEO_Fields::taxonomies();
	sseo_check( 'taxonomies: category, tags and a public custom one', ! array_diff( array( 'category', 'post_tag', 'sseo_genre' ), $sseo_taxes ), $sseo_taxes );
	sseo_check( 'taxonomies: no post formats', ! in_array( 'post_format', $sseo_taxes, true ), $sseo_taxes );
	add_filter( 'simple_seo_post_types', static fn( array $types ): array => array_merge( array_diff( $types, array( 'page' ) ), array( 'no_such_type' ) ) );
	Simple_SEO_Fields::flush_types();
	$sseo_filtered = Simple_SEO_Fields::post_types();
	sseo_check( 'simple_seo_post_types can remove a type and cannot add a missing one', ! in_array( 'page', $sseo_filtered, true ) && ! in_array( 'no_such_type', $sseo_filtered, true ), $sseo_filtered );
	remove_all_filters( 'simple_seo_post_types' );
	Simple_SEO_Fields::flush_types();

	Simple_SEO_Fields::register(
		'test_advanced',
		array(
			'label' => 'Advanced test field',
			'type'  => 'checkbox',
			'level' => Simple_SEO_Fields::LEVEL_ADVANCED,
			'order' => 90,
		)
	);
	Simple_SEO_Fields::register(
		'test_choice',
		array(
			'label'   => 'Choice test field',
			'type'    => 'select',
			'options' => array(
				''  => 'Default',
				'a' => 'A',
			),
			'objects' => array( 'post' ),
			'order'   => 95,
		)
	);
	$sseo_post_meta = get_registered_meta_keys( 'post' );
	sseo_check( 'a field registered after init gets its meta at once', isset( $sseo_post_meta['_simple_seo_test_advanced'] ) && 'boolean' === $sseo_post_meta['_simple_seo_test_advanced']['type'] );
	sseo_check( 'a post-only field has no term meta', ! isset( get_registered_meta_keys( 'term' )['_simple_seo_test_choice'] ) );
	sseo_check( 'fields come in editor order', array( 'title', 'description', 'og_title', 'og_description', 'og_image', 'primary_category', 'index', 'nofollow', 'canonical', 'keyphrase', 'test_advanced', 'keyphrases', 'test_choice' ) === array_keys( Simple_SEO_Fields::all( 'post' ) ), array_keys( Simple_SEO_Fields::all( 'post' ) ) );

	sseo_check( 'text: tags stripped, trimmed', 'Hello world' === Simple_SEO_Fields::sanitize_value( 'title', "  Hello <b>world</b> \n" ) );
	sseo_check( 'textarea: line breaks kept', "One\nTwo" === Simple_SEO_Fields::sanitize_value( 'description', "One\nTwo  " ) );
	sseo_check( 'select: unknown value becomes empty', '' === Simple_SEO_Fields::sanitize_value( 'test_choice', 'zzz' ) );
	sseo_check( 'select: known value kept', 'a' === Simple_SEO_Fields::sanitize_value( 'test_choice', 'a' ) );
	sseo_check( 'checkbox: boolean', true === Simple_SEO_Fields::sanitize_value( 'test_advanced', '1' ) && false === Simple_SEO_Fields::sanitize_value( 'test_advanced', '' ) );
	sseo_check( 'unknown field sanitizes to empty', '' === Simple_SEO_Fields::sanitize_value( 'nope', 'x' ) );

	$sseo_ed = $sseo_u['editor'];
	$sseo_au = $sseo_u['author'];
	$sseo['posts']['editor_post'] = wp_insert_post( array( 'post_title' => 'Editor post', 'post_status' => 'publish', 'post_author' => $sseo_ed ) );
	$sseo['posts']['author_post'] = wp_insert_post( array( 'post_title' => 'Author post', 'post_status' => 'publish', 'post_author' => $sseo_au ) );
	$sseo['posts']['contrib_draft'] = wp_insert_post( array( 'post_title' => 'Draft', 'post_status' => 'draft', 'post_author' => $sseo_u['contributor'] ) );
	$sseo_p = $sseo['posts'];

	sseo_check( 'author may edit fields of own post', Simple_SEO_Fields::user_can_edit( $sseo_au, 'post', $sseo_p['author_post'], 'title' ) );
	sseo_check( "author may not edit fields of the editor's post", ! Simple_SEO_Fields::user_can_edit( $sseo_au, 'post', $sseo_p['editor_post'], 'title' ) );
	sseo_check( 'contributor may edit fields of own draft', Simple_SEO_Fields::user_can_edit( $sseo_u['contributor'], 'post', $sseo_p['contrib_draft'], 'title' ) );
	sseo_check( 'subscriber may not edit fields', ! Simple_SEO_Fields::user_can_edit( $sseo_u['subscriber'], 'post', $sseo_p['author_post'], 'title' ) );
	sseo_check( 'author may not edit an advanced field', ! Simple_SEO_Fields::user_can_edit( $sseo_au, 'post', $sseo_p['author_post'], 'test_advanced' ) );
	sseo_check( 'editor may edit an advanced field', Simple_SEO_Fields::user_can_edit( $sseo_ed, 'post', $sseo_p['author_post'], 'test_advanced' ) );
	sseo_check( 'nobody edits an unknown field', ! Simple_SEO_Fields::user_can_edit( $sseo_ed, 'post', $sseo_p['author_post'], 'nope' ) );
	sseo_check( 'logged-out user edits nothing', ! Simple_SEO_Fields::user_can_edit( 0, 'post', $sseo_p['author_post'], 'title' ) );

	wp_set_current_user( $sseo_au );
	sseo_check( 'meta capability edit_post_meta follows the field rules (author, own post)', current_user_can( 'edit_post_meta', $sseo_p['author_post'], '_simple_seo_title' ) );
	sseo_check( 'meta capability: author cannot edit an advanced field', ! current_user_can( 'edit_post_meta', $sseo_p['author_post'], '_simple_seo_test_advanced' ) );

	wp_set_current_user( $sseo_ed );
	$sseo_tricky = "O'Reilly \\ \"quoted\" & <ok>";
	$sseo_changed = Simple_SEO_Fields::save( 'post', $sseo_p['author_post'], array( 'title' => $sseo_tricky, 'description' => "Line 1\nLine 2" ) );
	sseo_check( 'save() reports only the keys it was given', array( 'title', 'description' ) === $sseo_changed, $sseo_changed );
	sseo_check( 'quotes and backslashes survive saving', "O'Reilly \\ \"quoted\" &" === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'title' ), Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'title' ) );
	sseo_check( 'description saved with its line break', "Line 1\nLine 2" === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'description' ) );
	Simple_SEO_Fields::save( 'post', $sseo_p['author_post'], array( 'test_advanced' => '' ) );
	sseo_check( 'an unchecked checkbox leaves no row', ! metadata_exists( 'post', $sseo_p['author_post'], '_simple_seo_test_advanced' ) );
	Simple_SEO_Fields::save( 'post', $sseo_p['author_post'], array( 'test_advanced' => '1' ) );
	sseo_check( 'a checked checkbox is stored', '1' === get_post_meta( $sseo_p['author_post'], '_simple_seo_test_advanced', true ) );
	sseo_check( 'fields missing from the input are left as they were', "Line 1\nLine 2" === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'description' ) );
	Simple_SEO_Fields::save( 'post', $sseo_p['author_post'], array( 'title' => '   ' ) );
	sseo_check( 'an empty value deletes the row', ! metadata_exists( 'post', $sseo_p['author_post'], '_simple_seo_title' ) );
	sseo_check( 'a save without the checkbox keeps it checked', '1' === get_post_meta( $sseo_p['author_post'], '_simple_seo_test_advanced', true ) );

	wp_set_current_user( $sseo_au );
	Simple_SEO_Fields::save( 'post', $sseo_p['author_post'], array( 'title' => 'By author', 'test_advanced' => '' ) );
	sseo_check( 'author saves a basic field', 'By author' === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'title' ) );
	sseo_check( 'author cannot clear an advanced field', '1' === get_post_meta( $sseo_p['author_post'], '_simple_seo_test_advanced', true ) );
	Simple_SEO_Fields::save( 'post', $sseo_p['editor_post'], array( 'title' => 'Hijack' ) );
	sseo_check( "author cannot write into the editor's post", '' === Simple_SEO_Fields::post_value( $sseo_p['editor_post'], 'title' ) );
	wp_set_current_user( $sseo_u['subscriber'] );
	Simple_SEO_Fields::save( 'post', $sseo_p['author_post'], array( 'title' => 'Subscriber' ) );
	sseo_check( 'subscriber writes nothing', 'By author' === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'title' ) );

	$sseo_cat = wp_insert_term( 'SSEO category', 'category' );
	$sseo['terms'][] = array( (int) $sseo_cat['term_id'], 'category' );
	$sseo_cat_id = (int) $sseo_cat['term_id'];
	wp_set_current_user( $sseo_ed );
	Simple_SEO_Fields::save( 'term', $sseo_cat_id, array( 'title' => 'Category title', 'test_choice' => 'a' ) );
	sseo_check( 'term field saved by an editor', 'Category title' === Simple_SEO_Fields::term_value( $sseo_cat_id, 'title' ) );
	sseo_check( 'a post-only field is not saved on a term', ! metadata_exists( 'term', $sseo_cat_id, '_simple_seo_test_choice' ) );
	wp_set_current_user( $sseo_au );
	Simple_SEO_Fields::save( 'term', $sseo_cat_id, array( 'title' => 'Author term' ) );
	sseo_check( 'author cannot edit term fields (no edit_term)', 'Category title' === Simple_SEO_Fields::term_value( $sseo_cat_id, 'title' ) );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'REST' );

	wp_set_current_user( $sseo_au );
	$sseo_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $sseo_p['author_post'] );
	$sseo_request->set_body_params( array( 'meta' => array( '_simple_seo_title' => 'Via REST' ) ) );
	$sseo_response = rest_do_request( $sseo_request );
	sseo_check( 'author updates own post title field through REST', 200 === $sseo_response->get_status() && 'Via REST' === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'title' ), $sseo_response->get_status() );
	$sseo_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $sseo_p['author_post'] );
	$sseo_request->set_body_params( array( 'meta' => array( '_simple_seo_test_advanced' => false ) ) );
	$sseo_response = rest_do_request( $sseo_request );
	sseo_check( 'author is refused an advanced field through REST', 403 === $sseo_response->get_status() && '1' === get_post_meta( $sseo_p['author_post'], '_simple_seo_test_advanced', true ), $sseo_response->get_status() );
	$sseo_request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $sseo_p['author_post'] );
	$sseo_request->set_param( 'context', 'edit' );
	$sseo_data = rest_do_request( $sseo_request )->get_data();
	sseo_check( 'the field is readable in the edit context', 'Via REST' === ( $sseo_data['meta']['_simple_seo_title'] ?? null ) );
	// Core's posts controller leaves a post_password_required filter behind for the rest of this
	// process: for anyone who may edit a post it says no password is needed. A front-end request
	// never runs a REST request first, so the later checks of protected posts would be lying.
	remove_all_filters( 'post_password_required' );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Editor screens' );

	Simple_SEO_Editor::init();

	wp_set_current_user( $sseo_au );
	$GLOBALS['wp_meta_boxes'] = array();
	Simple_SEO_Editor::add_meta_box( 'post', get_post( $sseo_p['author_post'] ) );
	sseo_check( 'meta box added for an author', isset( $GLOBALS['wp_meta_boxes']['post']['normal']['default']['simple-seo'] ) );
	$GLOBALS['wp_meta_boxes'] = array();
	Simple_SEO_Editor::add_meta_box( 'sseo_private', null );
	sseo_check( 'no meta box on a type without SEO fields', empty( $GLOBALS['wp_meta_boxes'] ) );
	wp_set_current_user( $sseo_u['subscriber'] );
	$GLOBALS['wp_meta_boxes'] = array();
	Simple_SEO_Editor::add_meta_box( 'post', null );
	sseo_check( 'no meta box for a role without the fields capability', empty( $GLOBALS['wp_meta_boxes'] ) );

	wp_set_current_user( $sseo_au );
	// Tags never reach the database (the sanitizer strips them), but quotes do: an attribute breakout.
	update_post_meta( $sseo_p['author_post'], '_simple_seo_title', 'x" onfocus="alert(1)' );
	update_post_meta( $sseo_p['author_post'], '_simple_seo_description', '</textarea><b>bold' );
	sseo_check( 'tags are stripped before they are stored', 'bold' === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'description' ), Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'description' ) );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['author_post'] ) ) );
	sseo_check( 'meta box has the title input', str_contains( $sseo_html, 'name="simple_seo[title]"' ) );
	sseo_check( 'meta box escapes quotes in the stored value', ! str_contains( $sseo_html, 'x" onfocus' ) && str_contains( $sseo_html, 'value="x&quot; onfocus=&quot;alert(1)"' ) );
	sseo_check( 'meta box carries the nonce', str_contains( $sseo_html, 'name="simple_seo_fields_nonce"' ) );
	sseo_check( 'meta box hides the advanced field from an author', ! str_contains( $sseo_html, 'simple_seo[test_advanced]' ) );
	wp_set_current_user( $sseo_ed );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['author_post'] ) ) );
	sseo_check( 'meta box shows the advanced field to an editor, checked', (bool) preg_match( '/name="simple_seo\[test_advanced\]" value="1"\s+checked/', $sseo_html ) );
	sseo_check( 'an empty hidden value comes before the checkbox', str_contains( $sseo_html, '<input type="hidden" name="simple_seo[test_advanced]" value=""><input type="checkbox"' ) );
	update_post_meta( $sseo_p['editor_post'], '_simple_seo_test_advanced', '1' );
	sseo_post_form( $sseo_p['editor_post'], array( 'test_advanced' => '' ) );
	Simple_SEO_Editor::save_post( $sseo_p['editor_post'], get_post( $sseo_p['editor_post'] ) );
	sseo_check( 'unchecking the box in the form clears it', ! metadata_exists( 'post', $sseo_p['editor_post'], '_simple_seo_test_advanced' ) );
	$_POST = array();

	wp_set_current_user( $sseo_au );
	sseo_post_form( $sseo_p['author_post'], array( 'title' => 'From the form', 'description' => '' ) );
	Simple_SEO_Editor::save_post( $sseo_p['author_post'], get_post( $sseo_p['author_post'] ) );
	sseo_check( 'meta box save writes the title', 'From the form' === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'title' ) );
	sseo_check( 'meta box save with an empty description deletes it', ! metadata_exists( 'post', $sseo_p['author_post'], '_simple_seo_description' ) );
	sseo_check( "meta box save leaves the author's hidden advanced field alone", '1' === get_post_meta( $sseo_p['author_post'], '_simple_seo_test_advanced', true ) );

	sseo_post_form( $sseo_p['author_post'], array( 'title' => 'Bad nonce' ), false );
	Simple_SEO_Editor::save_post( $sseo_p['author_post'], get_post( $sseo_p['author_post'] ) );
	sseo_check( 'a bad nonce saves nothing', 'From the form' === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'title' ) );

	// wp-loc saves sibling translations during the same request: they must not get this form's values.
	wp_set_current_user( $sseo_ed );
	sseo_post_form( $sseo_p['author_post'], array( 'title' => 'Only for the edited post' ) );
	wp_update_post( array( 'ID' => $sseo_p['editor_post'], 'post_excerpt' => 'sibling saved in the same request' ) );
	sseo_check( 'a post saved in the same request does not take the form values', '' === Simple_SEO_Fields::post_value( $sseo_p['editor_post'], 'title' ) );
	wp_update_post( array( 'ID' => $sseo_p['author_post'], 'post_excerpt' => 'the edited post' ) );
	sseo_check( 'the edited post itself saves through the save_post hook', 'Only for the edited post' === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'title' ) );

	$sseo_revision = wp_save_post_revision( $sseo_p['author_post'] );
	if ( $sseo_revision ) {
		sseo_post_form( (int) $sseo_revision, array( 'title' => 'Revision' ) );
		Simple_SEO_Editor::save_post( (int) $sseo_revision, get_post( $sseo_revision ) );
		sseo_check( 'a revision never gets fields', ! metadata_exists( 'post', (int) $sseo_revision, '_simple_seo_title' ) );
	}
	$_POST = array();

	$sseo_tag = wp_insert_term( 'SSEO tag', 'post_tag' );
	$sseo['terms'][] = array( (int) $sseo_tag['term_id'], 'post_tag' );
	$sseo_tag_id = (int) $sseo_tag['term_id'];
	wp_set_current_user( $sseo_ed );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_term_fields( get_term( $sseo_cat_id ) ) );
	sseo_check( 'term screen has the SEO fields and the nonce', str_contains( $sseo_html, 'name="simple_seo[title]"' ) && str_contains( $sseo_html, 'simple_seo_fields_nonce' ) );
	sseo_check( 'term screen shows the stored value', str_contains( $sseo_html, 'value="Category title"' ) );
	sseo_check( 'term screen leaves out a post-only field', ! str_contains( $sseo_html, 'simple_seo[test_choice]' ) );
	sseo_term_form( $sseo_cat_id, array( 'title' => 'Saved from the term form' ) );
	Simple_SEO_Editor::save_term( $sseo_tag_id );
	sseo_check( 'another term saved in the same request does not take the form values', '' === Simple_SEO_Fields::term_value( $sseo_tag_id, 'title' ) );
	Simple_SEO_Editor::save_term( $sseo_cat_id );
	sseo_check( 'the term form saves its own term', 'Saved from the term form' === Simple_SEO_Fields::term_value( $sseo_cat_id, 'title' ) );
	$_POST = array();
	Simple_SEO_Editor::hook_taxonomies();
	sseo_check( 'term screens hooked for a custom taxonomy', (bool) has_action( 'sseo_genre_edit_form_fields', array( Simple_SEO_Editor::class, 'render_term_fields' ) ) );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Settings page' );

	Simple_SEO_Admin::init();
	Simple_SEO_Admin::register_settings();
	$sseo_registered = get_registered_settings();
	sseo_check( 'the access row is registered in its tab group', isset( $sseo_registered[ Simple_SEO_Access::OPTION ] ) && 'simple-seo-access' === $sseo_registered[ Simple_SEO_Access::OPTION ]['group'] );
	sseo_check( 'saving the tab needs the plugin capability, not manage_options', Simple_SEO_Access::CAP_SETTINGS === apply_filters( 'option_page_capability_simple-seo-access', 'manage_options' ) );
	update_option( Simple_SEO_Access::OPTION, array( 'settings' => array(), 'fields' => array( 'author', 'ghost' ) ) );
	$sseo_stored = get_option( Simple_SEO_Access::OPTION );
	sseo_check( 'update_option runs the sanitizer (administrator kept, ghost dropped)', array( 'administrator' ) === $sseo_stored['settings'] && array( 'author' ) === $sseo_stored['fields'], $sseo_stored );
	update_option( Simple_SEO_Access::OPTION, array() );

	wp_set_current_user( $sseo_u['administrator'] );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'settings page posts to options.php with the tab group', str_contains( $sseo_html, 'action="options.php"' ) && str_contains( $sseo_html, "value='simple-seo-access'" ) );
	sseo_check( 'settings page lists the roles', str_contains( $sseo_html, 'value="editor"' ) && str_contains( $sseo_html, 'value="subscriber"' ) );
	sseo_check( 'administrator checkbox of the settings row is checked and locked', (bool) preg_match( '/name="simple_seo_access\[settings\]\[\]"\s+value="administrator"\s+checked=\'checked\'\s+disabled=\'disabled\'/', $sseo_html ) );
	sseo_check( 'one tab: no tab bar yet', ! str_contains( $sseo_html, 'nav-tab-wrapper' ) );
	Simple_SEO_Admin::add_tab( 'sseo_test', 'Test tab', array(), static function (): void {
		echo '<p id="sseo-test-tab">test tab</p>';
	}, 5 );
	$_GET['tab'] = 'sseo_test';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'a second tab shows the tab bar, ordered', str_contains( $sseo_html, 'nav-tab-wrapper' ) && strpos( $sseo_html, 'Test tab' ) < strpos( $sseo_html, 'Access' ) );
	sseo_check( 'the chosen tab renders', str_contains( $sseo_html, 'id="sseo-test-tab"' ) );
	$_GET['tab'] = 'no-such-tab';
	sseo_check( 'an unknown tab falls back to the first', 'sseo_test' === Simple_SEO_Admin::current_tab() );
	unset( $_GET['tab'] );
	$sseo_links = Simple_SEO_Admin::action_links( array() );
	sseo_check( 'plugins list gets a Settings link', isset( $sseo_links[0] ) && str_contains( $sseo_links[0], 'page=simple-seo' ) );
	wp_set_current_user( $sseo_ed );
	sseo_check( 'no Settings link without the capability', array() === Simple_SEO_Admin::action_links( array() ) );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Page context' );

	wp_set_current_user( 0 );
	$sseo['posts']['front']     = wp_insert_post( array( 'post_title' => 'Front', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$sseo['posts']['blog']      = wp_insert_post( array( 'post_title' => 'Blog', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$sseo['posts']['protected'] = wp_insert_post( array( 'post_title' => 'Protected', 'post_status' => 'publish', 'post_password' => 'secret' ) );
	$sseo['posts']['multi']     = wp_insert_post( array( 'post_title' => 'Multi', 'post_status' => 'publish', 'post_content' => 'one<!--nextpage-->two' ) );
	$sseo['posts']['book']      = wp_insert_post( array( 'post_title' => 'Book', 'post_type' => 'sseo_book', 'post_status' => 'publish' ) );
	$sseo_p = $sseo['posts'];
	$sseo_genre = wp_insert_term( 'SSEO genre', 'sseo_genre' );
	$sseo['terms'][] = array( (int) $sseo_genre['term_id'], 'sseo_genre' );
	wp_set_post_terms( $sseo_p['book'], array( (int) $sseo_genre['term_id'] ), 'sseo_genre' );
	wp_set_post_terms( $sseo_p['author_post'], array( $sseo_tag_id ), 'post_tag' );
	wp_set_post_categories( $sseo_p['author_post'], array( $sseo_cat_id ) );

	update_option( 'show_on_front', 'posts' );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array() ) );
	sseo_check( 'latest posts on the front page: home, front page', Simple_SEO_Context::HOME === $sseo_ctx->kind && $sseo_ctx->is_front_page && ! $sseo_ctx->has_fields(), $sseo_ctx->kind );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'paged' => 3 ) ) );
	sseo_check( 'home page 3', Simple_SEO_Context::HOME === $sseo_ctx->kind && 3 === $sseo_ctx->page, array( $sseo_ctx->kind, $sseo_ctx->page ) );

	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'p' => $sseo_p['author_post'] ) ) );
	sseo_check( 'a post: singular, its ID and type, not front page', Simple_SEO_Context::SINGULAR === $sseo_ctx->kind && $sseo_p['author_post'] === $sseo_ctx->object_id && 'post' === $sseo_ctx->subtype && ! $sseo_ctx->is_front_page );
	sseo_check( 'a post: field() reads its SEO title', 'Only for the edited post' === $sseo_ctx->field( 'title' ) );
	sseo_check( 'a post: page 1', 1 === $sseo_ctx->page );

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $sseo_p['front'] );
	update_option( 'page_for_posts', $sseo_p['blog'] );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array() ) );
	sseo_check( 'a static front page: singular page, front page', Simple_SEO_Context::SINGULAR === $sseo_ctx->kind && $sseo_ctx->is_front_page && $sseo_p['front'] === $sseo_ctx->object_id, array( $sseo_ctx->kind, $sseo_ctx->object_id ) );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'page_id' => $sseo_p['blog'] ) ) );
	sseo_check( 'the posts page: posts_page with the page as object', Simple_SEO_Context::POSTS_PAGE === $sseo_ctx->kind && $sseo_p['blog'] === $sseo_ctx->object_id && $sseo_ctx->has_fields(), array( $sseo_ctx->kind, $sseo_ctx->object_id ) );
	update_option( 'show_on_front', 'posts' );

	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'p' => $sseo_p['protected'] ) ) );
	sseo_check( 'a password-protected post without the cookie', Simple_SEO_Context::SINGULAR === $sseo_ctx->kind && $sseo_ctx->password_required );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'p' => $sseo_p['multi'], 'page' => 2 ) ) );
	sseo_check( 'part 2 of a split post', 2 === $sseo_ctx->page, $sseo_ctx->page );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'p' => $sseo_p['book'], 'post_type' => 'sseo_book' ) ) );
	sseo_check( 'a custom post type entry', Simple_SEO_Context::SINGULAR === $sseo_ctx->kind && 'sseo_book' === $sseo_ctx->subtype );

	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'cat' => $sseo_cat_id, 'paged' => 2 ) ) );
	sseo_check( 'a category: term, taxonomy, page 2', Simple_SEO_Context::TERM === $sseo_ctx->kind && 'category' === $sseo_ctx->subtype && $sseo_cat_id === $sseo_ctx->object_id && 2 === $sseo_ctx->page, array( $sseo_ctx->kind, $sseo_ctx->subtype ) );
	sseo_check( 'a category: field() reads the term field', 'Saved from the term form' === $sseo_ctx->field( 'title' ) );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'tag' => 'sseo-tag' ) ) );
	sseo_check( 'a tag', Simple_SEO_Context::TERM === $sseo_ctx->kind && 'post_tag' === $sseo_ctx->subtype, array( $sseo_ctx->kind, $sseo_ctx->subtype ) );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'sseo_genre' => 'sseo-genre' ) ) );
	sseo_check( 'a custom taxonomy term', Simple_SEO_Context::TERM === $sseo_ctx->kind && 'sseo_genre' === $sseo_ctx->subtype, array( $sseo_ctx->kind, $sseo_ctx->subtype ) );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'post_type' => 'sseo_book' ) ) );
	sseo_check( 'a post type archive', Simple_SEO_Context::POST_TYPE_ARCHIVE === $sseo_ctx->kind && 'sseo_book' === $sseo_ctx->subtype && $sseo_ctx->object instanceof WP_Post_Type && ! $sseo_ctx->has_fields(), $sseo_ctx->kind );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'author' => $sseo_au ) ) );
	sseo_check( 'an author archive', Simple_SEO_Context::AUTHOR === $sseo_ctx->kind && $sseo_au === $sseo_ctx->object_id && ! $sseo_ctx->has_fields(), $sseo_ctx->kind );
	$sseo_year = (int) gmdate( 'Y' );
	$sseo_ctx  = Simple_SEO_Context::from_query( sseo_query( array( 'year' => $sseo_year ) ) );
	sseo_check( 'a year archive', Simple_SEO_Context::DATE === $sseo_ctx->kind && 'year' === $sseo_ctx->subtype, array( $sseo_ctx->kind, $sseo_ctx->subtype ) );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'm' => gmdate( 'Ym' ) ) ) );
	sseo_check( 'a month archive', Simple_SEO_Context::DATE === $sseo_ctx->kind && 'month' === $sseo_ctx->subtype, $sseo_ctx->subtype );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 'year' => $sseo_year, 'monthnum' => (int) gmdate( 'n' ), 'day' => (int) gmdate( 'j' ) ) ) );
	sseo_check( 'a day archive', Simple_SEO_Context::DATE === $sseo_ctx->kind && 'day' === $sseo_ctx->subtype, $sseo_ctx->subtype );
	$sseo_ctx = Simple_SEO_Context::from_query( sseo_query( array( 's' => 'anything' ) ) );
	sseo_check( 'a search', Simple_SEO_Context::SEARCH === $sseo_ctx->kind );
	$sseo_q = sseo_query( array( 'p' => 99999999 ) );
	$sseo_q->set_404();
	$sseo_ctx = Simple_SEO_Context::from_query( $sseo_q );
	sseo_check( 'a 404', Simple_SEO_Context::NOT_FOUND === $sseo_ctx->kind && '' === $sseo_ctx->field( 'title' ) );

	// current(): resolved from the main query and remembered only once the main query has run.
	$sseo_saved_query      = $GLOBALS['wp_query'];
	$sseo_saved_wp_actions = $GLOBALS['wp_actions']['wp'] ?? null;
	Simple_SEO_Context::reset();
	$GLOBALS['wp_query'] = sseo_query( array( 'p' => $sseo_p['author_post'] ) );
	unset( $GLOBALS['wp_actions']['wp'] );
	Simple_SEO_Context::current();
	$GLOBALS['wp_query'] = sseo_query( array( 's' => 'x' ) );
	sseo_check( 'before the main query runs nothing is remembered', Simple_SEO_Context::SEARCH === Simple_SEO_Context::current()->kind );
	$GLOBALS['wp_actions']['wp'] = 1;
	$GLOBALS['wp_query']         = sseo_query( array( 'p' => $sseo_p['author_post'] ) );
	Simple_SEO_Context::current();
	$GLOBALS['wp_query'] = sseo_query( array( 's' => 'x' ) );
	sseo_check( 'after the main query the context is resolved once', Simple_SEO_Context::SINGULAR === Simple_SEO_Context::current()->kind );
	Simple_SEO_Context::reset();
	sseo_check( 'reset() resolves again', Simple_SEO_Context::SEARCH === Simple_SEO_Context::current()->kind );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Head output' );

	Simple_SEO_Context::reset();
	$GLOBALS['wp_query'] = sseo_query( array( 'p' => $sseo_p['author_post'] ) );
	// The plugin's own providers are tested in their sections; here only the mechanism.
	Simple_SEO_Head::remove_provider( 'description' );
	Simple_SEO_Head::remove_provider( 'canonical' );
	Simple_SEO_Head::remove_provider( 'social' );
	sseo_check( 'no providers, no output', '' === sseo_capture( array( Simple_SEO_Head::class, 'output' ) ) );

	Simple_SEO_Head::add_provider(
		'sseo_test_late',
		static fn( Simple_SEO_Context $context ): array => array(
			'test:late' => array( 'tag' => 'meta', 'attributes' => array( 'name' => 'sseo-late', 'content' => 'late' ) ),
		),
		20
	);
	Simple_SEO_Head::add_provider(
		'sseo_test',
		static fn( Simple_SEO_Context $context ): array => array(
			'test:title' => array( 'tag' => 'meta', 'attributes' => array( 'name' => 'sseo-title', 'content' => $context->field( 'title' ) ) ),
			'test:evil'  => array( 'tag' => 'meta', 'attributes' => array( 'name' => 'sseo-evil', 'content' => '"><script>alert(1)</script>' ) ),
			'test:js'    => array( 'tag' => 'link', 'attributes' => array( 'rel' => 'sseo-js', 'href' => 'javascript:alert(1)' ) ),
			'test:link'  => array( 'tag' => 'link', 'attributes' => array( 'rel' => 'sseo-link', 'href' => 'https://example.test/a b?x=1&y=2' ) ),
			'test:empty' => array( 'tag' => 'meta', 'attributes' => array( 'name' => 'sseo-empty', 'content' => '' ) ),
			'test:attr'  => array( 'tag' => 'meta', 'attributes' => array( 'name' => 'sseo-attr', 'content' => 'x', 'on load' => 'bad', 'data-x' => array( 'no' ) ) ),
			'test:tag'   => array( 'tag' => 'script', 'attributes' => array( 'src' => 'https://example.test/x.js' ) ),
		),
		5
	);

	$sseo_queries = $GLOBALS['wpdb']->num_queries;
	$sseo_html    = sseo_capture( array( Simple_SEO_Head::class, 'output' ) );
	$sseo_queries = $GLOBALS['wpdb']->num_queries - $sseo_queries;
	sseo_check( 'a field of the queried post costs no database query (meta cache primed by the query)', 0 === $sseo_queries, $sseo_queries );
	sseo_check( 'a provider reads the field through the context', str_contains( $sseo_html, '<meta name="sseo-title" content="Only for the edited post" />' ), $sseo_html );
	sseo_check( 'attribute values are escaped', str_contains( $sseo_html, 'content="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"' ) && ! str_contains( $sseo_html, '<script>' ) );
	sseo_check( 'a link with an unsafe address is dropped whole', ! str_contains( $sseo_html, 'sseo-js' ) );
	sseo_check( 'a link address is escaped as a URL', str_contains( $sseo_html, 'href="https://example.test/a%20b?x=1&#038;y=2"' ), $sseo_html );
	sseo_check( 'a meta without content is dropped', ! str_contains( $sseo_html, 'sseo-empty' ) );
	sseo_check( 'bad attribute names and non-scalar values are dropped', str_contains( $sseo_html, '<meta name="sseo-attr" content="x" />' ) );
	sseo_check( 'only meta and link tags are printed', ! str_contains( $sseo_html, 'x.js' ) );
	sseo_check( 'providers run by priority', strpos( $sseo_html, 'sseo-title' ) < strpos( $sseo_html, 'sseo-late' ) );

	add_filter(
		'simple_seo_head_tags',
		static function ( array $tags, Simple_SEO_Context $context ): array {
			unset( $tags['test:late'] );
			$tags['test:added'] = array( 'tag' => 'meta', 'attributes' => array( 'name' => 'sseo-added', 'content' => $context->kind ) );
			return $tags;
		},
		10,
		2
	);
	$sseo_html = sseo_capture( array( Simple_SEO_Head::class, 'output' ) );
	sseo_check( 'simple_seo_head_tags removes a tag by its key', ! str_contains( $sseo_html, 'sseo-late' ) );
	sseo_check( 'simple_seo_head_tags adds a tag with the context', str_contains( $sseo_html, '<meta name="sseo-added" content="singular" />' ) );
	add_filter( 'simple_seo_head_tags', '__return_null', 20 );
	sseo_check( 'a broken filter result prints nothing instead of failing', '' === sseo_capture( array( Simple_SEO_Head::class, 'output' ) ) );
	// Only what this section added: the Yoast layer hooks this filter too (priority 5).
	remove_all_filters( 'simple_seo_head_tags', 10 );
	remove_all_filters( 'simple_seo_head_tags', 20 );
	Simple_SEO_Head::remove_provider( 'sseo_test' );
	Simple_SEO_Head::remove_provider( 'sseo_test_late' );
	sseo_check( 'removed providers print nothing', '' === sseo_capture( array( Simple_SEO_Head::class, 'output' ) ) );
	Simple_SEO_Head::add_provider( 'description', array( Simple_SEO_Titles::class, 'head_tags' ), 10 );
	Simple_SEO_Head::add_provider( 'canonical', array( Simple_SEO_Indexing::class, 'head_tags' ), 20 );
	Simple_SEO_Head::add_provider( 'social', array( Simple_SEO_Social::class, 'head_tags' ), 30 );

	$GLOBALS['wp_query'] = $sseo_saved_query;
	if ( null === $sseo_saved_wp_actions ) {
		unset( $GLOBALS['wp_actions']['wp'] );
	} else {
		$GLOBALS['wp_actions']['wp'] = $sseo_saved_wp_actions;
	}
	Simple_SEO_Context::reset();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Variables' );

	update_option( Simple_SEO_Access::OPTION, Simple_SEO_Access::defaults() );
	update_option( Simple_SEO_Titles::OPTION, array() );
	$sseo_tagline = get_option( 'blogdescription' );
	update_option( 'blogdescription', 'Just testing' );
	Simple_SEO_Settings::flush();
	$sseo_site = (string) get_option( 'blogname' );
	$sseo_ctx_of = static fn( array $vars ): Simple_SEO_Context => Simple_SEO_Context::from_query( sseo_query( $vars ) );

	sseo_check( 'twelve built-in variables', 12 === count( Simple_SEO_Variables::labels() ), array_keys( Simple_SEO_Variables::labels() ) );
	$sseo_ctx = $sseo_ctx_of( array( 'p' => $sseo_p['author_post'] ) );
	sseo_check( 'title, page and site with the separator', "Author post – {$sseo_site}" === Simple_SEO_Variables::replace( '{title} {page} {sep} {site}', $sseo_ctx, '–' ) );
	sseo_check( 'separators at the ends and in a row are tidied away', "Author post – {$sseo_site}" === Simple_SEO_Variables::replace( '{sep} {title} {sep}{sep}  {page} {sep} {site} {sep}', $sseo_ctx, '–' ), Simple_SEO_Variables::replace( '{sep} {title} {sep}{sep}  {page} {sep} {site} {sep}', $sseo_ctx, '–' ) );
	sseo_check( 'unknown names in braces stay as typed', 'SSEO category {nope}' === Simple_SEO_Variables::replace( '{category} {nope}', $sseo_ctx, '–' ) );
	sseo_check( 'an empty template stays empty', '' === Simple_SEO_Variables::replace( '  ', $sseo_ctx, '–' ) );
	sseo_check( 'plain(): tags and entities removed, one line', 'Tom & Jerry’s x' === Simple_SEO_Variables::plain( "<b>Tom</b> &amp;\n Jerry&#8217;s <i>x</i>" ) );
	sseo_check( 'a value cannot smuggle in a separator', "a b – {$sseo_site}" === Simple_SEO_Variables::replace( 'a {search} b {sep} {site}', Simple_SEO_Context::from_query( sseo_query( array( 's' => "\x1F" ) ) ), '–' ) );

	wp_update_term( $sseo_cat_id, 'category', array( 'description' => 'Category <em>about</em> things' ) );
	$sseo_ctx = $sseo_ctx_of( array( 'cat' => $sseo_cat_id ) );
	sseo_check( 'term: {title} is the name, {excerpt} the description', 'SSEO category|Category about things' === Simple_SEO_Variables::replace( '{title}|{excerpt}', $sseo_ctx, '–' ), Simple_SEO_Variables::replace( '{title}|{excerpt}', $sseo_ctx, '–' ) );
	sseo_check( 'post type archive: {title} is the plural name', 'Books' === Simple_SEO_Variables::replace( '{title}', $sseo_ctx_of( array( 'post_type' => 'sseo_book' ) ), '–' ) );
	sseo_check( 'author archive: {title} and {author} are the name', 'sseo-author sseo-author' === Simple_SEO_Variables::replace( '{title} {author}', $sseo_ctx_of( array( 'author' => $sseo_au ) ), '–' ) );
	$sseo_y = (int) gmdate( 'Y' );
	$sseo_n = (int) gmdate( 'n' );
	sseo_check( 'year archive', (string) $sseo_y === Simple_SEO_Variables::replace( '{title}', $sseo_ctx_of( array( 'year' => $sseo_y ) ), '–' ) );
	sseo_check( 'month archive: month and year', gmdate( 'F Y', gmmktime( 0, 0, 0, $sseo_n, 1, $sseo_y ) ) === Simple_SEO_Variables::replace( '{title}', $sseo_ctx_of( array( 'm' => gmdate( 'Ym' ) ) ), '–' ), Simple_SEO_Variables::replace( '{title}', $sseo_ctx_of( array( 'm' => gmdate( 'Ym' ) ) ), '–' ) );
	sseo_check( 'day archive: the site date format', date_i18n( get_option( 'date_format' ), gmmktime( 0, 0, 0, $sseo_n, 1, $sseo_y ) ) === Simple_SEO_Variables::replace( '{date}', $sseo_ctx_of( array( 'year' => $sseo_y, 'monthnum' => $sseo_n, 'day' => 1 ) ), '–' ) );
	$sseo_ctx = $sseo_ctx_of( array( 's' => 'кава' ) );
	sseo_check( 'search: a ready phrase and the query', 'Search results for "кава" / кава' === Simple_SEO_Variables::replace( '{title} / {search}', $sseo_ctx, '–' ) );
	$sseo_q = sseo_query( array( 'p' => 99999999 ) );
	$sseo_q->set_404();
	sseo_check( '404: a ready phrase', 'Page not found' === Simple_SEO_Variables::replace( '{title}', Simple_SEO_Context::from_query( $sseo_q ), '–' ) );
	sseo_check( 'home: {title} is the site title', $sseo_site === Simple_SEO_Variables::replace( '{title}', $sseo_ctx_of( array() ), '–' ) );
	sseo_check( 'part 2 of a split post: Page 2 of 2', 'Page 2 of 2' === Simple_SEO_Variables::replace( '{page}', $sseo_ctx_of( array( 'p' => $sseo_p['multi'], 'page' => 2 ) ), '–' ) );
	sseo_check( 'first page: no page number', '' === Simple_SEO_Variables::replace( '{page}', $sseo_ctx_of( array( 'p' => $sseo_p['multi'] ) ), '–' ) );

	$sseo['posts']['excerpt'] = wp_insert_post( array( 'post_title' => 'With excerpt', 'post_status' => 'publish', 'post_excerpt' => 'The <b>chosen</b> excerpt', 'post_content' => 'Body' ) );
	$sseo['posts']['blocks']  = wp_insert_post( array( 'post_title' => 'Blocks', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>Hello <strong>world</strong> [gallery]</p><!-- /wp:paragraph --><!-- wp:acf/hero {"name":"acf/hero","data":{"title":"Hidden"}} /-->' ) );
	$sseo['posts']['long']    = wp_insert_post( array( 'post_title' => 'Long', 'post_status' => 'publish', 'post_content' => str_repeat( 'Привіт світ, як справи. ', 30 ) ) );
	$sseo['posts']['secret']  = wp_insert_post( array( 'post_title' => 'Secret', 'post_status' => 'publish', 'post_password' => 'pw', 'post_excerpt' => 'Leaked excerpt', 'post_content' => 'Leaked text' ) );
	$sseo_p = $sseo['posts'];
	sseo_check( '{excerpt}: the written excerpt, as plain text', 'The chosen excerpt' === Simple_SEO_Variables::replace( '{excerpt}', $sseo_ctx_of( array( 'p' => $sseo_p['excerpt'] ) ), '–' ) );
	sseo_check( '{excerpt}: from the content, without shortcodes and dynamic blocks', 'Hello world' === Simple_SEO_Variables::replace( '{excerpt}', $sseo_ctx_of( array( 'p' => $sseo_p['blocks'] ) ), '–' ), Simple_SEO_Variables::replace( '{excerpt}', $sseo_ctx_of( array( 'p' => $sseo_p['blocks'] ) ), '–' ) );
	$sseo_long = Simple_SEO_Variables::replace( '{excerpt}', $sseo_ctx_of( array( 'p' => $sseo_p['long'] ) ), '–' );
	sseo_check( '{excerpt}: long Cyrillic text cut at a word with an ellipsis, valid UTF-8', mb_check_encoding( $sseo_long, 'UTF-8' ) && mb_strlen( $sseo_long ) <= 157 && str_ends_with( $sseo_long, '…' ) && ! preg_match( '/[\s,.]…$/u', $sseo_long ), $sseo_long );
	// "р" is D1 80 in UTF-8; 80 is also a byte of the dashes, so a byte-wise trim would break the letter.
	$sseo['posts']['cheese'] = wp_insert_post( array( 'post_title' => 'Cheese', 'post_status' => 'publish', 'post_content' => str_repeat( 'сир ', 60 ) ) );
	$sseo_cheese             = Simple_SEO_Variables::replace( '{excerpt}', $sseo_ctx_of( array( 'p' => $sseo['posts']['cheese'] ) ), '–' );
	sseo_check( '{excerpt}: a cut right after "р" keeps the letter whole', mb_check_encoding( $sseo_cheese, 'UTF-8' ) && str_ends_with( $sseo_cheese, 'сир…' ), $sseo_cheese );
	sseo_check( '{excerpt}: nothing of a password-protected post', '' === Simple_SEO_Variables::replace( '{excerpt}', $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ), '–' ) );
	$sseo_ctx = $sseo_ctx_of( array( 'p' => $sseo_p['author_post'] ) );
	sseo_check( '{category}, {author}, {post_type} of a post', 'SSEO category|sseo-author|Post' === Simple_SEO_Variables::replace( '{category}|{author}|{post_type}', $sseo_ctx, '–' ) );
	sseo_check( '{date} of a post: its publication date', get_the_date( '', $sseo_p['author_post'] ) === Simple_SEO_Variables::replace( '{date}', $sseo_ctx, '–' ) );
	sseo_check( '{year}: the current year', wp_date( 'Y' ) === Simple_SEO_Variables::replace( '{year}', $sseo_ctx, '–' ) );
	sseo_check( '{category} of a page is empty', '' === Simple_SEO_Variables::replace( '{category}', $sseo_ctx_of( array( 'page_id' => $sseo_p['front'] ) ), '–' ) );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Titles and descriptions' );

	sseo_check( 'default separator is the en dash', '–' === Simple_SEO_Titles::separator() );
	$sseo_slots = array(
		'home'              => $sseo_ctx_of( array() ),
		'post:post'         => $sseo_ctx_of( array( 'p' => $sseo_p['editor_post'] ) ),
		'term:category'     => $sseo_ctx_of( array( 'cat' => $sseo_cat_id ) ),
		'archive:sseo_book' => $sseo_ctx_of( array( 'post_type' => 'sseo_book' ) ),
		'author'            => $sseo_ctx_of( array( 'author' => $sseo_au ) ),
		'date'              => $sseo_ctx_of( array( 'year' => $sseo_y ) ),
		'search'            => $sseo_ctx_of( array( 's' => 'x' ) ),
		'404'               => Simple_SEO_Context::from_query( $sseo_q ),
		''                  => new Simple_SEO_Context(),
	);
	foreach ( $sseo_slots as $sseo_slot => $sseo_ctx ) {
		$sseo_slot = (string) $sseo_slot; // PHP turns the key '404' into an integer.
		sseo_check( 'slot ' . ( '' === $sseo_slot ? '(none)' : $sseo_slot ), $sseo_slot === Simple_SEO_Titles::slot_for( $sseo_ctx ), Simple_SEO_Titles::slot_for( $sseo_ctx ) );
	}

	$sseo['posts']['bare'] = wp_insert_post( array( 'post_title' => 'Bare', 'post_status' => 'publish' ) );
	$sseo_p                = $sseo['posts'];
	sseo_check( 'a post without fields: the type template', "Editor post – {$sseo_site}" === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['editor_post'] ) ) ) );
	sseo_check( 'a post without fields: its excerpt as the description', 'sibling saved in the same request' === Simple_SEO_Titles::description( $sseo_ctx_of( array( 'p' => $sseo_p['editor_post'] ) ) ) );
	sseo_check( 'a post without excerpt or text: no description', '' === Simple_SEO_Titles::description( $sseo_ctx_of( array( 'p' => $sseo_p['bare'] ) ) ) );
	sseo_check( 'the own field wins over the template', 'Only for the edited post' === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['author_post'] ) ) ) );
	update_post_meta( $sseo_p['author_post'], '_simple_seo_title', '{title} custom {sep} {site}' );
	sseo_check( 'the own field may use variables', "Author post custom – {$sseo_site}" === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['author_post'] ) ) ) );
	sseo_check( 'a term: the taxonomy template with its field', 'Saved from the term form' === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'cat' => $sseo_cat_id ) ) ) );
	sseo_check( 'a term: description from the term description', 'Category about things' === Simple_SEO_Titles::description( $sseo_ctx_of( array( 'cat' => $sseo_cat_id ) ) ) );
	sseo_check( 'home: site and tagline', "{$sseo_site} – Just testing" === Simple_SEO_Titles::title( $sseo_ctx_of( array() ) ) && 'Just testing' === Simple_SEO_Titles::description( $sseo_ctx_of( array() ) ) );
	sseo_check( 'search results: phrase and site, no description', "Search results for \"x\" – {$sseo_site}" === Simple_SEO_Titles::title( $sseo_ctx_of( array( 's' => 'x' ) ) ) && '' === Simple_SEO_Titles::description( $sseo_ctx_of( array( 's' => 'x' ) ) ) );
	sseo_check( 'a page Simple SEO does not know: nothing', '' === Simple_SEO_Titles::title( new Simple_SEO_Context() ) );

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $sseo_p['front'] );
	sseo_check( 'a static front page uses the home template', "{$sseo_site} – Just testing" === Simple_SEO_Titles::title( $sseo_ctx_of( array() ) ) );
	update_post_meta( $sseo_p['front'], '_simple_seo_title', 'Welcome {sep} {site}' );
	sseo_check( "a static front page's own field wins", "Welcome – {$sseo_site}" === Simple_SEO_Titles::title( $sseo_ctx_of( array() ) ) );
	update_option( 'show_on_front', 'posts' );

	update_option(
		Simple_SEO_Titles::OPTION,
		array(
			'separator' => '|',
			'templates' => array(
				'post:post' => array( 'title' => '{site}: {title} {sep} {year}', 'description' => '' ),
				'search'    => array( 'title' => '' ),
			),
		)
	);
	sseo_check( 'a saved template and separator are used', "{$sseo_site}: Editor post | " . wp_date( 'Y' ) === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['editor_post'] ) ) ) );
	sseo_check( 'a template saved empty stays empty (no fallback to the default)', '' === Simple_SEO_Titles::title( $sseo_ctx_of( array( 's' => 'x' ) ) ) );
	sseo_check( 'a description template saved empty: no description, even with an excerpt', '' === Simple_SEO_Titles::description( $sseo_ctx_of( array( 'p' => $sseo_p['excerpt'] ) ) ) );
	sseo_check( 'templates not saved keep their default', 'Category about things' === Simple_SEO_Titles::description( $sseo_ctx_of( array( 'cat' => $sseo_cat_id ) ) ) );
	update_option( Simple_SEO_Titles::OPTION, array( 'separator' => 'XX' ) );
	sseo_check( 'an unknown stored separator falls back to the default', '–' === Simple_SEO_Titles::separator() );
	update_option( Simple_SEO_Titles::OPTION, array() );

	add_filter( 'simple_seo_title', static fn( string $title, Simple_SEO_Context $context ): string => $title . ' [' . $context->kind . ']', 10, 2 );
	sseo_check( 'simple_seo_title changes the result', "Editor post – {$sseo_site} [singular]" === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['editor_post'] ) ) ) );
	remove_all_filters( 'simple_seo_title' );

	$sseo_clean = Simple_SEO_Titles::sanitize(
		array(
			'separator' => 'x',
			'templates' => array(
				'post:post'     => array(
					'title'       => '{title} {page} {sep} {site}',
					'description' => '<b>Hi</b> {excerpt}',
				),
				'bad key!'      => array( 'title' => 'x' ),
				'term:category' => array( 'title' => '' ),
				'author'        => 'not an array',
				'search'        => array( 'title' => array( 'x' ) ),
			),
		)
	);
	sseo_check( 'sanitize: an unknown separator becomes the default', '–' === $sseo_clean['separator'] );
	sseo_check( 'sanitize: a template equal to the default is not stored', ! isset( $sseo_clean['templates']['post:post']['title'] ), $sseo_clean );
	sseo_check( 'sanitize: tags stripped from a template', 'Hi {excerpt}' === ( $sseo_clean['templates']['post:post']['description'] ?? null ), $sseo_clean );
	sseo_check( 'sanitize: an empty template is stored as empty', '' === ( $sseo_clean['templates']['term:category']['title'] ?? null ), $sseo_clean );
	sseo_check( 'sanitize: bad slots and values are dropped', ! isset( $sseo_clean['templates']['bad key!'] ) && ! isset( $sseo_clean['templates']['author'] ) && ! isset( $sseo_clean['templates']['search'] ), array_keys( $sseo_clean['templates'] ) );

	// Output through core: <title> and the head.
	$sseo['posts']['tricky'] = wp_insert_post( array( 'post_title' => 'Tom & "Jerry"', 'post_status' => 'publish', 'post_excerpt' => 'Short "desc" & <b>more</b>' ) );
	$sseo_p                  = $sseo['posts'];
	$sseo_saved_query        = $GLOBALS['wp_query'];
	$GLOBALS['wp_query']     = sseo_query( array( 'p' => $sseo_p['tricky'] ) );
	Simple_SEO_Context::reset();
	sseo_check( 'wp_get_document_title() returns our title, escaped', "Tom &amp; &quot;Jerry&quot; – {$sseo_site}" === wp_get_document_title(), wp_get_document_title() );
	sseo_check( 'wp_title gets the same title', "Tom &amp; &quot;Jerry&quot; – {$sseo_site}" === apply_filters( 'wp_title', 'theme title', '|', 'right' ) );
	$sseo_html = sseo_capture( array( Simple_SEO_Head::class, 'output' ) );
	sseo_check( 'the head has the description, escaped', str_contains( $sseo_html, '<meta name="description" content="Short &quot;desc&quot; &amp; more" />' ), $sseo_html );

	Simple_SEO_Context::reset();
	$GLOBALS['wp_query'] = sseo_query( array( 'p' => $sseo_p['excerpt'] ) );
	$sseo_queries        = $GLOBALS['wpdb']->num_queries;
	$sseo_ctx            = Simple_SEO_Context::current();
	Simple_SEO_Titles::title( $sseo_ctx );
	Simple_SEO_Titles::description( $sseo_ctx );
	sseo_check( 'title and description of a post cost no database query', 0 === $GLOBALS['wpdb']->num_queries - $sseo_queries, $GLOBALS['wpdb']->num_queries - $sseo_queries );

	add_filter( 'simple_seo_conflicting_plugin', static fn(): string => 'Yoast SEO' );
	Simple_SEO_Context::reset();
	$GLOBALS['wp_query'] = sseo_query( array( 'p' => $sseo_p['tricky'] ) );
	sseo_check( 'with another SEO plugin active, <title> is left to it', ! str_contains( wp_get_document_title(), '&quot;Jerry&quot;' ), wp_get_document_title() );
	sseo_check( 'with another SEO plugin active, the head gets nothing', '' === sseo_capture( array( Simple_SEO_Head::class, 'output' ) ) );
	set_current_screen( 'plugins' );
	wp_set_current_user( $sseo_u['administrator'] );
	sseo_check( 'the plugins screen says why', str_contains( sseo_capture( array( Simple_SEO_Admin::class, 'conflict_notice' ) ), 'Yoast SEO is active' ) );
	remove_all_filters( 'simple_seo_conflicting_plugin' );
	sseo_check( 'no notice without another SEO plugin', '' === sseo_capture( array( Simple_SEO_Admin::class, 'conflict_notice' ) ) );
	sseo_check( 'no other SEO plugin in the test site', '' === Simple_SEO_Plugin::conflicting_plugin() );

	$GLOBALS['wp_query'] = $sseo_saved_query;
	Simple_SEO_Context::reset();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Titles tab and the structure button' );

	Simple_SEO_Titles::add_admin_tab();
	Simple_SEO_Admin::register_settings();
	update_option( Simple_SEO_Titles::OPTION, array( 'separator' => '|', 'templates' => array( 'post:post' => array( 'title' => '{title} {page} {sep} {site}', 'description' => 'Own {excerpt}' ) ) ) );
	$sseo_stored = get_option( Simple_SEO_Titles::OPTION );
	sseo_check( 'saving the tab runs the sanitizer', '|' === $sseo_stored['separator'] && ! isset( $sseo_stored['templates']['post:post']['title'] ) && 'Own {excerpt}' === $sseo_stored['templates']['post:post']['description'], $sseo_stored );
	sseo_check( 'the tab saves with the plugin capability', Simple_SEO_Access::CAP_SETTINGS === apply_filters( 'option_page_capability_simple-seo-titles', 'manage_options' ) );

	$_GET['tab'] = 'titles';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'] );
	sseo_check( 'the Titles tab comes first in the tab bar', strpos( $sseo_html, '>Titles<' ) < strpos( $sseo_html, '>Access<' ) );
	sseo_check( 'fourteen separators, the saved one checked', 14 === substr_count( $sseo_html, 'name="simple_seo_titles[separator]"' ) && (bool) preg_match( '/value="\|"\s+checked=\'checked\'/', $sseo_html ) );
	sseo_check( 'rows for post types and taxonomies', str_contains( $sseo_html, 'simple_seo_titles[templates][post:post][title]' ) && str_contains( $sseo_html, 'simple_seo_titles[templates][term:sseo_genre][description]' ) );
	sseo_check( 'an archive row only for a type with an archive', str_contains( $sseo_html, '[archive:sseo_book]' ) && ! str_contains( $sseo_html, '[archive:post]' ) );
	sseo_check( 'author, date and search rows', str_contains( $sseo_html, '[templates][author]' ) && str_contains( $sseo_html, '[templates][date]' ) && str_contains( $sseo_html, '[templates][search]' ) );
	sseo_check( 'a saved template is shown, the default where none is saved', str_contains( $sseo_html, 'value="Own {excerpt}"' ) && str_contains( $sseo_html, 'value="{title} {page} {sep} {site}"' ) );
	sseo_check( 'structure buttons carry the default', str_contains( $sseo_html, 'class="button simple-seo-mask"' ) && str_contains( $sseo_html, 'data-mask="{excerpt}"' ) );
	sseo_check( 'no structure button where the default is empty (search description)', ! str_contains( $sseo_html, 'data-target="simple-seo-template-search-description"' ) );
	sseo_check( 'the variables are listed', str_contains( $sseo_html, '<code>{excerpt}</code>' ) && str_contains( $sseo_html, '<code>{sep}</code>' ) );
	update_option( Simple_SEO_Titles::OPTION, array() );

	wp_set_current_user( $sseo_ed );
	delete_post_meta( $sseo_p['editor_post'], '_simple_seo_title' );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['editor_post'] ) ) );
	sseo_check( 'meta box: the template as placeholder', str_contains( $sseo_html, 'placeholder="{title} {page} {sep} {site}"' ) );
	sseo_check( 'meta box: the structure button for the title', str_contains( $sseo_html, 'data-target="simple-seo-field-title" data-mask="{title} {page} {sep} {site}"' ) );

	// Rendered the way core renders it, with the postbox around: every id must be unique, or the
	// labels and the structure button point at the wrong element (core gives the heading "<box id>-title").
	set_current_screen( 'post' );
	$GLOBALS['wp_meta_boxes'] = array();
	Simple_SEO_Editor::add_meta_box( 'post', get_post( $sseo_p['editor_post'] ) );
	$sseo_html = sseo_capture( static fn() => do_meta_boxes( 'post', 'normal', get_post( $sseo_p['editor_post'] ) ) );
	preg_match_all( '/\sid="([^"]+)"/', $sseo_html, $sseo_ids );
	$sseo_dupes = array_keys( array_filter( array_count_values( $sseo_ids[1] ), static fn( int $n ): bool => $n > 1 ) );
	sseo_check( 'meta box inside the core postbox: every id is unique', str_contains( $sseo_html, 'id="simple-seo-field-title"' ) && array() === $sseo_dupes, $sseo_dupes );
	sseo_check( 'meta box: the search result preview shows what the page shows now', str_contains( $sseo_html, '<div class="simple-seo-snippet-title">Editor post – ' . esc_html( $sseo_site ) . '</div>' ) && str_contains( $sseo_html, '<div class="simple-seo-snippet-description">sibling saved in the same request</div>' ) );
	sseo_check( 'meta box: the preview comes before the fields, and no "Now" under the title and the description', strpos( $sseo_html, 'simple-seo-snippet' ) < strpos( $sseo_html, 'id="simple-seo-field-title"' ) && preg_match( '#id="simple-seo-field-title".*?</p>#s', $sseo_html, $sseo_m1 ) && ! str_contains( $sseo_m1[0], 'Now:' ) && preg_match( '#id="simple-seo-field-description".*?</p>#s', $sseo_html, $sseo_m2 ) && ! str_contains( $sseo_m2[0], 'Now:' ) );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['bare'] ) ) );
	sseo_check( 'meta box: no description, an empty one in the preview', str_contains( $sseo_html, '<div class="simple-seo-snippet-description"></div>' ) );
	$sseo['posts']['draft'] = wp_insert_post( array( 'post_title' => 'Auto Draft', 'post_status' => 'auto-draft' ) );
	$sseo_html              = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo['posts']['draft'] ) ) );
	sseo_check( 'meta box of a new post: no "Now", the preview with the home address', ! str_contains( $sseo_html, 'Now:' ) && str_contains( $sseo_html, 'simple-seo-mask' ) && str_contains( $sseo_html, 'simple-seo-snippet-url">' . esc_html( Simple_SEO_Snippet::display_url( home_url( '/' ) ) ) . '<' ) );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_term_fields( get_term( $sseo_cat_id ) ) );
	sseo_check( 'term screen: structure button and the preview of what the term shows now', str_contains( $sseo_html, 'data-mask="{excerpt}"' ) && str_contains( $sseo_html, '<div class="simple-seo-snippet-title">Saved from the term form</div>' ) && str_contains( $sseo_html, '<tr class="form-field simple-seo-snippet-row">' ) );

	foreach ( array( 'toplevel_page_simple-seo' => '', 'post.php' => 'post', 'edit.php' => 'edit-post', 'term.php' => 'edit-category' ) as $sseo_hook => $sseo_screen ) {
		wp_dequeue_script( 'simple-seo-admin' );
		wp_dequeue_style( 'simple-seo-admin' );
		if ( '' !== $sseo_screen ) {
			set_current_screen( $sseo_screen );
		}
		Simple_SEO_Admin::enqueue( $sseo_hook );
		$sseo_expected = 'edit.php' !== $sseo_hook;
		sseo_check( "admin.js and admin.css " . ( $sseo_expected ? 'load' : 'do not load' ) . " on {$sseo_hook}", $sseo_expected === wp_script_is( 'simple-seo-admin', 'enqueued' ) && $sseo_expected === wp_style_is( 'simple-seo-admin', 'enqueued' ) );
	}
	wp_dequeue_script( 'simple-seo-admin' );
	wp_dequeue_style( 'simple-seo-admin' );
	update_option( 'blogdescription', $sseo_tagline );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Indexing' );

	// set_current_screen() above makes is_admin() true, and WP_Query then never sees a home page.
	unset( $GLOBALS['current_screen'] );
	delete_option( Simple_SEO_Indexing::OPTION );
	update_option( 'blog_public', '1' );
	update_option( 'show_on_front', 'posts' );
	Simple_SEO_Settings::flush();
	delete_post_meta( $sseo_p['author_post'], '_simple_seo_title' );

	$sseo_post_meta = get_registered_meta_keys( 'post' );
	sseo_check( 'index, nofollow and canonical fields are registered', isset( $sseo_post_meta['_simple_seo_index'], $sseo_post_meta['_simple_seo_nofollow'], $sseo_post_meta['_simple_seo_canonical'] ) && 'boolean' === $sseo_post_meta['_simple_seo_nofollow']['type'] );
	sseo_check( 'indexing fields are for editors, not authors', Simple_SEO_Fields::user_can_edit( $sseo_ed, 'post', $sseo_p['author_post'], 'index' ) && ! Simple_SEO_Fields::user_can_edit( $sseo_au, 'post', $sseo_p['author_post'], 'canonical' ) );
	sseo_check( 'index: an unknown choice is dropped', '' === Simple_SEO_Fields::sanitize_value( 'index', 'maybe' ) && 'noindex' === Simple_SEO_Fields::sanitize_value( 'index', 'noindex' ) );

	$sseo_urls = array(
		'https://example.test/a/?b=1' => 'https://example.test/a/?b=1',
		'/about/'                     => home_url( '/about/' ),
		'javascript:alert(1)'         => '',
		'example.com/page'            => '',
		'//evil.test/x'               => '',
		'http://'                     => '',
		'ftp://example.test/file'     => '',
	);
	foreach ( $sseo_urls as $sseo_in => $sseo_out ) {
		sseo_check( "canonical field: {$sseo_in}", $sseo_out === Simple_SEO_Indexing::sanitize_url( $sseo_in ), Simple_SEO_Indexing::sanitize_url( $sseo_in ) );
	}

	$sseo_q404 = sseo_query( array( 'p' => 99999999 ) );
	$sseo_q404->set_404();
	$sseo_kinds = array(
		'a post'              => array( false, $sseo_ctx_of( array( 'p' => $sseo_p['editor_post'] ) ) ),
		'a page'              => array( false, $sseo_ctx_of( array( 'page_id' => $sseo_p['front'] ) ) ),
		'a category'          => array( false, $sseo_ctx_of( array( 'cat' => $sseo_cat_id ) ) ),
		'a tag'               => array( false, $sseo_ctx_of( array( 'tag' => 'sseo-tag' ) ) ),
		'the home page'       => array( false, $sseo_ctx_of( array() ) ),
		'a date archive'      => array( true, $sseo_ctx_of( array( 'year' => $sseo_y ) ) ),
		'an author archive'   => array( true, $sseo_ctx_of( array( 'author' => $sseo_au ) ) ),
		'search results'      => array( true, $sseo_ctx_of( array( 's' => 'x' ) ) ),
		'404'                 => array( true, Simple_SEO_Context::from_query( $sseo_q404 ) ),
	);
	foreach ( $sseo_kinds as $sseo_name => $sseo_case ) {
		sseo_check( "default: {$sseo_name} is " . ( $sseo_case[0] ? 'hidden' : 'shown' ), $sseo_case[0] === Simple_SEO_Indexing::is_noindex( $sseo_case[1] ) );
	}

	update_post_meta( $sseo_p['editor_post'], '_simple_seo_index', 'noindex' );
	sseo_check( "a post's own noindex wins over its type", Simple_SEO_Indexing::is_noindex( $sseo_ctx_of( array( 'p' => $sseo_p['editor_post'] ) ) ) );
	update_option( Simple_SEO_Indexing::OPTION, array( 'noindex' => array( 'post:sseo_book', 'term:category' ) ) );
	sseo_check( 'a hidden post type hides its posts', Simple_SEO_Indexing::is_noindex( $sseo_ctx_of( array( 'p' => $sseo_p['book'], 'post_type' => 'sseo_book' ) ) ) );
	update_post_meta( $sseo_p['book'], '_simple_seo_index', 'index' );
	sseo_check( "a post's own index wins over a hidden type", ! Simple_SEO_Indexing::is_noindex( $sseo_ctx_of( array( 'p' => $sseo_p['book'], 'post_type' => 'sseo_book' ) ) ) );
	sseo_check( 'a hidden taxonomy hides its terms', Simple_SEO_Indexing::is_noindex( $sseo_ctx_of( array( 'cat' => $sseo_cat_id ) ) ) );
	sseo_check( 'with the list saved, date archives follow it (shown now)', ! Simple_SEO_Indexing::is_noindex( $sseo_ctx_of( array( 'year' => $sseo_y ) ) ) );
	update_term_meta( $sseo_tag_id, '_simple_seo_index', 'noindex' );
	sseo_check( "a term's own noindex", Simple_SEO_Indexing::is_noindex( $sseo_ctx_of( array( 'tag' => 'sseo-tag' ) ) ) );
	update_option( 'blog_public', '0' );
	sseo_check( 'a site closed in Settings → Reading: everything counts as hidden', Simple_SEO_Indexing::is_noindex( $sseo_ctx_of( array( 'p' => $sseo_p['author_post'] ) ) ) );
	update_option( 'blog_public', '1' );
	add_filter( 'simple_seo_noindex', '__return_true' );
	sseo_check( 'simple_seo_noindex decides last', Simple_SEO_Indexing::is_noindex( $sseo_ctx_of( array( 'p' => $sseo_p['author_post'] ) ) ) );
	remove_all_filters( 'simple_seo_noindex' );
	update_post_meta( $sseo_p['author_post'], '_simple_seo_nofollow', '1' );
	sseo_check( 'nofollow from the field', Simple_SEO_Indexing::is_nofollow( $sseo_ctx_of( array( 'p' => $sseo_p['author_post'] ) ) ) && ! Simple_SEO_Indexing::is_nofollow( $sseo_ctx_of( array( 'p' => $sseo_p['bare'] ) ) ) );

	// <meta name="robots"> through core.
	$sseo_saved_query = $GLOBALS['wp_query'];
	$sseo_robots      = static function ( array $vars ): string {
		$GLOBALS['wp_query'] = sseo_query( $vars );
		Simple_SEO_Context::reset();
		return sseo_capture( 'wp_robots' );
	};
	$sseo_html = $sseo_robots( array( 'p' => $sseo_p['bare'] ) );
	sseo_check( 'robots of a plain post: no noindex, core keeps max-image-preview', ! str_contains( $sseo_html, 'noindex' ) && ! str_contains( $sseo_html, 'nofollow' ) && str_contains( $sseo_html, 'max-image-preview:large' ), $sseo_html );
	$sseo_html = $sseo_robots( array( 'p' => $sseo_p['editor_post'] ) );
	sseo_check( 'robots of a hidden post: noindex, follow', str_contains( $sseo_html, 'noindex' ) && (bool) preg_match( '/[\s,\']follow[,\s\']/', $sseo_html ) && ! str_contains( $sseo_html, 'nofollow' ), $sseo_html );
	$sseo_html = $sseo_robots( array( 'p' => $sseo_p['author_post'] ) );
	sseo_check( 'robots with nofollow only', str_contains( $sseo_html, 'nofollow' ) && ! str_contains( $sseo_html, 'noindex' ), $sseo_html );
	update_post_meta( $sseo_p['author_post'], '_simple_seo_index', 'noindex' );
	$sseo_html = $sseo_robots( array( 'p' => $sseo_p['author_post'] ) );
	sseo_check( 'robots with noindex and nofollow: no "follow" left', str_contains( $sseo_html, 'noindex' ) && str_contains( $sseo_html, 'nofollow' ) && ! preg_match( '/[\s,\']follow[,\s\']/', $sseo_html ), $sseo_html );
	// A theme or plugin that says "index, follow" outright must not contradict the page's own choice.
	add_filter( 'wp_robots', static fn( array $robots ): array => array_merge( $robots, array( 'index' => true, 'follow' => true ) ), 10 );
	$sseo_html = $sseo_robots( array( 'p' => $sseo_p['author_post'] ) );
	sseo_check( 'an explicit "index, follow" from elsewhere gives way to noindex, nofollow', ! preg_match( '/[\s,\'](?:index|follow)[,\s\']/', $sseo_html ) && str_contains( $sseo_html, 'noindex' ) && str_contains( $sseo_html, 'nofollow' ), $sseo_html );
	remove_all_filters( 'wp_robots', 10 );
	add_filter( 'wp_robots', 'wp_robots_noindex' );
	add_filter( 'wp_robots', 'wp_robots_noindex_embeds' );
	add_filter( 'wp_robots', 'wp_robots_noindex_search' );
	add_filter( 'wp_robots', 'wp_robots_max_image_preview_large' );
	delete_post_meta( $sseo_p['author_post'], '_simple_seo_index' );
	delete_post_meta( $sseo_p['author_post'], '_simple_seo_nofollow' );
	$sseo_html = $sseo_robots( array( 's' => 'x' ) );
	sseo_check( 'robots of search results: noindex', str_contains( $sseo_html, 'noindex' ), $sseo_html );
	add_filter( 'simple_seo_conflicting_plugin', static fn(): string => 'Yoast SEO' );
	$sseo_html = $sseo_robots( array( 'p' => $sseo_p['editor_post'] ) );
	sseo_check( 'with another SEO plugin active, robots are left to it', ! str_contains( $sseo_html, 'noindex' ), $sseo_html );
	remove_all_filters( 'simple_seo_conflicting_plugin' );

	// Canonical addresses.
	update_option( Simple_SEO_Indexing::OPTION, array( 'noindex' => array() ) );
	$sseo_canon = static fn( array $vars ): string => Simple_SEO_Indexing::canonical( $sseo_ctx_of( $vars ) );
	sseo_check( 'canonical of a post: its permalink', get_permalink( $sseo_p['bare'] ) === $sseo_canon( array( 'p' => $sseo_p['bare'] ) ) );
	sseo_check( 'canonical of part 2 of a split post', trailingslashit( get_permalink( $sseo_p['multi'] ) ) . '2/' === $sseo_canon( array( 'p' => $sseo_p['multi'], 'page' => 2 ) ), $sseo_canon( array( 'p' => $sseo_p['multi'], 'page' => 2 ) ) );
	sseo_check( 'canonical of page 2 of a category', trailingslashit( get_term_link( $sseo_cat_id ) ) . 'page/2/' === $sseo_canon( array( 'cat' => $sseo_cat_id, 'paged' => 2 ) ), $sseo_canon( array( 'cat' => $sseo_cat_id, 'paged' => 2 ) ) );
	sseo_check( 'canonical of page 3 of the home page', home_url( '/page/3/' ) === $sseo_canon( array( 'paged' => 3 ) ), $sseo_canon( array( 'paged' => 3 ) ) );
	sseo_check( 'canonical of a post type archive', get_post_type_archive_link( 'sseo_book' ) === $sseo_canon( array( 'post_type' => 'sseo_book' ) ) );
	sseo_check( 'canonical of an author archive (when shown)', get_author_posts_url( $sseo_au ) === $sseo_canon( array( 'author' => $sseo_au ) ) );
	sseo_check( 'canonical of a year archive (when shown)', get_year_link( $sseo_y ) === $sseo_canon( array( 'year' => $sseo_y ) ) );
	sseo_check( 'canonical of a month archive', get_month_link( $sseo_y, $sseo_n ) === $sseo_canon( array( 'm' => gmdate( 'Ym' ) ) ) );
	sseo_check( 'no canonical on search results and 404', '' === $sseo_canon( array( 's' => 'x' ) ) && '' === Simple_SEO_Indexing::canonical( Simple_SEO_Context::from_query( $sseo_q404 ) ) );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $sseo_p['front'] );
	update_option( 'page_for_posts', $sseo_p['blog'] );
	sseo_check( 'canonical of the posts page and of the static front page', get_permalink( $sseo_p['blog'] ) === $sseo_canon( array( 'page_id' => $sseo_p['blog'] ) ) && home_url( '/' ) === $sseo_canon( array() ) );
	update_option( 'show_on_front', 'posts' );
	update_post_meta( $sseo_p['bare'], '_simple_seo_canonical', 'https://example.test/original/' );
	sseo_check( 'the canonical field wins', 'https://example.test/original/' === $sseo_canon( array( 'p' => $sseo_p['bare'] ) ) );
	update_post_meta( $sseo_p['bare'], '_simple_seo_index', 'noindex' );
	sseo_check( 'a hidden page gets no canonical, even with the field', '' === $sseo_canon( array( 'p' => $sseo_p['bare'] ) ) );
	delete_post_meta( $sseo_p['bare'], '_simple_seo_index' );
	sseo_check( 'a draft has no canonical of its own', '' === Simple_SEO_Indexing::own_address( Simple_SEO_Context::for_post( get_post( $sseo['posts']['draft'] ) ) ) );
	add_filter( 'simple_seo_canonical', static fn( string $url ): string => $url . '#x' );
	sseo_check( 'simple_seo_canonical changes it', 'https://example.test/original/#x' === $sseo_canon( array( 'p' => $sseo_p['bare'] ) ) );
	remove_all_filters( 'simple_seo_canonical' );

	$GLOBALS['wp_query'] = sseo_query( array( 'p' => $sseo_p['bare'] ) );
	Simple_SEO_Context::reset();
	$sseo_html = sseo_capture( array( Simple_SEO_Head::class, 'output' ) );
	sseo_check( 'the head has the canonical link', str_contains( $sseo_html, '<link rel="canonical" href="https://example.test/original/" />' ), $sseo_html );
	delete_post_meta( $sseo_p['bare'], '_simple_seo_canonical' );

	sseo_check( "core's rel_canonical is on before the takeover", (bool) has_action( 'wp_head', 'rel_canonical' ) );
	add_filter( 'simple_seo_conflicting_plugin', static fn(): string => 'Yoast SEO' );
	Simple_SEO_Indexing::take_over();
	sseo_check( "with another SEO plugin active, core's canonical stays", (bool) has_action( 'wp_head', 'rel_canonical' ) );
	sseo_check( 'with another SEO plugin active, wp-loc decides its canonical itself', false === apply_filters( 'wp_loc_canonical_already_handled', false ) );
	remove_all_filters( 'simple_seo_conflicting_plugin' );
	Simple_SEO_Indexing::take_over();
	sseo_check( "the takeover removes core's rel_canonical (ours replaces it)", ! has_action( 'wp_head', 'rel_canonical' ) );
	add_action( 'wp_head', 'rel_canonical' );
	sseo_check( 'wp-loc is told the canonical is handled', true === apply_filters( 'wp_loc_canonical_already_handled', false ) );

	// Attachment pages.
	$sseo['posts']['att_parent'] = wp_insert_post( array( 'post_title' => 'Gallery', 'post_status' => 'publish' ) );
	$sseo['posts']['att_draft']  = wp_insert_post( array( 'post_title' => 'Draft gallery', 'post_status' => 'draft' ) );
	$sseo['posts']['att']        = wp_insert_attachment( array( 'post_title' => 'Image', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), 'sseo-image.jpg', $sseo['posts']['att_parent'] );
	$sseo['posts']['att_loose']  = wp_insert_attachment( array( 'post_title' => 'Loose', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), 'sseo-loose.jpg' );
	$sseo['posts']['att_hidden'] = wp_insert_attachment( array( 'post_title' => 'Hidden', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), 'sseo-hidden.jpg', $sseo['posts']['att_draft'] );
	$sseo_p                      = $sseo['posts'];
	delete_option( Simple_SEO_Indexing::OPTION );
	sseo_check( 'default: attachment pages go to the file (core does it, the option reads 0)', Simple_SEO_Indexing::ATTACHMENTS_FILE === Simple_SEO_Indexing::attachments_mode() && '0' === get_option( 'wp_attachment_pages_enabled' ) && '' === Simple_SEO_Indexing::attachment_redirect( get_post( $sseo_p['att'] ) ) );
	sseo_check( 'default: a kept attachment page would be hidden', Simple_SEO_Indexing::is_noindex( Simple_SEO_Context::for_post( get_post( $sseo_p['att'] ) ) ) );
	update_option( Simple_SEO_Indexing::OPTION, array( 'attachments' => Simple_SEO_Indexing::ATTACHMENTS_PARENT ) );
	sseo_check( 'parent mode: to the published parent', get_permalink( $sseo_p['att_parent'] ) === Simple_SEO_Indexing::attachment_redirect( get_post( $sseo_p['att'] ) ) && '1' === get_option( 'wp_attachment_pages_enabled' ) );
	sseo_check( 'parent mode: no parent — to the file', wp_get_attachment_url( $sseo_p['att_loose'] ) === Simple_SEO_Indexing::attachment_redirect( get_post( $sseo_p['att_loose'] ) ) && str_ends_with( wp_get_attachment_url( $sseo_p['att_loose'] ), 'sseo-loose.jpg' ) );
	sseo_check( 'parent mode: a draft parent is not revealed — to the file', wp_get_attachment_url( $sseo_p['att_hidden'] ) === Simple_SEO_Indexing::attachment_redirect( get_post( $sseo_p['att_hidden'] ) ) );
	update_option( Simple_SEO_Indexing::OPTION, array( 'attachments' => Simple_SEO_Indexing::ATTACHMENTS_KEEP ) );
	sseo_check( 'keep mode: no redirect, attachment pages on for core', '' === Simple_SEO_Indexing::attachment_redirect( get_post( $sseo_p['att'] ) ) && '1' === get_option( 'wp_attachment_pages_enabled' ) );
	add_filter( 'simple_seo_conflicting_plugin', static fn(): string => 'Yoast SEO' );
	sseo_check( 'with another SEO plugin active, the core option is left alone', 'stored' === Simple_SEO_Indexing::attachment_pages_option( 'stored' ) );
	remove_all_filters( 'simple_seo_conflicting_plugin' );

	// Settings tab.
	delete_option( Simple_SEO_Indexing::OPTION );
	Simple_SEO_Indexing::add_admin_tab();
	Simple_SEO_Admin::register_settings();
	update_option( Simple_SEO_Indexing::OPTION, array( 'noindex' => array( 'post:ghost', 'date', 'bad key!' ) ) );
	sseo_check( 'a list given directly keeps well-formed slots only', array( 'post:ghost', 'date' ) === Simple_SEO_Settings::value( Simple_SEO_Indexing::OPTION, 'noindex' ), Simple_SEO_Settings::value( Simple_SEO_Indexing::OPTION, 'noindex' ) );
	update_option(
		Simple_SEO_Indexing::OPTION,
		array(
			'index'       => array(
				'post:post'     => '0',
				'date'          => '1',
				'term:category' => '1',
				'nonsense key'  => '0',
			),
			'attachments' => 'everywhere',
		)
	);
	$sseo_stored = get_option( Simple_SEO_Indexing::OPTION );
	sseo_check( 'the form: unchecked hides, checked shows', in_array( 'post:post', $sseo_stored['noindex'], true ) && ! in_array( 'date', $sseo_stored['noindex'], true ), $sseo_stored );
	sseo_check( 'the form: a slot not on the form keeps its state', in_array( 'post:ghost', $sseo_stored['noindex'], true ), $sseo_stored );
	sseo_check( 'the form: bad keys and modes are dropped', ! in_array( 'nonsense key', $sseo_stored['noindex'], true ) && Simple_SEO_Indexing::ATTACHMENTS_FILE === $sseo_stored['attachments'], $sseo_stored );
	sseo_check( 'the tab saves with the plugin capability', Simple_SEO_Access::CAP_SETTINGS === apply_filters( 'option_page_capability_simple-seo-indexing', 'manage_options' ) );

	wp_set_current_user( $sseo_u['administrator'] );
	update_option( 'blog_public', '0' );
	$_GET['tab'] = 'indexing';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'] );
	update_option( 'blog_public', '1' );
	sseo_check( 'the Indexing tab sits between Titles and Access', strpos( $sseo_html, '>Titles<' ) < strpos( $sseo_html, '>Indexing<' ) && strpos( $sseo_html, '>Indexing<' ) < strpos( $sseo_html, '>Access<' ) );
	sseo_check( 'three attachment modes, the saved one checked', 3 === substr_count( $sseo_html, 'name="simple_seo_indexing[attachments]"' ) && (bool) preg_match( '/value="file"\s+checked=\'checked\'/', $sseo_html ) );
	sseo_check( 'every slot sends 0 before its checkbox', str_contains( $sseo_html, '<input type="hidden" name="simple_seo_indexing[index][post:post]" value="0">' ) && str_contains( $sseo_html, 'name="simple_seo_indexing[index][post:attachment]"' ) );
	sseo_check( 'a hidden kind is unchecked, a shown one checked', ! preg_match( '/\[index\]\[post:post\]" value="1"\s+checked/', $sseo_html ) && (bool) preg_match( '/\[index\]\[term:category\]" value="1"\s+checked/', $sseo_html ) );
	sseo_check( 'no switch for search results', ! str_contains( $sseo_html, '[index][search]' ) );
	sseo_check( 'a site closed in Settings → Reading is pointed out', str_contains( $sseo_html, 'options-reading.php' ) );

	// Editor.
	delete_option( Simple_SEO_Indexing::OPTION );
	wp_set_current_user( $sseo_ed );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['bare'] ) ) );
	sseo_check( 'meta box: an Indexing heading before the indexing fields', str_contains( $sseo_html, '>Indexing</h4>' ) && strpos( $sseo_html, '>Indexing</h4>' ) < strpos( $sseo_html, 'name="simple_seo[index]"' ) );
	sseo_check( 'meta box: what search engines do now, and the canonical', str_contains( $sseo_html, 'Now: shown in search results' ) && str_contains( $sseo_html, 'Now: ' . get_permalink( $sseo_p['bare'] ) ) );
	$sseo_select = preg_match( '#<select id="simple-seo-field-index"[^>]*>(.*?)</select>#s', $sseo_html, $sseo_m ) ? $sseo_m[1] : '';
	sseo_check( 'meta box: three choices for search engines', 3 === substr_count( $sseo_select, '<option value=' ) );
	wp_set_current_user( $sseo_au );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['author_post'] ) ) );
	sseo_check( 'meta box of an author: no indexing fields, no heading', ! str_contains( $sseo_html, 'Indexing' ) && ! str_contains( $sseo_html, 'simple_seo[index]' ) );
	wp_set_current_user( $sseo_ed );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_term_fields( get_term( $sseo_cat_id ) ) );
	sseo_check( 'term screen: indexing fields under their heading', str_contains( $sseo_html, '>Indexing</h3>' ) && str_contains( $sseo_html, 'name="simple_seo[canonical]"' ) );

	delete_option( Simple_SEO_Indexing::OPTION );
	delete_term_meta( $sseo_tag_id, '_simple_seo_index' );
	delete_post_meta( $sseo_p['editor_post'], '_simple_seo_index' );
	$GLOBALS['wp_query'] = $sseo_saved_query;
	Simple_SEO_Context::reset();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Social networks' );

	unset( $GLOBALS['current_screen'] );
	delete_option( Simple_SEO_Social::OPTION );
	delete_option( Simple_SEO_Indexing::OPTION );
	update_option( 'show_on_front', 'posts' );
	Simple_SEO_Settings::flush();

	$sseo_image = static function ( string $file, string $mime, int $width, int $height, string $alt = '' ) use ( &$sseo ): int {
		$id = wp_insert_attachment( array( 'post_title' => $file, 'post_mime_type' => $mime, 'post_status' => 'inherit' ), $file );
		if ( $width ) {
			wp_update_attachment_metadata( $id, array( 'width' => $width, 'height' => $height, 'file' => $file ) );
		}
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		$sseo['posts'][ 'img_' . $file ] = $id;
		return $id;
	};
	$sseo_og      = $sseo_image( 'sseo-og.jpg', 'image/jpeg', 1200, 630, 'Alt "text"' );
	$sseo_feat    = $sseo_image( 'sseo-featured.png', 'image/png', 800, 600 );
	$sseo_default = $sseo_image( 'sseo-default.jpg', 'image/jpeg', 1200, 630 );
	$sseo_pdf     = $sseo_image( 'sseo-doc.pdf', 'application/pdf', 0, 0 );
	$sseo_p       = $sseo['posts'];
	$sseo_tags    = static fn( Simple_SEO_Context $context ): array => array_map( static fn( array $tag ): string => (string) $tag['attributes']['content'], Simple_SEO_Social::head_tags( $context ) );

	$sseo_post_meta = get_registered_meta_keys( 'post' );
	sseo_check( 'social fields are registered, the image as an integer', isset( $sseo_post_meta['_simple_seo_og_title'], $sseo_post_meta['_simple_seo_og_description'] ) && 'integer' === ( $sseo_post_meta['_simple_seo_og_image']['type'] ?? '' ) );
	sseo_check( 'social fields are for authors too', Simple_SEO_Fields::user_can_edit( $sseo_au, 'post', $sseo_p['author_post'], 'og_image' ) );
	sseo_check( 'image field: an image attachment is kept', $sseo_og === Simple_SEO_Fields::sanitize_value( 'og_image', (string) $sseo_og ) );
	sseo_check( 'image field: a PDF, a post, a missing ID and text are dropped', '' === Simple_SEO_Fields::sanitize_value( 'og_image', $sseo_pdf ) && '' === Simple_SEO_Fields::sanitize_value( 'og_image', $sseo_p['bare'] ) && '' === Simple_SEO_Fields::sanitize_value( 'og_image', 99999999 ) && '' === Simple_SEO_Fields::sanitize_value( 'og_image', 'abc' ) );
	wp_set_current_user( $sseo_ed );
	Simple_SEO_Fields::save( 'post', $sseo_p['bare'], array( 'og_image' => (string) $sseo_og ) );
	sseo_check( 'image field: saved as the ID', (string) $sseo_og === Simple_SEO_Fields::post_value( $sseo_p['bare'], 'og_image' ) );
	Simple_SEO_Fields::save( 'post', $sseo_p['bare'], array( 'og_image' => '0' ) );
	sseo_check( 'image field: 0 deletes the row', ! metadata_exists( 'post', $sseo_p['bare'], '_simple_seo_og_image' ) );

	foreach ( array( 'en_US' => 'en_US', 'uk' => 'uk_UA', 'de_DE_formal' => 'de_DE', 'pt_BR' => 'pt_BR', 'fi' => 'fi_FI', 'el' => 'el_GR', '' => 'en_US' ) as $sseo_in => $sseo_out ) {
		sseo_check( "og:locale of '{$sseo_in}'", $sseo_out === Simple_SEO_Social::og_locale( (string) $sseo_in ), Simple_SEO_Social::og_locale( (string) $sseo_in ) );
	}
	foreach ( array( '@kaplia' => '@kaplia', 'kaplia' => '@kaplia', 'https://x.com/kaplia' => '@kaplia', 'twitter.com/@kaplia?s=1' => '@kaplia', 'bad handle!' => '', 'a_name_much_too_long' => '', 'https://evil.test/kaplia' => '' ) as $sseo_in => $sseo_out ) {
		sseo_check( "X account '{$sseo_in}'", $sseo_out === Simple_SEO_Social::sanitize_account( $sseo_in ), Simple_SEO_Social::sanitize_account( $sseo_in ) );
	}
	$sseo_clean = Simple_SEO_Social::sanitize( array( 'default_image' => $sseo_pdf, 'x_account' => 'x.com/kaplia' ) );
	sseo_check( 'settings: a PDF is no default image', 0 === $sseo_clean['default_image'] && '@kaplia' === $sseo_clean['x_account'], $sseo_clean );

	update_post_meta( $sseo_p['bare'], '_thumbnail_id', $sseo_feat );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'p' => $sseo_p['bare'] ) ) );
	sseo_check( 'a post: article, SEO title, permalink, site name', 'article' === $sseo_t['og:type'] && "Bare – {$sseo_site}" === $sseo_t['og:title'] && get_permalink( $sseo_p['bare'] ) === $sseo_t['og:url'] && $sseo_site === $sseo_t['og:site_name'], $sseo_t );
	sseo_check( 'a post: publication and modification times', (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $sseo_t['article:published_time'] ?? '' ) && isset( $sseo_t['article:modified_time'] ), $sseo_t );
	sseo_check( 'a post: the featured image with its size and type', str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-featured.png' ) && '800' === ( $sseo_t['og:image:width'] ?? '' ) && '600' === ( $sseo_t['og:image:height'] ?? '' ) && 'image/png' === ( $sseo_t['og:image:type'] ?? '' ), $sseo_t );
	sseo_check( 'a post with an image: the large X card', 'summary_large_image' === $sseo_t['twitter:card'] && ! isset( $sseo_t['twitter:site'] ) );
	sseo_check( 'og:locale of the site', 'en_US' === $sseo_t['og:locale'] );

	update_post_meta( $sseo_p['bare'], '_simple_seo_og_image', $sseo_og );
	update_post_meta( $sseo_p['bare'], '_simple_seo_og_title', '{title} for social' );
	update_post_meta( $sseo_p['bare'], '_simple_seo_og_description', 'Shared {sep} {site}' );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'p' => $sseo_p['bare'] ) ) );
	sseo_check( 'own social image wins over the featured image, with its alt text', str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-og.jpg' ) && 'Alt "text"' === ( $sseo_t['og:image:alt'] ?? '' ), $sseo_t );
	sseo_check( 'own social title and description, with variables', 'Bare for social' === $sseo_t['og:title'] && "Shared – {$sseo_site}" === $sseo_t['og:description'], $sseo_t );

	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'p' => $sseo_p['editor_post'] ) ) );
	sseo_check( 'no image anywhere: no og:image, the small X card', ! isset( $sseo_t['og:image'] ) && 'summary' === $sseo_t['twitter:card'], $sseo_t );
	sseo_check( 'og:description falls back to the meta description', 'sibling saved in the same request' === $sseo_t['og:description'] );
	update_option( Simple_SEO_Social::OPTION, array( 'default_image' => $sseo_default, 'x_account' => '@kaplia' ) );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'p' => $sseo_p['editor_post'] ) ) );
	sseo_check( 'the default image from the settings', str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-default.jpg' ), $sseo_t );
	sseo_check( 'twitter:site from the settings', '@kaplia' === ( $sseo_t['twitter:site'] ?? '' ) );

	update_post_meta( $sseo_p['secret'], '_thumbnail_id', $sseo_feat );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) );
	sseo_check( 'a password-protected post does not show its featured image or text', str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-default.jpg' ) && '' === ( $sseo_t['og:description'] ?? '' ), $sseo_t );

	$sseo_t = $sseo_tags( $sseo_ctx_of( array() ) );
	sseo_check( 'home: website, the home address', 'website' === $sseo_t['og:type'] && home_url( '/' ) === $sseo_t['og:url'] && ! isset( $sseo_t['article:published_time'] ), $sseo_t );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $sseo_p['front'] );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array() ) );
	sseo_check( 'a static front page is a website, not an article', 'website' === $sseo_t['og:type'] && home_url( '/' ) === $sseo_t['og:url'], $sseo_t );
	update_option( 'show_on_front', 'posts' );
	update_term_meta( $sseo_cat_id, '_simple_seo_og_image', $sseo_og );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'cat' => $sseo_cat_id ) ) );
	sseo_check( 'a term: website, its title and its own image', 'website' === $sseo_t['og:type'] && 'Saved from the term form' === $sseo_t['og:title'] && str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-og.jpg' ), $sseo_t );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'year' => $sseo_y ) ) );
	sseo_check( 'a hidden page still has og:url, its own address', get_year_link( $sseo_y ) === ( $sseo_t['og:url'] ?? '' ), $sseo_t );
	sseo_check( 'no social tags on search results and 404', array() === Simple_SEO_Social::head_tags( $sseo_ctx_of( array( 's' => 'x' ) ) ) && array() === Simple_SEO_Social::head_tags( Simple_SEO_Context::from_query( $sseo_q404 ) ) );

	add_filter( 'locale', static fn(): string => 'uk' );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'p' => $sseo_p['bare'] ) ) );
	remove_all_filters( 'locale' );
	sseo_check( 'og:locale follows the locale of the page (wp-loc switches it)', 'uk_UA' === $sseo_t['og:locale'], $sseo_t['og:locale'] );
	add_filter( 'simple_seo_social_image', static fn(): int => $sseo_pdf );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'p' => $sseo_p['bare'] ) ) );
	remove_all_filters( 'simple_seo_social_image' );
	sseo_check( 'simple_seo_social_image cannot slip in a non-image', ! isset( $sseo_t['og:image'] ) );
	add_filter( 'simple_seo_social_image', static fn(): int => $sseo_pdf );
	sseo_check( 'image_id() gives 0 for a non-image, whoever chose it', 0 === Simple_SEO_Social::image_id( $sseo_ctx_of( array( 'p' => $sseo_p['bare'] ) ) ) );
	remove_all_filters( 'simple_seo_social_image' );

	$GLOBALS['wp_query'] = sseo_query( array( 'p' => $sseo_p['tricky'] ) );
	Simple_SEO_Context::reset();
	$sseo_html = sseo_capture( array( Simple_SEO_Head::class, 'output' ) );
	sseo_check( 'the head: og:title as a property, escaped', str_contains( $sseo_html, '<meta property="og:title" content="Tom &amp; &quot;Jerry&quot; – ' . esc_attr( $sseo_site ) . '" />' ), $sseo_html );
	sseo_check( 'the head: the X card as a name', str_contains( $sseo_html, '<meta name="twitter:card" content="summary_large_image" />' ) );
	sseo_check( 'the head: tags in order — description, canonical, then social', strpos( $sseo_html, 'name="description"' ) < strpos( $sseo_html, 'rel="canonical"' ) && strpos( $sseo_html, 'rel="canonical"' ) < strpos( $sseo_html, 'og:locale' ), $sseo_html );

	delete_option( Simple_SEO_Social::OPTION );
	$GLOBALS['wp_query'] = sseo_query( array( 'p' => $sseo_p['editor_post'] ) );
	$sseo_queries        = $GLOBALS['wpdb']->num_queries;
	Simple_SEO_Social::head_tags( Simple_SEO_Context::from_query( $GLOBALS['wp_query'] ) );
	sseo_check( 'social tags of a post without images cost no database query', 0 === $GLOBALS['wpdb']->num_queries - $sseo_queries, $GLOBALS['wpdb']->num_queries - $sseo_queries );
	$GLOBALS['wp_query'] = $sseo_saved_query;
	Simple_SEO_Context::reset();

	// Settings tab and the editor.
	Simple_SEO_Social::add_admin_tab();
	Simple_SEO_Admin::register_settings();
	update_option( Simple_SEO_Social::OPTION, array( 'default_image' => (string) $sseo_default, 'x_account' => 'https://x.com/kaplia' ) );
	$sseo_stored = get_option( Simple_SEO_Social::OPTION );
	sseo_check( 'saving the tab runs the sanitizer', $sseo_default === $sseo_stored['default_image'] && '@kaplia' === $sseo_stored['x_account'], $sseo_stored );
	wp_set_current_user( $sseo_u['administrator'] );
	$_GET['tab'] = 'social';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'] );
	sseo_check( 'the Social networks tab sits between Titles and Indexing', strpos( $sseo_html, '>Titles<' ) < strpos( $sseo_html, '>Social networks<' ) && strpos( $sseo_html, '>Social networks<' ) < strpos( $sseo_html, '>Indexing<' ) );
	sseo_check( 'the default image picker shows the saved image', str_contains( $sseo_html, 'name="simple_seo_social[default_image]" value="' . $sseo_default . '"' ) && str_contains( $sseo_html, 'sseo-default.jpg' ) && ! str_contains( $sseo_html, 'class="simple-seo-image-preview" hidden' ) );
	sseo_check( 'the X account field', str_contains( $sseo_html, 'name="simple_seo_social[x_account]" value="@kaplia"' ) );

	wp_set_current_user( $sseo_au );
	delete_post_meta( $sseo_p['author_post'], '_simple_seo_og_image' );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['author_post'] ) ) );
	sseo_check( 'meta box of an author: the social networks group with its fields', str_contains( $sseo_html, '>Social networks</h4>' ) && str_contains( $sseo_html, 'name="simple_seo[og_title]"' ) && str_contains( $sseo_html, 'name="simple_seo[og_image]"' ) );
	sseo_check( 'meta box: no image yet — preview and remove hidden, no display style to override that', str_contains( $sseo_html, 'class="simple-seo-image-preview" hidden>' ) && str_contains( $sseo_html, 'class="button-link simple-seo-image-remove" hidden>' ) );
	sseo_check( 'meta box: the social title shows what is shared now', str_contains( $sseo_html, 'Now: Author post – ' . esc_html( $sseo_site ) ) );
	wp_set_current_user( $sseo_ed );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['bare'] ) ) );
	sseo_check( 'meta box of an editor: social before indexing, the chosen image shown', strpos( $sseo_html, '>Social networks</h4>' ) < strpos( $sseo_html, '>Indexing</h4>' ) && str_contains( $sseo_html, 'value="' . $sseo_og . '"' ) && str_contains( $sseo_html, 'sseo-og.jpg' ) );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_term_fields( get_term( $sseo_cat_id ) ) );
	sseo_check( 'term screen: the social networks group', str_contains( $sseo_html, '>Social networks</h3>' ) && str_contains( $sseo_html, 'name="simple_seo[og_image]"' ) );
	Simple_SEO_Admin::enqueue( 'toplevel_page_simple-seo' );
	sseo_check( 'the media library is loaded where the image pickers are', did_action( 'wp_enqueue_media' ) > 0 );
	unset( $GLOBALS['current_screen'] );
	delete_option( Simple_SEO_Social::OPTION );
	delete_term_meta( $sseo_cat_id, '_simple_seo_og_image' );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Special pages' );

	unset( $GLOBALS['current_screen'] );
	delete_option( Simple_SEO_Special::OPTION );
	update_option( 'show_on_front', 'posts' );
	Simple_SEO_Settings::flush();
	Simple_SEO_Context::reset();
	$sseo_404 = static fn(): Simple_SEO_Context => Simple_SEO_Context::from_query( $sseo_q404 );

	sseo_check( 'only the special pages row, the breadcrumb labels and the llms.txt text are kept per language', array( Simple_SEO_Special::OPTION, Simple_SEO_Breadcrumbs::LABELS, Simple_SEO_Llms::TEXT ) === Simple_SEO_Settings::localized(), Simple_SEO_Settings::localized() );
	Simple_SEO_Settings::install();
	sseo_check( 'install() creates the special pages row', array() === get_option( Simple_SEO_Special::OPTION, null ) );

	sseo_check( 'a protected post takes the password slot for its templates, its type for the rest', 'password' === Simple_SEO_Titles::template_slot( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) ) && 'post:post' === Simple_SEO_Titles::slot_for( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) ) );
	sseo_check( 'other pages keep their slot for templates', 'post:post' === Simple_SEO_Titles::template_slot( $sseo_ctx_of( array( 'p' => $sseo_p['bare'] ) ) ) && 'home' === Simple_SEO_Titles::template_slot( $sseo_ctx_of( array() ) ) );
	sseo_check( 'defaults: 404 has the phrase and the site, no description', "Page not found – {$sseo_site}" === Simple_SEO_Titles::title( $sseo_404() ) && '' === Simple_SEO_Titles::description( $sseo_404() ) );
	sseo_check( 'defaults: a protected post has its title and the site, no description', "Secret – {$sseo_site}" === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) ) && '' === Simple_SEO_Titles::description( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) ) );

	$sseo_clean = Simple_SEO_Special::sanitize(
		array(
			'home'     => array(
				'title'       => '{site} {page} {sep} {tagline}',
				'description' => '<b>Hi</b> {tagline}',
				'image'       => (string) $sseo_og,
			),
			'404'      => array(
				'title' => '',
				'image' => $sseo_og,
			),
			'password' => array(
				'title' => array( 'x' ),
				'image' => $sseo_pdf,
			),
			'search'   => array( 'title' => 'x' ),
		)
	);
	sseo_check( 'sanitize: a template equal to the default is not stored', ! isset( $sseo_clean['home']['title'] ), $sseo_clean );
	sseo_check( 'sanitize: tags stripped, the image kept as its ID', 'Hi {tagline}' === ( $sseo_clean['home']['description'] ?? null ) && $sseo_og === ( $sseo_clean['home']['image'] ?? null ), $sseo_clean );
	sseo_check( 'sanitize: an empty template is stored as empty', '' === ( $sseo_clean['404']['title'] ?? null ), $sseo_clean );
	sseo_check( 'sanitize: no image for 404, no PDF as an image', ! isset( $sseo_clean['404']['image'] ) && ! isset( $sseo_clean['password'] ), $sseo_clean );
	sseo_check( 'sanitize: other slots are not kept here', ! isset( $sseo_clean['search'] ), $sseo_clean );
	$sseo_clean = Simple_SEO_Titles::sanitize( array( 'templates' => array( 'home' => array( 'title' => 'x' ), '404' => array( 'title' => 'x' ), 'password' => array( 'title' => 'x' ) ) ) );
	sseo_check( 'the Titles row keeps no special page templates', array() === $sseo_clean['templates'], $sseo_clean );

	Simple_SEO_Special::add_admin_tab();
	Simple_SEO_Admin::register_settings();
	update_option(
		Simple_SEO_Special::OPTION,
		array(
			'home'     => array(
				'title'       => 'Home of {site}',
				'description' => 'Welcome home',
				'image'       => (string) $sseo_og,
			),
			'404'      => array(
				'title'       => 'Lost {sep} {site}',
				'description' => '',
				'image'       => (string) $sseo_og,
			),
			'password' => array(
				'title'       => 'Locked: {title}',
				'description' => 'Ask for the password',
				'image'       => (string) $sseo_default,
			),
		)
	);
	$sseo_stored = get_option( Simple_SEO_Special::OPTION );
	sseo_check( 'saving the tab runs the sanitizer', $sseo_og === ( $sseo_stored['home']['image'] ?? null ) && ! isset( $sseo_stored['404']['image'] ), $sseo_stored );
	sseo_check( 'image_id() of the home page', $sseo_og === Simple_SEO_Special::image_id( 'home' ) );
	$sseo_raw = static fn(): array => array(
		'404'    => array( 'image' => $sseo_og ),
		'search' => array( 'image' => $sseo_og ),
	);
	add_filter( 'pre_option_' . Simple_SEO_Special::OPTION, $sseo_raw );
	Simple_SEO_Settings::flush();
	sseo_check( 'image_id() of 404 and of other slots is 0, even with an image in the row', 0 === Simple_SEO_Special::image_id( '404' ) && 0 === Simple_SEO_Special::image_id( 'search' ) );
	remove_filter( 'pre_option_' . Simple_SEO_Special::OPTION, $sseo_raw );
	Simple_SEO_Settings::flush();

	sseo_check( 'home: the saved title and description', "Home of {$sseo_site}" === Simple_SEO_Titles::title( $sseo_ctx_of( array() ) ) && 'Welcome home' === Simple_SEO_Titles::description( $sseo_ctx_of( array() ) ) );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array() ) );
	sseo_check( 'home: its image for social networks', str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-og.jpg' ) && "Home of {$sseo_site}" === $sseo_t['og:title'], $sseo_t );
	sseo_check( '404: the saved title; saved empty description gives no description tag', "Lost – {$sseo_site}" === Simple_SEO_Titles::title( $sseo_404() ) && array() === Simple_SEO_Titles::head_tags( $sseo_404() ) );
	sseo_check( '404: still no social tags', array() === Simple_SEO_Social::head_tags( $sseo_404() ) );

	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) );
	sseo_check( 'a protected post: the password templates', 'Locked: Secret' === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) ) && 'Ask for the password' === ( $sseo_t['og:description'] ?? '' ), $sseo_t );
	sseo_check( 'a protected post: the password image, not its featured image', str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-default.jpg' ), $sseo_t );
	update_option( Simple_SEO_Titles::OPTION, array( 'templates' => array( 'post:post' => array( 'title' => 'POST {title}' ) ) ) );
	sseo_check( 'a protected post ignores its post type template', 'Locked: Secret' === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) ) );
	update_post_meta( $sseo_p['secret'], '_simple_seo_title', 'Own {title}' );
	sseo_check( "a protected post's own SEO title still wins", 'Own Secret' === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) ) );
	delete_post_meta( $sseo_p['secret'], '_simple_seo_title' );
	add_filter( 'post_password_required', '__return_false' );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) );
	sseo_check( 'with the password entered: the post type template and the featured image again', 'POST Secret' === Simple_SEO_Titles::title( $sseo_ctx_of( array( 'p' => $sseo_p['secret'] ) ) ) && str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-featured.png' ), $sseo_t );
	remove_filter( 'post_password_required', '__return_false' );
	update_option( Simple_SEO_Titles::OPTION, array() );

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $sseo_p['front'] );
	$sseo_t = $sseo_tags( $sseo_ctx_of( array() ) );
	sseo_check( 'a static front page: own title, the home description and image where its fields are empty', "Welcome – {$sseo_site}" === Simple_SEO_Titles::title( $sseo_ctx_of( array() ) ) && 'Welcome home' === Simple_SEO_Titles::description( $sseo_ctx_of( array() ) ) && str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-og.jpg' ), $sseo_t );

	// The tab.
	wp_set_current_user( $sseo_u['administrator'] );
	Simple_SEO_Titles::add_admin_tab();
	Simple_SEO_Social::add_admin_tab();
	$_GET['tab'] = 'special';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'the Special pages tab sits between Titles and Social networks', strpos( $sseo_html, '>Titles<' ) < strpos( $sseo_html, '>Special pages<' ) && strpos( $sseo_html, '>Special pages<' ) < strpos( $sseo_html, '>Social networks<' ) );
	sseo_check( 'the tab posts its own group', str_contains( $sseo_html, "value='simple-seo-special'" ) );
	sseo_check( 'the tab: three sections with title and description templates', 6 === preg_match_all( '/name="simple_seo_special\[(?:home|404|password)\]\[(?:title|description)\]"/', $sseo_html ) && str_contains( $sseo_html, 'value="Home of {site}"' ) );
	sseo_check( 'the tab: image pickers for the home page and protected content only', str_contains( $sseo_html, 'name="simple_seo_special[home][image]" value="' . $sseo_og . '"' ) && str_contains( $sseo_html, 'name="simple_seo_special[password][image]"' ) && ! str_contains( $sseo_html, 'simple_seo_special[404][image]' ) );
	sseo_check( 'the tab: the static front page is named, with a link to edit it', str_contains( $sseo_html, 'is the page &quot;Front&quot;' ) && str_contains( $sseo_html, 'post=' . $sseo_p['front'] . '&#038;action=edit' ), $sseo_html );
	sseo_check( 'the tab: the variables and structure buttons', str_contains( $sseo_html, 'simple-seo-variables' ) && str_contains( $sseo_html, 'data-target="simple-seo-special-password-title"' ) );
	if ( Simple_SEO_WP_Loc::active() && count( WP_LOC_Languages::get_active_languages() ) > 1 ) {
		sseo_check( 'with wp-loc: the language notice names the default language', str_contains( $sseo_html, 'kept for each language' ) && str_contains( $sseo_html, '<strong>' . esc_html( WP_LOC_Languages::get_display_name( WP_LOC_Languages::get_default_language() ) ) . '</strong>' ) );
	} else {
		sseo_check( 'without wp-loc: no language notice', ! str_contains( $sseo_html, 'kept for each language' ) && '' === Simple_SEO_WP_Loc::admin_language() );
	}
	$_GET['tab'] = 'titles';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'the Titles tab points to Special pages and no longer lists home or 404', str_contains( $sseo_html, 'tab=special' ) && ! str_contains( $sseo_html, '[templates][home]' ) && ! str_contains( $sseo_html, '[templates][404]' ) );
	unset( $_GET['tab'] );
	update_option( 'show_on_front', 'posts' );
	update_option( 'page_on_front', 0 );

	// The bond with wp-loc: its filters, without wp-loc itself.
	$sseo_ml = apply_filters( 'wp_loc_default_multilingual_options', array( 'blogname' ) );
	sseo_check( 'wp-loc gets the special pages row as a multilingual option', in_array( Simple_SEO_Special::OPTION, $sseo_ml, true ) && in_array( 'blogname', $sseo_ml, true ) && ! in_array( Simple_SEO_Titles::OPTION, $sseo_ml, true ), $sseo_ml );
	sseo_check( 'wp-loc localizes the options on the Simple SEO page only', true === apply_filters( 'wp_loc_is_localized_options_screen', false, WP_Screen::get( 'toplevel_page_simple-seo' ) ) && false === apply_filters( 'wp_loc_is_localized_options_screen', false, WP_Screen::get( 'dashboard' ) ) );
	Simple_SEO_Settings::flush();
	Simple_SEO_Special::saved_template( 'home', 'title' );
	$sseo_other_language = static fn(): array => array( 'home' => array( 'title' => 'Other language home' ) );
	add_filter( 'pre_option_' . Simple_SEO_Special::OPTION, $sseo_other_language );
	$sseo_before = Simple_SEO_Special::saved_template( 'home', 'title' );
	do_action( 'wpml_switch_language', 'xx' );
	$sseo_after = Simple_SEO_Special::saved_template( 'home', 'title' );
	sseo_check( 'a language switch empties the settings cache', 'Home of {site}' === $sseo_before && 'Other language home' === $sseo_after, array( $sseo_before, $sseo_after ) );
	remove_filter( 'pre_option_' . Simple_SEO_Special::OPTION, $sseo_other_language );
	do_action( 'change_locale', 'en_US' );
	sseo_check( 'so does a locale switch', 'Home of {site}' === Simple_SEO_Special::saved_template( 'home', 'title' ) );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Search result preview' );

	wp_set_current_user( $sseo_ed );
	$sseo_sn_post             = wp_insert_post( array( 'post_title' => 'Snippet post', 'post_name' => 'snippet-post', 'post_status' => 'publish', 'post_excerpt' => 'Own excerpt', 'post_content' => '<p>Text of the snippet post.</p>' ) );
	$sseo['posts']['snippet'] = $sseo_sn_post;
	$sseo_sn                  = Simple_SEO_Snippet::data( 'post', get_post( $sseo_sn_post ) );
	sseo_check( 'the card shows what the page shows', 'post' === $sseo_sn['kind'] && Simple_SEO_Titles::title( Simple_SEO_Context::for_post( get_post( $sseo_sn_post ) ) ) === $sseo_sn['title'] && "Snippet post – {$sseo_site}" === $sseo_sn['title'] && 'Own excerpt' === $sseo_sn['description'], $sseo_sn );
	sseo_check( 'the templates of its kind and the separator', array( 'title' => '{title} {page} {sep} {site}', 'description' => '{excerpt}' ) === $sseo_sn['templates'] && Simple_SEO_Titles::separator() === $sseo_sn['separator'], $sseo_sn['templates'] );
	sseo_check( 'a value for every variable', array_keys( Simple_SEO_Variables::labels() ) === array_keys( $sseo_sn['values'] ) && $sseo_site === $sseo_sn['values']['site'] && 'Snippet post' === $sseo_sn['values']['title'] && 'Own excerpt' === $sseo_sn['values']['excerpt'] && '' === $sseo_sn['values']['page'] && wp_date( 'Y' ) === $sseo_sn['values']['year'], $sseo_sn['values'] );
	sseo_check( 'what {excerpt} becomes once the excerpt box is emptied: the start of the text', 'Text of the snippet post.' === $sseo_sn['excerptFromText'] && true === $sseo_sn['excerptField'] && false === $sseo_sn['protected'], $sseo_sn['excerptFromText'] );
	sseo_check( 'the excerpt of the post itself is left as it is', 'Own excerpt' === get_post( $sseo_sn_post )->post_excerpt );
	sseo_check( 'what the form had: title, excerpt, slug', array( 'title' => 'Snippet post', 'excerpt' => 'Own excerpt', 'slug' => 'snippet-post' ) === $sseo_sn['raw'], $sseo_sn['raw'] );
	sseo_check( 'the address, the site, its icon and the home', get_permalink( $sseo_sn_post ) === $sseo_sn['url'] && $sseo_site === $sseo_sn['site'] && (string) get_site_icon_url( 64 ) === $sseo_sn['icon'] && home_url( '/' ) === $sseo_sn['home'] );
	sseo_check( 'shown in search results, and so is its kind', false === $sseo_sn['noindex'] && false === $sseo_sn['typeNoindex'] && true === $sseo_sn['public'] );
	sseo_check( 'no saved fields yet', array( 'title' => '', 'description' => '' ) === $sseo_sn['own'] );

	update_post_meta( $sseo_sn_post, '_simple_seo_title', 'Own {title} {sep} {year}' );
	update_post_meta( $sseo_sn_post, '_simple_seo_index', 'noindex' );
	$sseo_sn = Simple_SEO_Snippet::data( 'post', get_post( $sseo_sn_post ) );
	sseo_check( 'the own title filled, and the field as saved', 'Own Snippet post – ' . wp_date( 'Y' ) === $sseo_sn['title'] && 'Own {title} {sep} {year}' === $sseo_sn['own']['title'], $sseo_sn['title'] );
	sseo_check( 'hidden by its own choice, while its kind is not', true === $sseo_sn['noindex'] && false === $sseo_sn['typeNoindex'] );
	$sseo_html = Simple_SEO_Snippet::render( 'post', get_post( $sseo_sn_post ) );
	sseo_check( 'a hidden page: the card is marked and the note shown', str_contains( $sseo_html, '<div class="simple-seo-snippet is-noindex"' ) && str_contains( $sseo_html, '<p class="simple-seo-snippet-noindex description">' ) );
	delete_post_meta( $sseo_sn_post, '_simple_seo_index' );

	$sseo_sn_indexing = get_option( Simple_SEO_Indexing::OPTION, null );
	update_option( Simple_SEO_Indexing::OPTION, array( 'noindex' => array( 'post:post' ) ) );
	Simple_SEO_Settings::flush();
	$sseo_sn = Simple_SEO_Snippet::data( 'post', get_post( $sseo_sn_post ) );
	sseo_check( 'hidden as its kind is', true === $sseo_sn['noindex'] && true === $sseo_sn['typeNoindex'] );
	null === $sseo_sn_indexing ? delete_option( Simple_SEO_Indexing::OPTION ) : update_option( Simple_SEO_Indexing::OPTION, $sseo_sn_indexing );
	update_option( 'blog_public', '0' );
	Simple_SEO_Settings::flush();
	$sseo_sn = Simple_SEO_Snippet::data( 'post', get_post( $sseo_sn_post ) );
	sseo_check( 'a site closed to search engines: hidden whatever the page and its kind say', true === $sseo_sn['noindex'] && true === $sseo_sn['typeNoindex'] && false === $sseo_sn['public'] );
	update_option( 'blog_public', '1' );

	update_post_meta( $sseo_sn_post, '_simple_seo_description', 'Says "quoted" & <b>bold</b>' );
	$sseo_html = Simple_SEO_Snippet::render( 'post', get_post( $sseo_sn_post ) );
	preg_match( '/data-simple-seo-snippet="([^"]*)"/', $sseo_html, $sseo_m );
	$sseo_json = json_decode( html_entity_decode( $sseo_m[1] ?? '', ENT_QUOTES, 'UTF-8' ), true );
	sseo_check( 'the card carries its data and its labels', is_array( $sseo_json ) && 'Says "quoted" & bold' === $sseo_json['description'] && 'Search result preview' === $sseo_json['l10n']['heading'] && str_contains( $sseo_json['l10n']['titleCut'], '%s' ) && 12 === count( $sseo_json['l10n'] ), $sseo_json['l10n'] ?? $sseo_html );
	sseo_check( 'the card escapes what it prints', str_contains( $sseo_html, '<div class="simple-seo-snippet-description">Says &quot;quoted&quot; &amp; bold</div>' ) && ! str_contains( $sseo_html, '<b>' ) );
	sseo_check( 'the switch waits for the script; a shown page has the note hidden', str_contains( $sseo_html, 'role="group" aria-label="Search result preview" hidden>' ) && str_contains( $sseo_html, '<p class="simple-seo-snippet-noindex description" hidden>' ) && ! str_contains( $sseo_html, 'is-noindex' ) );
	sseo_check( 'the card: site, address and the title of the page', str_contains( $sseo_html, '<span class="simple-seo-snippet-name">' . esc_html( $sseo_site ) . '</span>' ) && str_contains( $sseo_html, '<span class="simple-seo-snippet-url">' . esc_html( Simple_SEO_Snippet::display_url( get_permalink( $sseo_sn_post ) ) ) . '</span>' ) && str_contains( $sseo_html, '<div class="simple-seo-snippet-title">Own Snippet post – ' ) );
	sseo_check( 'without a site icon, a globe', '' !== (string) get_site_icon_url( 64 ) || str_contains( $sseo_html, 'dashicons-admin-site-alt3' ) );
	update_post_meta( $sseo_sn_post, '_simple_seo_title', '{sep}' );
	$sseo_html = Simple_SEO_Snippet::render( 'post', get_post( $sseo_sn_post ) );
	sseo_check( 'no title of ours: the card shows the post\'s own', str_contains( $sseo_html, '<div class="simple-seo-snippet-title">Snippet post</div>' ) );

	$sseo_sn_draft                  = wp_insert_post( array( 'post_title' => 'Snippet draft', 'post_name' => 'snippet-draft', 'post_status' => 'draft' ) );
	$sseo['posts']['snippet_draft'] = $sseo_sn_draft;
	$sseo_sn                        = Simple_SEO_Snippet::data( 'post', get_post( $sseo_sn_draft ) );
	sseo_check( 'a draft: the address it will get, not ?p=', home_url( '/snippet-draft/' ) === $sseo_sn['url'], $sseo_sn['url'] );
	$sseo_sn = Simple_SEO_Snippet::data( 'post', get_post( $sseo['posts']['draft'] ) );
	sseo_check( 'a new post: no address yet', '' === $sseo_sn['url'] );
	$sseo_sn_page                  = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Snippet page', 'post_status' => 'publish', 'post_excerpt' => 'Stored excerpt' ) );
	$sseo['posts']['snippet_page'] = $sseo_sn_page;
	$sseo_sn                       = Simple_SEO_Snippet::data( 'post', get_post( $sseo_sn_page ) );
	sseo_check( 'a type without the excerpt box: the stored excerpt stays', false === $sseo_sn['excerptField'] && 'Stored excerpt' === $sseo_sn['values']['excerpt'] && 'post:page' === Simple_SEO_Titles::template_slot( Simple_SEO_Context::for_post( get_post( $sseo_sn_page ) ) ) );
	$sseo_sn = Simple_SEO_Snippet::data( 'post', get_post( $sseo_p['secret'] ) );
	sseo_check( 'a protected post: the password templates and nothing of its text', true === $sseo_sn['protected'] && Simple_SEO_Titles::template( 'password', 'title' ) === $sseo_sn['templates']['title'] && '' === $sseo_sn['values']['excerpt'] && '' === $sseo_sn['excerptFromText'] );
	$sseo_sn_cyr                  = wp_insert_post( array( 'post_title' => 'Кирилиця', 'post_name' => 'пост-сніпет', 'post_status' => 'publish' ) );
	$sseo['posts']['snippet_cyr'] = $sseo_sn_cyr;
	$sseo_sn                      = Simple_SEO_Snippet::data( 'post', get_post( $sseo_sn_cyr ) );
	sseo_check( 'the slug as its field shows it: decoded', 'пост-сніпет' === $sseo_sn['raw']['slug'] && str_contains( $sseo_sn['url'], '%d0%bf' ), $sseo_sn['raw'] );

	$sseo_sn_term = get_term( $sseo_cat_id );
	$sseo_sn      = Simple_SEO_Snippet::data( 'term', $sseo_sn_term );
	sseo_check( 'a term: its name, description, slug and address', 'term' === $sseo_sn['kind'] && array( 'title' => $sseo_sn_term->name, 'excerpt' => $sseo_sn_term->description, 'slug' => $sseo_sn_term->slug ) === $sseo_sn['raw'] && get_term_link( $sseo_sn_term ) === $sseo_sn['url'] && false === $sseo_sn['excerptField'] && Simple_SEO_Variables::plain( $sseo_sn_term->description ) === $sseo_sn['values']['excerpt'] && Simple_SEO_Titles::template( 'term:category', 'title' ) === $sseo_sn['templates']['title'], $sseo_sn );
	sseo_check( 'an address as search engines show it', 'https://example.com › blog › пост' === Simple_SEO_Snippet::display_url( 'https://example.com/blog/%D0%BF%D0%BE%D1%81%D1%82/' ) && 'http://127.0.0.1:8892' === Simple_SEO_Snippet::display_url( 'http://127.0.0.1:8892/' ) && 'not an address' === Simple_SEO_Snippet::display_url( 'not an address' ) );
	wp_set_current_user( $sseo_u['administrator'] );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Page text' );

	$sseo_pt_post                = wp_insert_post( array( 'post_title' => 'Page text', 'post_status' => 'publish', 'post_content' => 'Saved text of the post.' ) );
	$sseo['posts']['page_text']  = $sseo_pt_post;
	$sseo_pt_other               = wp_insert_post( array( 'post_title' => 'Another post shown before', 'post_status' => 'publish' ) );
	$sseo['posts']['page_other'] = $sseo_pt_other;
	$sseo_pt                     = Simple_SEO_Content::read( get_post( $sseo_pt_post ) );
	sseo_check( 'the saved content when none is given', 'Saved text of the post.' === $sseo_pt['text'] && 5 === $sseo_pt['words'] && array( array( 'type' => 'text', 'tag' => 'p', 'text' => 'Saved text of the post.' ) ) === $sseo_pt['blocks'], $sseo_pt );

	add_shortcode( 'sseo_pt', static fn(): string => 'SHORTCODE OUTPUT' );
	$sseo_pt = Simple_SEO_Content::read(
		get_post( $sseo_pt_post ),
		"First paragraph with a <a href=\"/about/\">link home</a> and <a href=\"https://example.org/x\" rel=\"noopener nofollow\">an outside one</a>.\n\n<h2>A &amp; B <em>heading</em></h2>\n\nSecond paragraph [sseo_pt] here.<script>var hidden = 1;</script>\n\n<img src=\"/a.png\" alt=\"An  image\"> <img src=\"/b.png\">\n\n<a href=\"#top\">Up</a> <a href=\"mailto:a@b.c\">Mail</a> <a href=\"" . home_url( '/contacts/' ) . "\">Contacts</a>"
	);
	sseo_check( 'classic content: paragraphs from its blank lines, headings between them', array( 'text', 'heading', 'text', 'text' ) === array_column( $sseo_pt['blocks'], 'type' ) && 'First paragraph with a link home and an outside one.' === $sseo_pt['blocks'][0]['text'] && 'Second paragraph here.' === $sseo_pt['blocks'][2]['text'], $sseo_pt['blocks'] );
	sseo_check( 'a heading: its level and its text, entities decoded', array( array( 'level' => 2, 'text' => 'A & B heading' ) ) === $sseo_pt['headings'] && 2 === $sseo_pt['blocks'][1]['level'], $sseo_pt['headings'] );
	sseo_check( 'no scripts and no shortcodes in the text', ! str_contains( $sseo_pt['text'], 'hidden' ) && ! str_contains( $sseo_pt['text'], 'SHORTCODE' ) && ! str_contains( $sseo_pt['text'], '[sseo_pt]' ) );
	sseo_check(
		'links: inside the site or out, nofollow; anchors and mail left out',
		array(
			array( 'url' => '/about/', 'text' => 'link home', 'internal' => true, 'nofollow' => false ),
			array( 'url' => 'https://example.org/x', 'text' => 'an outside one', 'internal' => false, 'nofollow' => true ),
			array( 'url' => home_url( '/contacts/' ), 'text' => 'Contacts', 'internal' => true, 'nofollow' => false ),
		) === $sseo_pt['links'],
		$sseo_pt['links']
	);
	sseo_check( 'images with their alt text, an empty one where there is none', array( array( 'src' => '/a.png', 'alt' => 'An image' ), array( 'src' => '/b.png', 'alt' => '' ) ) === $sseo_pt['images'], $sseo_pt['images'] );
	sseo_check( 'the text: blocks one after another, the words counted', "First paragraph with a link home and an outside one.\n\nA & B heading\n\nSecond paragraph here.\n\nUp Mail Contacts" === $sseo_pt['text'] && 19 === $sseo_pt['words'], array( $sseo_pt['text'], $sseo_pt['words'] ) );

	register_block_type(
		'sseo/acf-like',
		array(
			'attributes'      => array( 'data' => array( 'type' => 'object' ) ),
			// As ACF renders a block: its fields from the comment, through the theme's template.
			'render_callback' => static fn( array $attributes ): string => '<section class="hero"><h3>' . esc_html( $attributes['data']['title'] ?? '' ) . '</h3><div>' . esc_html( $attributes['data']['text'] ?? '' ) . ' #' . get_the_ID() . '</div></section>',
		)
	);
	register_block_type( 'sseo/box', array( 'render_callback' => static fn( array $attributes, string $content ): string => '<div class="box">' . $content . '</div>' ) );
	register_block_type( 'sseo/broken', array( 'render_callback' => static function (): string { throw new RuntimeException( 'template failed' ); } ) );
	$sseo_pt_builder = ! WP_Block_Type_Registry::get_instance()->is_registered( 'builder/code' );
	if ( $sseo_pt_builder ) {
		register_block_type( 'builder/code', array( 'render_callback' => static fn(): string => '<pre>SECRET CODE</pre>' ) );
	}
	$sseo_pt_blocks = '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Core heading</h3><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>Core paragraph.</p><!-- /wp:paragraph -->'
		. '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One</li><!-- /wp:list-item --><!-- wp:list-item --><li>Two</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
		. '<!-- wp:code --><pre class="wp-block-code"><code>skipped code</code></pre><!-- /wp:code -->'
		. '<!-- wp:latest-posts /-->'
		. '<!-- wp:post-content /-->'
		. '<!-- wp:sseo/acf-like {"name":"sseo/acf-like","data":{"title":"ACF heading","_title":"field_1","text":"ACF text","_text":"field_2"},"mode":"preview"} /-->'
		. '<!-- wp:sseo/broken /-->'
		. '<!-- wp:sseo/box --><!-- wp:builder/code {"code":"echo 1;"} /--><!-- wp:paragraph --><p>Inside the box.</p><!-- /wp:paragraph --><!-- /wp:sseo/box -->';
	$GLOBALS['post'] = get_post( $sseo_pt_other );
	$sseo_pt         = Simple_SEO_Content::read( get_post( $sseo_pt_post ), $sseo_pt_blocks );
	$sseo_pt_seen    = array_map( static fn( array $block ): string => $block['tag'] . ':' . $block['text'], $sseo_pt['blocks'] );
	sseo_check( 'blocks: core ones as saved, dynamic ones as their templates render them, in order', array( 'h3:Core heading', 'p:Core paragraph.', 'li:One', 'li:Two', 'h3:ACF heading', 'div:ACF text #' . $sseo_pt_post, 'p:Inside the box.' ) === $sseo_pt_seen, $sseo_pt_seen );
	sseo_check( 'headings of blocks with their levels', array( array( 'level' => 3, 'text' => 'Core heading' ), array( 'level' => 3, 'text' => 'ACF heading' ) ) === $sseo_pt['headings'], $sseo_pt['headings'] );
	sseo_check( 'left out: code (nested too), lists of other posts, the post content block, a block that fails', ! str_contains( $sseo_pt['text'], 'code' ) && ! str_contains( $sseo_pt['text'], 'SECRET' ) && ! str_contains( $sseo_pt['text'], 'Another post shown before' ) && ! str_contains( $sseo_pt['text'], 'Saved text' ), $sseo_pt['text'] );
	sseo_check( 'templates see the post being read; the post shown before comes back, and blocks render again', $sseo_pt_other === ( $GLOBALS['post']->ID ?? 0 ) && str_contains( do_blocks( '<!-- wp:code --><pre class="wp-block-code"><code>shown again</code></pre><!-- /wp:code -->' ), 'shown again' ) );
	$sseo_pt_skip = static fn( array $blocks ): array => array_merge( $blocks, array( 'sseo/acf-like' ) );
	add_filter( 'simple_seo_content_skip_blocks', $sseo_pt_skip );
	$sseo_pt = Simple_SEO_Content::read( get_post( $sseo_pt_post ), $sseo_pt_blocks );
	remove_filter( 'simple_seo_content_skip_blocks', $sseo_pt_skip );
	sseo_check( 'a theme can leave its own blocks out', ! str_contains( $sseo_pt['text'], 'ACF' ) && str_contains( $sseo_pt['text'], 'Inside the box.' ) );
	unset( $GLOBALS['post'] );

	sseo_check( 'words: Cyrillic, apostrophes and hyphens inside a word, numbers', 5 === Simple_SEO_Content::count_words( "Мар'яна п’є каву-латте, 2026 року!" ) && 0 === Simple_SEO_Content::count_words( ' — , ! ' ) );
	$sseo_pt = Simple_SEO_Content::parse( 'Loose <b>text</b><p>A&nbsp;&nbsp;B</p><p hidden>Hidden text</p><nav><a href="/menu/">Menu</a></nav><div>Outer <p>inner</p> tail</div><p>Line<br>break</p>Loose end' );
	sseo_check( 'loose text, no-break spaces, hidden elements, menus, line breaks', array( 'Loose text', 'A B', 'Outer', 'inner', 'tail', 'Line break', 'Loose end' ) === array_column( $sseo_pt['blocks'], 'text' ) && array() === $sseo_pt['links'], $sseo_pt['blocks'] );
	$sseo_pt = Simple_SEO_Content::read( get_post( $sseo_pt_post ), '' );
	sseo_check( 'empty content: nothing to read', array() === $sseo_pt['blocks'] && '' === $sseo_pt['text'] && 0 === $sseo_pt['words'] );
	foreach ( array( 'sseo/acf-like', 'sseo/box', 'sseo/broken' ) as $sseo_name ) {
		unregister_block_type( $sseo_name );
	}
	if ( $sseo_pt_builder ) {
		unregister_block_type( 'builder/code' );
	}
	remove_shortcode( 'sseo_pt' );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Content analysis' );

	wp_set_current_user( $sseo_ed );
	$sseo_an_post              = wp_insert_post( array( 'post_title' => 'Ключова фраза', 'post_name' => 'kliuchova-fraza', 'post_status' => 'publish' ) );
	$sseo['posts']['analysis'] = $sseo_an_post;
	$sseo_an_find              = static fn( array $result, string $id ): array => array_values( array_filter( $result['checks'], static fn( array $check ): bool => $id === $check['id'] ) );
	$sseo_an_status            = static fn( array $result, string $id ): string => $sseo_an_find( $result, $id )[0]['status'] ?? 'none';
	$sseo_an_text              = static fn( array $result, string $id ): string => $sseo_an_find( $result, $id )[0]['text'] ?? '';
	$sseo_an_field             = Simple_SEO_Fields::get( 'keyphrase' );
	sseo_check( 'the keyphrase fields: posts only, in the analysis group', array( 'post' ) === $sseo_an_field['objects'] && 'analysis' === $sseo_an_field['group'] && 'textarea' === Simple_SEO_Fields::get( 'keyphrases' )['type'] && array( 'post' ) === Simple_SEO_Fields::get( 'keyphrases' )['objects'] && 'Content analysis' === Simple_SEO_Fields::group_heading( 'analysis' ) );

	$sseo_an = Simple_SEO_Analysis::run( get_post( $sseo_an_post ), array( 'content' => '<p>Short text.</p>', 'title' => '', 'description' => '', 'keyphrase' => ' ' ) );
	sseo_check( 'no keyphrase: a note, and the checks of the page only', 'info' === $sseo_an_status( $sseo_an, 'keyphrase' ) && 'none' === $sseo_an_status( $sseo_an, 'keyphrase_title' ) && 'bad' === $sseo_an_status( $sseo_an, 'length' ) && 'bad' === $sseo_an_status( $sseo_an, 'title_length' ) && 'bad' === $sseo_an_status( $sseo_an, 'description_length' ) && 'ok' === $sseo_an_status( $sseo_an, 'images' ) && 'none' === $sseo_an_status( $sseo_an, 'alt' ), $sseo_an['checks'] );
	sseo_check( 'a note counts for nothing; the score of the rest and its rating', 2 === $sseo_an['words'] && 25 === $sseo_an['score'] && 'bad' === $sseo_an['rating'], $sseo_an );

	$sseo_an_body = '<p>Ключова фраза на початку тексту.</p><h2>Де шукати ключові фрази</h2><p>' . str_repeat( 'Слово ', 300 ) . 'і трохи ключової фрази.</p>'
		. '<p><a href="/inside/">всередині</a> <a href="/second/">ще</a> <a href="https://example.org/">назовні</a></p><img src="/a.png" alt="Ключова фраза на фото">';
	$sseo_an_good = array(
		'content'     => $sseo_an_body,
		'title'       => 'Ключова фраза: як знайти і де вжити – IT',
		'description' => 'Що таке ключова фраза і чому вона важлива для пошуку сторінки в Google та інших пошуковиках.',
		'url'         => home_url( '/kliuchova-fraza/' ),
		'keyphrase'   => '  Ключова   фраза ',
	);
	$sseo_an = Simple_SEO_Analysis::run( get_post( $sseo_an_post ), $sseo_an_good );
	$sseo_an_ids = array( 'keyphrase_length', 'keyphrase_title', 'keyphrase_description', 'keyphrase_slug', 'keyphrase_intro', 'keyphrase_headings', 'keyphrase_density', 'keyphrase_images', 'keyphrase_used', 'length', 'links_internal', 'links_external', 'images', 'title_length', 'description_length' );
	sseo_check( 'a page that does it all: every check good, in their order, other forms of the words found', $sseo_an_ids === array_column( $sseo_an['checks'], 'id' ) && array( 'good' ) === array_values( array_unique( array_column( $sseo_an['checks'], 'status' ) ) ) && 100 === $sseo_an['score'] && 'good' === $sseo_an['rating'], $sseo_an['checks'] );
	sseo_check( 'the counts are told', str_contains( $sseo_an_text( $sseo_an, 'keyphrase_density' ), ' 3 ' ) && str_contains( $sseo_an_text( $sseo_an, 'links_internal' ), ': 2.' ) && str_contains( $sseo_an_text( $sseo_an, 'links_external' ), ': 1.' ) && str_contains( $sseo_an_text( $sseo_an, 'length' ), '316' ), $sseo_an['checks'] );

	$sseo_an = Simple_SEO_Analysis::run(
		get_post( $sseo_an_post ),
		array(
			'content'     => '<h2>Про інше</h2><p>Перший абзац без неї.</p><p>Другий теж.</p><p>Ось ключова фраза.</p><img src="/a.png"><img src="/c.png" alt=""><img src="/b.png" alt="Фото">',
			'title'       => 'Зовсім інша назва цієї сторінки, задовга для пошукової видачі – IT',
			'description' => 'Короткий опис.',
			'url'         => home_url( '/insha-adresa/' ),
			'keyphrase'   => 'ключова фраза',
		)
	);
	sseo_check( 'the keyphrase missing from the title, description, address, first paragraph and subheadings', 'bad' === $sseo_an_status( $sseo_an, 'keyphrase_title' ) && 'bad' === $sseo_an_status( $sseo_an, 'keyphrase_description' ) && 'ok' === $sseo_an_status( $sseo_an, 'keyphrase_slug' ) && 'bad' === $sseo_an_status( $sseo_an, 'keyphrase_intro' ) && 'ok' === $sseo_an_status( $sseo_an, 'keyphrase_headings' ) && 'ok' === $sseo_an_status( $sseo_an, 'keyphrase_images' ), $sseo_an['checks'] );
	sseo_check( 'a short text with the keyphrase once is enough', 'good' === $sseo_an_status( $sseo_an, 'keyphrase_density' ) );
	sseo_check( 'images without alt text are counted; no links of either kind', 'bad' === $sseo_an_status( $sseo_an, 'alt' ) && str_contains( $sseo_an_text( $sseo_an, 'alt' ), ': 2.' ) && 'ok' === $sseo_an_status( $sseo_an, 'links_internal' ) && 'ok' === $sseo_an_status( $sseo_an, 'links_external' ) && 'good' === $sseo_an_status( $sseo_an, 'images' ), $sseo_an['checks'] );
	sseo_check( 'a title too long and a description too short', 'ok' === $sseo_an_status( $sseo_an, 'title_length' ) && str_contains( $sseo_an_text( $sseo_an, 'title_length' ), 'cut' ) && 'ok' === $sseo_an_status( $sseo_an, 'description_length' ) && str_contains( $sseo_an_text( $sseo_an, 'description_length' ), 'short' ), $sseo_an['checks'] );

	$sseo_an = Simple_SEO_Analysis::run( get_post( $sseo_an_post ), array( 'content' => '<p>Ключова фраза. Ключова фраза. Ключова фраза. Ключова фраза.</p>', 'keyphrase' => 'ключова фраза' ) );
	sseo_check( 'the keyphrase too often, without subheadings or images to check', 'bad' === $sseo_an_status( $sseo_an, 'keyphrase_density' ) && str_contains( $sseo_an_text( $sseo_an, 'keyphrase_density' ), '100' ) && 'none' === $sseo_an_status( $sseo_an, 'keyphrase_headings' ) && 'none' === $sseo_an_status( $sseo_an, 'keyphrase_images' ), $sseo_an['checks'] );
	$sseo_an = Simple_SEO_Analysis::run( get_post( $sseo_an_post ), array( 'content' => '<p>Ключова фраза ' . str_repeat( 'слово ', 500 ) . '</p>', 'keyphrase' => 'ключова фраза' ) );
	sseo_check( 'the keyphrase rare in a long text', 'ok' === $sseo_an_status( $sseo_an, 'keyphrase_density' ), $sseo_an_text( $sseo_an, 'keyphrase_density' ) );
	$sseo_an = Simple_SEO_Analysis::run( get_post( $sseo_an_post ), array( 'content' => '<p>Нічого.</p>', 'keyphrase' => 'ключова фраза' ) );
	sseo_check( 'the keyphrase not in the text at all', 'bad' === $sseo_an_status( $sseo_an, 'keyphrase_density' ) );
	$sseo_an = Simple_SEO_Analysis::run( get_post( $sseo_an_post ), array( 'content' => '<p>Text.</p>', 'keyphrase' => 'one two three four five' ) );
	sseo_check( 'a keyphrase of five words is long', 'ok' === $sseo_an_status( $sseo_an, 'keyphrase_length' ) );

	$sseo_an = Simple_SEO_Analysis::run( get_post( $sseo_an_post ), array( 'content' => $sseo_an_body, 'keyphrase' => '', 'keyphrases' => "інша фраза\nслово\n\nінша фраза\nп'ята\nшоста\nсьома" ) );
	$sseo_an_more = $sseo_an_find( $sseo_an, 'more_keyphrases' );
	sseo_check( 'more keyphrases: each once, four at most, counted in the text', 4 === count( $sseo_an_more ) && 'bad' === $sseo_an_more[0]['status'] && str_contains( $sseo_an_more[0]['text'], 'інша фраза' ) && 'good' === $sseo_an_more[1]['status'] && str_contains( $sseo_an_more[1]['text'], '300' ) && str_contains( $sseo_an_more[3]['text'], 'шоста' ), $sseo_an_more );

	// As saved: the post's content, its fields, its title and address as the site shows them.
	wp_update_post( array( 'ID' => $sseo_an_post, 'post_content' => $sseo_an_body ) );
	update_post_meta( $sseo_an_post, '_simple_seo_keyphrase', 'Ключова фраза' );
	$sseo_an = Simple_SEO_Analysis::run( get_post( $sseo_an_post ) );
	sseo_check( 'as saved: the post\'s own text, keyphrase, title and address', 'good' === $sseo_an_status( $sseo_an, 'keyphrase_title' ) && 'good' === $sseo_an_status( $sseo_an, 'keyphrase_slug' ) && 'good' === $sseo_an_status( $sseo_an, 'keyphrase_intro' ) && 'bad' !== $sseo_an_status( $sseo_an, 'description_length' ), $sseo_an['checks'] );

	$sseo_an_other = wp_insert_post( array( 'post_title' => 'Other page on the phrase', 'post_status' => 'publish' ) );
	$sseo_an_trash = wp_insert_post( array( 'post_title' => 'Trashed page on the phrase', 'post_status' => 'trash' ) );
	$sseo['posts']['analysis_other'] = $sseo_an_other;
	$sseo['posts']['analysis_trash'] = $sseo_an_trash;
	update_post_meta( $sseo_an_other, '_simple_seo_keyphrase', 'КЛЮЧОВА ФРАЗА' );
	update_post_meta( $sseo_an_trash, '_simple_seo_keyphrase', 'ключова фраза' );
	$sseo_an = Simple_SEO_Analysis::run( get_post( $sseo_an_post ), $sseo_an_good );
	sseo_check( 'the same focus keyphrase on another page, whatever its case; a trashed page does not count', 'ok' === $sseo_an_status( $sseo_an, 'keyphrase_used' ) && str_contains( $sseo_an_text( $sseo_an, 'keyphrase_used' ), '"Other page on the phrase"' ) && ! str_contains( $sseo_an_text( $sseo_an, 'keyphrase_used' ), 'Trashed' ), $sseo_an_text( $sseo_an, 'keyphrase_used' ) );
	delete_post_meta( $sseo_an_other, '_simple_seo_keyphrase' );

	sseo_check( 'words: lower case, apostrophes alike', array( "мар'яна", "п'є", 'seo-аналіз' ) === Simple_SEO_Analysis::words( 'Мар’яна пʼє SEO-аналіз!' ) );
	sseo_check( 'stems: two letters off a long word, one off a middle one, short ones whole', 'анал' === Simple_SEO_Analysis::stem( 'аналіз' ) && 'фраз' === Simple_SEO_Analysis::stem( 'фраза' ) && 'seo' === Simple_SEO_Analysis::stem( 'seo' ) && 'page' === Simple_SEO_Analysis::stem( 'pages' ) && 'сай' === Simple_SEO_Analysis::stem( 'сайт' ) );
	sseo_check( 'contains: every stem, in any order; nothing to look for is not found', Simple_SEO_Analysis::contains( array( 'фрази', 'ключові' ), array( 'ключо', 'фраз' ) ) && ! Simple_SEO_Analysis::contains( array( 'ключові' ), array( 'ключо', 'фраз' ) ) && ! Simple_SEO_Analysis::contains( array( 'x' ), array() ) );
	sseo_check( 'occurrences: the stems one after another, not overlapping', 2 === Simple_SEO_Analysis::occurrences( array( 'a', 'b', 'a', 'b', 'a' ), array( 'a', 'b' ) ) && 0 === Simple_SEO_Analysis::occurrences( array( 'b', 'a' ), array( 'a', 'b' ) ) && 3 === Simple_SEO_Analysis::occurrences( array( 'a', 'a', 'a' ), array( 'a' ) ) && 1 === Simple_SEO_Analysis::occurrences( array( 'a', 'a', 'a' ), array( 'a', 'a' ) ) );
	sseo_check( 'Cyrillic in Latin letters, as slugs spell it', 'shchastia izhak kliuchova' === Simple_SEO_Analysis::latin( 'Щастя Їжак ключова' ) );

	// The REST route the editor sends its text to.
	$sseo_an_rest = static function ( int $id, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/simple-seo/v1/analysis/' . $id );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	};
	$sseo_an_response = $sseo_an_rest( $sseo_an_post, array_merge( $sseo_an_good, array( 'content' => '<p>Ключова фраза одна.</p>' ) ) );
	sseo_check( 'REST: the analysis of the text sent', 200 === $sseo_an_response->get_status() && 3 === $sseo_an_response->get_data()['words'] && 'good' === $sseo_an_status( $sseo_an_response->get_data(), 'keyphrase_used' ) && 'good' === $sseo_an_status( $sseo_an_response->get_data(), 'keyphrase_title' ), $sseo_an_response->get_data() );
	wp_set_current_user( $sseo_au );
	sseo_check( 'REST: not for an author on someone else\'s post', in_array( $sseo_an_rest( $sseo_an_post, array() )->get_status(), array( 401, 403 ), true ) );
	wp_set_current_user( $sseo_u['subscriber'] );
	$sseo_an_response = $sseo_an_rest( $sseo_an_post, $sseo_an_good );
	sseo_check( 'REST: not for whoever cannot edit the post', in_array( $sseo_an_response->get_status(), array( 401, 403 ), true ), $sseo_an_response->get_status() );
	wp_set_current_user( $sseo_ed );
	$sseo_an_private = wp_insert_post( array( 'post_type' => 'sseo_private', 'post_title' => 'No fields here', 'post_status' => 'publish' ) );
	$sseo['posts']['analysis_private'] = $sseo_an_private;
	sseo_check( 'REST: not for a type without SEO fields, nor for a post that is not there', in_array( $sseo_an_rest( $sseo_an_private, array() )->get_status(), array( 401, 403 ), true ) && in_array( $sseo_an_rest( 99999999, array() )->get_status(), array( 401, 403, 404 ), true ) );

	update_post_meta( $sseo_an_post, '_simple_seo_keyphrases', "\"quoted\" <i>tag</i>" );
	$sseo_html = Simple_SEO_Analysis::render( get_post( $sseo_an_post ) );
	$sseo_an   = Simple_SEO_Analysis::run( get_post( $sseo_an_post ) );
	preg_match( '/data-simple-seo-analysis="([^"]*)"/', $sseo_html, $sseo_m );
	$sseo_json = json_decode( html_entity_decode( $sseo_m[1] ?? '', ENT_QUOTES, 'UTF-8' ), true );
	sseo_check( 'the panel: the route, a nonce and the labels for the script', is_array( $sseo_json ) && rest_url( 'simple-seo/v1/analysis/' . $sseo_an_post ) === $sseo_json['url'] && false !== wp_verify_nonce( $sseo_json['nonce'], 'wp_rest' ) && str_contains( $sseo_json['score'], '%s' ), $sseo_json );
	sseo_check( 'the panel: the score of the saved post and a line for each check', str_contains( $sseo_html, '<p class="simple-seo-analysis-score is-' . $sseo_an['rating'] . '"><strong>SEO score: ' . $sseo_an['score'] . ' of 100</strong>' ) && count( $sseo_an['checks'] ) === substr_count( $sseo_html, '<li class="is-' ), $sseo_html );
	sseo_check( 'the panel escapes what it prints', str_contains( $sseo_html, '&quot;quoted&quot;' ) && ! str_contains( $sseo_html, '<i>' ) );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_an_post ) ) );
	sseo_check( 'meta box: the panel after the keyphrases', strpos( $sseo_html, 'simple-seo-analysis"' ) > strpos( $sseo_html, 'id="simple-seo-field-keyphrases"' ) && str_contains( $sseo_html, 'name="simple_seo[keyphrase]"' ) );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_term_fields( get_term( $sseo_cat_id ) ) );
	sseo_check( 'term screen: no keyphrases and no analysis', ! str_contains( $sseo_html, 'simple_seo[keyphrase]' ) && ! str_contains( $sseo_html, 'simple-seo-analysis' ) );
	wp_set_current_user( $sseo_u['administrator'] );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'wp-loc' );

	if ( ! Simple_SEO_WP_Loc::active() || count( WP_LOC_Languages::get_active_languages() ) < 2 ) {
		echo "  skip  wp-loc with two languages is not active here (tools/wp-loc-it.sh runs these checks)\n";
	} else {
		$sseo_default_lang = WP_LOC_Languages::get_default_language();
		$sseo_other_lang   = (string) current( array_diff( array_keys( WP_LOC_Languages::get_active_languages() ), array( $sseo_default_lang ) ) );
		$sseo_other_row    = Simple_SEO_Special::OPTION . '_' . ( WP_LOC_DB::to_db_language_code( $sseo_other_lang ) ?: $sseo_other_lang );
		sseo_check( 'wp-loc knows the special pages row as multilingual', WP_LOC_Options::is_multilingual( Simple_SEO_Special::OPTION ) );

		update_option( $sseo_other_row, array( 'home' => array( 'title' => 'Other home {sep} {site}' ) ) );
		sseo_check( 'the default language reads the row itself', 'Home of {site}' === Simple_SEO_Special::saved_template( 'home', 'title' ) );
		do_action( 'wpml_switch_language', $sseo_other_lang );
		sseo_check( 'the other language reads its own row', "Other home – {$sseo_site}" === Simple_SEO_Titles::title( $sseo_ctx_of( array() ) ), Simple_SEO_Titles::title( $sseo_ctx_of( array() ) ) );
		sseo_check( 'what its row does not have is the default, not the other language\'s value', '{title} {sep} {site}' === Simple_SEO_Titles::template( '404', 'title' ) && 0 === Simple_SEO_Special::image_id( 'home' ) );
		do_action( 'wpml_switch_language', $sseo_default_lang );
		sseo_check( 'back in the default language', "Home of {$sseo_site}" === Simple_SEO_Titles::title( $sseo_ctx_of( array() ) ) );

		// The settings page in the other admin language: shows and saves that language's row.
		$_COOKIE['admin_lang'] = WP_LOC_Languages::get_language_locale( $sseo_other_lang );
		set_current_screen( 'toplevel_page_simple-seo' );
		sseo_check( 'the settings page shows the admin language\'s values', 'Other home {sep} {site}' === Simple_SEO_Special::saved_template( 'home', 'title' ) );
		$_GET['tab'] = 'special';
		$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
		unset( $_GET['tab'] );
		sseo_check( 'the tab names the language being edited', str_contains( $sseo_html, 'kept for each language' ) && str_contains( $sseo_html, '<strong>' . esc_html( WP_LOC_Languages::get_display_name( $sseo_other_lang ) ) . '</strong>' ) && str_contains( $sseo_html, 'value="Other home {sep} {site}"' ) );
		update_option( Simple_SEO_Special::OPTION, array( 'home' => array( 'title' => 'Saved in the other language' ) ) );
		// On this screen get_option() of the row gives the admin language's values: read the row itself.
		$sseo_base  = WP_LOC_Options::get_unfiltered_option( Simple_SEO_Special::OPTION )[1];
		$sseo_local = get_option( $sseo_other_row );
		sseo_check( 'saving in the other admin language writes its row, sanitized, and leaves the default\'s', 'Saved in the other language' === ( $sseo_local['home']['title'] ?? null ) && 'Home of {site}' === ( $sseo_base['home']['title'] ?? null ), array( $sseo_base, $sseo_local ) );
		unset( $_COOKIE['admin_lang'], $GLOBALS['current_screen'] );

		// The search result preview in the editor: the post's language, whatever the admin's is.
		$sseo_codes = array();
		foreach ( array( $sseo_default_lang, $sseo_other_lang ) as $sseo_lang ) {
			$sseo_codes[] = WP_LOC_DB::to_db_language_code( $sseo_lang ) ?: $sseo_lang;
		}
		update_option( Simple_SEO_Special::OPTION, array( 'password' => array( 'title' => 'Locked {title}' ) ) );
		update_option( $sseo_other_row, array( 'password' => array( 'title' => 'Other locked {title}' ) ) );
		$sseo_locked = array();
		foreach ( $sseo_codes as $sseo_code ) {
			$sseo_locked[ $sseo_code ]             = wp_insert_post( array( 'post_title' => 'Post ' . $sseo_code, 'post_status' => 'publish', 'post_password' => 'pw' ) );
			$sseo['posts'][ 'locked_' . $sseo_code ] = $sseo_locked[ $sseo_code ];
			do_action( 'wpml_set_element_language_details', array( 'element_id' => $sseo_locked[ $sseo_code ], 'element_type' => 'post_post', 'trid' => null, 'language_code' => $sseo_code, 'source_language_code' => null ) );
		}
		// The settings page above left wp-loc's filter of the admin language on the row for the rest
		// of this process (a real request has one screen): without it the row reads as it is stored.
		remove_all_filters( 'option_' . Simple_SEO_Special::OPTION );
		set_current_screen( 'post' );
		foreach ( array( $sseo_default_lang, $sseo_other_lang ) as $sseo_admin ) {
			$_COOKIE['admin_lang'] = WP_LOC_Languages::get_language_locale( $sseo_admin );
			Simple_SEO_Settings::flush();
			foreach ( $sseo_locked as $sseo_code => $sseo_id ) {
				$sseo_locale = determine_locale();
				$sseo_sn     = Simple_SEO_Snippet::data( 'post', get_post( $sseo_id ) );
				$sseo_want   = ( $sseo_code === $sseo_codes[0] ? 'Locked ' : 'Other locked ' ) . 'Post ' . $sseo_code;
				sseo_check( "admin in {$sseo_admin}: the preview of a post in {$sseo_code} takes that language's templates", $sseo_want === $sseo_sn['title'] && ( $sseo_code === $sseo_codes[0] ? 'Locked {title}' : 'Other locked {title}' ) === $sseo_sn['templates']['title'], $sseo_sn['title'] );
				// The default language here is Ukrainian: its month names are Cyrillic, English ones are not.
				sseo_check( "admin in {$sseo_admin}: the date of a post in {$sseo_code} in that language, and the locale back afterwards", ( $sseo_code === $sseo_codes[0] ) === (bool) preg_match( '/\p{Cyrillic}/u', $sseo_sn['values']['date'] ) && $sseo_locale === determine_locale(), array( $sseo_sn['values']['date'], $sseo_locale, determine_locale() ) );
			}
			sseo_check( "admin in {$sseo_admin}: no language left switched afterwards", ! WP_LOC_Routing::has_switched_language() && 'Locked {title}' === Simple_SEO_Special::saved_template( 'password', 'title' ) );
		}
		unset( $_COOKIE['admin_lang'], $GLOBALS['current_screen'] );

		// The text of a page: its blocks render in its language (translated patterns, menus, labels).
		register_block_type( 'sseo/language', array( 'render_callback' => static fn(): string => '<p>language ' . apply_filters( 'wpml_current_language', null ) . '</p>' ) );
		foreach ( $sseo_locked as $sseo_code => $sseo_id ) {
			$sseo_pt = Simple_SEO_Content::read( get_post( $sseo_id ), '<!-- wp:sseo/language /-->' );
			sseo_check( "the text of a post in {$sseo_code} is rendered in its language, and the language is back afterwards", 'language ' . $sseo_code === $sseo_pt['text'] && ! WP_LOC_Routing::has_switched_language(), $sseo_pt['text'] );
		}
		unregister_block_type( 'sseo/language' );

		// The same focus keyphrase on a page of another language is no competitor; of the same, it is.
		foreach ( $sseo_locked as $sseo_id ) {
			update_post_meta( $sseo_id, '_simple_seo_keyphrase', 'спільна фраза' );
		}
		$sseo_an_lang = static fn(): string => current( array_filter( Simple_SEO_Analysis::run( get_post( $sseo_locked[ $sseo_codes[0] ] ), array( 'content' => '<p>x</p>' ) )['checks'], static fn( array $check ): bool => 'keyphrase_used' === $check['id'] ) )['status'] ?? 'none';
		sseo_check( 'the same keyphrase on a page of the other language: no competitor', 'good' === $sseo_an_lang() );
		$sseo['posts']['analysis_same_language'] = wp_insert_post( array( 'post_title' => 'Same language', 'post_status' => 'publish' ) );
		do_action( 'wpml_set_element_language_details', array( 'element_id' => $sseo['posts']['analysis_same_language'], 'element_type' => 'post_post', 'trid' => null, 'language_code' => $sseo_codes[0], 'source_language_code' => null ) );
		update_post_meta( $sseo['posts']['analysis_same_language'], '_simple_seo_keyphrase', 'Спільна фраза' );
		sseo_check( 'the same keyphrase on a page of the same language: a competitor', 'ok' === $sseo_an_lang() );
		delete_option( $sseo_other_row );
	}

	wp_set_current_user( 0 );
	delete_option( Simple_SEO_Special::OPTION );
	Simple_SEO_Settings::flush();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Sitemaps' );

	unset( $GLOBALS['current_screen'] );
	delete_option( Simple_SEO_Indexing::OPTION );
	delete_option( Simple_SEO_Sitemaps::OPTION );
	update_option( 'show_on_front', 'posts' );
	Simple_SEO_Settings::flush();
	register_post_type(
		'sseo_map',
		array(
			'public'      => true,
			'has_archive' => true,
			'label'       => 'Maps',
		)
	);
	register_taxonomy(
		'sseo_topic',
		array( 'sseo_map' ),
		array(
			'public' => true,
			'label'  => 'Topics',
		)
	);
	Simple_SEO_Fields::flush_types();

	$sseo_map  = static function ( string $title, array $extra = array() ) use ( &$sseo ): int {
		$id                                         = wp_insert_post( array_merge( array( 'post_title' => $title, 'post_type' => 'sseo_map', 'post_status' => 'publish' ), $extra ) );
		$sseo['posts'][ 'map_' . sanitize_key( $title ) ] = $id;
		return $id;
	};
	$sseo_locs = static fn( ?array $entries ): array => array_map( static fn( array $entry ): string => $entry['loc'], (array) $entries );
	$sseo_w3c  = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/';

	$sseo_shown = $sseo_map( 'Shown', array( 'post_content' => '<p><img class="a" src="/wp-content/uploads/inline.jpg"> <img src=\'//cdn.example.com/x.png?a=1&amp;b=2\'> <img src="data:image/png;base64,AAAA"> <img src="/wp-content/uploads/inline.jpg"></p>' ) );
	update_post_meta( $sseo_shown, '_thumbnail_id', $sseo_og );
	$sseo_hidden = $sseo_map( 'Hidden' );
	update_post_meta( $sseo_hidden, '_simple_seo_index', 'noindex' );
	$sseo_locked = $sseo_map( 'Locked', array( 'post_password' => 'pw' ) );
	$sseo_draft  = $sseo_map( 'Draft', array( 'post_status' => 'draft' ) );
	$sseo_moved  = $sseo_map( 'Moved' );
	update_post_meta( $sseo_moved, '_simple_seo_canonical', 'https://example.com/original/' );
	$sseo_same = $sseo_map( 'Same' );
	update_post_meta( $sseo_same, '_simple_seo_canonical', get_permalink( $sseo_same ) );

	sseo_check( 'the sitemap addresses are registered', isset( $GLOBALS['wp_rewrite']->extra_rules_top['^sitemap_index\.xml$'], $GLOBALS['wp_rewrite']->extra_rules_top['^([a-z0-9_-]+?)-sitemap([0-9]*)\.xml$'] ) && in_array( Simple_SEO_Sitemaps::QUERY_VAR, apply_filters( 'query_vars', array() ), true ) );
	sseo_check( 'on by default, and core\'s wp-sitemap.xml steps aside', Simple_SEO_Sitemaps::enabled() && false === apply_filters( 'wp_sitemaps_enabled', true ) );
	sseo_check( 'served after wp-loc switches the locale (template_redirect 1), before core\'s sitemaps (10)', 2 === has_action( 'template_redirect', array( Simple_SEO_Sitemaps::class, 'serve' ) ) );
	sseo_check( 'sanitize: off only when the form says 0', false === Simple_SEO_Sitemaps::sanitize( array( 'enabled' => '0' ) )['enabled'] && true === Simple_SEO_Sitemaps::sanitize( array( 'enabled' => '1' ) )['enabled'] && true === Simple_SEO_Sitemaps::sanitize( array() )['enabled'] );
	update_option( Simple_SEO_Sitemaps::OPTION, array( 'enabled' => false ) );
	sseo_check( 'switched off: not served, core\'s sitemap comes back', ! Simple_SEO_Sitemaps::enabled() && true === apply_filters( 'wp_sitemaps_enabled', true ) );
	delete_option( Simple_SEO_Sitemaps::OPTION );
	update_option( 'blog_public', '0' );
	sseo_check( 'a site hidden from search engines has no sitemap', ! Simple_SEO_Sitemaps::enabled() );
	update_option( 'blog_public', '1' );
	add_filter( 'simple_seo_conflicting_plugin', static fn(): string => 'Yoast SEO' );
	sseo_check( 'with another SEO plugin the addresses stay its own', ! Simple_SEO_Sitemaps::enabled() && true === apply_filters( 'wp_sitemaps_enabled', true ) );
	remove_all_filters( 'simple_seo_conflicting_plugin' );

	sseo_check( 'addresses: the index and pages the way Yoast SEO named them', home_url( '/sitemap_index.xml' ) === Simple_SEO_Sitemaps::index_url() && home_url( '/sseo_map-sitemap.xml' ) === Simple_SEO_Sitemaps::url( 'sseo_map' ) && home_url( '/sseo_map-sitemap2.xml' ) === Simple_SEO_Sitemaps::url( 'sseo_map', 2 ) );
	add_filter( 'pre_option_permalink_structure', '__return_empty_string' );
	$sseo_url = Simple_SEO_Sitemaps::url( 'sseo_map', 2 );
	remove_filter( 'pre_option_permalink_structure', '__return_empty_string' );
	sseo_check( 'addresses without pretty permalinks use query arguments', str_contains( $sseo_url, 'simple_seo_sitemap=sseo_map' ) && str_contains( $sseo_url, 'simple_seo_sitemap_page=2' ), $sseo_url );
	sseo_check( 'what a name lists', 'post' === Simple_SEO_Sitemaps::kind_of( 'sseo_map' ) && 'term' === Simple_SEO_Sitemaps::kind_of( 'sseo_topic' ) && 'author' === Simple_SEO_Sitemaps::kind_of( 'author' ) && '' === Simple_SEO_Sitemaps::kind_of( 'sseo_private' ) && '' === Simple_SEO_Sitemaps::kind_of( 'attachment' ) );

	// One archive entry: the sitemap's own language (sseo_map is not translated by wp-loc anyway).
	$sseo_lead    = 1;
	$sseo_entries = Simple_SEO_Sitemaps::entries( 'sseo_map', 1 );
	$sseo_l       = $sseo_locs( $sseo_entries );
	sseo_check( 'a type: its archive first, then the posts search engines may show', get_post_type_archive_link( 'sseo_map' ) === ( $sseo_l[0] ?? '' ) && array( get_permalink( $sseo_shown ), get_permalink( $sseo_same ) ) === array_slice( $sseo_l, $sseo_lead ), $sseo_l );
	$sseo_entry = array_column( (array) $sseo_entries, null, 'loc' )[ get_permalink( $sseo_shown ) ] ?? array();
	sseo_check( 'images: the featured image and those in the text, absolute, once each, no data: URIs', array( wp_get_attachment_url( $sseo_og ), home_url( '/wp-content/uploads/inline.jpg' ), 'http://cdn.example.com/x.png?a=1&b=2' ) === ( $sseo_entry['images'] ?? null ), $sseo_entry['images'] ?? null );
	sseo_check( 'lastmod in W3C format, in UTC', (bool) preg_match( $sseo_w3c, $sseo_entry['lastmod'] ?? '' ) && get_post_modified_time( 'c', true, $sseo_shown ) === $sseo_entry['lastmod'], $sseo_entry['lastmod'] ?? '' );
	sseo_check( 'without wp-loc: no alternates', array() === ( $sseo_entry['alternates'] ?? null ) || Simple_SEO_WP_Loc::languages() );

	update_option( Simple_SEO_Indexing::OPTION, array( 'noindex' => array( 'post:sseo_map', 'archive:sseo_map', 'date', 'author', 'post:attachment' ) ) );
	update_post_meta( $sseo_same, '_simple_seo_index', 'index' );
	sseo_check( 'a hidden type lists only the posts shown on their own', array( get_permalink( $sseo_same ) ) === $sseo_locs( Simple_SEO_Sitemaps::entries( 'sseo_map', 1 ) ) );
	delete_post_meta( $sseo_same, '_simple_seo_index' );
	sseo_check( 'a hidden type with nothing shown has no sitemap', 0 === Simple_SEO_Sitemaps::page_count( 'sseo_map' ) && ! in_array( 'sseo_map', array_column( Simple_SEO_Sitemaps::sitemaps(), 'name' ), true ) && null === Simple_SEO_Sitemaps::entries( 'sseo_map', 1 ) );
	delete_option( Simple_SEO_Indexing::OPTION );

	$sseo_topic = static function ( string $name ) use ( &$sseo ): int {
		$term            = wp_insert_term( $name, 'sseo_topic' );
		$sseo['terms'][] = array( (int) $term['term_id'], 'sseo_topic' );
		return (int) $term['term_id'];
	};
	$sseo_t_full   = $sseo_topic( 'Full topic' );
	$sseo_t_empty  = $sseo_topic( 'Empty topic' );
	$sseo_t_hidden = $sseo_topic( 'Hidden topic' );
	wp_set_object_terms( $sseo_shown, array( $sseo_t_full, $sseo_t_hidden ), 'sseo_topic' );
	update_term_meta( $sseo_t_hidden, '_simple_seo_index', 'noindex' );
	$sseo_entries = Simple_SEO_Sitemaps::entries( 'sseo_topic', 1 );
	sseo_check( 'terms: those with posts, not hidden ones', array( get_term_link( $sseo_t_full, 'sseo_topic' ) ) === $sseo_locs( $sseo_entries ), $sseo_locs( $sseo_entries ) );
	sseo_check( 'a term is as new as its newest post', get_post_modified_time( 'c', true, $sseo_shown ) === ( $sseo_entries[0]['lastmod'] ?? '' ) );
	add_filter( 'simple_seo_sitemap_entries', '__return_true' );
	sseo_check( 'empty and hidden terms are not even counted', 1 === Simple_SEO_Sitemaps::page_count( 'sseo_topic' ) );
	remove_filter( 'simple_seo_sitemap_entries', '__return_true' );

	sseo_check( 'authors stay out while author archives are hidden', 0 === Simple_SEO_Sitemaps::page_count( 'author' ) && ! in_array( 'author', array_column( Simple_SEO_Sitemaps::sitemaps(), 'name' ), true ) );
	update_option( Simple_SEO_Indexing::OPTION, array( 'noindex' => array( 'date', 'post:attachment' ) ) );
	$sseo_l = $sseo_locs( Simple_SEO_Sitemaps::entries( 'author', 1 ) );
	sseo_check( 'with author archives shown: the authors who have published', in_array( get_author_posts_url( $sseo_ed ), $sseo_l, true ) && ! in_array( get_author_posts_url( $sseo_u['subscriber'] ), $sseo_l, true ), $sseo_l );
	delete_option( Simple_SEO_Indexing::OPTION );

	$sseo_entries = Simple_SEO_Sitemaps::entries( 'page', 1 );
	sseo_check( 'the page sitemap opens with the home page', home_url( '/' ) === ( $sseo_entries[0]['loc'] ?? '' ) && (bool) preg_match( $sseo_w3c, $sseo_entries[0]['lastmod'] ?? '' ) );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $sseo_p['front'] );
	$sseo_l = $sseo_locs( Simple_SEO_Sitemaps::entries( 'page', 1 ) );
	sseo_check( 'a static front page is listed once, as the home page', home_url( '/' ) === ( $sseo_l[0] ?? '' ) && 1 === count( array_keys( $sseo_l, home_url( '/' ), true ) ) && in_array( get_permalink( $sseo_p['blog'] ), $sseo_l, true ), $sseo_l );
	update_option( 'show_on_front', 'posts' );

	add_filter( 'simple_seo_sitemap_entries', static fn(): int => 2 );
	$sseo_map( 'Third' );
	// Shown, Moved, Same and Third pass the query; Moved (canonical elsewhere) drops out of page 1 afterwards.
	sseo_check( 'pages of the size asked for', 2 === Simple_SEO_Sitemaps::page_count( 'sseo_map' ) && array( get_permalink( $sseo_same ), get_permalink( $sseo['posts']['map_third'] ) ) === $sseo_locs( Simple_SEO_Sitemaps::entries( 'sseo_map', 2 ) ) && null === Simple_SEO_Sitemaps::entries( 'sseo_map', 3 ) && null === Simple_SEO_Sitemaps::entries( 'sseo_map', 0 ), array( Simple_SEO_Sitemaps::page_count( 'sseo_map' ), $sseo_locs( Simple_SEO_Sitemaps::entries( 'sseo_map', 2 ) ) ) );
	$sseo_xml = Simple_SEO_Sitemaps::render_index();
	remove_all_filters( 'simple_seo_sitemap_entries' );
	$sseo_doc = simplexml_load_string( $sseo_xml );
	sseo_check( 'the index: well-formed, every page listed with its date', false !== $sseo_doc && str_contains( $sseo_xml, '<loc>' . home_url( '/sseo_map-sitemap2.xml' ) . '</loc>' ) && str_contains( $sseo_xml, '<loc>' . home_url( '/sseo_topic-sitemap.xml' ) . '</loc>' ) && ! str_contains( $sseo_xml, 'author-sitemap' ) && (bool) preg_match( '#<lastmod>\d{4}-\d\d-\d\dT[^<]+\+00:00</lastmod>#', $sseo_xml ), $sseo_xml );

	$sseo_xml = (string) Simple_SEO_Sitemaps::render_sitemap( 'sseo_map', 1 );
	sseo_check( 'a sitemap page: well-formed, styled with CSS (no XSLT), with images and escaped addresses', false !== simplexml_load_string( $sseo_xml ) && str_contains( $sseo_xml, '<?xml-stylesheet type="text/css" href="' . home_url( '/sitemap.css?ver=' . SIMPLE_SEO_VERSION ) . '"?>' ) && ! str_contains( $sseo_xml, 'text/xsl' ) && str_contains( $sseo_xml, '<image:loc>http://cdn.example.com/x.png?a=1&amp;b=2</image:loc>' ), $sseo_xml );
	$sseo_css = Simple_SEO_Sitemaps::render_stylesheet();
	sseo_check( 'the stylesheet styles the sitemap and index elements and their namespaces', str_contains( $sseo_css, '@namespace xhtml url(http://www.w3.org/1999/xhtml);' ) && str_contains( $sseo_css, 'xhtml|link::before' ) && str_contains( $sseo_css, 'image|loc::before' ) && str_contains( $sseo_css, 'sitemapindex::before' ) && substr_count( $sseo_css, '{' ) === substr_count( $sseo_css, '}' ) );
	add_filter( 'gettext', $sseo_quote = static fn( string $text ): string => 'Last modified:' === $text ? 'Say "hi" \\ now' : $text );
	$sseo_css = Simple_SEO_Sitemaps::render_stylesheet();
	remove_filter( 'gettext', $sseo_quote );
	sseo_check( 'labels are safe CSS strings', str_contains( $sseo_css, '"Say \\"hi\\" \\\\ now"' ), $sseo_css );
	sseo_check( 'namespaces only where used', ! str_contains( $sseo_xml, 'xmlns:xhtml' ) || Simple_SEO_WP_Loc::languages() );
	sseo_check( 'no such sitemap: nothing to send', null === Simple_SEO_Sitemaps::render_sitemap( 'nope', 1 ) && null === Simple_SEO_Sitemaps::render_sitemap( 'sseo_map', 9 ) );

	sseo_check( 'robots.txt names the index', str_contains( Simple_SEO_Sitemaps::robots_txt( "User-agent: *\n", '1' ), 'Sitemap: ' . home_url( '/sitemap_index.xml' ) ) && ! str_contains( Simple_SEO_Sitemaps::robots_txt( '', '0' ), 'Sitemap:' ) );
	update_option( Simple_SEO_Sitemaps::OPTION, array( 'enabled' => false ) );
	sseo_check( 'robots.txt: no sitemap line while it is off', ! str_contains( Simple_SEO_Sitemaps::robots_txt( '', '1' ), 'Sitemap:' ) );
	delete_option( Simple_SEO_Sitemaps::OPTION );
	unset( $GLOBALS['wp_rewrite']->extra_rules_top['^sitemap_index\.xml$'] );
	add_filter( 'simple_seo_conflicting_plugin', static fn(): string => 'Yoast SEO' );
	Simple_SEO_Sitemaps::register_rewrites();
	$sseo_taken = isset( $GLOBALS['wp_rewrite']->extra_rules_top['^sitemap_index\.xml$'] );
	remove_all_filters( 'simple_seo_conflicting_plugin' );
	Simple_SEO_Sitemaps::register_rewrites();
	sseo_check( 'no sitemap addresses of ours next to another SEO plugin', ! $sseo_taken && isset( $GLOBALS['wp_rewrite']->extra_rules_top['^sitemap_index\.xml$'] ) );
	$sseo_q = new WP_Query();
	$sseo_q->set( Simple_SEO_Sitemaps::QUERY_VAR, Simple_SEO_Sitemaps::index_url() ? '__index' : '' );
	$sseo_main                 = $GLOBALS['wp_the_query'];
	$GLOBALS['wp_the_query']   = $sseo_q;
	$sseo_skipped              = apply_filters( 'posts_pre_query', null, $sseo_q );
	$GLOBALS['wp_the_query']   = $sseo_main;
	sseo_check( 'a sitemap request skips the posts query', array() === $sseo_skipped && null === apply_filters( 'posts_pre_query', null, new WP_Query() ) );

	wp_cache_flush();
	$sseo_queries = $GLOBALS['wpdb']->num_queries;
	Simple_SEO_Sitemaps::entries( 'sseo_map', 1 );
	$sseo_few = $GLOBALS['wpdb']->num_queries - $sseo_queries;
	foreach ( array( 'Fourth', 'Fifth', 'Sixth', 'Seventh' ) as $sseo_title ) {
		$sseo_map( $sseo_title );
	}
	wp_cache_flush();
	$sseo_queries = $GLOBALS['wpdb']->num_queries;
	Simple_SEO_Sitemaps::entries( 'sseo_map', 1 );
	$sseo_more = $GLOBALS['wpdb']->num_queries - $sseo_queries;
	sseo_check( 'a sitemap page costs the same queries for more posts', $sseo_more <= $sseo_few, array( $sseo_few, $sseo_more ) );

	Simple_SEO_Indexing::add_admin_tab();
	Simple_SEO_Admin::register_settings();
	$sseo_registered = get_registered_settings();
	sseo_check( 'the Indexing tab saves the sitemap setting too', 'simple-seo-indexing' === ( $sseo_registered[ Simple_SEO_Sitemaps::OPTION ]['group'] ?? '' ) );
	wp_set_current_user( $sseo_u['administrator'] );
	$_GET['tab'] = 'indexing';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'] );
	wp_set_current_user( 0 );
	sseo_check( 'the Indexing tab: the sitemap switch, on, with the link', (bool) preg_match( '/name="simple_seo_sitemaps\[enabled\]" value="1"\s+checked/', $sseo_html ) && str_contains( $sseo_html, 'href="' . home_url( '/sitemap_index.xml' ) . '"' ) && str_contains( $sseo_html, 'stays out of the XML sitemap' ) );

	Simple_SEO_Plugin::deactivate();
	sseo_check( 'deactivation drops the rewrite rules for WordPress to build again', ! get_option( 'rewrite_rules' ) );
	Simple_SEO_Plugin::activate();
	sseo_check( 'activation builds them with the sitemap addresses', isset( get_option( 'rewrite_rules' )['^sitemap_index\.xml$'] ) );
	delete_option( 'rewrite_rules' );
	update_option( Simple_SEO_Plugin::VERSION_OPTION, '0.6.0' );
	Simple_SEO_Plugin::maybe_upgrade();
	sseo_check( 'an update builds them too (no activation hook runs then)', isset( get_option( 'rewrite_rules' )['^sitemap_index\.xml$'] ) && SIMPLE_SEO_VERSION === get_option( Simple_SEO_Plugin::VERSION_OPTION ) );

	if ( Simple_SEO_WP_Loc::languages() ) {
		Simple_SEO_WP_Loc::flush();
		$sseo_codes = array_keys( Simple_SEO_WP_Loc::languages() );
		$sseo_d     = Simple_SEO_WP_Loc::default_language();
		$sseo_o     = (string) current( array_diff( $sseo_codes, array( $sseo_d ) ) );
		$sseo_lang  = static function ( int $id, string $type, string $code, int $trid = 0 ) use ( $sseo_d ): void {
			do_action( 'wpml_set_element_language_details', array( 'element_id' => $id, 'element_type' => $type, 'trid' => $trid ?: null, 'language_code' => $code, 'source_language_code' => $trid ? $sseo_d : null ) );
		};
		$sseo['posts']['ml_d'] = wp_insert_post( array( 'post_title' => 'Ml default', 'post_status' => 'publish' ) );
		$sseo['posts']['ml_o'] = wp_insert_post( array( 'post_title' => 'Ml other', 'post_status' => 'publish' ) );
		$sseo_lang( $sseo['posts']['ml_d'], 'post_post', $sseo_d );
		$sseo_lang( $sseo['posts']['ml_o'], 'post_post', $sseo_o, (int) apply_filters( 'wpml_element_trid', 0, $sseo['posts']['ml_d'], 'post_post' ) );
		$sseo_c_d = wp_insert_term( 'Ml cat default', 'category' );
		$sseo_c_o = wp_insert_term( 'Ml cat other', 'category' );
		$sseo['terms'][] = array( (int) $sseo_c_d['term_id'], 'category' );
		$sseo['terms'][] = array( (int) $sseo_c_o['term_id'], 'category' );
		$sseo_lang( (int) $sseo_c_d['term_taxonomy_id'], 'tax_category', $sseo_d );
		$sseo_lang( (int) $sseo_c_o['term_taxonomy_id'], 'tax_category', $sseo_o, (int) apply_filters( 'wpml_element_trid', 0, $sseo_c_d['term_taxonomy_id'], 'tax_category' ) );
		// wp-loc moves a post's terms to its language: assign them once the languages are set.
		wp_set_post_categories( $sseo['posts']['ml_d'], array( (int) $sseo_c_d['term_id'] ) );
		wp_set_post_categories( $sseo['posts']['ml_o'], array( (int) $sseo_c_o['term_id'] ) );
		clean_term_cache( array( (int) $sseo_c_d['term_id'], (int) $sseo_c_o['term_id'] ), 'category' );

		$sseo_hl  = Simple_SEO_WP_Loc::languages();
		$sseo_by  = static fn( ?array $entries ): array => array_column( (array) $entries, null, 'loc' );
		$sseo_e   = $sseo_by( Simple_SEO_Sitemaps::entries( 'post', 1, $sseo_d ) );
		$sseo_eo  = $sseo_by( Simple_SEO_Sitemaps::entries( 'post', 1, $sseo_o ) );
		$sseo_u_d = get_permalink( $sseo['posts']['ml_d'] );
		$sseo_u_o = get_permalink( $sseo['posts']['ml_o'] );
		$sseo_alt = array(
			$sseo_hl[ $sseo_d ] => $sseo_u_d,
			$sseo_hl[ $sseo_o ] => $sseo_u_o,
			'x-default'         => $sseo_u_d,
		);
		$sseo_prefix = '/' . WP_LOC_DB::from_db_language_code( $sseo_o ) . '/';
		sseo_check( 'wp-loc: each language has its own sitemap, the other one under its prefix', isset( $sseo_e[ $sseo_u_d ], $sseo_eo[ $sseo_u_o ] ) && ! isset( $sseo_e[ $sseo_u_o ] ) && ! isset( $sseo_eo[ $sseo_u_d ] ) && str_contains( $sseo_u_o, $sseo_prefix ), array( array_keys( $sseo_e ), array_keys( $sseo_eo ) ) );
		sseo_check( 'wp-loc: every address of the other language\'s sitemap is under its prefix', ! array_filter( array_keys( $sseo_eo ), static fn( string $loc ): bool => ! str_contains( $loc, $sseo_prefix ) ), array_keys( $sseo_eo ) );
		sseo_check( 'wp-loc: each translation lists both with their hreflang, and x-default', $sseo_alt === ( $sseo_e[ $sseo_u_d ]['alternates'] ?? null ) && $sseo_alt === ( $sseo_eo[ $sseo_u_o ]['alternates'] ?? null ), $sseo_e[ $sseo_u_d ]['alternates'] ?? null );
		sseo_check( 'wp-loc: the sitemap XML carries them as xhtml:link', str_contains( (string) Simple_SEO_Sitemaps::render_sitemap( 'post', 1, $sseo_d ), '<xhtml:link rel="alternate" hreflang="' . $sseo_hl[ $sseo_o ] . '" href="' . $sseo_u_o . '"/>' ) );
		$sseo_xml         = Simple_SEO_Sitemaps::render_index();
		$sseo_first_other = strpos( $sseo_xml, home_url( $sseo_prefix ) );
		sseo_check( 'wp-loc: the index lists the sitemaps language by language, the default one first', str_contains( $sseo_xml, '<loc>' . home_url( '/post-sitemap.xml' ) . '</loc>' ) && str_contains( $sseo_xml, '<loc>' . home_url( $sseo_prefix . 'post-sitemap.xml' ) . '</loc>' ) && false !== $sseo_first_other && $sseo_first_other > strrpos( $sseo_xml, '<loc>' . home_url( '/category-sitemap.xml' ) ) && ! str_contains( $sseo_xml, $sseo_prefix . 'sseo_map-sitemap' ), $sseo_xml );
		sseo_check( 'wp-loc: a sitemap of the other language takes the stylesheet of its language', str_contains( (string) Simple_SEO_Sitemaps::render_sitemap( 'post', 1, $sseo_o ), 'href="' . home_url( $sseo_prefix . 'sitemap.css?ver=' . SIMPLE_SEO_VERSION ) . '"' ) );
		update_post_meta( $sseo['posts']['ml_o'], '_simple_seo_index', 'noindex' );
		$sseo_e  = $sseo_by( Simple_SEO_Sitemaps::entries( 'post', 1, $sseo_d ) );
		$sseo_eo = $sseo_by( (array) Simple_SEO_Sitemaps::entries( 'post', 1, $sseo_o ) );
		sseo_check( 'wp-loc: a hidden translation leaves the sitemap and the alternates', ! isset( $sseo_eo[ $sseo_u_o ] ) && array() === ( $sseo_e[ $sseo_u_d ]['alternates'] ?? null ) );
		delete_post_meta( $sseo['posts']['ml_o'], '_simple_seo_index' );

		$sseo_e   = $sseo_by( Simple_SEO_Sitemaps::entries( 'category', 1, $sseo_o ) );
		$sseo_t_o = Simple_SEO_WP_Loc::in_language( $sseo_o, static fn() => get_term_link( (int) $sseo_c_o['term_id'], 'category' ) );
		sseo_check( 'wp-loc: a term is listed in its language\'s sitemap with its link and alternates', isset( $sseo_e[ $sseo_t_o ] ) && str_contains( $sseo_t_o, $sseo_prefix ) && 3 === count( $sseo_e[ $sseo_t_o ]['alternates'] ?? array() ) && ! isset( $sseo_by( Simple_SEO_Sitemaps::entries( 'category', 1, $sseo_d ) )[ $sseo_t_o ] ), array_keys( $sseo_e ) );
		$sseo_h = trailingslashit( (string) apply_filters( 'wpml_home_url', '', $sseo_o ) );
		$sseo_l = $sseo_locs( Simple_SEO_Sitemaps::entries( 'page', 1, $sseo_d ) );
		$sseo_m = Simple_SEO_Sitemaps::entries( 'page', 1, $sseo_o );
		sseo_check( 'wp-loc: each language\'s page sitemap opens with its home page, the others as alternates', home_url( '/' ) === ( $sseo_l[0] ?? '' ) && ! in_array( $sseo_h, $sseo_l, true ) && $sseo_h === ( $sseo_m[0]['loc'] ?? '' ) && 3 === count( $sseo_m[0]['alternates'] ?? array() ), array( $sseo_l, $sseo_m[0] ?? null ) );
		$sseo_queries = $GLOBALS['wpdb']->num_queries;
		Simple_SEO_Sitemaps::page_count( 'sseo_map', 'post', $sseo_o );
		sseo_check( 'wp-loc: an untranslated type costs no query in another language', $sseo_queries === $GLOBALS['wpdb']->num_queries, $GLOBALS['wpdb']->num_queries - $sseo_queries );
		$sseo_l = $sseo_locs( Simple_SEO_Sitemaps::entries( 'sseo_map', 1, $sseo_d ) );
		sseo_check( 'wp-loc: a type wp-loc does not translate stays in the default language, posts without a language included', 0 === Simple_SEO_Sitemaps::page_count( 'sseo_map', 'post', $sseo_o ) && in_array( get_permalink( $sseo_shown ), $sseo_l, true ) && 1 === count( array_filter( $sseo_l, static fn( string $loc ): bool => get_post_type_archive_link( 'sseo_map' ) === $loc ) ), $sseo_l );

		do_action( 'wpml_switch_language', $sseo_o );
		$sseo_e   = $sseo_by( Simple_SEO_Sitemaps::entries( 'post', 1 ) );
		$sseo_idx = Simple_SEO_Sitemaps::index_url();
		$sseo_c   = Simple_SEO_WP_Loc::current_language();
		do_action( 'wpml_switch_language', $sseo_d );
		sseo_check( 'wp-loc: a request in the other language gets its sitemap; the index stays at the default address', isset( $sseo_e[ $sseo_u_o ] ) && ! isset( $sseo_e[ $sseo_u_d ] ) && $sseo_o === $sseo_c && home_url( '/sitemap_index.xml' ) === $sseo_idx, $sseo_idx );

		$sseo_hreflang = static fn( string $code, string $slug ): string => 'en-US' === $code ? 'en' : $code;
		add_filter( 'wp_loc_hreflang_code', $sseo_hreflang, 10, 2 );
		Simple_SEO_WP_Loc::flush();
		$sseo_hl2 = Simple_SEO_WP_Loc::languages();
		remove_filter( 'wp_loc_hreflang_code', $sseo_hreflang, 10 );
		Simple_SEO_WP_Loc::flush();
		sseo_check( 'wp-loc: hreflang values go through wp_loc_hreflang_code, as in its head output', in_array( 'en', $sseo_hl2, true ) || ! in_array( 'en-US', $sseo_hl, true ), $sseo_hl2 );
	} else {
		echo "  skip  sitemaps with wp-loc (tools/wp-loc-it.sh runs these checks)\n";
	}

	delete_option( Simple_SEO_Indexing::OPTION );
	Simple_SEO_Settings::flush();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Breadcrumbs and primary term' );

	unset( $GLOBALS['current_screen'] );
	delete_option( Simple_SEO_Breadcrumbs::OPTION );
	delete_option( Simple_SEO_Breadcrumbs::LABELS );
	update_option( 'show_on_front', 'posts' );
	Simple_SEO_Settings::flush();
	$sseo_cat_term = static function ( string $name, int $parent = 0 ) use ( &$sseo ): int {
		$term            = wp_insert_term( $name, 'category', array( 'parent' => $parent ) );
		$sseo['terms'][] = array( (int) $term['term_id'], 'category' );
		return (int) $term['term_id'];
	};
	$sseo_news  = $sseo_cat_term( 'Bc News' );
	$sseo_sport = $sseo_cat_term( 'Bc Sport', $sseo_news );
	$sseo_other = $sseo_cat_term( 'Bc Other' );
	$sseo_unused = $sseo_cat_term( 'Bc Unused' );
	$sseo['posts']['bc'] = wp_insert_post( array( 'post_title' => 'Bc Post', 'post_status' => 'publish', 'post_category' => array( $sseo_news, $sseo_sport, $sseo_other ) ) );
	$sseo['posts']['bc_parent'] = wp_insert_post( array( 'post_title' => 'Bc Parent', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$sseo['posts']['bc_child']  = wp_insert_post( array( 'post_title' => 'Bc Child', 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $sseo['posts']['bc_parent'] ) );
	$sseo['posts']['bc_map']    = wp_insert_post( array( 'post_title' => 'Bc Map', 'post_type' => 'sseo_map', 'post_status' => 'publish' ) );
	$sseo_p      = $sseo['posts'];
	$sseo_bc     = get_post( $sseo_p['bc'] );
	$sseo_labels = static fn( array $items ): array => array_column( $items, 'label' );
	$sseo_trail  = static fn( array $vars ): array => $sseo_labels( Simple_SEO_Breadcrumbs::items( $sseo_ctx_of( $vars ) ) );

	// The primary term.
	$sseo_field = Simple_SEO_Fields::get( 'primary_category' );
	sseo_check( 'a primary category field, for posts only', null !== $sseo_field && in_array( 'post', $sseo_field['post_types'], true ) && ! in_array( 'page', $sseo_field['post_types'], true ) && array( 'post' ) === $sseo_field['objects'] && ! Simple_SEO_Fields::user_can_edit( $sseo_u['administrator'], 'post', $sseo_p['bc_parent'], 'primary_category' ) && Simple_SEO_Fields::user_can_edit( $sseo_u['administrator'], 'post', $sseo_p['bc'], 'primary_category' ) && ! Simple_SEO_Fields::user_can_edit( $sseo_u['administrator'], 'term', $sseo_news, 'primary_category' ) );
	sseo_check( 'no primary field for a flat taxonomy', null === Simple_SEO_Fields::get( 'primary_post_tag' ) && null === Simple_SEO_Fields::get( 'primary_sseo_genre' ) );
	sseo_check( 'sanitize: a category ID, nothing else', $sseo_sport === Simple_SEO_Primary_Term::sanitize( (string) $sseo_sport, 'category' ) && '' === Simple_SEO_Primary_Term::sanitize( 99999999, 'category' ) && '' === Simple_SEO_Primary_Term::sanitize( 'abc', 'category' ) );
	$sseo_tag = wp_insert_term( 'Bc Tag', 'post_tag' );
	$sseo['terms'][] = array( (int) $sseo_tag['term_id'], 'post_tag' );
	sseo_check( 'sanitize: a term of another taxonomy is no category', '' === Simple_SEO_Primary_Term::sanitize( $sseo_tag['term_id'], 'category' ) );
	$sseo_options = Simple_SEO_Primary_Term::options( 'category' );
	sseo_check( 'options: automatic first, children indented under their parent', '' === array_key_first( $sseo_options ) && '— Bc Sport' === ( $sseo_options[ (string) $sseo_sport ] ?? '' ) && array_search( (string) $sseo_news, array_map( 'strval', array_keys( $sseo_options ) ), true ) + 1 === array_search( (string) $sseo_sport, array_map( 'strval', array_keys( $sseo_options ) ), true ) );

	sseo_check( 'automatically: the deepest of the post\'s categories', $sseo_sport === ( Simple_SEO_Primary_Term::get( $sseo_bc, 'category' )->term_id ?? 0 ) );
	update_post_meta( $sseo_p['bc'], '_simple_seo_primary_category', $sseo_other );
	sseo_check( 'a picked category wins', $sseo_other === ( Simple_SEO_Primary_Term::get( $sseo_bc, 'category' )->term_id ?? 0 ) && 'Bc Other' === ( simple_seo_primary_term( $sseo_p['bc'] )->name ?? '' ) );
	sseo_check( '{category} names the primary category', 'Bc Other' === Simple_SEO_Variables::replace( '{category}', Simple_SEO_Context::for_post( $sseo_bc ), '–' ) );
	update_post_meta( $sseo_p['bc'], '_simple_seo_primary_category', $sseo_unused );
	sseo_check( 'a picked category the post no longer has is ignored', $sseo_sport === ( Simple_SEO_Primary_Term::get( $sseo_bc, 'category' )->term_id ?? 0 ) );
	add_filter( 'simple_seo_primary_term', static fn() => get_term( $sseo_news, 'category' ) );
	sseo_check( 'simple_seo_primary_term changes it', $sseo_news === ( Simple_SEO_Primary_Term::get( $sseo_bc, 'category' )->term_id ?? 0 ) );
	remove_all_filters( 'simple_seo_primary_term' );
	update_post_meta( $sseo_p['bc'], '_simple_seo_primary_category', $sseo_sport );

	wp_set_current_user( $sseo_ed );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( $sseo_bc ) );
	sseo_check( 'meta box: the primary category under a Breadcrumbs heading, the picked one selected', str_contains( $sseo_html, '>Breadcrumbs</h4>' ) && str_contains( $sseo_html, 'name="simple_seo[primary_category]"' ) && (bool) preg_match( '/<option value="' . $sseo_sport . '" selected=\'selected\'>— Bc Sport<\/option>/', $sseo_html ), $sseo_html );
	sseo_check( 'meta box: the trail the post has now', str_contains( $sseo_html, 'Now: Home › Bc News › Bc Sport › Bc Post' ) );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['bc_parent'] ) ) );
	sseo_check( 'meta box of a page: no primary category', ! str_contains( $sseo_html, 'primary_category' ) );
	sseo_post_form( $sseo_p['bc'], array( 'primary_category' => (string) $sseo_other ) );
	Simple_SEO_Editor::save_post( $sseo_p['bc'], $sseo_bc );
	sseo_check( 'the editor form saves the pick', (string) $sseo_other === Simple_SEO_Fields::post_value( $sseo_p['bc'], 'primary_category' ) );
	sseo_post_form( $sseo_p['bc'], array( 'primary_category' => '' ) );
	Simple_SEO_Editor::save_post( $sseo_p['bc'], $sseo_bc );
	sseo_check( 'Automatically deletes the pick', ! metadata_exists( 'post', $sseo_p['bc'], '_simple_seo_primary_category' ) );
	$_POST = array();
	wp_set_current_user( 0 );

	// The trail.
	sseo_check( 'a post: home, the primary category with its parent, the post', array( 'Home', 'Bc News', 'Bc Sport', 'Bc Post' ) === $sseo_trail( array( 'p' => $sseo_p['bc'] ) ), $sseo_trail( array( 'p' => $sseo_p['bc'] ) ) );
	$sseo_items = Simple_SEO_Breadcrumbs::items( $sseo_ctx_of( array( 'p' => $sseo_p['bc'] ) ) );
	sseo_check( 'items link home, the categories and the post', home_url( '/' ) === $sseo_items[0]['url'] && get_term_link( $sseo_news, 'category' ) === $sseo_items[1]['url'] && get_permalink( $sseo_p['bc'] ) === $sseo_items[3]['url'] );
	sseo_check( 'a child page: its parent between', array( 'Home', 'Bc Parent', 'Bc Child' ) === $sseo_trail( array( 'page_id' => $sseo_p['bc_child'] ) ) );
	sseo_check( 'a custom type: its archive between', array( 'Home', 'Maps', 'Bc Map' ) === $sseo_trail( array( 'p' => $sseo_p['bc_map'], 'post_type' => 'sseo_map' ) ), $sseo_trail( array( 'p' => $sseo_p['bc_map'], 'post_type' => 'sseo_map' ) ) );
	sseo_check( 'a category: its parent, then itself', array( 'Home', 'Bc News', 'Bc Sport' ) === $sseo_trail( array( 'cat' => $sseo_sport ) ) );
	sseo_check( 'a post type archive', array( 'Home', 'Maps' ) === $sseo_trail( array( 'post_type' => 'sseo_map' ) ) );
	sseo_check( 'an author', array( 'Home', get_userdata( $sseo_ed )->display_name ) === $sseo_trail( array( 'author' => $sseo_ed ) ) );
	$sseo_day = Simple_SEO_Breadcrumbs::items( $sseo_ctx_of( array( 'm' => gmdate( 'Ymd' ) ) ) );
	sseo_check( 'a day archive: year, month, day', array( 'Home', gmdate( 'Y' ), date_i18n( 'F', gmmktime( 0, 0, 0, (int) gmdate( 'n' ), 1, (int) gmdate( 'Y' ) ) ), (string) (int) gmdate( 'j' ) ) === $sseo_labels( $sseo_day ) && get_year_link( (int) gmdate( 'Y' ) ) === $sseo_day[1]['url'], $sseo_labels( $sseo_day ) );
	sseo_check( 'search results and 404: the phrase', array( 'Home', 'Search results for "x"' ) === $sseo_trail( array( 's' => 'x' ) ) && array( 'Home', 'Page not found' ) === $sseo_labels( Simple_SEO_Breadcrumbs::items( Simple_SEO_Context::from_query( $sseo_q404 ) ) ) );
	$sseo_items = Simple_SEO_Breadcrumbs::items( $sseo_ctx_of( array( 'cat' => $sseo_sport, 'paged' => 2 ) ) );
	sseo_check( 'a second page ends with its number', 'Page 2' === end( $sseo_items )['label'] && str_contains( end( $sseo_items )['url'], '/page/2' ), $sseo_items );
	sseo_check( 'the home page has no trail to print', '' === Simple_SEO_Breadcrumbs::render( array(), $sseo_ctx_of( array() ) ) );

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $sseo_p['front'] );
	update_option( 'page_for_posts', $sseo_p['blog'] );
	sseo_check( 'with a posts page: it comes before posts and their categories', array( 'Home', 'Blog', 'Bc News', 'Bc Sport', 'Bc Post' ) === $sseo_trail( array( 'p' => $sseo_p['bc'] ) ) && array( 'Home', 'Blog', 'Bc News' ) === $sseo_trail( array( 'cat' => $sseo_news ) ) );
	sseo_check( 'the posts page itself', array( 'Home', 'Blog' ) === $sseo_labels( Simple_SEO_Breadcrumbs::items( Simple_SEO_Context::for_post( get_post( $sseo_p['blog'] ) ) ) ) );
	sseo_check( 'a static front page has no trail', '' === Simple_SEO_Breadcrumbs::render( array(), Simple_SEO_Context::for_post( get_post( $sseo_p['front'] ) ) ) );
	update_option( Simple_SEO_Breadcrumbs::OPTION, array( 'show_blog' => false, 'taxonomies' => array( 'post' => '' ) ) );
	sseo_check( 'settings: no posts page, no categories', array( 'Home', 'Bc Post' ) === $sseo_trail( array( 'p' => $sseo_p['bc'] ) ) );
	delete_option( Simple_SEO_Breadcrumbs::OPTION );
	update_option( 'show_on_front', 'posts' );

	sseo_check( 'default taxonomies: categories for posts, parents for pages, the first hierarchical one for others', 'category' === Simple_SEO_Breadcrumbs::taxonomy_for( 'post' ) && '' === Simple_SEO_Breadcrumbs::taxonomy_for( 'page' ) && '' === Simple_SEO_Breadcrumbs::taxonomy_for( 'sseo_map' ) );
	$sseo_clean = Simple_SEO_Breadcrumbs::sanitize(
		array(
			'separator'    => ' <b>»</b> »»»»»»»»»»»» ',
			'show_current' => '0',
			'taxonomies'   => array(
				'post'     => 'category',
				'page'     => 'category',
				'sseo_map' => 'post_tag',
			),
		)
	);
	sseo_check( 'sanitize: the separator without tags, ten characters at most', '» ' . str_repeat( '»', 8 ) === $sseo_clean['separator'], $sseo_clean['separator'] );
	sseo_check( 'sanitize: switches, and only taxonomies that differ from the default and belong to the type', false === $sseo_clean['show_current'] && true === $sseo_clean['show_blog'] && array() === $sseo_clean['taxonomies'], $sseo_clean );
	$sseo_clean = Simple_SEO_Breadcrumbs::sanitize( array( 'separator' => '', 'taxonomies' => array( 'post' => '' ) ) );
	sseo_check( 'sanitize: an empty separator is the default, "none" for posts is kept', Simple_SEO_Breadcrumbs::DEFAULT_SEPARATOR === $sseo_clean['separator'] && array( 'post' => '' ) === $sseo_clean['taxonomies'], $sseo_clean );

	// HTML.
	update_option( Simple_SEO_Breadcrumbs::OPTION, array( 'separator' => '<i>/</i>' ) );
	update_option( Simple_SEO_Breadcrumbs::LABELS, array( 'home' => 'Start & go', 'prefix' => 'You are here:' ) );
	$sseo_html = Simple_SEO_Breadcrumbs::render( array( 'class' => 'theme" onload', 'before' => '<div class="wrap">', 'after' => '</div>' ), $sseo_ctx_of( array( 'p' => $sseo_p['bc'] ) ) );
	sseo_check( 'HTML: a labelled nav, links, the page itself as text', str_contains( $sseo_html, '<nav class="simple-seo-breadcrumbs themeonload" aria-label="Breadcrumbs">' ) && str_contains( $sseo_html, '<a href="' . esc_url( home_url( '/' ) ) . '">Start &amp; go</a>' ) && str_contains( $sseo_html, '<span class="simple-seo-breadcrumbs__current" aria-current="page">Bc Post</span>' ), $sseo_html );
	sseo_check( 'HTML: the separator and labels escaped, prefix, before and after', str_contains( $sseo_html, '&lt;i&gt;/&lt;/i&gt;' ) && str_contains( $sseo_html, '<span class="simple-seo-breadcrumbs__prefix">You are here:</span>' ) && str_starts_with( $sseo_html, '<div class="wrap">' ) && str_ends_with( $sseo_html, '</div>' ), $sseo_html );
	sseo_check( 'text for previews', 'Start & go <i>/</i> Bc News <i>/</i> Bc Sport <i>/</i> Bc Post' === Simple_SEO_Breadcrumbs::text( $sseo_ctx_of( array( 'p' => $sseo_p['bc'] ) ) ) );
	update_option( Simple_SEO_Breadcrumbs::OPTION, array( 'show_current' => false ) );
	$sseo_html = Simple_SEO_Breadcrumbs::render( array(), $sseo_ctx_of( array( 'p' => $sseo_p['bc'] ) ) );
	sseo_check( 'without the page itself: the trail ends with a link to its category', ! str_contains( $sseo_html, 'Bc Post' ) && (bool) preg_match( '#<a href="[^"]+">Bc Sport</a></nav>$#', $sseo_html ), $sseo_html );
	delete_option( Simple_SEO_Breadcrumbs::OPTION );
	delete_option( Simple_SEO_Breadcrumbs::LABELS );

	add_filter( 'simple_seo_breadcrumbs', static fn( array $items ): array => array_merge( $items, array( array( 'label' => 'Extra', 'url' => '' ), array( 'label' => '', 'url' => 'x' ) ) ) );
	sseo_check( 'simple_seo_breadcrumbs changes the items; empty labels are dropped', array( 'Home', 'Bc News', 'Bc Sport', 'Bc Post', 'Extra' ) === $sseo_trail( array( 'p' => $sseo_p['bc'] ) ) );
	remove_all_filters( 'simple_seo_breadcrumbs', 10 );

	$sseo_saved_query    = $GLOBALS['wp_query'];
	$GLOBALS['wp_query'] = sseo_query( array( 'p' => $sseo_p['bc'] ) );
	$GLOBALS['wp_actions']['wp'] = max( 1, (int) ( $GLOBALS['wp_actions']['wp'] ?? 0 ) );
	Simple_SEO_Context::reset();
	$sseo_html = do_shortcode( '[simple_seo_breadcrumbs class="in-content"]' );
	sseo_check( 'the shortcode prints the trail of the page', str_contains( $sseo_html, 'class="simple-seo-breadcrumbs in-content"' ) && str_contains( $sseo_html, '>Bc Post</span>' ), $sseo_html );
	sseo_check( 'the template functions', simple_seo_breadcrumbs() === Simple_SEO_Breadcrumbs::render() && simple_seo_breadcrumbs() === sseo_capture( 'simple_seo_the_breadcrumbs' ) && '' !== simple_seo_breadcrumbs() );
	$GLOBALS['wp_query'] = $sseo_saved_query;
	Simple_SEO_Context::reset();
	sseo_check( 'Twig: the function goes to Timber', 10 === has_filter( 'timber/twig', array( Simple_SEO_Breadcrumbs::class, 'twig' ) ) );
	if ( class_exists( '\Twig\Environment' ) ) {
		$sseo_twig = Simple_SEO_Breadcrumbs::twig( new \Twig\Environment( new \Twig\Loader\ArrayLoader( array() ) ) );
		sseo_check( 'Twig: simple_seo_breadcrumbs() is registered, its HTML not escaped', false !== $sseo_twig->getFunction( 'simple_seo_breadcrumbs' ) );
	}

	// The tab.
	Simple_SEO_Breadcrumbs::add_admin_tab();
	Simple_SEO_Admin::register_settings();
	wp_set_current_user( $sseo_u['administrator'] );
	$_GET['tab'] = 'breadcrumbs';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'] );
	wp_set_current_user( 0 );
	sseo_check( 'the Breadcrumbs tab sits between Indexing and Access', strpos( $sseo_html, '>Indexing<' ) < strpos( $sseo_html, '>Breadcrumbs<' ) && strpos( $sseo_html, '>Breadcrumbs<' ) < strpos( $sseo_html, '>Access<' ) );
	sseo_check( 'the tab: how to use it, the newest post\'s trail, the settings', str_contains( $sseo_html, 'simple_seo_the_breadcrumbs()' ) && str_contains( $sseo_html, '[simple_seo_breadcrumbs]' ) && str_contains( $sseo_html, 'The newest post now:' ) && str_contains( $sseo_html, 'name="simple_seo_breadcrumbs[separator]" value="›"' ) && str_contains( $sseo_html, 'name="simple_seo_breadcrumb_labels[home]"' ) && str_contains( $sseo_html, 'name="simple_seo_breadcrumbs[taxonomies][post]"' ) );
	sseo_check( 'the tab saves both rows', 'simple-seo-breadcrumbs' === ( get_registered_settings()[ Simple_SEO_Breadcrumbs::OPTION ]['group'] ?? '' ) && 'simple-seo-breadcrumbs' === ( get_registered_settings()[ Simple_SEO_Breadcrumbs::LABELS ]['group'] ?? '' ) );

	if ( Simple_SEO_WP_Loc::languages() ) {
		$sseo_d    = Simple_SEO_WP_Loc::default_language();
		$sseo_o    = (string) current( array_diff( Simple_SEO_WP_Loc::codes(), array( $sseo_d ) ) );
		$sseo_lang = static function ( int $id, string $type, string $code, int $trid = 0 ) use ( $sseo_d ): void {
			do_action( 'wpml_set_element_language_details', array( 'element_id' => $id, 'element_type' => $type, 'trid' => $trid ?: null, 'language_code' => $code, 'source_language_code' => $trid ? $sseo_d : null ) );
		};
		$sseo_c_d = wp_insert_term( 'Bc Ml default', 'category' );
		$sseo_c_o = wp_insert_term( 'Bc Ml other', 'category' );
		$sseo['terms'][] = array( (int) $sseo_c_d['term_id'], 'category' );
		$sseo['terms'][] = array( (int) $sseo_c_o['term_id'], 'category' );
		$sseo_lang( (int) $sseo_c_d['term_taxonomy_id'], 'tax_category', $sseo_d );
		$sseo_lang( (int) $sseo_c_o['term_taxonomy_id'], 'tax_category', $sseo_o, (int) apply_filters( 'wpml_element_trid', 0, $sseo_c_d['term_taxonomy_id'], 'tax_category' ) );
		$sseo['posts']['bc_ml_d'] = wp_insert_post( array( 'post_title' => 'Bc Ml post default', 'post_status' => 'publish' ) );
		$sseo['posts']['bc_ml_o'] = wp_insert_post( array( 'post_title' => 'Bc Ml post other', 'post_status' => 'publish' ) );
		$sseo_lang( $sseo['posts']['bc_ml_d'], 'post_post', $sseo_d );
		$sseo_lang( $sseo['posts']['bc_ml_o'], 'post_post', $sseo_o, (int) apply_filters( 'wpml_element_trid', 0, $sseo['posts']['bc_ml_d'], 'post_post' ) );
		// A deeper category of the same language: the automatic choice would take it.
		$sseo_c_deep = Simple_SEO_WP_Loc::in_language( $sseo_o, static fn() => wp_insert_term( 'Bc Ml other deep', 'category', array( 'parent' => (int) $sseo_c_o['term_id'] ) ) );
		$sseo['terms'][] = array( (int) $sseo_c_deep['term_id'], 'category' );
		$sseo_lang( (int) $sseo_c_deep['term_taxonomy_id'], 'tax_category', $sseo_o );
		// wp-loc drops a category given to a post of another language than the current one.
		Simple_SEO_WP_Loc::in_language( $sseo_o, static fn() => wp_set_post_categories( $sseo['posts']['bc_ml_o'], array( (int) $sseo_c_o['term_id'], (int) $sseo_c_deep['term_id'] ) ) );
		// A pick copied from the default language's post names that language's category.
		update_post_meta( $sseo['posts']['bc_ml_o'], '_simple_seo_primary_category', (int) $sseo_c_d['term_id'] );
		$sseo_primary = Simple_SEO_WP_Loc::in_language( $sseo_o, static fn() => Simple_SEO_Primary_Term::get( get_post( $sseo['posts']['bc_ml_o'] ), 'category' ) );
		sseo_check( 'wp-loc: a copied pick is read as its translation', (int) $sseo_c_o['term_id'] === ( $sseo_primary->term_id ?? 0 ), $sseo_primary );
		sseo_check( "wp-loc: Yoast's key reads the translation too, as wp-loc answered for Yoast", (string) $sseo_c_o['term_id'] === get_post_meta( $sseo['posts']['bc_ml_o'], '_yoast_wpseo_primary_category', true ) );
		$sseo_items = Simple_SEO_WP_Loc::in_language( $sseo_o, static fn() => Simple_SEO_Breadcrumbs::items( Simple_SEO_Context::for_post( get_post( $sseo['posts']['bc_ml_o'] ) ) ) );
		$sseo_prefix = '/' . WP_LOC_DB::from_db_language_code( $sseo_o ) . '/';
		sseo_check( 'wp-loc: the trail of a translation goes through its own language\'s category and links', 'Bc Ml other' === ( $sseo_items[1]['label'] ?? '' ) && str_contains( $sseo_items[1]['url'] ?? '', $sseo_prefix ) && str_contains( end( $sseo_items )['url'], $sseo_prefix ), $sseo_items );
		update_option( Simple_SEO_Breadcrumbs::LABELS . '_' . $sseo_o, array( 'home' => 'Other home' ) );
		$sseo_home = Simple_SEO_WP_Loc::in_language( $sseo_o, static fn() => Simple_SEO_Breadcrumbs::home_label() );
		delete_option( Simple_SEO_Breadcrumbs::LABELS . '_' . $sseo_o );
		Simple_SEO_Settings::flush();
		sseo_check( 'wp-loc: the labels are the language\'s own', 'Other home' === $sseo_home && 'Home' === Simple_SEO_Breadcrumbs::home_label(), $sseo_home );
		wp_set_current_user( $sseo_u['administrator'] );
		$_GET['tab'] = 'breadcrumbs';
		$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
		unset( $_GET['tab'] );
		wp_set_current_user( 0 );
		sseo_check( 'wp-loc: the tab\'s example is a post of the admin language, not the newest of all', str_contains( $sseo_html, 'The newest post now:' ) && ! str_contains( $sseo_html, 'Bc Ml post other' ) );
	} else {
		echo "  skip  breadcrumbs with wp-loc (tools/wp-loc-it.sh runs these checks)\n";
	}

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Files: robots.txt and .htaccess' );

	$sseo_robots_path = Simple_SEO_Files::path( Simple_SEO_Files::ROBOTS );
	$sseo_ht_path     = Simple_SEO_Files::path( Simple_SEO_Files::HTACCESS );
	$sseo_copy_path   = dirname( $sseo_ht_path ) . '/' . Simple_SEO_Files::DISK_COPY;
	foreach ( array( $sseo_robots_path, $sseo_ht_path, $sseo_copy_path ) as $sseo_path ) {
		if ( is_file( $sseo_path ) ) {
			unlink( $sseo_path );
		}
	}
	delete_option( Simple_SEO_Files::ROBOTS_OPTION );
	delete_option( Simple_SEO_Files::BACKUPS_OPTION );
	update_option( 'blog_public', '1' );
	wp_set_current_user( $sseo_u['administrator'] );
	$sseo_fp = static fn( string $file ): string => Simple_SEO_Files::fingerprint( Simple_SEO_Files::current( $file ) );

	sseo_check( 'the paths are the site root', ABSPATH . 'robots.txt' === $sseo_robots_path && ABSPATH . '.htaccess' === $sseo_ht_path, array( $sseo_robots_path, $sseo_ht_path ) );
	sseo_check( 'the robots.txt text replaces the WordPress lines before other filters', -10 === has_filter( 'robots_txt', array( Simple_SEO_Files::class, 'robots_txt' ) ) );
	remove_filter( 'robots_txt', array( Simple_SEO_Files::class, 'robots_txt' ), -10 );
	remove_filter( 'robots_txt', array( Simple_SEO_Sitemaps::class, 'robots_txt' ), 20 );
	$sseo_core_robots = sseo_capture( 'do_robots' );
	add_filter( 'robots_txt', array( Simple_SEO_Files::class, 'robots_txt' ), -10, 2 );
	add_filter( 'robots_txt', array( Simple_SEO_Sitemaps::class, 'robots_txt' ), 20, 2 );
	sseo_check( 'the default text is what WordPress itself serves', $sseo_core_robots === Simple_SEO_Files::robots_default(), $sseo_core_robots );
	sseo_check( 'without a robots.txt file the virtual one is edited, starting from the WordPress text', ! Simple_SEO_Files::robots_is_file() && Simple_SEO_Files::robots_default() === Simple_SEO_Files::current( 'robots' ) );
	sseo_check( 'nothing saved: robots.txt passes through', "X\n" === Simple_SEO_Files::robots_txt( "X\n", true ) );
	sseo_check( 'a missing .htaccess reads as empty', '' === Simple_SEO_Files::current( 'htaccess' ) );

	$sseo_res = Simple_SEO_Files::save( 'robots', "User-agent: *\r\nDisallow: /private/\r\n\r\n\r\n", $sseo_fp( 'robots' ) );
	sseo_check( 'the virtual robots.txt is saved', $sseo_res['ok'] && 'saved' === $sseo_res['code'] && str_contains( $sseo_res['message'], 'robots.txt' ), $sseo_res );
	sseo_check( 'with one kind of line ending and one line break at the end', "User-agent: *\nDisallow: /private/\n" === get_option( Simple_SEO_Files::ROBOTS_OPTION ), get_option( Simple_SEO_Files::ROBOTS_OPTION ) );
	$sseo_autoload = $GLOBALS['wpdb']->get_col( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name IN ('" . Simple_SEO_Files::ROBOTS_OPTION . "', '" . Simple_SEO_Files::BACKUPS_OPTION . "')" );
	sseo_check( 'the text and the history are not autoloaded', 2 === count( $sseo_autoload ) && ! array_intersect( $sseo_autoload, wp_autoload_values_to_autoload() ), $sseo_autoload );
	$sseo_hist = Simple_SEO_Files::history( 'robots' );
	sseo_check( 'the history keeps the WordPress text it replaced, by whom', 1 === count( $sseo_hist ) && Simple_SEO_Files::robots_default() === $sseo_hist[0]['content'] && 'virtual' === $sseo_hist[0]['source'] && $sseo_u['administrator'] === $sseo_hist[0]['user'] && abs( time() - $sseo_hist[0]['time'] ) < 60, $sseo_hist );
	$sseo_served = Simple_SEO_Files::robots_served();
	sseo_check( 'the site serves the saved text, then the sitemap line', str_starts_with( $sseo_served, "User-agent: *\nDisallow: /private/\n" ) && ! str_contains( $sseo_served, 'admin-ajax' ) && 1 === substr_count( $sseo_served, 'Sitemap: ' . home_url( '/sitemap_index.xml' ) ), $sseo_served );
	$sseo_other = static fn( $output ): string => $output . "# added by another plugin\n";
	add_filter( 'robots_txt', $sseo_other );
	sseo_check( 'what other plugins add stays', str_contains( Simple_SEO_Files::robots_served(), '# added by another plugin' ) );
	remove_filter( 'robots_txt', $sseo_other );
	update_option( 'blog_public', '0' );
	sseo_check( 'a site hidden from search engines gets the text without the sitemap line', "User-agent: *\nDisallow: /private/\n" === Simple_SEO_Files::robots_served(), Simple_SEO_Files::robots_served() );
	update_option( 'blog_public', '1' );
	$sseo_conflict = static fn(): string => 'Yoast SEO';
	add_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );
	sseo_check( 'while another SEO plugin is active the text is not served', "X\n" === Simple_SEO_Files::robots_txt( "X\n", true ) );
	remove_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );

	Simple_SEO_Files::save( 'robots', "User-agent: *\nDisallow: /a/\n\n  sitemap:  " . home_url( '/sitemap_index.xml' ) . "  \n", $sseo_fp( 'robots' ) );
	sseo_check( 'a text that names the sitemap does not get it twice', 1 === substr_count( strtolower( Simple_SEO_Files::robots_served() ), 'sitemap:' ), Simple_SEO_Files::robots_served() );
	Simple_SEO_Files::save( 'robots', "User-agent: *\nDisallow: /a/\n# Sitemap: " . home_url( '/sitemap_index.xml' ) . "\n", $sseo_fp( 'robots' ) );
	sseo_check( 'a sitemap named in a comment does not count', 1 === substr_count( Simple_SEO_Files::robots_served(), "\nSitemap: " ), Simple_SEO_Files::robots_served() );

	$sseo_count = count( Simple_SEO_Files::history( 'robots' ) );
	$sseo_res   = Simple_SEO_Files::save( 'robots', (string) Simple_SEO_Files::current( 'robots' ), $sseo_fp( 'robots' ) );
	sseo_check( 'the same text again: nothing saved, nothing kept', $sseo_res['ok'] && 'unchanged' === $sseo_res['code'] && $sseo_count === count( Simple_SEO_Files::history( 'robots' ) ), $sseo_res );
	$sseo_res = Simple_SEO_Files::save( 'robots', "User-agent: *\n", 'stale' );
	sseo_check( 'a text that changed since the form opened is not overwritten', ! $sseo_res['ok'] && 'conflict' === $sseo_res['code'] && str_contains( (string) get_option( Simple_SEO_Files::ROBOTS_OPTION ), 'Disallow: /a/' ), $sseo_res );
	$sseo_res = Simple_SEO_Files::save( 'robots', str_repeat( 'a', 600000 ), $sseo_fp( 'robots' ) );
	sseo_check( 'a text that is too long is refused', ! $sseo_res['ok'] && 'too-long' === $sseo_res['code'], $sseo_res );
	Simple_SEO_Files::save( 'robots', Simple_SEO_Files::robots_default(), $sseo_fp( 'robots' ) );
	sseo_check( 'the WordPress text saved again is kept as nothing (it follows WordPress)', '' === get_option( Simple_SEO_Files::ROBOTS_OPTION ) );
	Simple_SEO_Files::save( 'robots', "User-agent: *\nDisallow: /b/\n", $sseo_fp( 'robots' ) );
	Simple_SEO_Files::save( 'robots', " \n ", $sseo_fp( 'robots' ) );
	sseo_check( 'an empty text is the WordPress text again', '' === get_option( Simple_SEO_Files::ROBOTS_OPTION ) && Simple_SEO_Files::robots_default() === Simple_SEO_Files::current( 'robots' ) );

	for ( $sseo_i = 1; $sseo_i <= 12; $sseo_i++ ) {
		Simple_SEO_Files::save( 'robots', "User-agent: *\nDisallow: /v{$sseo_i}/\n", $sseo_fp( 'robots' ) );
	}
	$sseo_hist = Simple_SEO_Files::history( 'robots' );
	sseo_check( 'the history keeps the last ten, newest first', 10 === count( $sseo_hist ) && "User-agent: *\nDisallow: /v11/\n" === $sseo_hist[0]['content'] && "User-agent: *\nDisallow: /v2/\n" === $sseo_hist[9]['content'], wp_list_pluck( $sseo_hist, 'content' ) );
	sseo_check( 'every version has its own id', 10 === count( array_unique( wp_list_pluck( $sseo_hist, 'id' ) ) ) );
	$sseo_res = Simple_SEO_Files::restore( 'robots', $sseo_hist[4]['id'], $sseo_fp( 'robots' ) );
	sseo_check( 'a version is restored from the history', $sseo_res['ok'] && 'restored' === $sseo_res['code'] && "User-agent: *\nDisallow: /v7/\n" === Simple_SEO_Files::current( 'robots' ), $sseo_res );
	sseo_check( 'restoring keeps the text it replaced', "User-agent: *\nDisallow: /v12/\n" === Simple_SEO_Files::history( 'robots' )[0]['content'] );
	$sseo_res = Simple_SEO_Files::restore( 'robots', 'nosuchversion', $sseo_fp( 'robots' ) );
	sseo_check( 'an unknown version is refused', ! $sseo_res['ok'] && 'missing' === $sseo_res['code'], $sseo_res );
	$sseo_res = Simple_SEO_Files::restore( 'robots', Simple_SEO_Files::history( 'robots' )[1]['id'], 'stale' );
	sseo_check( 'restoring over a text changed meanwhile is refused', ! $sseo_res['ok'] && 'conflict' === $sseo_res['code'] && "User-agent: *\nDisallow: /v7/\n" === Simple_SEO_Files::current( 'robots' ), $sseo_res );

	// Rights: the virtual robots.txt needs the plugin settings, files the core permission to edit files.
	wp_set_current_user( $sseo_u['editor'] );
	sseo_check( 'without the settings capability nothing is changed', '' !== Simple_SEO_Files::refusal( 'robots' ) && 'refused' === Simple_SEO_Files::save( 'robots', "User-agent: *\n", $sseo_fp( 'robots' ) )['code'] );
	update_option( Simple_SEO_Access::OPTION, array( 'settings' => array( 'administrator', 'editor' ) ) );
	sseo_check( 'an editor with the settings capability edits the virtual robots.txt', '' === Simple_SEO_Files::refusal( 'robots' ) );
	sseo_check( 'but not .htaccess: editing files is for administrators', str_contains( Simple_SEO_Files::refusal( 'htaccess' ), 'administrator' ), Simple_SEO_Files::refusal( 'htaccess' ) );
	wp_set_current_user( $sseo_u['administrator'] );
	add_filter( 'file_mod_allowed', '__return_false' );
	sseo_check( 'DISALLOW_FILE_MODS stops editing files', str_contains( Simple_SEO_Files::refusal( 'htaccess' ), 'DISALLOW_FILE_MODS' ) && '' === Simple_SEO_Files::refusal( 'robots' ), Simple_SEO_Files::refusal( 'htaccess' ) );
	remove_filter( 'file_mod_allowed', '__return_false' );

	// process(): the forms of the tab.
	$GLOBALS['wp_settings_errors'] = array();
	$_SERVER['REQUEST_METHOD']     = 'GET';
	$_POST                         = wp_slash( array( 'simple_seo_file' => 'robots', 'simple_seo_content' => 'x' ) );
	sseo_check( 'a GET request changes nothing', null === Simple_SEO_Files::process() );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = wp_slash(
		array(
			'_wpnonce'           => wp_create_nonce( 'simple_seo_file_robots' ),
			'simple_seo_file'    => 'robots',
			'simple_seo_do'      => 'default',
			'simple_seo_loaded'  => $sseo_fp( 'robots' ),
			'simple_seo_content' => 'ignored',
		)
	);
	$sseo_url = Simple_SEO_Files::process();
	sseo_check( 'the default button brings the WordPress text back, then redirects', '' === get_option( Simple_SEO_Files::ROBOTS_OPTION ) && is_string( $sseo_url ) && str_contains( $sseo_url, 'tab=files' ) && str_contains( $sseo_url, 'simple-seo-done=saved' ) && str_ends_with( $sseo_url, '#simple-seo-robots' ), $sseo_url );
	$_POST = wp_slash(
		array(
			'_wpnonce'           => 'bad',
			'simple_seo_file'    => 'robots',
			'simple_seo_do'      => 'save',
			'simple_seo_loaded'  => $sseo_fp( 'robots' ),
			'simple_seo_content' => "User-agent: *\nDisallow: /draft/\n",
		)
	);
	sseo_check( 'a bad nonce changes nothing and stays on the page', null === Simple_SEO_Files::process() && '' === get_option( Simple_SEO_Files::ROBOTS_OPTION ) );
	$sseo_errors = get_settings_errors( 'simple-seo-files' );
	sseo_check( 'and says the page has expired', 1 === count( $sseo_errors ) && 'error' === $sseo_errors[0]['type'] && str_contains( $sseo_errors[0]['message'], 'expired' ), $sseo_errors );
	$_POST = wp_slash(
		array(
			'_wpnonce'           => wp_create_nonce( 'simple_seo_file_htaccess' ),
			'simple_seo_file'    => 'htaccess',
			'simple_seo_do'      => 'save',
			'simple_seo_loaded'  => $sseo_fp( 'htaccess' ),
			'simple_seo_content' => array( 'not', 'a', 'string' ),
		)
	);
	$sseo_url = Simple_SEO_Files::process();
	sseo_check( 'a field that is not text reads as empty, without warnings', is_string( $sseo_url ) && str_contains( $sseo_url, 'simple-seo-done=unchanged' ) && ! is_file( $sseo_ht_path ), $sseo_url );
	$_POST = wp_slash(
		array(
			'_wpnonce'          => wp_create_nonce( 'simple_seo_file_ghost' ),
			'simple_seo_file'   => 'ghost',
			'simple_seo_loaded' => '',
		)
	);
	sseo_check( 'an unknown file is ignored', null === Simple_SEO_Files::process() );

	// A robots.txt file in the site root.
	file_put_contents( $sseo_robots_path, "User-agent: *\nDisallow: /old/\n" );
	sseo_check( 'a robots.txt file is what gets edited', Simple_SEO_Files::robots_is_file() && "User-agent: *\nDisallow: /old/\n" === Simple_SEO_Files::current( 'robots' ) );
	$sseo_res = Simple_SEO_Files::save( 'robots', "User-agent: *\nDisallow: /new/\n", $sseo_fp( 'robots' ) );
	sseo_check( 'the file is written, the virtual text left alone', $sseo_res['ok'] && "User-agent: *\nDisallow: /new/\n" === file_get_contents( $sseo_robots_path ) && '' === get_option( Simple_SEO_Files::ROBOTS_OPTION ), $sseo_res );
	$sseo_hist = Simple_SEO_Files::history( 'robots' );
	sseo_check( 'the history keeps the file text it replaced', "User-agent: *\nDisallow: /old/\n" === $sseo_hist[0]['content'] && 'file' === $sseo_hist[0]['source'] );
	wp_set_current_user( $sseo_u['editor'] );
	$sseo_res = Simple_SEO_Files::save( 'robots', "User-agent: *\n", $sseo_fp( 'robots' ) );
	sseo_check( 'an editor may not write the file', ! $sseo_res['ok'] && 'refused' === $sseo_res['code'] && "User-agent: *\nDisallow: /new/\n" === file_get_contents( $sseo_robots_path ), $sseo_res );
	wp_set_current_user( $sseo_u['administrator'] );
	chmod( $sseo_robots_path, 0444 );
	clearstatcache();
	$sseo_res = Simple_SEO_Files::save( 'robots', "User-agent: *\n", $sseo_fp( 'robots' ) );
	sseo_check( 'a file the server may not write is left alone, with the reason', ! $sseo_res['ok'] && 'refused' === $sseo_res['code'] && str_contains( $sseo_res['message'], '0444' ) && "User-agent: *\nDisallow: /new/\n" === file_get_contents( $sseo_robots_path ), $sseo_res );
	chmod( $sseo_robots_path, 0644 );
	clearstatcache();
	$sseo_res = Simple_SEO_Files::to_virtual( $sseo_fp( 'robots' ) );
	sseo_check( 'the file can give way to the virtual robots.txt', $sseo_res['ok'] && 'virtual' === $sseo_res['code'] && ! is_file( $sseo_robots_path ) && "User-agent: *\nDisallow: /new/\n" === get_option( Simple_SEO_Files::ROBOTS_OPTION ), $sseo_res );
	sseo_check( 'its text stays in the history', "User-agent: *\nDisallow: /new/\n" === Simple_SEO_Files::history( 'robots' )[0]['content'] && 'file' === Simple_SEO_Files::history( 'robots' )[0]['source'] );
	sseo_check( 'without a file there is nothing to replace', 'unchanged' === Simple_SEO_Files::to_virtual( '' )['code'] );

	// .htaccess, with the server checked over a faked loopback: the file decides the answers.
	$GLOBALS['is_apache']     = true;
	$GLOBALS['sseo_requests'] = array();
	$GLOBALS['sseo_loopback'] = true;
	$sseo_fake                = static function ( $pre, $args, $url ) use ( $sseo_ht_path ) {
		$GLOBALS['sseo_requests'][] = array( $url, $args['redirection'] ?? null );
		$text = is_file( $sseo_ht_path ) ? (string) file_get_contents( $sseo_ht_path ) : '';
		if ( ! $GLOBALS['sseo_loopback'] || str_contains( $text, 'DOWN' ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		}
		$code = str_contains( $text, 'BROKEN' ) ? 500 : ( str_starts_with( $url, rest_url() ) && str_contains( $text, 'NOREST' ) ? 404 : 200 );

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $sseo_fake, 10, 3 );

	$sseo_rules = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^index\\.php$ - [L]\nHeader set X-Note \"it's\"\n</IfModule>\n# END WordPress\n";
	$sseo_res   = Simple_SEO_Files::save( 'htaccess', $sseo_rules, $sseo_fp( 'htaccess' ) );
	sseo_check( 'a missing .htaccess is created', $sseo_res['ok'] && 'saved' === $sseo_res['code'] && str_contains( $sseo_res['message'], '.htaccess' ), $sseo_res );
	sseo_check( 'with its text as typed, tags and all', $sseo_rules === file_get_contents( $sseo_ht_path ), file_get_contents( $sseo_ht_path ) );
	$sseo_urls = wp_list_pluck( $GLOBALS['sseo_requests'], 0 );
	sseo_check( 'the server opens the home page and the REST API before and after', 4 === count( $sseo_urls ) && str_starts_with( $sseo_urls[0], home_url( '/' ) . '?simple-seo-check=' ) && str_starts_with( $sseo_urls[1], rest_url() ) && str_starts_with( $sseo_urls[3], rest_url() ), $sseo_urls );
	sseo_check( 'without following redirects', array( 0 ) === array_values( array_unique( wp_list_pluck( $GLOBALS['sseo_requests'], 1 ) ) ) );
	sseo_check( 'no copy on disk of a file that was not there', ! is_file( $sseo_copy_path ) );

	$sseo_more = "# Custom\nOptions -Indexes\n\n" . $sseo_rules;
	$sseo_res  = Simple_SEO_Files::save( 'htaccess', $sseo_more, $sseo_fp( 'htaccess' ) );
	sseo_check( 'the next change is saved', $sseo_res['ok'] && $sseo_more === file_get_contents( $sseo_ht_path ), $sseo_res );
	sseo_check( 'with the previous file copied next to it', is_file( $sseo_copy_path ) && $sseo_rules === file_get_contents( $sseo_copy_path ) );
	sseo_check( 'and in the history', $sseo_rules === Simple_SEO_Files::history( 'htaccess' )[0]['content'] && 'file' === Simple_SEO_Files::history( 'htaccess' )[0]['source'] );

	$sseo_count = count( Simple_SEO_Files::history( 'htaccess' ) );
	$sseo_res   = Simple_SEO_Files::save( 'htaccess', "BROKEN\n" . $sseo_more, $sseo_fp( 'htaccess' ) );
	sseo_check( 'a file that takes the site down is put back at once', ! $sseo_res['ok'] && 'rolled-back' === $sseo_res['code'] && $sseo_more === file_get_contents( $sseo_ht_path ), $sseo_res );
	sseo_check( 'saying which address stopped answering and how', str_contains( $sseo_res['message'], home_url( '/' ) . ' (500)' ), $sseo_res['message'] );
	sseo_check( 'a change that did not happen leaves no version behind', $sseo_count === count( Simple_SEO_Files::history( 'htaccess' ) ) );
	$sseo_res = Simple_SEO_Files::save( 'htaccess', "# NOREST\n" . $sseo_more, $sseo_fp( 'htaccess' ) );
	sseo_check( 'a file that breaks the REST API is put back too', 'rolled-back' === $sseo_res['code'] && str_contains( $sseo_res['message'], '(404)' ) && $sseo_more === file_get_contents( $sseo_ht_path ), $sseo_res );
	$sseo_res = Simple_SEO_Files::save( 'htaccess', "# DOWN\n" . $sseo_more, $sseo_fp( 'htaccess' ) );
	sseo_check( 'a server that stops answering at all counts as broken', 'rolled-back' === $sseo_res['code'] && str_contains( $sseo_res['message'], '(no answer)' ) && $sseo_more === file_get_contents( $sseo_ht_path ), $sseo_res );

	unlink( $sseo_ht_path );
	unlink( $sseo_copy_path );
	$sseo_root_mode = fileperms( ABSPATH ) & 0777;
	chmod( ABSPATH, 0555 );
	clearstatcache();
	sseo_check( 'a folder the server may not write: no new .htaccess, with the reason', str_contains( Simple_SEO_Files::refusal( 'htaccess' ), 'may not create files in' ) && 'refused' === Simple_SEO_Files::save( 'htaccess', "# x\n", Simple_SEO_Files::fingerprint( '' ) )['code'] && ! is_file( $sseo_ht_path ) );
	chmod( ABSPATH, $sseo_root_mode );
	clearstatcache();
	$sseo_res = Simple_SEO_Files::save( 'htaccess', "BROKEN\n", Simple_SEO_Files::fingerprint( '' ) );
	sseo_check( 'a new file that takes the site down is removed again', 'rolled-back' === $sseo_res['code'] && ! is_file( $sseo_ht_path ), $sseo_res );

	file_put_contents( $sseo_ht_path, "BROKEN already\n" );
	$sseo_res = Simple_SEO_Files::save( 'htaccess', "BROKEN still\n", $sseo_fp( 'htaccess' ) );
	sseo_check( 'an address that failed before the change is not held against it', $sseo_res['ok'] && "BROKEN still\n" === file_get_contents( $sseo_ht_path ), $sseo_res );
	file_put_contents( $sseo_ht_path, $sseo_more );

	$GLOBALS['sseo_loopback'] = false;
	$sseo_res                 = Simple_SEO_Files::save( 'htaccess', "# unchecked\n" . $sseo_more, $sseo_fp( 'htaccess' ) );
	sseo_check( 'when the server cannot reach itself the file is saved with a warning', $sseo_res['ok'] && 'unverified' === $sseo_res['code'] && 'warning' === $sseo_res['type'] && str_starts_with( (string) file_get_contents( $sseo_ht_path ), '# unchecked' ), $sseo_res );
	$GLOBALS['sseo_loopback'] = true;

	$GLOBALS['sseo_requests'] = array();
	$sseo_res                 = Simple_SEO_Files::save( 'htaccess', (string) Simple_SEO_Files::current( 'htaccess' ), $sseo_fp( 'htaccess' ) );
	sseo_check( 'the same .htaccess again: nothing written, nothing requested', 'unchanged' === $sseo_res['code'] && array() === $GLOBALS['sseo_requests'], $sseo_res );

	$sseo_hist = Simple_SEO_Files::history( 'htaccess' );
	$sseo_res  = Simple_SEO_Files::restore( 'htaccess', $sseo_hist[0]['id'], $sseo_fp( 'htaccess' ) );
	sseo_check( 'a version of .htaccess is restored, checked like a change', $sseo_res['ok'] && $sseo_hist[0]['content'] === file_get_contents( $sseo_ht_path ) && 4 === count( $GLOBALS['sseo_requests'] ), $sseo_res );

	$GLOBALS['is_apache']     = false;
	$GLOBALS['sseo_requests'] = array();
	$sseo_copy_before         = (string) file_get_contents( $sseo_copy_path );
	$sseo_res                 = Simple_SEO_Files::save( 'htaccess', "# nginx\n", $sseo_fp( 'htaccess' ) );
	sseo_check( 'where the server does not read .htaccess, nothing is checked or copied', $sseo_res['ok'] && 'saved' === $sseo_res['code'] && array() === $GLOBALS['sseo_requests'] && $sseo_copy_before === file_get_contents( $sseo_copy_path ), $sseo_res );
	$GLOBALS['is_apache'] = true;

	chmod( $sseo_ht_path, 0444 );
	clearstatcache();
	$sseo_res = Simple_SEO_Files::save( 'htaccess', "# x\n", $sseo_fp( 'htaccess' ) );
	sseo_check( 'a .htaccess the server may not write is left alone', 'refused' === $sseo_res['code'] && "# nginx\n" === file_get_contents( $sseo_ht_path ), $sseo_res );
	chmod( $sseo_ht_path, 0644 );
	clearstatcache();

	// The tab.
	Simple_SEO_Files::add_admin_tab();
	$sseo_tabs = Simple_SEO_Admin::tabs();
	sseo_check( 'the Files tab has no option rows of its own', isset( $sseo_tabs['files'] ) && array() === $sseo_tabs['files']['options'] && 80 === $sseo_tabs['files']['order'] );
	sseo_check( 'its forms are handled when the page loads', 10 === has_action( 'load-toplevel_page_simple-seo', array( Simple_SEO_Files::class, 'handle' ) ) );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = wp_slash(
		array(
			'_wpnonce'           => wp_create_nonce( 'simple_seo_file_htaccess' ),
			'simple_seo_file'    => 'htaccess',
			'simple_seo_do'      => 'save',
			'simple_seo_loaded'  => $sseo_fp( 'htaccess' ),
			'simple_seo_content' => str_replace( "\n", "\r\n", $sseo_rules ),
		)
	);
	$sseo_url = Simple_SEO_Files::process();
	sseo_check( 'the form saves .htaccess as typed, tags and quotes and all', is_string( $sseo_url ) && str_contains( $sseo_url, 'simple-seo-file=htaccess' ) && $sseo_rules === file_get_contents( $sseo_ht_path ), file_get_contents( $sseo_ht_path ) );
	$_POST       = array();
	$_GET['tab'] = 'files';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'the tab is not an options.php form', ! str_contains( $sseo_html, 'action="options.php"' ) && str_contains( $sseo_html, 'tab=files#simple-seo-htaccess' ) );
	sseo_check( 'it shows both files, .htaccess with its tags escaped', str_contains( $sseo_html, 'id="simple-seo-robots-text"' ) && str_contains( $sseo_html, '&lt;IfModule mod_rewrite.c&gt;' ) && ! str_contains( $sseo_html, '<IfModule' ) );
	sseo_check( 'the virtual robots.txt shows what the site serves', str_contains( $sseo_html, 'What the site serves' ) && str_contains( $sseo_html, 'Sitemap: ' . home_url( '/sitemap_index.xml' ) ) );
	sseo_check( 'each form has its nonce and fingerprint', 2 <= substr_count( $sseo_html, 'name="simple_seo_loaded" value="' . $sseo_fp( 'htaccess' ) . '"' ) && str_contains( $sseo_html, 'name="_wpnonce"' ) );
	sseo_check( 'the history offers each version back', substr_count( $sseo_html, 'name="simple_seo_backup"' ) === count( Simple_SEO_Files::history( 'robots' ) ) + count( Simple_SEO_Files::history( 'htaccess' ) ) );
	sseo_check( 'the copy next to .htaccess is named', str_contains( $sseo_html, esc_html( $sseo_copy_path ) ) );
	sseo_check( 'the server reads .htaccess: no warning', ! str_contains( $sseo_html, 'does not read .htaccess' ) && ! str_contains( $sseo_html, 'does not look like Apache' ) );
	$GLOBALS['is_apache']        = false;
	$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27.0';
	$sseo_html                   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'nginx: .htaccess changes nothing, the page says so', str_contains( $sseo_html, 'This server (nginx/1.27.0) is not Apache or LiteSpeed' ) );
	unset( $_SERVER['SERVER_SOFTWARE'] );
	$GLOBALS['is_apache'] = true;
	file_put_contents( $sseo_robots_path, "User-agent: *\nDisallow: /\n" );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'a robots.txt file: the page says so and offers to delete it', str_contains( $sseo_html, 'There is a robots.txt file in the site root' ) && str_contains( $sseo_html, 'value="virtual"' ) && ! str_contains( $sseo_html, 'What the site serves' ) );
	sseo_check( 'and the sitemap line it lacks', str_contains( $sseo_html, 'The file does not name it' ) );
	wp_set_current_user( $sseo_u['editor'] );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'an editor sees the files read-only, with the reason', 2 === substr_count( $sseo_html, ' readonly>' ) && ! str_contains( $sseo_html, 'value="save"' ) && ! str_contains( $sseo_html, 'name="simple_seo_backup"' ) && str_contains( $sseo_html, 'an administrator can' ) );
	wp_set_current_user( $sseo_u['administrator'] );
	unlink( $sseo_robots_path );

	$_POST = wp_slash(
		array(
			'_wpnonce'           => wp_create_nonce( 'simple_seo_file_htaccess' ),
			'simple_seo_file'    => 'htaccess',
			'simple_seo_do'      => 'save',
			'simple_seo_loaded'  => 'stale',
			'simple_seo_content' => "# my draft\n",
		)
	);
	$GLOBALS['wp_settings_errors'] = array();
	sseo_check( 'a conflict keeps the page', null === Simple_SEO_Files::process() );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	$sseo_part = (string) strstr( $sseo_html, 'id="simple-seo-htaccess"' );
	sseo_check( 'with the draft in the editor and the file as it is now under it', str_contains( $sseo_part, '># my draft' ) && str_contains( $sseo_part, 'The file as it is now' ) && str_contains( $sseo_html, 'changed after you opened it' ), substr( $sseo_part, 0, 300 ) );
	$_POST                         = array();
	$_SERVER['REQUEST_METHOD']     = 'GET';
	$GLOBALS['wp_settings_errors'] = array();
	$_GET['simple-seo-file']       = 'htaccess';
	$_GET['simple-seo-done']       = 'unverified';
	Simple_SEO_Files::handle();
	$sseo_errors = get_settings_errors( 'simple-seo-files' );
	sseo_check( 'after the redirect the page says what was done', 1 === count( $sseo_errors ) && 'warning' === $sseo_errors[0]['type'] && str_contains( $sseo_errors[0]['message'], 'could not open the site' ), $sseo_errors );
	$GLOBALS['wp_settings_errors'] = array();
	$_GET['simple-seo-done']       = 'nonsense';
	Simple_SEO_Files::handle();
	sseo_check( 'an unknown code says nothing', array() === get_settings_errors( 'simple-seo-files' ) );
	unset( $_GET['simple-seo-file'], $_GET['simple-seo-done'], $_GET['tab'] );
	remove_filter( 'pre_http_request', $sseo_fake, 10 );
	$GLOBALS['is_apache'] = $sseo_saved_apache;
	update_option( Simple_SEO_Access::OPTION, array() );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Clean-up of the page head' );

	// apply() unhooks core callbacks for the rest of the request: keep the hooks to put them back.
	$sseo_hooks = array();
	foreach ( array( 'wp_head', 'template_redirect', 'wp_enqueue_scripts', 'wp_print_styles', 'embed_head', 'enqueue_embed_scripts' ) as $sseo_name ) {
		$sseo_hooks[ $sseo_name ] = $GLOBALS['wp_filter'][ $sseo_name ] instanceof WP_Hook ? clone $GLOBALS['wp_filter'][ $sseo_name ] : null;
	}
	$sseo_restore_hooks = static function () use ( $sseo_hooks ): void {
		foreach ( $sseo_hooks as $sseo_name => $sseo_hook ) {
			if ( $sseo_hook ) {
				$GLOBALS['wp_filter'][ $sseo_name ] = clone $sseo_hook;
			}
		}
	};
	$sseo_hooked = static fn(): array => array(
		'generator' => false !== has_action( 'wp_head', 'wp_generator' ),
		'shortlink' => false !== has_action( 'wp_head', 'wp_shortlink_wp_head' ) || false !== has_action( 'template_redirect', 'wp_shortlink_header' ),
		'rsd'       => false !== has_action( 'wp_head', 'rsd_link' ),
		'rest'      => false !== has_action( 'wp_head', 'rest_output_link_wp_head' ) || false !== has_action( 'template_redirect', 'rest_output_link_header' ),
		'oembed'    => false !== has_action( 'wp_head', 'wp_oembed_add_discovery_links' ),
		'emoji'     => false !== has_action( 'wp_head', 'print_emoji_detection_script' ) || false !== has_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' ) || false !== has_action( 'embed_head', 'print_emoji_detection_script' ) || false !== has_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' ),
	);
	$sseo_all_off = array_fill_keys( array_keys( Simple_SEO_Cleanup::defaults() ), true );

	delete_option( Simple_SEO_Cleanup::OPTION );
	Simple_SEO_Settings::flush();
	sseo_check( 'clean-up runs after the main query, before the Link headers', 1 === has_action( 'wp', array( Simple_SEO_Cleanup::class, 'apply' ) ) );
	$sseo_defaults = Simple_SEO_Cleanup::defaults();
	sseo_check( 'out of the box only the version, the shortlink and XML-RPC discovery go', array( 'generator', 'shortlink', 'rsd' ) === array_keys( array_filter( $sseo_defaults ) ) && 15 === count( $sseo_defaults ), $sseo_defaults );
	sseo_check( 'every feed link has its switch', array_keys( Simple_SEO_Cleanup::FEEDS ) === array_values( array_filter( array_keys( $sseo_defaults ), static fn( string $key ): bool => str_starts_with( $key, 'feed_' ) ) ) );

	$GLOBALS['wp_query'] = sseo_query( array( 'p' => $sseo_p['author_post'] ) );
	Simple_SEO_Cleanup::apply();
	sseo_check( 'with the defaults the version, shortlink and RSD are unhooked, the rest stays', array( 'generator' => false, 'shortlink' => false, 'rsd' => false, 'rest' => true, 'oembed' => true, 'emoji' => true ) === $sseo_hooked(), $sseo_hooked() );
	$sseo_restore_hooks();
	update_option( Simple_SEO_Cleanup::OPTION, $sseo_all_off );
	Simple_SEO_Cleanup::apply();
	sseo_check( 'everything switched off is unhooked, both oEmbed priorities and the embed page included', array( 'generator' => false, 'shortlink' => false, 'rsd' => false, 'rest' => false, 'oembed' => false, 'emoji' => false ) === $sseo_hooked() && false === has_action( 'wp_print_styles', 'print_emoji_styles' ), $sseo_hooked() );
	$sseo_restore_hooks();
	sseo_check( 'the hooks are back for the rest of the test', array_fill_keys( array( 'generator', 'shortlink', 'rsd', 'rest', 'oembed', 'emoji' ), true ) === $sseo_hooked() );

	add_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );
	Simple_SEO_Cleanup::apply();
	sseo_check( 'next to another SEO plugin nothing is removed', array_fill_keys( array( 'generator', 'shortlink', 'rsd', 'rest', 'oembed', 'emoji' ), true ) === $sseo_hooked() && 'X' === Simple_SEO_Cleanup::generator( 'X' ) && true === apply_filters( 'feed_links_show_posts_feed', true ) );
	remove_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );
	$sseo_restore_hooks();
	set_current_screen( 'dashboard' );
	sseo_check( 'nothing is removed in the admin', ! Simple_SEO_Cleanup::removes( 'generator' ) && 'X' === Simple_SEO_Cleanup::generator( 'X' ) );
	unset( $GLOBALS['current_screen'] );

	sseo_check( 'the X-Pingback header goes with RSD, other headers stay', array( 'Other' => 'y' ) === Simple_SEO_Cleanup::headers( array( 'X-Pingback' => 'x', 'Other' => 'y' ) ) && array( 'Other' => 'y' ) === apply_filters( 'wp_headers', array( 'X-Pingback' => 'x', 'Other' => 'y' ), $GLOBALS['wp'] ) );
	sseo_check( 'the version is taken out of feeds too', '' === apply_filters( 'the_generator', '<generator>https://wordpress.org/?v=7</generator>', 'rss2' ) );
	sseo_check( 'every feed link filter says no', array( false ) === array_values( array_unique( array_map( static fn( string $filter ) => apply_filters( $filter, true ), Simple_SEO_Cleanup::FEEDS ) ) ) );

	update_option( Simple_SEO_Cleanup::OPTION, array( 'rsd' => false, 'generator' => false, 'feed_categories' => true ) );
	sseo_check( 'a switch left on keeps X-Pingback and the version', array( 'X-Pingback' => 'x' ) === Simple_SEO_Cleanup::headers( array( 'X-Pingback' => 'x' ) ) && 'X' === Simple_SEO_Cleanup::generator( 'X' ) );
	$sseo_no = static fn(): bool => false;
	add_filter( 'feed_links_show_posts_feed', $sseo_no );
	sseo_check( "another plugin's no stays a no, a kept link stays", false === apply_filters( 'feed_links_show_posts_feed', true ) && true === apply_filters( 'feed_links_show_comments_feed', true ) );
	remove_filter( 'feed_links_show_posts_feed', $sseo_no );
	$GLOBALS['wp_query'] = sseo_query( array( 'cat' => $sseo_cat_id ) );
	$sseo_feeds          = sseo_capture( 'feed_links' ) . sseo_capture( 'feed_links_extra' );
	sseo_check( 'a category archive loses only its category feed link', ! str_contains( $sseo_feeds, get_category_feed_link( $sseo_cat_id ) ) && str_contains( $sseo_feeds, get_feed_link() ) && str_contains( $sseo_feeds, get_feed_link( 'comments_rss2' ) ), $sseo_feeds );
	update_option( Simple_SEO_Cleanup::OPTION, array( 'feed_posts' => true ) );
	$sseo_feeds = sseo_capture( 'feed_links' ) . sseo_capture( 'feed_links_extra' );
	sseo_check( 'without the posts feed the category feed link is back', str_contains( $sseo_feeds, get_category_feed_link( $sseo_cat_id ) ) && ! str_contains( $sseo_feeds, '"' . get_feed_link() . '"' ), $sseo_feeds );
	$GLOBALS['wp_query'] = $sseo_saved_query;

	// The settings: saved through options.php with a hidden 0 before every checkbox.
	Simple_SEO_Cleanup::add_admin_tab();
	Simple_SEO_Admin::register_settings();
	$sseo_tabs = Simple_SEO_Admin::tabs();
	sseo_check( 'the Clean-up tab saves its row', isset( $sseo_tabs['cleanup'] ) && array( Simple_SEO_Cleanup::OPTION ) === $sseo_tabs['cleanup']['options'] && 30 === $sseo_tabs['cleanup']['order'] );
	delete_option( Simple_SEO_Cleanup::OPTION );
	Simple_SEO_Settings::flush();
	Simple_SEO_Settings::install();
	update_option( Simple_SEO_Cleanup::OPTION, array( 'generator' => '0', 'rest' => '1', 'feed_tags' => '1', 'junk' => '1' ) );
	$sseo_row = get_option( Simple_SEO_Cleanup::OPTION );
	sseo_check( 'the sanitizer keeps booleans of known switches; one not sent keeps its value', is_array( $sseo_row ) && 15 === count( $sseo_row ) && false === $sseo_row['generator'] && true === $sseo_row['rest'] && true === $sseo_row['feed_tags'] && true === $sseo_row['shortlink'] && false === $sseo_row['emoji'] && ! isset( $sseo_row['junk'] ), $sseo_row );
	$sseo_autoload = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Simple_SEO_Cleanup::OPTION ) );
	sseo_check( 'the row is autoloaded: every page reads it', in_array( $sseo_autoload, wp_autoload_values_to_autoload(), true ), $sseo_autoload );
	$_GET['tab'] = 'cleanup';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'] );
	sseo_check( 'the tab is an options.php form of its group', str_contains( $sseo_html, 'action="options.php"' ) && str_contains( $sseo_html, "value='simple-seo-cleanup'" ) );
	sseo_check( 'every switch has a hidden 0 and a checkbox', 15 === substr_count( $sseo_html, 'type="hidden" name="simple_seo_cleanup[' ) && 15 === substr_count( $sseo_html, 'type="checkbox"' ) );
	sseo_check( 'the boxes show the saved row', (bool) preg_match( '/name="simple_seo_cleanup\[rest\]" value="1"\s+checked/', $sseo_html ) && ! preg_match( '/name="simple_seo_cleanup\[generator\]" value="1"\s+checked/', $sseo_html ) && (bool) preg_match( '/name="simple_seo_cleanup\[shortlink\]" value="1"\s+checked/', $sseo_html ) );
	sseo_check( 'the code each switch removes is shown escaped', str_contains( $sseo_html, '&lt;meta name=&quot;generator&quot;' ) && ! str_contains( $sseo_html, '<meta name="generator"' ) );
	delete_option( Simple_SEO_Cleanup::OPTION );
	Simple_SEO_Settings::flush();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'llms.txt' );

	delete_option( Simple_SEO_Llms::OPTION );
	delete_option( Simple_SEO_Llms::TEXT );
	Simple_SEO_Settings::flush();
	Simple_SEO_Llms::flush();
	update_option( 'blog_public', '1' );
	update_option( 'show_on_front', 'posts' );
	update_option( Simple_SEO_Sitemaps::OPTION, array( 'enabled' => true ) );
	$sseo_page = static function ( string $title, array $args = array() ) use ( &$sseo ): int {
		$id              = (int) wp_insert_post( array_merge( array( 'post_type' => 'page', 'post_title' => $title, 'post_status' => 'publish' ), $args ) );
		$sseo['posts'][] = $id;

		return $id;
	};
	$sseo_lp = array(
		'about'    => $sseo_page( 'Llms About', array( 'menu_order' => 2 ) ),
		'contacts' => $sseo_page( 'Llms Contacts', array( 'menu_order' => 1 ) ),
		'hidden'   => $sseo_page( 'Llms Hidden' ),
		'locked'   => $sseo_page( 'Llms Locked', array( 'post_password' => 'x' ) ),
		'draft'    => $sseo_page( 'Llms Draft', array( 'post_status' => 'draft' ) ),
		'old'      => $sseo_page( 'Llms [old] post', array( 'post_type' => 'post', 'post_date' => '2020-01-01 10:00:00' ) ),
		'new'      => $sseo_page( 'Llms new post', array( 'post_type' => 'post', 'post_date' => '2026-01-01 10:00:00' ) ),
		'away'     => $sseo_page( 'Llms Elsewhere' ),
		'marked'   => $sseo_page( 'Llms Tom\'s & <em>Jerry</em>' ),
	);
	update_post_meta( $sseo_lp['away'], Simple_SEO_Fields::meta_key( 'canonical' ), 'https://example.com/elsewhere/' );
	$sseo_few        = wp_insert_term( 'Llms Few', 'category' );
	$sseo_many       = wp_insert_term( 'Llms Many', 'category' );
	$sseo['terms'][] = array( (int) $sseo_few['term_id'], 'category' );
	$sseo['terms'][] = array( (int) $sseo_many['term_id'], 'category' );
	wp_set_post_categories( $sseo_lp['old'], array( (int) $sseo_many['term_id'] ) );
	wp_set_post_categories( $sseo_lp['new'], array( (int) $sseo_many['term_id'], (int) $sseo_few['term_id'] ) );
	clean_term_cache( array( (int) $sseo_few['term_id'], (int) $sseo_many['term_id'] ), 'category' );
	update_post_meta( $sseo_lp['hidden'], Simple_SEO_Fields::meta_key( 'index' ), 'noindex' );
	update_post_meta( $sseo_lp['new'], Simple_SEO_Fields::meta_key( 'description' ), 'Own description of the new post.' );
	$sseo_section = static function ( string $text, string $heading ): string {
		$start = strpos( $text, "\n## {$heading}\n" );
		if ( false === $start ) {
			return '';
		}
		$end = strpos( $text, "\n## ", $start + 4 );

		return false === $end ? substr( $text, $start ) : substr( $text, $start, $end - $start );
	};

	Simple_SEO_Llms::register_rewrites();
	sseo_check( 'llms.txt has its address', isset( $GLOBALS['wp_rewrite']->extra_rules_top['^llms\.txt$'] ) && in_array( Simple_SEO_Llms::QUERY_VAR, apply_filters( 'query_vars', array() ), true ) );
	unset( $GLOBALS['wp_rewrite']->extra_rules_top['^llms\.txt$'] );
	add_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );
	Simple_SEO_Llms::register_rewrites();
	sseo_check( 'next to another SEO plugin the address is left to it, and nothing is served', ! isset( $GLOBALS['wp_rewrite']->extra_rules_top['^llms\.txt$'] ) && ! Simple_SEO_Llms::enabled() );
	remove_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );
	Simple_SEO_Llms::register_rewrites();
	flush_rewrite_rules( false );
	sseo_check( 'the rewrite rules carry it', isset( get_option( 'rewrite_rules' )['^llms\.txt$'] ) );
	sseo_check( 'it is on out of the box, at /llms.txt', Simple_SEO_Llms::enabled() && home_url( '/llms.txt' ) === Simple_SEO_Llms::url() );
	update_option( 'blog_public', '0' );
	sseo_check( 'a site hidden from search engines has none', ! Simple_SEO_Llms::enabled() );
	update_option( 'blog_public', '1' );
	$sseo_q = new WP_Query();
	$sseo_q->set( Simple_SEO_Llms::QUERY_VAR, '1' );
	$sseo_main               = $GLOBALS['wp_the_query'];
	$GLOBALS['wp_the_query'] = $sseo_q;
	$sseo_skipped            = apply_filters( 'posts_pre_query', null, $sseo_q );
	$GLOBALS['wp_the_query'] = $sseo_main;
	sseo_check( 'the llms.txt request skips the posts query', array() === $sseo_skipped );

	$sseo_txt = Simple_SEO_Llms::build( '' );
	sseo_check( 'it opens with the site name as the only H1', str_starts_with( $sseo_txt, '# ' . get_bloginfo( 'name' ) . "\n" ) && 1 === preg_match_all( '/^# /m', $sseo_txt ), substr( $sseo_txt, 0, 200 ) );
	$sseo_pages = $sseo_section( $sseo_txt, 'Pages' );
	$sseo_posts = $sseo_section( $sseo_txt, 'Posts' );
	sseo_check( 'pages come first, then posts', '' !== $sseo_pages && '' !== $sseo_posts && strpos( $sseo_txt, '## Pages' ) < strpos( $sseo_txt, '## Posts' ) );
	sseo_check( 'pages in their order', strpos( $sseo_pages, '[Llms Contacts]' ) < strpos( $sseo_pages, '[Llms About]' ) && false !== strpos( $sseo_pages, '[Llms Contacts]' ) );
	sseo_check( 'a link without a description is just the link', str_contains( $sseo_pages, '- [Llms Contacts](' . get_permalink( $sseo_lp['contacts'] ) . ")\n" ), $sseo_pages );
	sseo_check( 'hidden, password-protected and draft pages are left out', ! str_contains( $sseo_txt, 'Llms Hidden' ) && ! str_contains( $sseo_txt, 'Llms Locked' ) && ! str_contains( $sseo_txt, 'Llms Draft' ) );
	sseo_check( 'a page whose canonical is elsewhere is left out', ! str_contains( $sseo_txt, 'Llms Elsewhere' ) );
	sseo_check( 'titles are plain text: no tags, entities decoded', str_contains( $sseo_pages, "- [Llms Tom\u{2019}s & Jerry](" ), $sseo_pages );
	sseo_check( 'posts newest first, brackets in a title escaped, the description after a colon', str_contains( $sseo_posts, '- [Llms new post](' . get_permalink( $sseo_lp['new'] ) . '): Own description of the new post.' ) && strpos( $sseo_posts, '[Llms new post]' ) < strpos( $sseo_posts, '[Llms \[old\] post]' ), $sseo_posts );
	sseo_check( 'taxonomies are left out by default', ! str_contains( $sseo_txt, '## Categories' ) && ! str_contains( $sseo_txt, '## Tags' ) );
	sseo_check( 'the Optional section names the XML sitemap', str_contains( $sseo_section( $sseo_txt, 'Optional' ), '- [XML sitemap](' . Simple_SEO_Sitemaps::index_url() . '): ' ) );
	sseo_check( 'one language: no other languages line', ! str_contains( $sseo_txt, 'Other languages:' ) || Simple_SEO_WP_Loc::languages() );
	sseo_check( 'one line break at the end', str_ends_with( $sseo_txt, "\n" ) && ! str_ends_with( $sseo_txt, "\n\n" ) );

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $sseo_lp['contacts'] );
	sseo_check( 'the static front page is the site, not one of its pages', ! str_contains( Simple_SEO_Llms::build( '' ), '[Llms Contacts]' ) );
	update_option( 'show_on_front', 'posts' );

	$sseo_paren = static fn( $link, $post ) => ( $post instanceof WP_Post ? $post->ID : (int) $post ) === $sseo_lp['new'] ? $link . '?a=(1) b' : $link;
	add_filter( 'post_link', $sseo_paren, 10, 2 );
	sseo_check( 'parentheses and spaces in an address are encoded', str_contains( Simple_SEO_Llms::build( '' ), '?a=%281%29%20b)' ) );
	remove_filter( 'post_link', $sseo_paren, 10 );

	update_option( Simple_SEO_Llms::TEXT, Simple_SEO_Llms::sanitize_text( array( 'summary' => " Our <b>studio</b>\n builds sites. ", 'details' => "We make sites.\n## Prices\n  ### From 100 EUR.\n<script>x</script>Ask us." ) ) );
	// With wp-loc the row is written through its language row: forget what this request read.
	Simple_SEO_Settings::flush();
	$sseo_txt = Simple_SEO_Llms::build( '' );
	sseo_check( 'the summary is the blockquote, the details follow without headings', str_contains( $sseo_txt, "\n\n> Our studio builds sites.\n\nWe make sites.\nPrices\nFrom 100 EUR.\nAsk us.\n\n" ), substr( $sseo_txt, 0, 300 ) );
	update_option( Simple_SEO_Llms::TEXT, array( 'summary' => '', 'details' => '' ) );
	Simple_SEO_Settings::flush();
	$sseo_home = static fn( $text, $context ) => Simple_SEO_Context::HOME === $context->kind ? 'Home said so.' : $text;
	add_filter( 'simple_seo_description', $sseo_home, 10, 2 );
	sseo_check( 'without a summary, the description of the home page', str_contains( Simple_SEO_Llms::build( '' ), "\n\n> Home said so.\n" ) );
	remove_filter( 'simple_seo_description', $sseo_home, 10 );
	$sseo_none = static fn( $text, $context ) => Simple_SEO_Context::HOME === $context->kind ? '' : $text;
	add_filter( 'simple_seo_description', $sseo_none, 10, 2 );
	update_option( 'blogdescription', 'Tagline of the site' );
	sseo_check( 'without that either, the tagline', str_contains( Simple_SEO_Llms::build( '' ), "\n\n> Tagline of the site\n" ) );
	update_option( 'blogdescription', '' );
	$sseo_txt = Simple_SEO_Llms::build( '' );
	sseo_check( 'with nothing at all, no blockquote', ! str_contains( $sseo_txt, "\n> " ) && str_contains( $sseo_txt, "\n\n## " ) );
	remove_filter( 'simple_seo_description', $sseo_none, 10 );

	update_option( Simple_SEO_Llms::OPTION, array( 'sections' => array( 'term:category' => 'main', 'post:post' => 'optional', 'post:page' => '' ) ) );
	$sseo_txt = Simple_SEO_Llms::build( '' );
	$sseo_opt = $sseo_section( $sseo_txt, 'Optional' );
	update_option( Simple_SEO_Llms::OPTION, array( 'sections' => array( 'term:category' => 'main' ), 'limit' => 500 ) );
	$sseo_cats = $sseo_section( Simple_SEO_Llms::build( '' ), 'Categories' );
	sseo_check( 'terms with the most posts first', false !== strpos( $sseo_cats, '[Llms Many]' ) && strpos( $sseo_cats, '[Llms Many]' ) < strpos( $sseo_cats, '[Llms Few]' ), $sseo_cats );
	sseo_check( 'sections follow the settings: categories in, posts optional, pages out', str_contains( $sseo_txt, '## Categories' ) && ! str_contains( $sseo_txt, '## Posts' ) && ! str_contains( $sseo_txt, '## Pages' ) && str_contains( $sseo_opt, '[Llms new post]' ), $sseo_txt );
	sseo_check( 'one Optional section, at the end', 1 === substr_count( $sseo_txt, '## Optional' ) && strrpos( $sseo_txt, '## ' ) === strpos( $sseo_txt, '## Optional' ) );
	update_option( Simple_SEO_Llms::OPTION, array( 'limit' => 1 ) );
	sseo_check( 'at most the limit of links in a section', 1 === substr_count( $sseo_section( Simple_SEO_Llms::build( '' ), 'Pages' ), "\n- [" ) );
	update_option( Simple_SEO_Llms::OPTION, array( 'sections' => array( 'post:post' => '', 'post:page' => '' ) ) );
	update_option( Simple_SEO_Sitemaps::OPTION, array( 'enabled' => false ) );
	$sseo_txt = Simple_SEO_Llms::build( '' );
	sseo_check( 'without a sitemap and optional types there is no Optional section', ! str_contains( $sseo_txt, '## Optional' ) );
	sseo_check( 'and the file still ends with one line break', str_ends_with( $sseo_txt, "\n" ) && ! str_ends_with( $sseo_txt, "\n\n" ), $sseo_txt );
	update_option( Simple_SEO_Sitemaps::OPTION, array( 'enabled' => true ) );
	delete_option( Simple_SEO_Llms::OPTION );
	$sseo_more = static fn( string $text ): string => $text . "- extra\n";
	add_filter( 'simple_seo_llms_txt', $sseo_more );
	sseo_check( 'the text goes through simple_seo_llms_txt', str_ends_with( Simple_SEO_Llms::build( '' ), "- extra\n" ) );
	remove_filter( 'simple_seo_llms_txt', $sseo_more );

	// The cache: one transient per language (the request's language with wp-loc), dropped when
	// what the file shows changes.
	$sseo_code = Simple_SEO_WP_Loc::current_language();
	$sseo_key  = 'simple_seo_llms_' . ( '' !== $sseo_code ? sanitize_key( $sseo_code ) : 'default' );
	Simple_SEO_Llms::flush();
	$sseo_first = Simple_SEO_Llms::get( $sseo_code );
	sseo_check( 'the built file is kept for a day', $sseo_first === get_transient( $sseo_key ) && (int) get_option( '_transient_timeout_' . $sseo_key ) > time() + DAY_IN_SECONDS - 60 );
	$sseo_cached = static fn(): bool => 'CACHED' === get_transient( $sseo_key );
	set_transient( $sseo_key, 'CACHED', DAY_IN_SECONDS );
	sseo_check( 'the next request reads it from the cache', 'CACHED' === Simple_SEO_Llms::get( $sseo_code ) );
	wp_update_post( array( 'ID' => $sseo_lp['contacts'], 'post_title' => 'Llms Contacts us' ) );
	sseo_check( 'saving a post drops it', ! $sseo_cached() );
	set_transient( $sseo_key, 'CACHED', DAY_IN_SECONDS );
	$sseo['posts'][] = (int) wp_insert_post( array( 'post_type' => 'revision', 'post_parent' => $sseo_lp['about'], 'post_status' => 'inherit', 'post_title' => 'Llms About' ) );
	update_post_meta( $sseo_lp['about'], '_edit_lock', '1:1' );
	update_option( 'sseo_unrelated_option', 1 );
	delete_option( 'sseo_unrelated_option' );
	$sseo['posts'][] = (int) wp_insert_post( array( 'post_type' => 'nav_menu_item', 'post_status' => 'publish', 'post_title' => 'Llms menu item' ) );
	$sseo['posts'][] = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'auto-draft', 'post_title' => 'Llms auto draft' ) );
	sseo_check( 'a revision, a menu item, an auto-draft, another meta or an unrelated option keep it', $sseo_cached() );
	update_post_meta( $sseo_lp['about'], Simple_SEO_Fields::meta_key( 'title' ), 'About the studio' );
	sseo_check( 'an SEO field drops it', ! $sseo_cached() );
	foreach ( array(
		'a term'                   => static function () use ( &$sseo ): void {
			$sseo_t          = wp_insert_term( 'Llms term', 'category' );
			$sseo['terms'][] = array( (int) $sseo_t['term_id'], 'category' );
		},
		'a Simple SEO setting'     => static fn() => update_option( Simple_SEO_Cleanup::OPTION, array( 'emoji' => true ) ),
		'the site name'            => static fn() => update_option( 'blogname', 'IT renamed' ),
		'a deleted post'           => static fn() => wp_delete_post( $sseo_lp['draft'], true ),
	) as $sseo_name => $sseo_change ) {
		set_transient( $sseo_key, 'CACHED', DAY_IN_SECONDS );
		$sseo_change();
		sseo_check( "{$sseo_name} drops it", ! $sseo_cached() );
	}
	update_option( 'blogname', $sseo_saved_options['blogname'] );
	delete_option( Simple_SEO_Cleanup::OPTION );

	// serve(): the switched-off and the foreign cases return (a served file exits).
	$sseo_saved_query    = $GLOBALS['wp_query'];
	$GLOBALS['wp_query'] = new WP_Query();
	$GLOBALS['wp_query']->set( Simple_SEO_Llms::QUERY_VAR, '1' );
	update_option( Simple_SEO_Llms::OPTION, array( 'enabled' => false ) );
	Simple_SEO_Llms::serve();
	sseo_check( 'switched off, /llms.txt is a 404', is_404() );
	$GLOBALS['wp_query'] = new WP_Query();
	$GLOBALS['wp_query']->set( Simple_SEO_Llms::QUERY_VAR, '1' );
	delete_option( Simple_SEO_Llms::OPTION );
	add_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );
	Simple_SEO_Llms::serve();
	sseo_check( 'next to another SEO plugin the request is left to it', ! is_404() );
	remove_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );
	$GLOBALS['wp_query'] = $sseo_saved_query;

	// The settings.
	$sseo_clean = Simple_SEO_Llms::sanitize( array( 'enabled' => '0', 'sections' => array( 'post:page' => 'optional', 'post:nope' => 'main', 'term:category' => 'weird', 'term:post_tag' => '' ), 'limit' => '9999' ) );
	sseo_check( 'the sanitizer keeps known sections with known places and clamps the limit', array( 'enabled' => false, 'sections' => array( 'post:page' => 'optional', 'term:post_tag' => '' ), 'limit' => 500 ) === $sseo_clean, $sseo_clean );
	sseo_check( 'a limit of 0 is the default 50', 50 === Simple_SEO_Llms::sanitize( array( 'limit' => '0' ) )['limit'] );
	sseo_check( 'the text loses its tags', array( 'summary' => 'Our studio builds', 'details' => 'Line' ) === Simple_SEO_Llms::sanitize_text( array( 'summary' => " Our <b>studio</b>\n builds ", 'details' => '<script>x</script>Line' ) ) );
	sseo_check( 'the summary and the details are kept per language', in_array( Simple_SEO_Llms::TEXT, Simple_SEO_Settings::localized(), true ) && ! in_array( Simple_SEO_Llms::OPTION, Simple_SEO_Settings::localized(), true ) );
	Simple_SEO_Llms::add_admin_tab();
	Simple_SEO_Admin::register_settings();
	$sseo_tabs = Simple_SEO_Admin::tabs();
	sseo_check( 'the llms.txt tab saves both rows', isset( $sseo_tabs['llms'] ) && array( Simple_SEO_Llms::OPTION, Simple_SEO_Llms::TEXT ) === $sseo_tabs['llms']['options'] && 35 === $sseo_tabs['llms']['order'] );
	update_option( Simple_SEO_Llms::OPTION, array( 'sections' => array( 'post:post' => 'optional' ) ) );
	update_option( Simple_SEO_Llms::TEXT, array( 'summary' => 'Sites & shops', 'details' => '' ) );
	Simple_SEO_Settings::flush();
	file_put_contents( ABSPATH . 'llms.txt', "# A file\n" );
	$_GET['tab'] = 'llms';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'] );
	unlink( ABSPATH . 'llms.txt' );
	sseo_check( 'the tab is an options.php form of its group', str_contains( $sseo_html, 'action="options.php"' ) && str_contains( $sseo_html, "value='simple-seo-llms'" ) );
	sseo_check( 'each post type and taxonomy has its place, the saved one selected', (bool) preg_match( '/name="simple_seo_llms\[sections\]\[post:post\]">\s*<option value="main"\s*>[^<]*<\/option>\s*<option value="optional"\s+selected/', $sseo_html ) && str_contains( $sseo_html, 'name="simple_seo_llms[sections][term:category]"' ) );
	sseo_check( 'it shows the file as the site serves it, escaped', str_contains( $sseo_html, esc_html( '# ' . get_bloginfo( 'name' ) ) ) && str_contains( $sseo_html, '&gt; Sites &amp; shops' ) && str_contains( $sseo_html, esc_html( Simple_SEO_Llms::url() ) ) );
	sseo_check( 'a file in the site root is named', str_contains( $sseo_html, esc_html( ABSPATH . 'llms.txt' ) ) );
	delete_option( Simple_SEO_Llms::OPTION );
	delete_option( Simple_SEO_Llms::TEXT );
	Simple_SEO_Settings::flush();

	if ( Simple_SEO_WP_Loc::languages() ) {
		$sseo_d      = Simple_SEO_WP_Loc::default_language();
		$sseo_o      = (string) current( array_diff( Simple_SEO_WP_Loc::codes(), array( $sseo_d ) ) );
		$sseo_names  = Simple_SEO_WP_Loc::language_names();
		$sseo_prefix = '/' . WP_LOC_DB::from_db_language_code( $sseo_o ) . '/';
		Simple_SEO_Llms::flush();
		$sseo_fd = Simple_SEO_Llms::get( $sseo_d );
		$sseo_fo = Simple_SEO_Llms::get( $sseo_o );
		$sseo_lo = (string) Simple_SEO_WP_Loc::in_language( $sseo_o, static fn() => get_permalink( $sseo['posts']['ml_o'] ) );
		sseo_check( 'wp-loc: each language has its file under its prefix', home_url( '/llms.txt' ) === Simple_SEO_Llms::url( $sseo_d ) && home_url( $sseo_prefix . 'llms.txt' ) === Simple_SEO_Llms::url( $sseo_o ), Simple_SEO_Llms::url( $sseo_o ) );
		sseo_check( 'wp-loc: the default file lists its language and names the other', str_contains( $sseo_fd, '[Ml default](' . get_permalink( $sseo['posts']['ml_d'] ) . ')' ) && ! str_contains( $sseo_fd, 'Ml other' ) && str_contains( $sseo_fd, 'Other languages: [' . $sseo_names[ $sseo_o ] . '](' . Simple_SEO_Llms::url( $sseo_o ) . ')' ) && ! str_contains( $sseo_fd, '[' . $sseo_names[ $sseo_d ] . '](' . Simple_SEO_Llms::url( $sseo_d ) . ')' ), $sseo_fd );
		sseo_check( 'wp-loc: the other file lists its own posts at their addresses and names the default', str_contains( $sseo_fo, '[Ml other](' . $sseo_lo . ')' ) && str_contains( $sseo_lo, $sseo_prefix ) && ! str_contains( $sseo_fo, 'Ml default' ) && str_contains( $sseo_fo, 'Other languages: [' . $sseo_names[ $sseo_d ] . '](' . Simple_SEO_Llms::url( $sseo_d ) . ')' ), $sseo_fo );
		sseo_check( 'wp-loc: a type wp-loc does not translate stays in the default file', ! isset( $sseo_shown ) || ( str_contains( $sseo_fd, (string) get_permalink( $sseo_shown ) ) && ! str_contains( $sseo_fo, (string) get_permalink( $sseo_shown ) ) ) );
		sseo_check( 'wp-loc: one cache per language, flush drops both', is_string( get_transient( 'simple_seo_llms_' . sanitize_key( $sseo_d ) ) ) && is_string( get_transient( 'simple_seo_llms_' . sanitize_key( $sseo_o ) ) ) );
		Simple_SEO_Llms::flush();
		sseo_check( 'wp-loc: …and flush drops both', false === get_transient( 'simple_seo_llms_' . sanitize_key( $sseo_d ) ) && false === get_transient( 'simple_seo_llms_' . sanitize_key( $sseo_o ) ) );
	} else {
		echo "  skip  llms.txt with wp-loc (tools/wp-loc-it.sh runs these checks)\n";
	}

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Yoast compatibility' );

	sseo_check( 'the layer loads after every plugin, before the theme', 20 === has_action( 'plugins_loaded', array( Simple_SEO_Yoast_Compat::class, 'load' ) ) && Simple_SEO_Yoast_Compat::loaded() );
	sseo_check( "Yoast's functions and WPSEO_Primary_Term are served", function_exists( 'wpseo_replace_vars' ) && function_exists( 'yoast_breadcrumb' ) && function_exists( 'yoast_get_primary_term_id' ) && function_exists( 'yoast_get_primary_term' ) && class_exists( 'WPSEO_Primary_Term', false ) );
	sseo_check( 'nothing that marks a real Yoast is defined', ! defined( 'WPSEO_VERSION' ) && ! class_exists( 'WPSEO_Meta', false ) && ! class_exists( 'WPSEO_Options', false ) && ! class_exists( 'WPSEO_Frontend', false ) && ! function_exists( 'YoastSEO' ) );
	add_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );
	$sseo_off = Simple_SEO_Yoast_Compat::enabled();
	remove_filter( 'simple_seo_conflicting_plugin', $sseo_conflict );
	add_filter( 'simple_seo_yoast_compat', '__return_false' );
	$sseo_off2 = Simple_SEO_Yoast_Compat::enabled();
	remove_filter( 'simple_seo_yoast_compat', '__return_false' );
	sseo_check( 'not next to Yoast or another SEO plugin, and it can be switched off', ! $sseo_off && ! $sseo_off2 && Simple_SEO_Yoast_Compat::enabled() );

	// Activating Yoast includes its files in that request: its functions would collide with ours.
	$sseo_saved_pagenow = $GLOBALS['pagenow'] ?? '';
	$sseo_saved_argv    = $GLOBALS['argv'] ?? array();
	set_current_screen( 'dashboard' );
	$sseo_screens = array();
	foreach ( array( 'plugins.php', 'update.php', 'index.php', 'post.php' ) as $sseo_name ) {
		$GLOBALS['pagenow']          = $sseo_name;
		$sseo_screens[ $sseo_name ] = Simple_SEO_Yoast_Compat::activation_request();
	}
	unset( $GLOBALS['current_screen'] );
	$GLOBALS['pagenow'] = $sseo_saved_pagenow;
	sseo_check( 'not on the Plugins and Update screens, where plugins get activated', array( 'plugins.php' => true, 'update.php' => true, 'index.php' => false, 'post.php' => false ) === $sseo_screens, $sseo_screens );
	$sseo_cli = array();
	foreach ( array( 'wp plugin activate wordpress-seo', 'wp plugin install wordpress-seo --activate', 'wp plugin toggle wordpress-seo', 'wp plugin list', 'wp eval-file x.php', 'wp theme activate twentytwentyfive' ) as $sseo_name ) {
		$GLOBALS['argv']          = explode( ' ', $sseo_name );
		$sseo_cli[ $sseo_name ] = Simple_SEO_Yoast_Compat::activation_request();
	}
	$GLOBALS['argv'] = $sseo_saved_argv;
	sseo_check( 'nor in WP-CLI plugin activate, install or toggle', array( true, true, true, false, false, false ) === array_values( $sseo_cli ), $sseo_cli );

	// wpseo_replace_vars().
	$sseo_sep  = Simple_SEO_Titles::separator();
	$sseo_name = (string) get_option( 'blogname' );
	sseo_check( 'the site name and the separator, as most themes ask', $sseo_name === wpseo_replace_vars( '%%sitename%%', array() ) && $sseo_sep === wpseo_replace_vars( '%%sep%%', array() ) && "{$sseo_sep} {$sseo_name}" === wpseo_replace_vars( '%%sep%% %%sitename%%', array() ), array( wpseo_replace_vars( '%%sep%%', array() ), wpseo_replace_vars( '%%sep%% %%sitename%%', array() ) ) );
	update_post_meta( $sseo_p['bc'], Simple_SEO_Fields::meta_key( Simple_SEO_Primary_Term::field( 'category' ) ), '' );
	$sseo_text = wpseo_replace_vars( '%%title%% %%sep%% %%category%% %%sep%% %%sitename%%', get_post( $sseo_p['bc'] ) );
	sseo_check( 'a post: its title and primary category', "Bc Post {$sseo_sep} Bc Sport {$sseo_sep} {$sseo_name}" === $sseo_text, $sseo_text );
	sseo_check( 'a post given as an array of its fields', $sseo_text === wpseo_replace_vars( '%%title%% %%sep%% %%category%% %%sep%% %%sitename%%', array( 'ID' => $sseo_p['bc'] ) ) );
	$sseo_term = get_term( $sseo_sport, 'category' );
	wp_update_term( $sseo_sport, 'category', array( 'description' => 'All about sport.' ) );
	sseo_check( 'a term, as a term or as an object of its fields', 'Bc Sport: All about sport.' === wpseo_replace_vars( '%%term_title%%: %%term_description%%', get_term( $sseo_sport, 'category' ) ) && 'Bc Sport' === wpseo_replace_vars( '%%term_title%%', (object) array( 'term_id' => $sseo_sport, 'taxonomy' => 'category' ) ) );
	sseo_check( 'post type names', 'Post / Posts' === wpseo_replace_vars( '%%pt_single%% / %%pt_plural%%', get_post( $sseo_p['bc'] ) ) );
	sseo_check( 'an unknown variable goes away, an omitted one stays', 'x' === wpseo_replace_vars( '%%nope%% x', array() ) && '%%title%% x' === wpseo_replace_vars( '%%title%% x', get_post( $sseo_p['bc'] ), array( 'title' ) ) );
	update_post_meta( $sseo_p['bc'], 'sseo_price', '100 <b>EUR</b>' );
	sseo_check( 'custom fields and taxonomies by name', '100 EUR' === wpseo_replace_vars( '%%cf_sseo_price%%', get_post( $sseo_p['bc'] ) ) && str_contains( wpseo_replace_vars( '%%ct_category%%', get_post( $sseo_p['bc'] ) ), 'Bc Sport' ) );
	update_post_meta( $sseo_p['bc'], Simple_SEO_Fields::meta_key( 'keyphrase' ), 'bc focus' );
	sseo_check( "the focus keyphrase is the post's keyphrase field, none without a post", 'bc focus' === wpseo_replace_vars( '%%focuskw%%', get_post( $sseo_p['bc'] ) ) && '' === wpseo_replace_vars( '%%focuskw%%', get_term( $sseo_sport, 'category' ) ) );
	delete_post_meta( $sseo_p['bc'], Simple_SEO_Fields::meta_key( 'keyphrase' ) );
	wp_update_post( array( 'ID' => $sseo_p['bc'], 'post_author' => $sseo_u['author'] ) );
	$sseo_vals = array( wp_date( 'Y' ), wpseo_replace_vars( '%%currentyear%%', array() ), get_userdata( $sseo_u['author'] )->display_name, wpseo_replace_vars( '%%name%%', get_post( $sseo_p['bc'] ) ) );
	sseo_check( 'the year and the author', $sseo_vals[0] === $sseo_vals[1] && $sseo_vals[2] === $sseo_vals[3], $sseo_vals );
	sseo_check( "text without Yoast variables stays as it is, Simple SEO's {braces} too", 'Plain {title}  text ' === wpseo_replace_vars( 'Plain {title}  text ', array() ) );

	// The current page: what wpseo_replace_vars( …, array() ) and yoast_breadcrumb() are about.
	$sseo_saved_query                = $GLOBALS['wp_query'];
	$sseo_saved_wp_actions           = $GLOBALS['wp_actions']['wp'] ?? null;
	$GLOBALS['wp_query']             = sseo_query( array( 'p' => $sseo_p['bc'] ) );
	$GLOBALS['wp_actions']['wp']     = 1;
	Simple_SEO_Context::reset();
	sseo_check( 'with no post given, the page being shown', 'Bc Post' === wpseo_replace_vars( '%%title%%', array() ) );

	// yoast_breadcrumb().
	delete_option( Simple_SEO_Breadcrumbs::OPTION );
	update_option( Simple_SEO_Breadcrumbs::LABELS, array( 'home' => '', 'prefix' => 'You & me:' ) );
	Simple_SEO_Settings::flush();
	$sseo_html = yoast_breadcrumb( '<div class="breadcrumbs">', '</div>', false );
	$sseo_bsep = ' ' . esc_html( Simple_SEO_Breadcrumbs::separator() ) . ' ';
	sseo_check( 'the markup themes styled for Yoast: spans in a span, the last one marked', '<div class="breadcrumbs"><span>You &amp; me: <span><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></span>' . $sseo_bsep . '<span><a href="' . esc_url( (string) get_category_link( $sseo_news ) ) . '">Bc News</a></span>' . $sseo_bsep . '<span><a href="' . esc_url( (string) get_category_link( $sseo_sport ) ) . '">Bc Sport</a></span>' . $sseo_bsep . '<span class="breadcrumb_last" aria-current="page">Bc Post</span></span></div>' === $sseo_html, $sseo_html );
	$sseo_ret  = 'x';
	$sseo_out  = sseo_capture( static function () use ( &$sseo_ret ): void {
		$sseo_ret = yoast_breadcrumb( '<p>', '</p>' );
	} );
	sseo_check( 'printed by default, returning nothing to print twice', '' === $sseo_ret && $sseo_out === str_replace( array( '<div class="breadcrumbs">', '</div>' ), array( '<p>', '</p>' ), $sseo_html ), $sseo_out );
	// The row as if a sanitizer had let markup through: the output must escape it itself.
	$sseo_raw = static fn(): array => array( 'show_current' => false, 'separator' => '<b>' );
	add_filter( 'pre_option_' . Simple_SEO_Breadcrumbs::OPTION, $sseo_raw );
	Simple_SEO_Settings::flush();
	$sseo_html = yoast_breadcrumb( '', '', false );
	remove_filter( 'pre_option_' . Simple_SEO_Breadcrumbs::OPTION, $sseo_raw );
	sseo_check( 'without the page itself no last mark; the separator escaped', ! str_contains( $sseo_html, 'breadcrumb_last' ) && ! str_contains( $sseo_html, 'Bc Post' ) && str_contains( $sseo_html, ' &lt;b&gt; ' ), $sseo_html );
	delete_option( Simple_SEO_Breadcrumbs::OPTION );
	delete_option( Simple_SEO_Breadcrumbs::LABELS );
	$GLOBALS['wp_query'] = sseo_query( array() );
	$GLOBALS['wp_query']->is_home = true;
	Simple_SEO_Settings::flush();
	Simple_SEO_Context::reset();
	sseo_check( 'no trail on the home page, not even the wrappers', '' === yoast_breadcrumb( '<div>', '</div>', false ) );
	$sseo['posts'][]             = (int) wp_insert_post( array( 'post_title' => 'Compat & <i>co</i>', 'post_status' => 'publish', 'post_category' => array( $sseo_news ) ) );
	$GLOBALS['wp_query']         = sseo_query( array( 'p' => end( $sseo['posts'] ) ) );
	Simple_SEO_Context::reset();
	sseo_check( 'the page title escaped', str_contains( yoast_breadcrumb( '', '', false ), '<span class="breadcrumb_last" aria-current="page">Compat &amp; co</span>' ), yoast_breadcrumb( '', '', false ) );
	$GLOBALS['wp_query'] = $sseo_saved_query;
	if ( null === $sseo_saved_wp_actions ) {
		unset( $GLOBALS['wp_actions']['wp'] );
	} else {
		$GLOBALS['wp_actions']['wp'] = $sseo_saved_wp_actions;
	}
	Simple_SEO_Context::reset();

	// WPSEO_Primary_Term and the primary term helpers.
	$sseo_bc_other = (int) get_term_by( 'name', 'Bc Other', 'category' )->term_id;
	$sseo_primary  = new WPSEO_Primary_Term( 'category', $sseo_p['bc'] );
	sseo_check( 'without a pick, the deepest category', $sseo_sport === $sseo_primary->get_primary_term(), $sseo_primary->get_primary_term() );
	$sseo_primary->set_primary_term( $sseo_bc_other );
	sseo_check( 'set_primary_term() picks it in the SEO box', (string) $sseo_bc_other === Simple_SEO_Fields::post_value( $sseo_p['bc'], Simple_SEO_Primary_Term::field( 'category' ) ) && $sseo_bc_other === $sseo_primary->get_primary_term() );
	sseo_check( 'yoast_get_primary_term_id() and yoast_get_primary_term()', $sseo_bc_other === yoast_get_primary_term_id( 'category', $sseo_p['bc'] ) && 'Bc Other' === yoast_get_primary_term( 'category', get_post( $sseo_p['bc'] ) ) );
	sseo_check( 'false without terms in the taxonomy or for an unknown one', false === ( new WPSEO_Primary_Term( 'post_tag', $sseo_p['bc'] ) )->get_primary_term() && false === yoast_get_primary_term_id( 'nope', $sseo_p['bc'] ) && '' === yoast_get_primary_term( 'nope', $sseo_p['bc'] ) );
	update_post_meta( $sseo_p['bc'], Simple_SEO_Fields::meta_key( Simple_SEO_Primary_Term::field( 'category' ) ), '' );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Yoast filters' );

	foreach ( array( Simple_SEO_Titles::OPTION, Simple_SEO_Social::OPTION, Simple_SEO_Breadcrumbs::OPTION, Simple_SEO_Breadcrumbs::LABELS ) as $sseo_name ) {
		delete_option( $sseo_name );
	}
	// Author archives shown, so the sitemaps have authors too.
	update_option( Simple_SEO_Indexing::OPTION, array( 'noindex' => array( 'date' ), 'attachments' => Simple_SEO_Indexing::ATTACHMENTS_FILE ) );
	Simple_SEO_Settings::flush();
	$sseo_yf_hooks = array(
		array( 'simple_seo_document_title', 'title', 5 ),
		array( 'simple_seo_head_tags', 'head_tags', 5 ),
		array( 'simple_seo_breadcrumbs', 'breadcrumbs', 5 ),
		array( 'simple_seo_sitemap_exclude', 'sitemap_exclude', 5 ),
		array( 'simple_seo_sitemap_entry', 'sitemap_entry', 5 ),
		array( 'simple_seo_sitemap_url', 'sitemap_url', 5 ),
		array( 'wp_robots', 'robots', 21 ),
	);
	$sseo_yf_ok = true;
	foreach ( $sseo_yf_hooks as $sseo_hook ) {
		$sseo_yf_ok = $sseo_yf_ok && $sseo_hook[2] === has_filter( $sseo_hook[0], array( Simple_SEO_Yoast_Filters::class, $sseo_hook[1] ) );
	}
	sseo_check( "hooked when the layer loads: before Simple SEO's own API, robots right after Simple SEO's", $sseo_yf_ok );
	$sseo_yf_without = static function ( callable $callback ) use ( $sseo_yf_hooks ) {
		foreach ( $sseo_yf_hooks as $sseo_hook ) {
			remove_filter( $sseo_hook[0], array( Simple_SEO_Yoast_Filters::class, $sseo_hook[1] ), $sseo_hook[2] );
		}
		try {
			return $callback();
		} finally {
			Simple_SEO_Yoast_Filters::init();
		}
	};

	// The page being shown: what themes' filters are about.
	$sseo_yf_query               = $GLOBALS['wp_query'];
	$sseo_yf_actions             = $GLOBALS['wp_actions']['wp'] ?? null;
	$GLOBALS['wp_actions']['wp'] = 1;
	$sseo_yf_show                = static function ( array $vars ): Simple_SEO_Context {
		$GLOBALS['wp_query'] = sseo_query( $vars );
		Simple_SEO_Context::reset();
		return Simple_SEO_Context::current();
	};
	$sseo_yf_tags   = static fn( ?Simple_SEO_Context $context = null ): array => array_map( static fn( array $tag ): string => (string) ( $tag['attributes'][ 'link' === $tag['tag'] ? 'href' : 'content' ] ?? '' ), Simple_SEO_Head::tags( $context ?? Simple_SEO_Context::current() ) );
	$sseo_yf_robots = static function (): string {
		return preg_match( "/content='([^']*)'/", sseo_capture( 'wp_robots' ), $sseo_match ) ? $sseo_match[1] : '';
	};
	$sseo['posts']['yf'] = (int) wp_insert_post( array( 'post_title' => 'Yf Post', 'post_excerpt' => 'Short text', 'post_status' => 'publish', 'post_category' => array( $sseo_news ) ) );
	update_post_meta( $sseo['posts']['yf'], Simple_SEO_Fields::meta_key( 'og_image' ), (string) $sseo_og );
	$sseo['posts']['yf_bare'] = (int) wp_insert_post( array( 'post_title' => 'Yf Bare', 'post_status' => 'publish' ) );
	$sseo_yf                  = $sseo['posts']['yf'];

	$sseo_yf_show( array( 'p' => $sseo_yf ) );
	$sseo_yf_snapshot = static fn(): array => array(
		wp_get_document_title(),
		Simple_SEO_Head::render( Simple_SEO_Head::tags( Simple_SEO_Context::current() ) ),
		sseo_capture( 'wp_robots' ),
		Simple_SEO_Breadcrumbs::items( Simple_SEO_Context::current() ),
		Simple_SEO_Sitemaps::render_sitemap( 'sseo_map', 1 ),
		Simple_SEO_Sitemaps::render_sitemap( 'category', 1 ),
	);
	sseo_check( 'with no theme hooking a Yoast filter, every output is what Simple SEO makes', $sseo_yf_without( $sseo_yf_snapshot ) === $sseo_yf_snapshot() );

	// wpseo_title: the text of <title> only.
	$sseo_title = wp_get_document_title();
	add_filter( 'wpseo_title', static fn( $title, $presentation ) => null === $presentation ? $title . ' | Yoast & <b>co</b>' : 'a presentation?', 10, 2 );
	$sseo_t = $sseo_yf_tags();
	sseo_check( 'wpseo_title changes <title>, plain text escaped', $sseo_title . ' | Yoast &amp; co' === wp_get_document_title(), wp_get_document_title() );
	sseo_check( 'og:title keeps the SEO title', Simple_SEO_Titles::title( Simple_SEO_Context::current() ) === $sseo_t['og:title'], $sseo_t );
	add_filter( 'simple_seo_title', '__return_empty_string' );
	$sseo_yf_show( array( 'p' => $sseo_yf ) );
	sseo_check( 'a page Simple SEO leaves to the theme is not given to wpseo_title', ! str_contains( wp_get_document_title(), 'Yoast' ), wp_get_document_title() );
	remove_all_filters( 'simple_seo_title' );
	remove_all_filters( 'wpseo_title' );
	$sseo_yf_show( array( 'p' => $sseo_yf ) );
	add_filter( 'simple_seo_document_title', static fn(): string => 'Doc & co' );
	sseo_check( 'simple_seo_document_title: the <title> text of Simple SEO itself', 'Doc &amp; co' === wp_get_document_title() );
	remove_all_filters( 'simple_seo_document_title', 10 );

	// wpseo_metadesc.
	$sseo_yf_show( array( 'p' => $sseo['posts']['yf_bare'] ) );
	$sseo_t0 = $sseo_yf_tags();
	add_filter( 'wpseo_metadesc', static fn( $description, $presentation ) => '' === $description ? 'Filled <i>in</i> &amp; more' : $description . ' +', 10, 2 );
	$sseo_t = $sseo_yf_tags();
	sseo_check( 'wpseo_metadesc fills in a page without a description, first of the tags', ! isset( $sseo_t0['description'] ) && 'Filled in & more' === ( $sseo_t['description'] ?? '' ) && 'description' === array_key_first( $sseo_t ), $sseo_t );
	$sseo_yf_show( array( 'p' => $sseo_yf ) );
	sseo_check( 'and changes one that has it', 'Short text +' === ( $sseo_yf_tags()['description'] ?? '' ) );
	sseo_check( 'only on the page being shown', 'Short text' === ( $sseo_yf_tags( Simple_SEO_Context::for_post( get_post( $sseo_yf ) ) )['description'] ?? '' ) );
	remove_all_filters( 'wpseo_metadesc' );
	add_filter( 'wpseo_metadesc', '__return_empty_string' );
	sseo_check( "'' takes the description away", ! isset( $sseo_yf_tags()['description'] ) );
	remove_all_filters( 'wpseo_metadesc' );

	// Canonical, Open Graph, X.
	add_filter( 'wpseo_locale', static fn(): string => 'de_DE' );
	add_filter( 'wpseo_og_locale', static fn( $locale ): string => 'de_DE' === $locale ? 'de_AT' : 'before wpseo_locale' );
	add_filter( 'wpseo_opengraph_type', static fn( $type, $presentation ): string => 'product', 10, 2 );
	add_filter( 'wpseo_opengraph_title', static fn( $title ): string => $title . ' <b>OG</b>' );
	add_filter( 'wpseo_opengraph_desc', '__return_empty_string' );
	add_filter( 'wpseo_opengraph_url', '__return_false' );
	add_filter( 'wpseo_canonical', static fn( $canonical ): string => $canonical . '#c' );
	add_filter( 'wpseo_opengraph_image_width', static fn(): int => 999 );
	add_filter( 'wpseo_opengraph_image_height', static fn(): string => '333' );
	add_filter( 'wpseo_opengraph_image_type', static fn(): string => 'image/webp' );
	$sseo_t = $sseo_yf_tags();
	sseo_check( 'og:locale through wpseo_locale, then wpseo_og_locale; og:type', 'de_AT' === ( $sseo_t['og:locale'] ?? '' ) && 'product' === ( $sseo_t['og:type'] ?? '' ), $sseo_t );
	sseo_check( 'og:title through wpseo_opengraph_title, plain text', Simple_SEO_Social::title( Simple_SEO_Context::current() ) . ' OG' === ( $sseo_t['og:title'] ?? '' ), $sseo_t );
	sseo_check( "og:description and og:url taken away with '' and false", ! isset( $sseo_t['og:description'] ) && ! isset( $sseo_t['og:url'] ), $sseo_t );
	sseo_check( 'canonical through wpseo_canonical', get_permalink( $sseo_yf ) . '#c' === ( $sseo_t['canonical'] ?? '' ), $sseo_t );
	sseo_check( 'the image size and type through their filters, the image itself as it was', str_ends_with( $sseo_t['og:image'] ?? '', 'sseo-og.jpg' ) && '999' === ( $sseo_t['og:image:width'] ?? '' ) && '333' === ( $sseo_t['og:image:height'] ?? '' ) && 'image/webp' === ( $sseo_t['og:image:type'] ?? '' ), $sseo_t );
	$sseo_t = $sseo_yf_tags( Simple_SEO_Context::for_post( get_post( $sseo_yf ) ) );
	sseo_check( 'only on the page being shown', 'article' === ( $sseo_t['og:type'] ?? '' ) && ! str_ends_with( $sseo_t['og:title'] ?? '', 'OG' ), $sseo_t );
	$sseo_yf_show( array( 's' => 'zz' ) );
	add_filter( 'wpseo_twitter_title', static fn(): string => 'X' );
	$sseo_t = $sseo_yf_tags();
	sseo_check( 'a page without the tags gets none of them', ! isset( $sseo_t['og:title'] ) && ! isset( $sseo_t['og:type'] ) && ! isset( $sseo_t['canonical'] ) && ! isset( $sseo_t['twitter:title'] ), $sseo_t );
	foreach ( array( 'wpseo_locale', 'wpseo_og_locale', 'wpseo_opengraph_type', 'wpseo_opengraph_title', 'wpseo_opengraph_desc', 'wpseo_opengraph_url', 'wpseo_canonical', 'wpseo_opengraph_image_width', 'wpseo_opengraph_image_height', 'wpseo_opengraph_image_type', 'wpseo_twitter_title' ) as $sseo_name ) {
		remove_all_filters( $sseo_name );
	}
	$sseo_yf_show( array( 'p' => $sseo_yf ) );
	add_filter( 'wpseo_opengraph_image', '__return_false' );
	add_filter( 'wpseo_canonical', '__return_false' );
	$sseo_t = $sseo_yf_tags();
	sseo_check( 'without og:image none of the tags about it; canonical taken away with false', ! array_intersect_key( $sseo_t, array_flip( array( 'og:image', 'og:image:width', 'og:image:height', 'og:image:type', 'og:image:alt', 'canonical' ) ) ), $sseo_t );
	remove_all_filters( 'wpseo_opengraph_image' );
	remove_all_filters( 'wpseo_canonical' );
	add_filter( 'wpseo_opengraph_image', static fn(): string => 'https://cdn.example.com/og.jpg' );
	sseo_check( 'og:image through wpseo_opengraph_image', 'https://cdn.example.com/og.jpg' === ( $sseo_yf_tags()['og:image'] ?? '' ) );
	remove_all_filters( 'wpseo_opengraph_image' );
	$sseo_t0 = $sseo_yf_tags();
	add_filter( 'wpseo_twitter_title', static fn( $title ): string => 'X: <b>' . $title . '</b>' );
	add_filter( 'wpseo_twitter_image', static fn(): string => 'https://cdn.example.com/x.jpg' );
	$sseo_t = $sseo_yf_tags();
	sseo_check( 'X tags only where a theme gives X something else', ! isset( $sseo_t0['twitter:title'] ) && ! isset( $sseo_t0['twitter:image'] ) && 'X: ' . $sseo_t['og:title'] === ( $sseo_t['twitter:title'] ?? '' ) && 'https://cdn.example.com/x.jpg' === ( $sseo_t['twitter:image'] ?? '' ), $sseo_t );
	remove_all_filters( 'wpseo_twitter_title' );
	remove_all_filters( 'wpseo_twitter_image' );
	add_filter( 'wpseo_twitter_title', static fn( $title ) => $title );
	sseo_check( 'the same as og:title: no X tag', ! isset( $sseo_yf_tags()['twitter:title'] ) );
	remove_all_filters( 'wpseo_twitter_title' );

	// Robots.
	$sseo_r0 = $sseo_yf_robots();
	add_filter( 'wpseo_robots', static fn( $robots, $presentation ): string => 'noindex, nofollow', 10, 2 );
	sseo_check( 'wpseo_robots as a string', 'max-image-preview:large' === $sseo_r0 && 'noindex, nofollow' === $sseo_yf_robots(), array( $sseo_r0, $sseo_yf_robots() ) );
	remove_all_filters( 'wpseo_robots' );
	add_filter( 'wpseo_robots', static fn(): string => 'NONE, follow' );
	sseo_check( '"none" is noindex, nofollow', 'noindex, nofollow' === $sseo_yf_robots(), $sseo_yf_robots() );
	remove_all_filters( 'wpseo_robots' );
	add_filter(
		'wpseo_robots_array',
		static function ( $robots ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'nofollow';
			unset( $robots['max-image-preview'] );
			return $robots;
		}
	);
	sseo_check( 'wpseo_robots_array: index, follow and the rest as Yoast keeps them', 'noindex, nofollow' === $sseo_yf_robots(), $sseo_yf_robots() );
	remove_all_filters( 'wpseo_robots_array' );
	add_filter( 'wpseo_robots_array', static fn( $robots ): array => array( 'index' => 'noindex' ) + $robots );
	sseo_check( 'follow stays next to noindex', 'noindex, follow, max-image-preview:large' === $sseo_yf_robots(), $sseo_yf_robots() );
	remove_all_filters( 'wpseo_robots_array' );
	add_filter( 'wpseo_robots_array', static fn( $robots ): array => $robots + array( 'noarchive' => 'noarchive' ) );
	sseo_check( 'index and a lone follow are not printed', 'max-image-preview:large, noarchive' === $sseo_yf_robots(), $sseo_yf_robots() );
	update_post_meta( $sseo['posts']['yf_bare'], '_simple_seo_index', 'noindex' );
	update_post_meta( $sseo['posts']['yf_bare'], '_simple_seo_nofollow', '1' );
	$sseo_yf_show( array( 'p' => $sseo['posts']['yf_bare'] ) );
	sseo_check( "a page's own noindex and nofollow reach the theme as Yoast has them", 'noindex, nofollow, max-image-preview:large, noarchive' === $sseo_yf_robots(), $sseo_yf_robots() );
	delete_post_meta( $sseo['posts']['yf_bare'], '_simple_seo_index' );
	delete_post_meta( $sseo['posts']['yf_bare'], '_simple_seo_nofollow' );
	$sseo_yf_show( array( 'p' => $sseo_yf ) );
	remove_all_filters( 'wpseo_robots_array' );
	// Another plugin's directive survives a theme filter that changes nothing.
	$sseo_follow = static fn( array $robots ): array => $robots + array( 'follow' => true );
	add_filter( 'wp_robots', $sseo_follow, 15 );
	add_filter( 'wpseo_robots', static fn( $robots ) => $robots );
	sseo_check( 'a filter that changes nothing leaves the robots as they were', str_contains( $sseo_yf_robots(), 'follow' ), $sseo_yf_robots() );
	remove_filter( 'wp_robots', $sseo_follow, 15 );
	remove_all_filters( 'wpseo_robots' );
	add_filter( 'wpseo_robots', static fn(): string => 'index, follow' );
	update_option( 'blog_public', '0' );
	sseo_check( 'a site closed in Settings → Reading stays closed', str_contains( $sseo_yf_robots(), 'noindex' ), $sseo_yf_robots() );
	update_option( 'blog_public', '1' );
	set_current_screen( 'dashboard' );
	$sseo_r = Simple_SEO_Yoast_Filters::robots( array( 'noindex' => true ) );
	unset( $GLOBALS['current_screen'] );
	sseo_check( 'not in the admin', array( 'noindex' => true ) === $sseo_r, $sseo_r );
	remove_all_filters( 'wpseo_robots' );

	// Breadcrumbs: the trail (both functions), then the markup of yoast_breadcrumb().
	register_post_type(
		'sseo_yf_slug',
		array(
			'public'      => true,
			'has_archive' => 'yf-archive',
			'label'       => 'Yf Items',
		)
	);
	$sseo['posts']['yf_item'] = (int) wp_insert_post( array( 'post_title' => 'Yf Item', 'post_type' => 'sseo_yf_slug', 'post_status' => 'publish' ) );
	$sseo_yf_links            = array();
	add_filter(
		'wpseo_breadcrumb_links',
		static function ( $links ) use ( &$sseo_yf_links ) {
			$sseo_yf_links[] = $links;
			foreach ( $links as &$link ) {
				if ( 'sseo_map' === ( $link['ptarchive'] ?? '' ) ) {
					$link['text'] = 'Atlas <b>A</b>';
				}
			}
			unset( $link );
			array_splice( $links, 1, 0, array( array( 'url' => 'https://example.com/hub/', 'text' => 'Hub' ) ) );
			return $links;
		}
	);
	$sseo_yf_show( array( 'p' => $sseo_p['bc_map'], 'post_type' => 'sseo_map' ) );
	$sseo_items = Simple_SEO_Breadcrumbs::items( Simple_SEO_Context::current() );
	sseo_check( 'wpseo_breadcrumb_links: links of url and text, a post type archive marked', array( 'url' => home_url( '/' ), 'text' => 'Home' ) === ( $sseo_yf_links[0][0] ?? null ) && array( 'url' => (string) get_post_type_archive_link( 'sseo_map' ), 'text' => 'Maps', 'ptarchive' => 'sseo_map' ) === ( $sseo_yf_links[0][1] ?? null ), $sseo_yf_links );
	sseo_check( 'the trail as the theme left it, labels plain', array( 'Home', 'Hub', 'Atlas A', 'Bc Map' ) === array_column( $sseo_items, 'label' ) && 'https://example.com/hub/' === $sseo_items[1]['url'], $sseo_items );
	sseo_check( "Simple SEO's own breadcrumbs get the trail too", str_contains( simple_seo_breadcrumbs(), '>Atlas A</a>' ) );
	sseo_check( 'only on the page being shown', array( 'Home', 'Maps', 'Bc Map' ) === array_column( Simple_SEO_Breadcrumbs::items( Simple_SEO_Context::for_post( get_post( $sseo_p['bc_map'] ) ) ), 'label' ) );
	$sseo_yf_links = array();
	$sseo_yf_show( array( 'p' => $sseo['posts']['yf_item'], 'post_type' => 'sseo_yf_slug' ) );
	Simple_SEO_Breadcrumbs::items( Simple_SEO_Context::current() );
	sseo_check( 'an archive with its own slug is marked too', 'sseo_yf_slug' === ( $sseo_yf_links[0][1]['ptarchive'] ?? '' ), $sseo_yf_links );
	remove_all_filters( 'wpseo_breadcrumb_links' );

	$sseo_yf_show( array( 'p' => $sseo_p['bc_map'], 'post_type' => 'sseo_map' ) );
	add_filter(
		'wpseo_breadcrumb_single_link_info',
		static function ( $info, $index, $crumbs ) {
			if ( 0 === $index ) {
				$info['text'] = 'Start & (' . count( $crumbs ) . ')';
				$info['url']  = home_url( '/?a=1&b=2' );
			}
			return 'Maps' === $info['text'] ? false : $info;
		},
		10,
		3
	);
	add_filter( 'wpseo_breadcrumb_single_link', static fn( $html, $info ): string => str_replace( 'Start', '<svg class="home"></svg>', $html ), 10, 2 );
	add_filter( 'wpseo_breadcrumb_separator', static fn(): string => '<svg class="sep"></svg>' );
	add_filter( 'wpseo_breadcrumb_output_class', static fn( $class ): string => trim( $class . ' hw-breadcrumbs x"y' ) );
	$sseo_html = yoast_breadcrumb( '', '', false );
	sseo_check( 'the markup filters: item info, item HTML, separator and class, the theme\'s HTML as it is, the rest escaped', '<span class="hw-breadcrumbs x&quot;y"><span><a href="' . esc_url( home_url( '/?a=1&b=2' ) ) . '"><svg class="home"></svg> &amp; (3)</a></span> <svg class="sep"></svg> <span class="breadcrumb_last" aria-current="page">Bc Map</span></span>' === $sseo_html, $sseo_html );
	sseo_check( "Simple SEO's own markup stays its own", str_contains( simple_seo_breadcrumbs(), '>Maps</a>' ) && ! str_contains( simple_seo_breadcrumbs(), '<svg' ) );
	add_filter( 'wpseo_breadcrumb_single_link', static fn( $html, $info ) => 'Bc Map' === $info['text'] ? '' : $html, 20, 2 );
	$sseo_html = yoast_breadcrumb( '', '', false );
	sseo_check( "an item made '' is left out with its separator", str_ends_with( $sseo_html, '(3)</a></span></span>' ), $sseo_html );
	remove_all_filters( 'wpseo_breadcrumb_single_link', 20 );
	add_filter( 'wpseo_breadcrumb_single_link_info', static fn( $info ) => 'Bc Map' === $info['text'] ? array( 'text' => '' ) + $info : $info, 20 );
	$sseo_html = yoast_breadcrumb( '', '', false );
	sseo_check( 'and so is an item info left without text', str_ends_with( $sseo_html, '(3)</a></span></span>' ), $sseo_html );
	foreach ( array( 'wpseo_breadcrumb_single_link_info', 'wpseo_breadcrumb_single_link', 'wpseo_breadcrumb_separator', 'wpseo_breadcrumb_output_class' ) as $sseo_name ) {
		remove_all_filters( $sseo_name );
	}
	unregister_post_type( 'sseo_yf_slug' );

	// Sitemaps.
	$sseo_yf_locs = static fn( string $name ): array => array_column( (array) Simple_SEO_Sitemaps::entries( $name, 1 ), 'loc' );
	$sseo_yf_ids  = static fn() => array( '7', 7, 'abc', -3, 0, array( 9 ), 12.0 );
	add_filter( 'simple_seo_sitemap_exclude', $sseo_yf_ids );
	$sseo_ids = ( new ReflectionMethod( Simple_SEO_Sitemaps::class, 'excluded' ) )->invoke( null, 'post', 'sseo_map' );
	remove_filter( 'simple_seo_sitemap_exclude', $sseo_yf_ids );
	sseo_check( 'simple_seo_sitemap_exclude: positive IDs only, once', array( 7, 12 ) === $sseo_ids, $sseo_ids );
	add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', static fn( $ids ): array => array_merge( (array) $ids, array( $sseo_shown ) ) );
	add_filter( 'wpseo_exclude_from_sitemap_by_term_ids', static fn( $ids ): array => array_merge( (array) $ids, array( $sseo_news ) ) );
	$sseo_l = $sseo_yf_locs( 'sseo_map' );
	$sseo_c = $sseo_yf_locs( 'category' );
	remove_all_filters( 'wpseo_exclude_from_sitemap_by_post_ids' );
	remove_all_filters( 'wpseo_exclude_from_sitemap_by_term_ids' );
	sseo_check( 'posts left out by ID', ! in_array( get_permalink( $sseo_shown ), $sseo_l, true ) && in_array( get_permalink( $sseo_p['bc_map'] ), $sseo_l, true ) && in_array( get_permalink( $sseo_shown ), $sseo_yf_locs( 'sseo_map' ), true ), $sseo_l );
	sseo_check( 'terms left out by ID', ! in_array( get_category_link( $sseo_news ), $sseo_c, true ) && in_array( get_category_link( $sseo_news ), $sseo_yf_locs( 'category' ), true ), $sseo_c );

	$sseo_yf_seen = array();
	add_filter(
		'wpseo_sitemap_entry',
		static function ( $url, $type, $object ) use ( &$sseo_yf_seen, $sseo_shown, $sseo_p, $sseo_sport ) {
			$sseo_yf_seen[ $type ] = is_object( $object ) ? get_class( $object ) : gettype( $object );
			if ( 'post' === $type && $sseo_shown === $object->ID ) {
				$url['loc']      = 'https://example.com/shown/';
				$url['mod']      = '2020-01-02T03:04:05+00:00';
				$url['images'][] = array( 'src' => 'https://cdn.example.com/extra.png' );
			}
			if ( 'user' === $type ) {
				$url['loc'] = 'https://example.com/team/';
			}
			if ( 'term' === $type && $sseo_sport === $object->term_id ) {
				return false;
			}
			return 'post' === $type && $sseo_p['bc_map'] === $object->ID ? array() : $url;
		},
		10,
		3
	);
	$sseo_list = (array) Simple_SEO_Sitemaps::entries( 'sseo_map', 1 );
	$sseo_e    = array_column( $sseo_list, null, 'loc' );
	$sseo_cl   = (array) Simple_SEO_Sitemaps::entries( 'category', 1 );
	$sseo_c    = array_column( $sseo_cl, 'loc' );
	$sseo_a = $sseo_yf_locs( 'author' );
	remove_all_filters( 'wpseo_sitemap_entry' );
	$sseo_x = $sseo_e['https://example.com/shown/'] ?? array();
	sseo_check( 'wpseo_sitemap_entry: address, date and images as the theme gives them', '2020-01-02T03:04:05+00:00' === ( $sseo_x['lastmod'] ?? '' ) && str_ends_with( $sseo_x['images'][0] ?? '', 'sseo-og.jpg' ) && array( 'https://cdn.example.com/extra.png' ) === array_slice( $sseo_x['images'] ?? array(), -1 ) && ! isset( $sseo_e[ get_permalink( $sseo_shown ) ] ), $sseo_e );
	sseo_check( 'an empty answer leaves the address out, without a hole in the list', ! isset( $sseo_e[ get_permalink( $sseo_p['bc_map'] ) ] ) && isset( $sseo_e[ (string) get_post_type_archive_link( 'sseo_map' ) ] ) && ! in_array( get_category_link( $sseo_sport ), $sseo_c, true ) && in_array( get_category_link( $sseo_news ), $sseo_c, true ) && array_is_list( $sseo_list ) && $sseo_list === array_filter( $sseo_list, 'is_array' ) && $sseo_cl === array_filter( $sseo_cl, 'is_array' ) );
	sseo_check( 'posts, terms and users with their objects', array( 'post' => 'WP_Post', 'term' => 'WP_Term', 'user' => 'WP_User' ) === array_intersect_key( $sseo_yf_seen, array_flip( array( 'post', 'term', 'user' ) ) ) && in_array( 'https://example.com/team/', $sseo_a, true ), $sseo_yf_seen );
	add_filter( 'wpseo_sitemap_post_type_first_links', static fn( $links, $type ) => 'sseo_map' === $type ? array() : $links, 10, 2 );
	$sseo_l = $sseo_yf_locs( 'sseo_map' );
	$sseo_h = $sseo_yf_locs( 'page' );
	remove_all_filters( 'wpseo_sitemap_post_type_first_links' );
	sseo_check( 'wpseo_sitemap_post_type_first_links on the archive or home page opening a sitemap', ! in_array( get_post_type_archive_link( 'sseo_map' ), $sseo_l, true ) && in_array( get_permalink( $sseo_shown ), $sseo_l, true ) && in_array( home_url( '/' ), $sseo_h, true ), array( $sseo_l, $sseo_h ) );
	// The other languages' addresses stay only with the address they belong to.
	$sseo_alt = static function ( $entry ) {
		$entry['alternates'] = array(
			'xx' => 'https://example.com/xx/',
			'yy' => array( 'not an address' ),
		);
		return $entry;
	};
	add_filter( 'simple_seo_sitemap_entry', $sseo_alt, 1 );
	add_filter(
		'wpseo_sitemap_entry',
		static function ( $url, $type, $object ) use ( $sseo_shown, $sseo_p ) {
			if ( 'post' === $type && $sseo_shown === $object->ID ) {
				$url['loc'] = 'https://example.com/shown/';
			}
			if ( 'post' === $type && $sseo_p['bc_map'] === $object->ID ) {
				$url['mod'] = '2021-01-01T00:00:00+00:00';
			}
			return $url;
		},
		10,
		3
	);
	$sseo_e = array_column( (array) Simple_SEO_Sitemaps::entries( 'sseo_map', 1 ), null, 'loc' );
	remove_all_filters( 'wpseo_sitemap_entry' );
	remove_filter( 'simple_seo_sitemap_entry', $sseo_alt, 1 );
	sseo_check( 'a moved address loses the alternates of the old one; one changed otherwise keeps them (strings only)', array() === ( $sseo_e['https://example.com/shown/']['alternates'] ?? null ) && array( 'xx' => 'https://example.com/xx/' ) === ( $sseo_e[ get_permalink( $sseo_p['bc_map'] ) ]['alternates'] ?? null ) && '2021-01-01T00:00:00+00:00' === ( $sseo_e[ get_permalink( $sseo_p['bc_map'] ) ]['lastmod'] ?? '' ), $sseo_e );
	$sseo_own = static function ( $entry, $kind, $object ) use ( $sseo_shown, $sseo_p ) {
		if ( 'post' === $kind && $sseo_shown === $object->ID ) {
			$entry['images']  = array( 'javascript:alert(1)', array( 'x' ), 'https://cdn.example.com/ok.png' );
			$entry['lastmod'] = 5;
		}
		if ( 'post' === $kind && $sseo_p['bc_map'] === $object->ID ) {
			$entry['loc'] = array( 'x' );
		}
		return $entry;
	};
	add_filter( 'simple_seo_sitemap_entry', $sseo_own, 10, 3 );
	$sseo_e = array_column( (array) Simple_SEO_Sitemaps::entries( 'sseo_map', 1 ), null, 'loc' );
	remove_filter( 'simple_seo_sitemap_entry', $sseo_own, 10 );
	sseo_check( 'simple_seo_sitemap_entry: web images only, a date or nothing, no address no entry', array( 'https://cdn.example.com/ok.png' ) === ( $sseo_e[ get_permalink( $sseo_shown ) ]['images'] ?? null ) && '' === ( $sseo_e[ get_permalink( $sseo_shown ) ]['lastmod'] ?? null ) && ! isset( $sseo_e[ get_permalink( $sseo_p['bc_map'] ) ] ), $sseo_e );
	add_filter( 'wpseo_sitemap_url', static fn( $xml, $url ): string => str_replace( '</url>', '<!-- ' . $url['loc'] . ' --></url>', $xml ), 10, 2 );
	$sseo_xml = (string) Simple_SEO_Sitemaps::render_sitemap( 'sseo_map', 1 );
	remove_all_filters( 'wpseo_sitemap_url' );
	sseo_check( 'wpseo_sitemap_url on the XML of each address', str_contains( $sseo_xml, '<!-- ' . get_permalink( $sseo_shown ) . " --></url>\n" ), $sseo_xml );

	// wpseo_replacements in wpseo_replace_vars().
	$sseo_yf_args = null;
	add_filter(
		'wpseo_replacements',
		static function ( $replacements, $args ) use ( &$sseo_yf_args ) {
			$sseo_yf_args                  = $args;
			$replacements['%%sitename%%'] = 'Site*';
			$replacements['%%custom%%']   = 'Mine %%sep%%';
			$replacements[0]              = 'zero';
			$replacements['%%title%%']    = is_string( $replacements['%%title%%'] ?? null ) ? $replacements['%%title%%'] : 'no title';
			$replacements['%%junk%%']     = array( 'not text' );
			return $replacements;
		},
		10,
		2
	);
	$sseo_text = wpseo_replace_vars( '%%sitename%% %%custom%% %%title%% %%sep%% 10 %%junk%%', get_post( $sseo_yf ) );
	remove_all_filters( 'wpseo_replacements' );
	sseo_check( 'wpseo_replacements: the values by %%name%%, with the arguments, replaced in one pass', 'Site* Mine %%sep%% Yf Post ' . Simple_SEO_Titles::separator() . ' 10' === $sseo_text && $sseo_yf_args instanceof WP_Post, $sseo_text );

	$GLOBALS['wp_query'] = $sseo_yf_query;
	if ( null === $sseo_yf_actions ) {
		unset( $GLOBALS['wp_actions']['wp'] );
	} else {
		$GLOBALS['wp_actions']['wp'] = $sseo_yf_actions;
	}
	delete_option( Simple_SEO_Indexing::OPTION );
	Simple_SEO_Settings::flush();
	Simple_SEO_Context::reset();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Yoast meta' );

	$sseo_ym_hooks = array(
		'get_post_metadata'    => 'get',
		'update_post_metadata' => 'update',
		'add_post_metadata'    => 'add',
		'delete_post_metadata' => 'delete',
	);
	$sseo_ym_ok    = true;
	foreach ( $sseo_ym_hooks as $sseo_hook => $sseo_name ) {
		$sseo_ym_ok = $sseo_ym_ok && 5 === has_filter( $sseo_hook, array( Simple_SEO_Yoast_Meta::class, $sseo_name ) );
	}
	sseo_check( "hooked when the layer loads, before other plugins' meta filters", $sseo_ym_ok );

	// Simple SEO's syntax and Yoast's.
	sseo_check( "to_simple(): Yoast's variables in Simple SEO's syntax, the ones it has no counterpart for as written", '{site} {tagline} {author} {search} {year} {post_type} {category} {excerpt} {title} %%cf_x%% %%id%% 100%' === Simple_SEO_Yoast_Compat::to_simple( '%%sitename%% %%sitedesc%% %%name%% %%searchphrase%% %%currentyear%% %%pt_single%% %%primary_category%% %%excerpt_only%% %%Title%% %%cf_x%% %%id%% 100%' ) );
	sseo_check( 'to_yoast(): back under the first name Yoast has for each; braces that are no variable stay', '%%sitename%% %%sitedesc%% %%name%% %%searchphrase%% %%currentyear%% %%pt_single%% %%category%% %%excerpt%% %%title%% %%sep%% {nope} {Title} {}' === Simple_SEO_Yoast_Compat::to_yoast( '{site} {tagline} {author} {search} {year} {post_type} {category} {excerpt} {title} {sep} {nope} {Title} {}' ) );
	Simple_SEO_Variables::register( 'sseo_price', 'Price', static fn(): string => '100 EUR' );
	sseo_check( 'a variable a theme registered keeps its name both ways and works in wpseo_replace_vars()', '%%sseo_price%%' === Simple_SEO_Yoast_Compat::to_yoast( '{sseo_price}' ) && '{sseo_price}' === Simple_SEO_Yoast_Compat::to_simple( '%%sseo_price%%' ) && '100 EUR' === wpseo_replace_vars( '%%sseo_price%%', array() ) );

	$sseo['posts']['ym']      = (int) wp_insert_post( array( 'post_title' => 'Ym Post', 'post_status' => 'publish', 'post_category' => array( $sseo_news, $sseo_sport ) ) );
	$sseo['posts']['ym_page'] = (int) wp_insert_post( array( 'post_title' => 'Ym Page', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$sseo['posts']['ym_copy'] = (int) wp_insert_post( array( 'post_title' => 'Ym Copy', 'post_status' => 'draft' ) );
	$sseo_ym                  = $sseo['posts']['ym'];
	$sseo_ym_page             = $sseo['posts']['ym_page'];
	$sseo_ym_copy             = $sseo['posts']['ym_copy'];
	$sseo_ym_rows             = static fn( string $key, int $id = 0 ): array => $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", $id ? $id : $sseo_ym, $key ) );
	$sseo_ym_insert           = static function ( int $id, array $rows ): void {
		foreach ( $rows as $sseo_key => $sseo_value ) {
			$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->postmeta, array( 'post_id' => $id, 'meta_key' => $sseo_key, 'meta_value' => $sseo_value ) );
		}
		wp_cache_delete( $id, 'post_meta' );
	};
	// The rows Yoast left, written past the meta API as Yoast itself had written them.
	$sseo_ym_insert( $sseo_ym, array( '_yoast_wpseo_title' => 'Old %%title%%', '_yoast_wpseo_metadesc' => 'Old description', '_yoast_wpseo_focuskw' => 'old focus', '_yoast_wpseo_bctitle' => 'old crumb', '_yoast_wpseo_primary_category' => (string) $sseo_news ) );

	$sseo_ym_notices = array();
	set_error_handler(
		static function ( int $no, string $text ) use ( &$sseo_ym_notices ): bool {
			$sseo_ym_notices[] = $text;
			return true;
		}
	);
	$sseo_vals = array( get_post_meta( $sseo_ym, '_yoast_wpseo_title', true ), get_post_meta( $sseo_ym, '_yoast_wpseo_title' ), metadata_exists( 'post', $sseo_ym, '_yoast_wpseo_metadesc' ), get_post_meta( $sseo_ym, '_yoast_wpseo_primary_category', true ), get_post_meta( $sseo_ym, '_yoast_wpseo_focuskw', true ) );
	restore_error_handler();
	sseo_check( "an alias reads Simple SEO's field, not the row Yoast left (and an empty one without a PHP notice)", array( '', array(), false, '', '' ) === $sseo_vals && array() === $sseo_ym_notices, array( $sseo_vals, $sseo_ym_notices ) );
	sseo_check( 'a key Simple SEO has no field for stays the row Yoast left', 'old crumb' === get_post_meta( $sseo_ym, '_yoast_wpseo_bctitle', true ) );
	update_post_meta( $sseo_ym, Simple_SEO_Fields::meta_key( 'title' ), '{title} {sep} {site} {nope}' );
	update_post_meta( $sseo_ym, Simple_SEO_Fields::meta_key( 'description' ), 'By {author} on {site}: {tagline}, {post_type}, {category}' );
	$sseo_text = get_post_meta( $sseo_ym, '_yoast_wpseo_title', true );
	sseo_check( "Simple SEO's value, its variables as Yoast's", '%%title%% %%sep%% %%sitename%% {nope}' === $sseo_text && array( $sseo_text ) === get_post_meta( $sseo_ym, '_yoast_wpseo_title' ) && metadata_exists( 'post', $sseo_ym, '_yoast_wpseo_title' ) && 'By %%name%% on %%sitename%%: %%sitedesc%%, %%pt_single%%, %%category%%' === get_post_meta( $sseo_ym, '_yoast_wpseo_metadesc', true ), $sseo_text );
	$sseo_text = wpseo_replace_vars( $sseo_text, get_post( $sseo_ym ) );
	sseo_check( 'what a theme reads works in wpseo_replace_vars()', 'Ym Post ' . Simple_SEO_Titles::separator() . ' ' . get_option( 'blogname' ) . ' {nope}' === $sseo_text, $sseo_text );

	// Writing.
	$sseo_vals = array( update_post_meta( $sseo_ym, '_yoast_wpseo_title', '%%title%% %%sep%% %%sitename%% %%TERM_TITLE%% %%modified%%' ), Simple_SEO_Fields::post_value( $sseo_ym, 'title' ), $sseo_ym_rows( '_yoast_wpseo_title' ) );
	sseo_check( "update_post_meta() writes Simple SEO's field in its syntax; the row Yoast left stays as it was", array( true, '{title} {sep} {site} {title} %%modified%%', array( 'Old %%title%%' ) ) === $sseo_vals, $sseo_vals );
	update_post_meta( $sseo_ym, '_yoast_wpseo_title', '%%title%% for the page' );
	$sseo_text = Simple_SEO_Titles::title( Simple_SEO_Context::for_post( get_post( $sseo_ym ) ) );
	sseo_check( 'the page shows it', 'Ym Post for the page' === $sseo_text, $sseo_text );
	$sseo_text = "Quotes \" and slash \\ %%title%%";
	update_post_meta( $sseo_ym, '_yoast_wpseo_title', wp_slash( $sseo_text ) );
	update_post_meta( $sseo_ym, '_yoast_wpseo_metadesc', wp_slash( "Line one\nLine two's" ) );
	sseo_check( 'what a theme writes reads back the same: quotes, slashes, lines', $sseo_text === get_post_meta( $sseo_ym, '_yoast_wpseo_title', true ) && "Line one\nLine two's" === get_post_meta( $sseo_ym, '_yoast_wpseo_metadesc', true ), get_post_meta( $sseo_ym, '_yoast_wpseo_title', true ) );
	update_post_meta( $sseo_ym, '_yoast_wpseo_title', '<b>Bold</b> title' );
	$sseo_vals = array( get_post_meta( $sseo_ym, '_yoast_wpseo_title', true ), update_post_meta( $sseo_ym, '_yoast_wpseo_title', '<b></b>' ), update_post_meta( $sseo_ym, '_yoast_wpseo_title', array( 'x' ) ), get_post_meta( $sseo_ym, '_yoast_wpseo_title', true ) );
	sseo_check( "the field's sanitizer applies; what it cannot hold is not written", array( 'Bold title', false, false, 'Bold title' ) === $sseo_vals, $sseo_vals );
	$sseo_vals = array( update_post_meta( $sseo_ym, '_yoast_wpseo_title', '' ), $sseo_ym_rows( Simple_SEO_Fields::meta_key( 'title' ) ), $sseo_ym_rows( '_yoast_wpseo_title' ), get_post_meta( $sseo_ym, '_yoast_wpseo_title', true ) );
	sseo_check( "'' clears the field, no row left; the row Yoast left stays", array( true, array(), array( 'Old %%title%%' ), '' ) === $sseo_vals, $sseo_vals );
	$sseo_vals = array( delete_post_meta( $sseo_ym, '_yoast_wpseo_metadesc', 'Another text' ), get_post_meta( $sseo_ym, '_yoast_wpseo_metadesc', true ), delete_post_meta( $sseo_ym, '_yoast_wpseo_metadesc', "Line one\nLine two's" ), $sseo_ym_rows( Simple_SEO_Fields::meta_key( 'description' ) ), $sseo_ym_rows( '_yoast_wpseo_metadesc' ), delete_post_meta( $sseo_ym, '_yoast_wpseo_metadesc' ) );
	sseo_check( "delete_post_meta() clears the field, given a value only that value; the row Yoast left stays", array( false, "Line one\nLine two's", true, array(), array( 'Old description' ), false ) === $sseo_vals, $sseo_vals );
	$sseo_vals = array( add_post_meta( $sseo_ym, '_yoast_wpseo_title', 'First', true ), add_post_meta( $sseo_ym, '_yoast_wpseo_title', 'Second', true ), add_post_meta( $sseo_ym, '_yoast_wpseo_title', 'Third' ), $sseo_ym_rows( Simple_SEO_Fields::meta_key( 'title' ) ) );
	sseo_check( 'add_post_meta() sets the one value; with unique only while it is unset', is_int( $sseo_vals[0] ) && false === $sseo_vals[1] && true === $sseo_vals[2] && array( 'Third' ) === $sseo_vals[3], $sseo_vals );
	$sseo_vals = array( update_post_meta( $sseo_ym, '_yoast_wpseo_title', 'Fourth', 'Not it' ), get_post_meta( $sseo_ym, '_yoast_wpseo_title', true ), update_post_meta( $sseo_ym, '_yoast_wpseo_title', 'Fourth', 'Third' ), get_post_meta( $sseo_ym, '_yoast_wpseo_title', true ), delete_post_meta( $sseo_ym, '_yoast_wpseo_title', array( 'x' ) ) );
	sseo_check( 'update_post_meta() given the previous value changes only that one', array( false, 'Third', true, 'Fourth', false ) === $sseo_vals, $sseo_vals );
	$sseo_theirs = static fn( $value, $id, $key ) => '_yoast_wpseo_title' === $key ? array( 'Theirs' ) : $value;
	$sseo_veto   = static fn( $check, $id, $key ) => '_yoast_wpseo_title' === $key ? 'vetoed' : $check;
	add_filter( 'get_post_metadata', $sseo_theirs, 1, 3 );
	foreach ( array( 'update_post_metadata', 'add_post_metadata', 'delete_post_metadata' ) as $sseo_hook ) {
		add_filter( $sseo_hook, $sseo_veto, 1, 3 );
	}
	$sseo_vals = array( get_post_meta( $sseo_ym, '_yoast_wpseo_title', true ), update_post_meta( $sseo_ym, '_yoast_wpseo_title', 'Mine' ), add_post_meta( $sseo_ym, '_yoast_wpseo_title', 'Mine' ), delete_post_meta( $sseo_ym, '_yoast_wpseo_title' ), Simple_SEO_Fields::post_value( $sseo_ym, 'title' ) );
	remove_filter( 'get_post_metadata', $sseo_theirs, 1 );
	foreach ( array( 'update_post_metadata', 'add_post_metadata', 'delete_post_metadata' ) as $sseo_hook ) {
		remove_filter( $sseo_hook, $sseo_veto, 1 );
	}
	sseo_check( "another plugin's earlier answer stands", array( 'Theirs', true, 'vetoed', true, 'Fourth' ) === $sseo_vals, $sseo_vals );

	// Indexing.
	update_option( 'blog_public', '1' );
	update_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-noindex', '1' );
	update_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-nofollow', '1' );
	$sseo_vals = array( Simple_SEO_Fields::post_value( $sseo_ym, 'index' ), Simple_SEO_Fields::post_value( $sseo_ym, 'nofollow' ), get_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-noindex', true ), get_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-nofollow', true ), Simple_SEO_Indexing::is_noindex( Simple_SEO_Context::for_post( get_post( $sseo_ym ) ) ) );
	sseo_check( "Yoast's noindex 1 and nofollow 1 are Simple SEO's noindex and nofollow", array( 'noindex', '1', '1', '1', true ) === $sseo_vals, $sseo_vals );
	update_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-noindex', '2' );
	update_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-nofollow', '0' );
	$sseo_vals = array( Simple_SEO_Fields::post_value( $sseo_ym, 'index' ), get_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-noindex', true ), Simple_SEO_Indexing::is_noindex( Simple_SEO_Context::for_post( get_post( $sseo_ym ) ) ), $sseo_ym_rows( Simple_SEO_Fields::meta_key( 'nofollow' ) ), get_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-nofollow', true ) );
	sseo_check( '2 is index; nofollow 0 clears it', array( 'index', '2', false, array(), '' ) === $sseo_vals, $sseo_vals );
	update_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-noindex', '0' );
	$sseo_ym_insert( $sseo_ym, array( Simple_SEO_Fields::meta_key( 'nofollow' ) => '0' ) );
	sseo_check( "noindex 0 is Yoast's default, the setting of the type; a stored 0 is no nofollow, as Simple SEO reads it", array() === $sseo_ym_rows( Simple_SEO_Fields::meta_key( 'index' ) ) && '' === get_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-noindex', true ) && '' === get_post_meta( $sseo_ym, '_yoast_wpseo_meta-robots-nofollow', true ) );
	delete_post_meta( $sseo_ym, Simple_SEO_Fields::meta_key( 'nofollow' ) );
	$sseo_vals = array( update_post_meta( $sseo_ym, '_yoast_wpseo_canonical', 'https://example.com/original/' ), update_post_meta( $sseo_ym, '_yoast_wpseo_canonical', 'javascript:alert(1)' ), get_post_meta( $sseo_ym, '_yoast_wpseo_canonical', true ), Simple_SEO_Indexing::canonical( Simple_SEO_Context::for_post( get_post( $sseo_ym ) ) ) );
	sseo_check( 'the canonical address; one the field refuses is not written', true === $sseo_vals[0] && false === $sseo_vals[1] && array( 'https://example.com/original/', 'https://example.com/original/' ) === array_slice( $sseo_vals, 2 ), $sseo_vals );

	// Social networks.
	update_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-title', '%%title%% to share' );
	update_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-description', 'About %%sitename%%' );
	$sseo_vals = array( Simple_SEO_Fields::post_value( $sseo_ym, 'og_title' ), Simple_SEO_Fields::post_value( $sseo_ym, 'og_description' ), get_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-title', true ), get_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-description', true ), Simple_SEO_Social::title( Simple_SEO_Context::for_post( get_post( $sseo_ym ) ) ) );
	sseo_check( 'Open Graph title and description, with variables', array( '{title} to share', 'About {site}', '%%title%% to share', 'About %%sitename%%', 'Ym Post to share' ) === $sseo_vals, $sseo_vals );
	update_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-image-id', (string) $sseo_og );
	$sseo_vals = array( Simple_SEO_Fields::post_value( $sseo_ym, 'og_image' ), get_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-image-id', true ), get_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-image', true ) );
	sseo_check( 'the image: its ID, and its address where Yoast kept that', array( (string) $sseo_og, (string) $sseo_og, (string) wp_get_attachment_url( $sseo_og ) ) === $sseo_vals, $sseo_vals );
	$sseo_vals = array( update_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-image', wp_get_attachment_url( $sseo_feat ) ), Simple_SEO_Fields::post_value( $sseo_ym, 'og_image' ), update_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-image', 'https://elsewhere.example/picture.jpg' ), update_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-image-id', (string) $sseo_pdf ), Simple_SEO_Fields::post_value( $sseo_ym, 'og_image' ) );
	sseo_check( 'an address in the media library is that image; another address, or a file that is no image, is not written', array( true, (string) $sseo_feat, false, false, (string) $sseo_feat ) === $sseo_vals, $sseo_vals );
	$sseo_vals = array( update_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-image-id', '0' ), $sseo_ym_rows( Simple_SEO_Fields::meta_key( 'og_image' ) ), get_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-image', true ), update_post_meta( $sseo_ym, '_yoast_wpseo_opengraph-image', '' ) );
	sseo_check( "an image ID of 0 or an empty address clears the image", array( true, array(), '', false ) === $sseo_vals, $sseo_vals );

	// The primary category.
	$sseo_vals = array( update_post_meta( $sseo_ym, '_yoast_wpseo_primary_category', $sseo_news ), Simple_SEO_Fields::post_value( $sseo_ym, Simple_SEO_Primary_Term::field( 'category' ) ), get_post_meta( $sseo_ym, '_yoast_wpseo_primary_category', true ), Simple_SEO_Primary_Term::get( get_post( $sseo_ym ), 'category' )->term_id ?? 0 );
	sseo_check( "the primary category is the SEO box's pick (not the deepest one)", true === $sseo_vals[0] && array( (string) $sseo_news, (string) $sseo_news, $sseo_news ) === array_slice( $sseo_vals, 1 ), $sseo_vals );
	$sseo_tag        = wp_insert_term( 'Ym Tag', 'post_tag' );
	$sseo['terms'][] = array( (int) $sseo_tag['term_id'], 'post_tag' );
	$sseo_vals       = array( update_post_meta( $sseo_ym, '_yoast_wpseo_primary_category', (int) $sseo_tag['term_id'] ), get_post_meta( $sseo_ym, '_yoast_wpseo_primary_category', true ), update_post_meta( $sseo_ym, '_yoast_wpseo_primary_category', 0 ), $sseo_ym_rows( Simple_SEO_Fields::meta_key( Simple_SEO_Primary_Term::field( 'category' ) ) ) );
	sseo_check( 'a term of another taxonomy is not written; 0 is no pick', array( false, (string) $sseo_news, true, array() ) === $sseo_vals, $sseo_vals );
	update_post_meta( $sseo_ym, '_yoast_wpseo_primary_category', $sseo_news );
	update_post_meta( $sseo_ym_page, '_yoast_wpseo_primary_category', 5 );
	update_post_meta( $sseo_ym_page, '_yoast_wpseo_title', 'Page %%title%%' );
	$sseo_vals = array( $sseo_ym_rows( '_yoast_wpseo_primary_category', $sseo_ym_page ), get_post_meta( $sseo_ym_page, '_yoast_wpseo_primary_category', true ), Simple_SEO_Fields::post_value( $sseo_ym_page, 'title' ) );
	sseo_check( "a post type without the taxonomy has no primary alias, the row is a row; the other fields are every type's", array( array( '5' ), '5', 'Page {title}' ) === $sseo_vals, $sseo_vals );
	update_post_meta( $sseo_ym, '_yoast_wpseo_bctitle', 'new crumb' );
	update_post_meta( $sseo_ym, '_yoast_wpseo_twitter-title', 'X title' );
	sseo_check( "the keys Simple SEO has no field for are Yoast's rows to write too", array( 'new crumb' ) === $sseo_ym_rows( '_yoast_wpseo_bctitle' ) && array( 'X title' ) === $sseo_ym_rows( '_yoast_wpseo_twitter-title' ) );
	update_post_meta( $sseo_ym, '_yoast_wpseo_focuskw', '  new   focus ' );
	$sseo_vals = array( Simple_SEO_Fields::post_value( $sseo_ym, 'keyphrase' ), get_post_meta( $sseo_ym, '_yoast_wpseo_focuskw', true ), $sseo_ym_rows( '_yoast_wpseo_focuskw' ) );
	sseo_check( "the focus keyphrase is Simple SEO's keyphrase field, Yoast's row stays", array( 'new focus', 'new focus', array( 'old focus' ) ) === $sseo_vals, $sseo_vals );

	// Every row of a post at once.
	update_post_meta( $sseo_ym, '_yoast_wpseo_title', 'Fifth' );
	wp_cache_delete( $sseo_ym, 'post_meta' );
	$sseo_list = get_post_meta( $sseo_ym );
	sseo_check( "the list of all of a post's meta has no aliases, the rest as it is", ! isset( $sseo_list['_yoast_wpseo_title'] ) && ! isset( $sseo_list['_yoast_wpseo_metadesc'] ) && ! isset( $sseo_list['_yoast_wpseo_primary_category'] ) && ! isset( $sseo_list['_yoast_wpseo_focuskw'] ) && array( 'new crumb' ) === ( $sseo_list['_yoast_wpseo_bctitle'] ?? null ) && array( 'Fifth' ) === ( $sseo_list[ Simple_SEO_Fields::meta_key( 'title' ) ] ?? null ), array_keys( $sseo_list ) );
	sseo_check( '... asked for a single value too', $sseo_list === get_post_meta( $sseo_ym, '', true ) );
	sseo_check( 'a key that is no alias for that post stays in its list', array( '5' ) === ( get_post_meta( $sseo_ym_page )['_yoast_wpseo_primary_category'] ?? null ) );
	foreach ( $sseo_list as $sseo_name => $sseo_values ) {
		foreach ( $sseo_values as $sseo_value ) {
			add_post_meta( $sseo_ym_copy, $sseo_name, maybe_unserialize( $sseo_value ) );
		}
	}
	$sseo_vals = array( $sseo_ym_rows( Simple_SEO_Fields::meta_key( 'title' ), $sseo_ym_copy ), $sseo_ym_rows( Simple_SEO_Fields::meta_key( Simple_SEO_Primary_Term::field( 'category' ) ), $sseo_ym_copy ), $sseo_ym_rows( '_yoast_wpseo_title', $sseo_ym_copy ) );
	sseo_check( "copying every row of a post, as wp-loc does for a new translation, copies Simple SEO's fields once and not Yoast's old values", array( array( 'Fifth' ), array( (string) $sseo_news ), array() ) === $sseo_vals, $sseo_vals );

	delete_post_meta_by_key( '_yoast_wpseo_title' );
	sseo_check( "a delete from every post removes the rows Yoast left and keeps Simple SEO's fields", array() === $sseo_ym_rows( '_yoast_wpseo_title' ) && 'Fifth' === Simple_SEO_Fields::post_value( $sseo_ym, 'title' ) );
	foreach ( $sseo_ym_hooks as $sseo_hook => $sseo_name ) {
		remove_filter( $sseo_hook, array( Simple_SEO_Yoast_Meta::class, $sseo_name ), 5 );
	}
	$sseo_vals = array( get_post_meta( $sseo_ym, '_yoast_wpseo_metadesc', true ), get_post_meta( $sseo_ym, '_yoast_wpseo_primary_category', true ) );
	Simple_SEO_Yoast_Meta::init();
	sseo_check( 'without the layer (next to Yoast) the rows Yoast left are what they were', array( 'Old description', (string) $sseo_news ) === $sseo_vals, $sseo_vals );
	update_option( 'blog_public', '0' === (string) $sseo_saved_options['blog_public'] ? '0' : '1' );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Yoast import' );

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	foreach ( array( 'import-source', 'yoast-import', 'rank-math-import', 'aioseo-import', 'source-cleanup', 'yoast-cleanup', 'rank-math-cleanup', 'aioseo-cleanup', 'import' ) as $sseo_name ) {
		require_once SIMPLE_SEO_DIR . 'includes/class-simple-seo-' . $sseo_name . '.php';
	}
	$sseo_wpdb = $GLOBALS['wpdb'];
	// A site fresh from Yoast: none of Yoast's rows of the earlier sections or of the stand's own Yoast,
	// Simple SEO's rows as install() makes them.
	$sseo_wpdb->query( "DELETE FROM {$sseo_wpdb->postmeta} WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%'" );
	wp_cache_flush();
	foreach ( array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_taxonomy_meta', Simple_SEO_Yoast_Import::STATE ) as $sseo_name ) {
		delete_option( $sseo_name );
	}
	$sseo_yi_rows = array( Simple_SEO_Titles::OPTION, Simple_SEO_Indexing::OPTION, Simple_SEO_Social::OPTION, Simple_SEO_Special::OPTION, Simple_SEO_Breadcrumbs::OPTION, Simple_SEO_Breadcrumbs::LABELS, Simple_SEO_Sitemaps::OPTION, Simple_SEO_Cleanup::OPTION );
	foreach ( $sseo_yi_rows as $sseo_name ) {
		// Earlier sections registered the tabs' sanitizers, which fill a row with its defaults even
		// on add_option(); on a site install() runs before them and the rows start empty.
		remove_all_filters( 'sanitize_option_' . $sseo_name );
		delete_option( $sseo_name );
	}
	Simple_SEO_Settings::install();
	Simple_SEO_Settings::flush();
	sseo_check( 'no data of Yoast: nothing found, nothing to import', ! Simple_SEO_Yoast_Import::found() && array( 'settings' => false, 'posts' => 0, 'terms' => 0 ) === Simple_SEO_Yoast_Import::summary() );
	Simple_SEO_Import::detect();
	sseo_check( 'the first admin request finds nothing to offer', 'none' === Simple_SEO_Import::state( 'yoast' )['status'] );
	delete_option( Simple_SEO_Yoast_Import::STATE );
	update_option( 'wpseo_taxonomy_meta', array( 'category' => array( $sseo_news => array( 'wpseo_title' => 'x' ) ) ) );
	$sseo_yi_by     = array( Simple_SEO_Yoast_Import::found() );
	$sseo_yi_report = array();
	Simple_SEO_Yoast_Import::settings( $sseo_yi_report );
	delete_option( 'wpseo_taxonomy_meta' );
	$sseo_ym_insert( $sseo['posts']['bc'], array( '_yoast_wpseo_focuskw' => 'x' ) );
	$sseo_yi_by[] = Simple_SEO_Yoast_Import::found();
	$sseo_wpdb->query( "DELETE FROM {$sseo_wpdb->postmeta} WHERE meta_key = '_yoast_wpseo_focuskw'" );
	wp_cache_delete( $sseo['posts']['bc'], 'post_meta' );
	sseo_check( "found by Yoast's terms alone or by any of its post meta; without Yoast's settings none are taken", array( true, true ) === $sseo_yi_by && array() === $sseo_yi_report && array() === get_option( Simple_SEO_Indexing::OPTION ), $sseo_yi_report );

	register_post_type(
		'sseo_yi_book',
		array(
			'public'      => true,
			'has_archive' => true,
			'label'       => 'Books',
		)
	);
	// Breadcrumbs follow a hierarchical taxonomy; the first one is a type's default.
	foreach ( array( 'sseo_yi_shelf', 'sseo_yi_room' ) as $sseo_name ) {
		register_taxonomy(
			$sseo_name,
			'sseo_yi_book',
			array(
				'public'       => true,
				'hierarchical' => true,
				'label'        => $sseo_name,
			)
		);
	}
	Simple_SEO_Fields::flush_types();
	$sseo_yi_url  = static fn( int $id ): string => (string) wp_get_attachment_url( $id );
	$sseo_yi_tag  = wp_insert_term( 'Yi Tag', 'post_tag' );
	$sseo_yi_cat  = wp_insert_term( 'Yi Cat', 'category' );
	$sseo_yi_cat2 = wp_insert_term( 'Yi Cat Two', 'category' );
	// A taxonomy without SEO fields: post formats.
	$sseo_yi_fmt = wp_insert_term( 'post-format-sseo-yi', 'post_format' );
	foreach ( array( 'post_tag' => $sseo_yi_tag, 'category' => $sseo_yi_cat, 'post_format' => $sseo_yi_fmt ) as $sseo_name => $sseo_term ) {
		$sseo['terms'][] = array( (int) $sseo_term['term_id'], $sseo_name );
	}
	$sseo['terms'][] = array( (int) $sseo_yi_cat2['term_id'], 'category' );
	$sseo_yi_fmt     = (int) $sseo_yi_fmt['term_id'];
	$sseo_yi_tag  = (int) $sseo_yi_tag['term_id'];
	$sseo_yi_cat  = (int) $sseo_yi_cat['term_id'];
	$sseo_yi_cat2 = (int) $sseo_yi_cat2['term_id'];

	// Yoast's settings, as Yoast keeps them.
	update_option(
		'wpseo_titles',
		array(
			'separator'                     => 'sc-pipe',
			'title-home-wpseo'              => '%%sitename%% %%page%% %%sep%% %%sitedesc%%',
			'metadesc-home-wpseo'           => 'Home of %%sitename%%',
			'title-404-wpseo'               => 'Nothing here %%sep%% %%sitename%%',
			'title-search-wpseo'            => 'You searched for %%searchphrase%% %%page%% %%sep%% %%sitename%%',
			'title-author-wpseo'            => '%%name%%, author at %%sitename%%',
			'metadesc-author-wpseo'         => '',
			'title-archive-wpseo'           => '%%date%% %%page%% %%sep%% %%sitename%%',
			'title-post'                    => '%%title%% %%page%% %%sep%% %%sitename%%',
			'metadesc-post'                 => '%%excerpt%% %%modified%%',
			'title-page'                    => '%%title%% | %%sitename%%',
			'metadesc-page'                 => 'Page about %%title%% %%cf_price%%',
			'title-tax-category'            => '%%term_title%% Archives %%page%% %%sep%% %%sitename%%',
			'metadesc-tax-category'         => '%%term_description%%',
			'title-ptarchive-sseo_yi_book'  => '%%pt_plural%% Archive %%page%% %%sep%% %%sitename%%',
			'title-ptarchive-post'          => 'Never used: posts have no archive',
			'noindex-tax-post_tag'          => true,
			'noindex-author-wpseo'          => false,
			'noindex-archive-wpseo'         => true,
			'noindex-sseo_yi_book'          => true,
			'noindex-ptarchive-sseo_yi_book' => true,
			'noindex-attachment'            => false,
			'disable-attachment'            => true,
			'breadcrumbs-sep'               => '&raquo;',
			'breadcrumbs-home'              => 'Home',
			'breadcrumbs-prefix'            => 'You are here:',
			'breadcrumbs-display-blog-page' => false,
			'breadcrumbs-archiveprefix'     => 'Archives for',
			'post_types-post-maintax'       => 'post_tag',
			'post_types-sseo_yi_book-maintax' => 'sseo_yi_room',
			'post_types-page-maintax'       => 0,
			'social-title-post'             => '%%title%%',
			'open_graph_frontpage_image_id' => $sseo_og,
		)
	);
	update_option(
		'wpseo_social',
		array(
			'og_default_image_id' => $sseo_default,
			'twitter_site'        => 'yoastsite',
		)
	);
	update_option(
		'wpseo',
		array(
			'enable_xml_sitemap'   => false,
			'remove_generator'     => false,
			'remove_shortlinks'    => true,
			'remove_emoji_scripts' => true,
			'remove_feed_authors'  => true,
		)
	);
	update_option(
		'wpseo_taxonomy_meta',
		array(
			'post_tag'    => array(
				$sseo_yi_tag => array(
					'wpseo_desc'    => 'Tag desc',
					'wpseo_noindex' => 'default',
				),
			),
			'category'    => array(
				$sseo_yi_cat  => array(
					'wpseo_title'           => '%%term_title%% best %%sep%% %%sitename%%',
					'wpseo_desc'            => 'Cat desc',
					'wpseo_noindex'         => 'noindex',
					'wpseo_canonical'       => 'https://example.com/cat/',
					'wpseo_opengraph-image' => $sseo_yi_url( $sseo_feat ),
					'wpseo_focuskw'         => 'cat focus',
				),
				$sseo_yi_cat2 => array( 'wpseo_title' => 'Yoast cat two' ),
				99999999      => array( 'wpseo_title' => 'A term that is gone' ),
			),
			'sseo_nope'   => array( 1 => array( 'wpseo_title' => 'A taxonomy that is gone' ) ),
			'post_format' => array( $sseo_yi_fmt => array( 'wpseo_title' => 'A format' ) ),
		)
	);

	// Posts with Yoast's fields, written as Yoast wrote them.
	$sseo['posts']['yi_1']    = (int) wp_insert_post( array( 'post_title' => 'Yi One', 'post_status' => 'publish', 'post_category' => array( $sseo_news, $sseo_sport ) ) );
	$sseo['posts']['yi_2']    = (int) wp_insert_post( array( 'post_title' => 'Yi Two', 'post_status' => 'publish' ) );
	$sseo['posts']['yi_page'] = (int) wp_insert_post( array( 'post_title' => 'Yi Page', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$sseo['posts']['yi_menu'] = (int) wp_insert_post( array( 'post_title' => 'Yi Menu', 'post_type' => 'nav_menu_item', 'post_status' => 'publish' ) );
	$sseo_yi_1                = $sseo['posts']['yi_1'];
	$sseo_yi_2                = $sseo['posts']['yi_2'];
	$sseo_yi_page             = $sseo['posts']['yi_page'];
	$sseo_ym_insert(
		$sseo_yi_1,
		array(
			'_yoast_wpseo_title'                 => '%%title%% %%sep%% %%sitename%%',
			'_yoast_wpseo_metadesc'              => 'Desc %%modified%%',
			'_yoast_wpseo_opengraph-title'       => 'OG %%title%% \\o/',
			'_yoast_wpseo_opengraph-image-id'    => (string) $sseo_pdf,
			'_yoast_wpseo_opengraph-image'       => $sseo_yi_url( $sseo_og ),
			'_yoast_wpseo_meta-robots-noindex'   => '1',
			'_yoast_wpseo_meta-robots-nofollow'  => '1',
			'_yoast_wpseo_canonical'             => 'https://example.com/one/',
			'_yoast_wpseo_primary_category'      => (string) $sseo_news,
			'_yoast_wpseo_focuskw'               => 'one focus',
			'_yoast_wpseo_focuskeywords'         => '[{"keyword":"two focus","score":"good"},{"keyword":" ","score":""},{"score":"bad"},{"keyword":["nested"]},{"keyword":"three   focus","score":"ok"}]',
			'_yoast_wpseo_twitter-title'         => 'X one',
			'_yoast_wpseo_twitter-description'   => 'X one desc',
		)
	);
	$sseo_ym_insert(
		$sseo_yi_2,
		array(
			'_yoast_wpseo_title'               => 'Yoast two',
			'_yoast_wpseo_metadesc'            => '',
			'_yoast_wpseo_meta-robots-noindex' => '2',
			'_yoast_wpseo_opengraph-image'     => 'https://elsewhere.example/picture.jpg',
			'_yoast_wpseo_meta-robots-adv'     => 'noimageindex',
			'_yoast_wpseo_canonical'           => 'https://example.com/first/',
			'_yoast_wpseo_focuskeywords'       => '["alpha","beta"]',
		)
	);
	// A second row of a key: as get_post_meta() reads it, the first one counts.
	$sseo_ym_insert( $sseo_yi_2, array( '_yoast_wpseo_canonical' => 'https://example.com/second/' ) );
	$sseo_ym_insert(
		$sseo_yi_page,
		array(
			'_yoast_wpseo_title'            => 'Page T',
			'_yoast_wpseo_primary_category' => '5',
			'_yoast_wpseo_opengraph-image-id' => (string) $sseo_feat,
			'_yoast_wpseo_opengraph-image'  => $sseo_yi_url( $sseo_og ),
			'_yoast_wpseo_focuskw'          => 'page focus',
			'_yoast_wpseo_focuskeywords'    => '"just text"',
		)
	);
	$sseo_ym_insert( $sseo['posts']['yi_menu'], array( '_yoast_wpseo_title' => 'A menu item' ) );
	// Yoast Premium's default: no more keyphrases. The post is not one with SEO fields.
	$sseo['posts']['yi_none'] = (int) wp_insert_post( array( 'post_title' => 'Yi None', 'post_status' => 'publish' ) );
	$sseo_ym_insert( $sseo['posts']['yi_none'], array( '_yoast_wpseo_focuskeywords' => '[]' ) );
	update_post_meta( $sseo_yi_2, Simple_SEO_Fields::meta_key( 'title' ), 'Own two' );
	update_post_meta( $sseo_yi_page, Simple_SEO_Fields::meta_key( 'keyphrase' ), 'Own focus' );
	update_term_meta( $sseo_yi_cat2, Simple_SEO_Fields::meta_key( 'title' ), 'Own cat two' );
	$sseo_yi_raw = (int) $sseo_wpdb->get_var( "SELECT COUNT(*) FROM {$sseo_wpdb->postmeta} WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%'" );

	sseo_check( "Yoast's data found: settings, posts and terms with SEO fields (an empty value is none)", Simple_SEO_Yoast_Import::found() && array( 'settings' => true, 'posts' => 4, 'terms' => 6 ) === Simple_SEO_Yoast_Import::summary(), Simple_SEO_Yoast_Import::summary() );
	Simple_SEO_Import::detect();
	sseo_check( 'the first admin request offers the import', 'pending' === Simple_SEO_Import::state( 'yoast' )['status'] );

	// Simple SEO's own choices, saved before the import.
	update_option( Simple_SEO_Titles::OPTION, array( 'templates' => array( 'post:page' => array( 'title' => 'Own page {title}' ) ) ) );
	update_option( Simple_SEO_Social::OPTION, array( 'x_account' => '@mine' ) );
	update_option( Simple_SEO_Special::OPTION, array( '404' => array( 'title' => 'Own 404' ) ) );
	update_option( Simple_SEO_Breadcrumbs::LABELS, array( 'home' => 'My home' ) );
	update_option( Simple_SEO_Breadcrumbs::OPTION, array( 'separator' => '/' ) );
	update_option( Simple_SEO_Cleanup::OPTION, array( 'emoji' => false ) );
	Simple_SEO_Settings::flush();

	// Settings.
	$sseo_yi_report = array();
	Simple_SEO_Yoast_Import::settings( $sseo_yi_report );
	Simple_SEO_Settings::flush();
	$sseo_yi_titles = get_option( Simple_SEO_Titles::OPTION );
	sseo_check( "the separator by Yoast's name of it", '|' === $sseo_yi_titles['separator'] && '|' === Simple_SEO_Titles::separator() );
	sseo_check(
		"templates in Simple SEO's syntax; Simple SEO's own one stays; ones equal to its defaults are not kept",
		array(
			'post:page'           => array(
				'title'       => 'Own page {title}',
				'description' => 'Page about {title}',
			),
			'author'              => array( 'title' => '{author}, author at {site}' ),
			'date'                => array( 'title' => '{date} {page} {sep} {site}' ),
			'search'              => array( 'title' => 'You searched for {search} {page} {sep} {site}' ),
			'archive:sseo_yi_book' => array( 'title' => '{title} Archive {page} {sep} {site}' ),
			'term:category'       => array( 'title' => '{title} Archives {page} {sep} {site}' ),
		) == $sseo_yi_titles['templates'], // phpcs:ignore Universal.Operators.StrictComparisons -- the order of slots is the importer's.
		$sseo_yi_titles['templates']
	);
	sseo_check( 'a variable Simple SEO has no counterpart for is left out and counted; %%pt_plural%% of an archive is its title', array( 'modified' => 1, 'cf_price' => 1 ) === $sseo_yi_report['dropped'] && ! str_contains( (string) wp_json_encode( $sseo_yi_titles ), '%%' ), $sseo_yi_report['dropped'] );
	$sseo_yi_index = get_option( Simple_SEO_Indexing::OPTION );
	sseo_check( 'indexing: what Yoast hid, attachment pages to the file', array( 'post:sseo_yi_book', 'archive:sseo_yi_book', 'post:attachment', 'term:post_tag', 'date' ) === $sseo_yi_index['noindex'] && Simple_SEO_Indexing::ATTACHMENTS_FILE === $sseo_yi_index['attachments'], $sseo_yi_index );
	sseo_check( "social networks: the default image, Simple SEO's own X account stays", array( 'default_image' => $sseo_default, 'x_account' => '@mine' ) === get_option( Simple_SEO_Social::OPTION ) );
	sseo_check( "home and 404: Yoast's description and image of the home page, Simple SEO's own 404 title stays", array( '404' => array( 'title' => 'Own 404' ), 'home' => array( 'description' => 'Home of {site}', 'image' => $sseo_og ) ) == get_option( Simple_SEO_Special::OPTION ), get_option( Simple_SEO_Special::OPTION ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$sseo_yi_bc = get_option( Simple_SEO_Breadcrumbs::OPTION );
	sseo_check( "breadcrumbs: Simple SEO's own separator stays, the posts page left out, a type's taxonomy (not a flat one)", '/' === $sseo_yi_bc['separator'] && false === $sseo_yi_bc['show_blog'] && array( 'sseo_yi_book' => 'sseo_yi_room' ) === $sseo_yi_bc['taxonomies'], $sseo_yi_bc );
	sseo_check( "breadcrumb labels as Yoast had them (not translated); Simple SEO's own one stays", array( 'home' => 'My home', 'prefix' => 'You are here:' ) === get_option( Simple_SEO_Breadcrumbs::LABELS ) );
	sseo_check( 'the sitemap Yoast switched off', array( 'enabled' => false ) === get_option( Simple_SEO_Sitemaps::OPTION ) );
	$sseo_yi_clean = get_option( Simple_SEO_Cleanup::OPTION );
	sseo_check( "clean-up: what Yoast removed; Simple SEO's own choice and defaults stay", '1' === $sseo_yi_clean['feed_authors'] && '0' === $sseo_yi_clean['emoji'] && '1' === $sseo_yi_clean['generator'] && '1' === $sseo_yi_clean['shortlink'] && '0' === $sseo_yi_clean['feed_tags'], $sseo_yi_clean );
	sseo_check(
		'the report names what was taken and what Simple SEO has nothing for',
		array(
			'separator'     => 1,
			'templates'     => 6,
			'indexing'      => 1,
			'attachments'   => 1,
			'breadcrumbs'   => 2,
			'special'       => 2,
			'labels'        => 1,
			'default_image' => 1,
			'sitemaps'      => 1,
			'cleanup'       => 1,
		) === $sseo_yi_report['settings'] && array( 'breadcrumb_texts' => 1, 'social_templates' => 1 ) == $sseo_yi_report['left'], // phpcs:ignore Universal.Operators.StrictComparisons
		$sseo_yi_report
	);
	$sseo_yi_again = array();
	Simple_SEO_Yoast_Import::settings( $sseo_yi_again );
	sseo_check( 'run again: nothing more to take, what was taken is Simple SEO\'s now', ! isset( $sseo_yi_again['settings'] ) && '|' === get_option( Simple_SEO_Titles::OPTION )['separator'], $sseo_yi_again );
	delete_option( Simple_SEO_Breadcrumbs::OPTION );
	$sseo_yi_again = array();
	Simple_SEO_Yoast_Import::settings( $sseo_yi_again );
	sseo_check( "without a breadcrumb separator of Simple SEO's own: Yoast's, its HTML entity decoded", '»' === get_option( Simple_SEO_Breadcrumbs::OPTION )['separator'] && array( 'breadcrumbs' => 3 ) === $sseo_yi_again['settings'], $sseo_yi_again );

	// Posts.
	$sseo_yi_report = array();
	$sseo_yi_next   = Simple_SEO_Yoast_Import::posts( 0, 1, $sseo_yi_report );
	sseo_check( 'posts in batches, by ID', $sseo_yi_1 === $sseo_yi_next && 1 === $sseo_yi_report['post']['seen'] );
	$sseo_yi_next = Simple_SEO_Yoast_Import::posts( $sseo_yi_next, 100, $sseo_yi_report );
	sseo_check( 'the last batch ends with the last post, then nothing is left', $sseo['posts']['yi_menu'] === $sseo_yi_next && 0 === Simple_SEO_Yoast_Import::posts( $sseo_yi_next, 100, $sseo_yi_report ) );
	$sseo_yi_1_fields = array();
	foreach ( array( 'title', 'description', 'og_title', 'og_image', 'index', 'nofollow', 'canonical', 'primary_category', 'keyphrase', 'keyphrases' ) as $sseo_key ) {
		$sseo_yi_1_fields[ $sseo_key ] = Simple_SEO_Fields::post_value( $sseo_yi_1, $sseo_key );
	}
	sseo_check(
		"a post's fields: texts in Simple SEO's syntax, the image by its address where its ID is no image, robots, canonical, primary category, the focus keyphrase and Premium's more keyphrases one on a line",
		array(
			'title'            => '{title} {sep} {site}',
			'description'      => 'Desc',
			'og_title'         => 'OG {title} \\o/',
			'og_image'         => (string) $sseo_og,
			'index'            => 'noindex',
			'nofollow'         => '1',
			'canonical'        => 'https://example.com/one/',
			'primary_category' => (string) $sseo_news,
			'keyphrase'        => 'one focus',
			'keyphrases'       => "two focus\nthree focus",
		) === $sseo_yi_1_fields,
		$sseo_yi_1_fields
	);
	sseo_check( "Simple SEO's own field stays; Yoast's index 2 is index; an image elsewhere is not taken; of two rows the first", 'Own two' === Simple_SEO_Fields::post_value( $sseo_yi_2, 'title' ) && 'index' === Simple_SEO_Fields::post_value( $sseo_yi_2, 'index' ) && '' === Simple_SEO_Fields::post_value( $sseo_yi_2, 'og_image' ) && 'https://example.com/first/' === Simple_SEO_Fields::post_value( $sseo_yi_2, 'canonical' ) );
	sseo_check( 'a page: its title, the image by its ID before its address; no primary category, pages have none; a post type without SEO fields is left alone', 'Page T' === Simple_SEO_Fields::post_value( $sseo_yi_page, 'title' ) && (string) $sseo_feat === Simple_SEO_Fields::post_value( $sseo_yi_page, 'og_image' ) && '' === Simple_SEO_Fields::post_value( $sseo_yi_page, 'primary_category' ) && '' === Simple_SEO_Fields::post_value( $sseo['posts']['yi_menu'], 'title' ) );
	$sseo_vals = array( Simple_SEO_Fields::post_value( $sseo_yi_2, 'keyphrases' ), Simple_SEO_Fields::post_value( $sseo_yi_page, 'keyphrase' ), Simple_SEO_Fields::post_value( $sseo_yi_page, 'keyphrases' ) );
	sseo_check( "more keyphrases as plain words too (Yoast's own import from SEOPressor writes them so); Simple SEO's own keyphrase stays; what is no JSON list is nothing", array( "alpha\nbeta", 'Own focus', '' ) === $sseo_vals, $sseo_vals );
	sseo_check( 'the report of the posts', array( 'seen' => 3, 'fields' => 15, 'changed' => 3, 'kept' => 2, 'refused' => 1 ) == $sseo_yi_report['post'] && array( 'modified' => 1 ) === $sseo_yi_report['dropped'], $sseo_yi_report ); // phpcs:ignore Universal.Operators.StrictComparisons
	sseo_check( "Yoast's rows are left as they were", $sseo_yi_raw === (int) $sseo_wpdb->get_var( "SELECT COUNT(*) FROM {$sseo_wpdb->postmeta} WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%'" ) && array( '%%title%% %%sep%% %%sitename%%' ) === $sseo_ym_rows( '_yoast_wpseo_title', $sseo_yi_1 ) );

	// Terms.
	$sseo_yi_report = array();
	$sseo_yi_next   = Simple_SEO_Yoast_Import::terms( 0, 2, $sseo_yi_report );
	$sseo_yi_first  = array( Simple_SEO_Fields::term_value( $sseo_yi_cat, 'description' ), Simple_SEO_Fields::term_value( $sseo_yi_tag, 'description' ) );
	sseo_check( 'terms in batches, in a fixed order: by taxonomy, then by ID', 2 === $sseo_yi_next && array( 'Cat desc', '' ) === $sseo_yi_first && 4 === Simple_SEO_Yoast_Import::terms( 2, 2, $sseo_yi_report ) && 6 === Simple_SEO_Yoast_Import::terms( 4, 2, $sseo_yi_report ) && 0 === Simple_SEO_Yoast_Import::terms( 6, 2, $sseo_yi_report ), $sseo_yi_first );
	$sseo_yi_cat_fields = array();
	foreach ( array( 'title', 'description', 'index', 'canonical', 'og_image' ) as $sseo_key ) {
		$sseo_yi_cat_fields[ $sseo_key ] = Simple_SEO_Fields::term_value( $sseo_yi_cat, $sseo_key );
	}
	sseo_check(
		"a term's fields: noindex, canonical, the image by its address",
		array(
			'title'       => '{title} best {sep} {site}',
			'description' => 'Cat desc',
			'index'       => 'noindex',
			'canonical'   => 'https://example.com/cat/',
			'og_image'    => (string) $sseo_feat,
		) === $sseo_yi_cat_fields,
		$sseo_yi_cat_fields
	);
	sseo_check( "Simple SEO's own field stays; Yoast's default noindex is none; terms that are gone, or of a taxonomy without SEO fields, are left alone", 'Own cat two' === Simple_SEO_Fields::term_value( $sseo_yi_cat2, 'title' ) && 'Tag desc' === Simple_SEO_Fields::term_value( $sseo_yi_tag, 'description' ) && '' === Simple_SEO_Fields::term_value( $sseo_yi_tag, 'index' ) && '' === Simple_SEO_Fields::term_value( $sseo_yi_fmt, 'title' ) );
	sseo_check( 'the report of the terms', array( 'seen' => 3, 'fields' => 6, 'changed' => 2, 'kept' => 1 ) == $sseo_yi_report['term'], $sseo_yi_report ); // phpcs:ignore Universal.Operators.StrictComparisons
	sseo_check( "what Simple SEO has no field for, by posts", array( 'twitter' => 1, 'robots_adv' => 1 ) === Simple_SEO_Yoast_Import::left(), Simple_SEO_Yoast_Import::left() );

	// The whole run, a few seconds at a time.
	sseo_check( 'putting the offer off', 'dismissed' === ( static function (): string {
		Simple_SEO_Import::act( 'dismiss' );
		return (string) Simple_SEO_Import::state( 'yoast' )['status'];
	} )() );
	Simple_SEO_Import::start( 'yoast' );
	$sseo_yi_steps = 0;
	do {
		$sseo_yi_state = Simple_SEO_Import::run( 'yoast', 0.0 );
		++$sseo_yi_steps;
	} while ( 'running' === $sseo_yi_state['status'] && $sseo_yi_steps < 20 );
	sseo_check( 'a run of zero seconds still takes one batch, and goes on from where it stopped', 'done' === $sseo_yi_state['status'] && 5 === $sseo_yi_steps && isset( $sseo_yi_state['finished'] ), array( $sseo_yi_steps, $sseo_yi_state['status'] ) );
	sseo_check( 'run again: everything is Simple SEO\'s already, kept', ! isset( $sseo_yi_state['report']['settings'] ) && 0 === ( $sseo_yi_state['report']['post']['fields'] ?? 0 ) && 17 === $sseo_yi_state['report']['post']['kept'] && 7 === $sseo_yi_state['report']['term']['kept'] && array( 'twitter' => 1, 'robots_adv' => 1, 'breadcrumb_texts' => 1, 'social_templates' => 1 ) == $sseo_yi_state['report']['left'], $sseo_yi_state['report'] ); // phpcs:ignore Universal.Operators.StrictComparisons
	Simple_SEO_Import::act( 'dismiss' );
	Simple_SEO_Import::detect();
	sseo_check( 'a run done is not put off, nor offered again', 'done' === Simple_SEO_Import::state( 'yoast' )['status'] );
	update_option( Simple_SEO_Yoast_Import::STATE, array( 'status' => 'running', 'phase' => 'terms', 'cursor' => 5, 'report' => array( 'post' => array( 'seen' => 99 ) ) ) );
	Simple_SEO_Import::act( 'start', array( 'simple_seo_source' => 'yoast' ) );
	sseo_check( 'starting while a run goes on continues it', 'done' === Simple_SEO_Import::state( 'yoast' )['status'] && 99 === Simple_SEO_Import::state( 'yoast' )['report']['post']['seen'] );
	update_option( Simple_SEO_Yoast_Import::STATE, $sseo_yi_state );

	// The admin.
	wp_set_current_user( $sseo_u['administrator'] );
	Simple_SEO_Import::add_admin_tab();
	update_option( Simple_SEO_Yoast_Import::STATE, array( 'status' => 'pending' ) );
	set_current_screen( 'dashboard' );
	$sseo_html = sseo_capture( array( Simple_SEO_Import::class, 'notice' ) );
	sseo_check( 'the notice offers the import, and putting it off', str_contains( $sseo_html, 'Go to the import' ) && str_contains( $sseo_html, 'value="dismiss"' ) && str_contains( $sseo_html, 'name="_wpnonce"' ) && str_contains( $sseo_html, esc_url( Simple_SEO_Admin::url( 'import' ) ) ) );
	set_current_screen( 'toplevel_page_' . Simple_SEO_Admin::PAGE );
	$_GET['tab'] = 'import';
	$sseo_yi_notice_tab = sseo_capture( array( Simple_SEO_Import::class, 'notice' ) );
	$sseo_html          = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'] );
	sseo_check( 'not on the Import tab itself; the tab lists what was found and the button', '' === $sseo_yi_notice_tab && str_contains( $sseo_html, 'posts with SEO fields: 4' ) && str_contains( $sseo_html, 'terms with SEO fields: 6' ) && str_contains( $sseo_html, 'value="start"' ) && str_contains( $sseo_html, 'Import from Yoast SEO' ) );
	update_option( Simple_SEO_Yoast_Import::STATE, array( 'status' => 'dismissed' ) );
	sseo_check( 'no notice once put off', '' === sseo_capture( array( Simple_SEO_Import::class, 'notice' ) ) );
	wp_set_current_user( $sseo_ed );
	update_option( Simple_SEO_Yoast_Import::STATE, array( 'status' => 'pending' ) );
	sseo_check( 'no notice without the settings capability', '' === sseo_capture( array( Simple_SEO_Import::class, 'notice' ) ) );
	wp_set_current_user( $sseo_u['administrator'] );
	update_option( Simple_SEO_Yoast_Import::STATE, array_merge( $sseo_yi_state, array( 'status' => 'running', 'report' => array( 'post' => array( 'seen' => 1234 ) ) ) ) );
	$_GET['tab'] = 'import';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'while running: the progress and a Continue button the page presses by itself', str_contains( $sseo_html, '1,234 posts and 0 terms so far' ) && str_contains( $sseo_html, 'data-simple-seo-continue="1"' ) && str_contains( $sseo_html, 'value="continue"' ) && ! str_contains( $sseo_html, 'value="start"' ) && ! str_contains( $sseo_html, 'Remove the data of Yoast SEO' ) );
	update_option( Simple_SEO_Yoast_Import::STATE, $sseo_yi_state );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'], $GLOBALS['current_screen'] );
	sseo_check( 'after a run: the report and Import again', str_contains( $sseo_html, 'The last import' ) && str_contains( $sseo_html, 'Import again' ) && str_contains( $sseo_html, 'Posts with SEO fields: 3, 0 of them changed.' ) && str_contains( $sseo_html, 'titles, descriptions and images for X (posts: 1)' ) && str_contains( $sseo_html, 'Settings: nothing to take, Simple SEO has its own.' ), $sseo_html );
	wp_set_current_user( 0 );

	if ( Simple_SEO_WP_Loc::active() && count( Simple_SEO_WP_Loc::codes() ) > 1 ) {
		// wp-loc keeps Yoast's settings of another language in a copy of the row named after it.
		$sseo_yi_code = Simple_SEO_WP_Loc::codes()[1];
		$sseo_yi_slug = (string) ( WP_LOC_DB::from_db_language_code( $sseo_yi_code ) ?: $sseo_yi_code );
		update_option( 'wpseo_titles_' . $sseo_yi_code, array( 'title-home-wpseo' => 'Other %%sitename%%', 'breadcrumbs-home' => 'Startseite' ) );
		foreach ( array( Simple_SEO_Special::OPTION, Simple_SEO_Breadcrumbs::LABELS ) as $sseo_name ) {
			delete_option( $sseo_name );
			delete_option( $sseo_name . '_' . $sseo_yi_code );
		}
		// An admin who edits the other language: wp-loc would send a save of the base row to its copy.
		set_current_screen( 'dashboard' );
		$_COOKIE['admin_lang'] = WP_LOC_Languages::get_language_locale( $sseo_yi_slug );
		$sseo_yi_report        = array();
		Simple_SEO_Yoast_Import::settings( $sseo_yi_report );
		$sseo_yi_cookie = $_COOKIE['admin_lang'] ?? '';
		unset( $_COOKIE['admin_lang'], $GLOBALS['current_screen'] );
		sseo_check( "wp-loc: the other language's home page and breadcrumb label go to its rows", array( 'home' => array( 'title' => 'Other {site}' ) ) === get_option( Simple_SEO_Special::OPTION . '_' . $sseo_yi_code ) && 'Startseite' === ( get_option( Simple_SEO_Breadcrumbs::LABELS . '_' . $sseo_yi_code )['home'] ?? '' ) && array( $sseo_yi_code ) === $sseo_yi_report['languages'], $sseo_yi_report );
		sseo_check( "wp-loc: the default language's rows are written as themselves, whatever language the admin edits", 'Home of {site}' === ( WP_LOC_Options::get_unfiltered_option( Simple_SEO_Special::OPTION )[1]['home']['description'] ?? '' ) && 'Home' === ( WP_LOC_Options::get_unfiltered_option( Simple_SEO_Breadcrumbs::LABELS )[1]['home'] ?? '' ) && WP_LOC_Languages::get_language_locale( $sseo_yi_slug ) === $sseo_yi_cookie );
	} else {
		echo "  skip  the import with wp-loc (tools/wp-loc-it.sh runs these checks)\n";
	}

	foreach ( array_merge( $sseo_yi_rows, array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_taxonomy_meta', Simple_SEO_Yoast_Import::STATE ) ) as $sseo_name ) {
		delete_option( $sseo_name );
	}
	Simple_SEO_Settings::install();
	Simple_SEO_Settings::flush();
	unregister_taxonomy( 'sseo_yi_shelf' );
	unregister_taxonomy( 'sseo_yi_room' );
	unregister_post_type( 'sseo_yi_book' );
	Simple_SEO_Fields::flush_types();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Yoast clean-up' );

	$sseo_db = $GLOBALS['wpdb'];
	// Yoast's leftovers: the stand's own (from Yoast's activation; the end puts them back) and these.
	$sseo_db->query( "DELETE FROM {$sseo_db->postmeta} WHERE meta_key LIKE '\\_yoast\\_%'" );
	$sseo_db->query( "DELETE FROM {$sseo_db->usermeta} WHERE {$sseo_yoast_users}" );
	wp_cache_flush();
	$sseo_db->query( "CREATE TABLE IF NOT EXISTS `{$sseo_db->prefix}yoast_indexable` ( id INT NOT NULL PRIMARY KEY )" );
	$sseo_db->query( "CREATE TABLE IF NOT EXISTS `{$sseo_db->prefix}sseo_yoast_keep` ( id INT NOT NULL PRIMARY KEY )" );
	$sseo_yc_tables = (array) $sseo_db->get_col( $sseo_db->prepare( 'SHOW TABLES LIKE %s', $sseo_db->esc_like( $sseo_db->prefix . 'yoast_' ) . '%' ) );
	foreach ( array(
		'wpseo'                      => array( 'version' => '28.6' ),
		'wpseo_titles'               => array( 'company_name' => 'Acme' ),
		'wpseo_titles_en'            => array( 'title-home-wpseo' => 'Home' ),
		'wpseo_social'               => array( 'twitter_site' => 'acme' ),
		'wpseo_taxonomy_meta'        => array( 'category' => array() ),
		'wpseo_llmstxt'              => array( 'x' => 1 ),
		'yoast_migrations_free'      => array( 'version' => '28.6' ),
		'wpseo-cleanup-current-task' => 'x',
		'sseo_wpseo_keep'            => 'x',
	) as $sseo_name => $sseo_value ) {
		update_option( $sseo_name, $sseo_value );
	}
	set_transient( 'wpseo_sseo_test', 'x', HOUR_IN_SECONDS );
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpseo_sseo_daily' );
	wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, 'yoast_sseo_once', array( 1 ) );
	wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, 'yoast_sseo_once', array( 2 ) );
	wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, 'sseo_keep_hook' );
	$sseo_yc_user = (int) $sseo_u['administrator'];
	update_user_meta( $sseo_yc_user, 'wpseo_title', 'Author SEO title' );
	update_user_meta( $sseo_yc_user, '_yoast_wpseo_profile_updated', '1' );
	update_user_meta( $sseo_yc_user, $sseo_db->get_blog_prefix() . 'yoast_notifications', 'x' );
	update_user_meta( $sseo_yc_user, 'sseo_keep', 'x' );
	$sseo['posts']['yc'] = (int) wp_insert_post( array( 'post_title' => 'Yc Post', 'post_status' => 'publish' ) );
	$sseo_yc             = $sseo['posts']['yc'];
	update_post_meta( $sseo_yc, Simple_SEO_Fields::meta_key( 'title' ), 'Kept' );
	update_post_meta( $sseo_yc, 'sseo_keep', 'x' );
	// One batch and one row more: three of Yoast's keys and fillers.
	$sseo_ym_insert(
		$sseo_yc,
		array(
			'_yoast_wpseo_title'        => 'Yoast title',
			'_yoast_wpseo_bctitle'      => 'kw',
			'_yoast_indexnow_last_ping' => '1',
		)
	);
	$sseo_db->query( "INSERT INTO {$sseo_db->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ',', array_map( static fn( int $i ): string => "( {$sseo_yc}, '_yoast_wpseo_filler_{$i}', 'x' )", range( 1, Simple_SEO_Yoast_Cleanup::BATCH - 2 ) ) ) );
	wp_cache_delete( $sseo_yc, 'post_meta' );

	// When it may run.
	delete_option( Simple_SEO_Yoast_Import::STATE );
	$sseo_yc_yoast = static fn( $plugins ): array => array_merge( (array) $plugins, array( 'wordpress-seo/wp-seo.php' ) );
	$sseo_vals     = array( Simple_SEO_Yoast_Cleanup::blocked() );
	update_option( Simple_SEO_Yoast_Import::STATE, array( 'status' => 'done' ) );
	$sseo_vals[] = Simple_SEO_Yoast_Cleanup::blocked();
	add_filter( 'option_active_plugins', $sseo_yc_yoast );
	$sseo_vals[] = Simple_SEO_Yoast_Cleanup::blocked();
	remove_filter( 'option_active_plugins', $sseo_yc_yoast );
	sseo_check( 'only after the import, and never while Yoast is active', array( 'import', '', 'active' ) === $sseo_vals, $sseo_vals );

	// What is left.
	$sseo_yc_cron = 0;
	foreach ( (array) _get_cron_array() as $sseo_events ) {
		foreach ( (array) $sseo_events as $sseo_hook => $sseo_instances ) {
			$sseo_yc_cron += preg_match( '/^(?:wpseo|yoast)/i', (string) $sseo_hook ) ? count( (array) $sseo_instances ) : 0;
		}
	}
	$sseo_yc_internal = (int) $sseo_db->get_var( "SELECT COUNT(*) FROM {$sseo_db->options} WHERE {$sseo_yoast_options}" ) - 5 + $sseo_yc_cron;
	$sseo_yc_parts    = Simple_SEO_Yoast_Cleanup::preview();
	sseo_check(
		"what is left of Yoast's, by part: tables, the rest of its options and its tasks, settings with the copies for languages, user and post meta",
		array( 'tables', 'internals', 'settings', 'usermeta', 'postmeta' ) === array_keys( $sseo_yc_parts ) && count( $sseo_yc_tables ) === $sseo_yc_parts['tables']['rows'] && 5 === $sseo_yc_parts['settings']['rows'] && 3 === $sseo_yc_parts['usermeta']['rows'] && Simple_SEO_Yoast_Cleanup::BATCH + 1 === $sseo_yc_parts['postmeta']['rows'] && $sseo_yc_internal === $sseo_yc_parts['internals']['rows'] && $sseo_yc_parts['settings']['bytes'] > 0,
		$sseo_yc_parts
	);
	$sseo_yc_theme = get_theme_root() . '/sseo-yc-theme';
	wp_mkdir_p( $sseo_yc_theme . '/inc' );
	wp_mkdir_p( $sseo_yc_theme . '/vendor/lib' );
	file_put_contents( $sseo_yc_theme . '/inc/schema.php', "<?php \$name = get_option( 'wpseo_titles' )['company_name'];" );
	file_put_contents( $sseo_yc_theme . '/inc/other.php', '<?php // nothing of Yoast' );
	file_put_contents( $sseo_yc_theme . '/inc/readme.txt', 'wpseo_titles' );
	file_put_contents( $sseo_yc_theme . '/vendor/lib/x.php', "<?php WPSEO_Options::get( 'x' );" );
	$sseo_yc_dir = static fn(): string => $sseo_yc_theme;
	add_filter( 'stylesheet_directory', $sseo_yc_dir );
	$sseo_vals = Simple_SEO_Yoast_Cleanup::theme_reads( array( 'tables', 'settings', 'postmeta' ) );
	sseo_check( "the active theme's files (PHP and Twig) that read Yoast's settings, vendor left out; Yoast's post meta themes read through Simple SEO", array( 'settings' => array( 'sseo-yc-theme/inc/schema.php' ) ) === $sseo_vals, $sseo_vals );

	// The form.
	$sseo_yc_all = array(
		'simple_seo_source' => 'yoast',
		'simple_seo_groups' => array( 'tables', 'internals', 'usermeta', 'postmeta', 'nope' ),
		'simple_seo_backup' => '1',
	);
	$sseo_vals = array(
		Simple_SEO_Import::act( 'clean', array( 'simple_seo_source' => 'yoast', 'simple_seo_groups' => array( 'nope' ), 'simple_seo_backup' => '1' ) ),
		Simple_SEO_Import::act( 'clean', array( 'simple_seo_source' => 'yoast', 'simple_seo_groups' => array( 'tables' ) ) ),
	);
	add_filter( 'option_active_plugins', $sseo_yc_yoast );
	$sseo_vals[] = Simple_SEO_Import::act( 'clean', $sseo_yc_all );
	remove_filter( 'option_active_plugins', $sseo_yc_yoast );
	sseo_check( 'refused without a part, without a backup, and with Yoast active; nothing started', array( 'groups', 'backup', 'blocked' ) === $sseo_vals && array() === Simple_SEO_Yoast_Cleanup::state(), $sseo_vals );

	// The page, before.
	wp_set_current_user( $sseo_yc_user );
	Simple_SEO_Import::add_admin_tab();
	set_current_screen( 'toplevel_page_' . Simple_SEO_Admin::PAGE );
	$_GET['tab']                = 'import';
	$_GET['simple-seo-refused'] = 'backup';
	$_GET['simple-seo-source']  = 'rank_math';
	$sseo_html                  = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	$_GET['simple-seo-source']  = 'yoast';
	$sseo_html_refused          = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['simple-seo-refused'], $_GET['simple-seo-source'] );
	sseo_check(
		"the tab offers the parts with their size; Yoast's settings unticked while the theme reads them, and the files named; a backup is asked for (the refusal of another plugin's form is not Yoast's)",
		str_contains( $sseo_html, 'Remove the data of Yoast SEO' ) && ! str_contains( $sseo_html, 'Confirm that you have a backup of the database first.' ) && str_contains( $sseo_html_refused, 'Confirm that you have a backup of the database first.' )
			&& (bool) preg_match( '/value="postmeta"\s+checked=\'checked\'/', $sseo_html ) && (bool) preg_match( '/value="settings"\s*\/>/', $sseo_html )
			&& str_contains( $sseo_html, 'rows: 2,001, size:' ) && str_contains( $sseo_html, '<code>sseo-yc-theme/inc/schema.php</code>' )
			&& (bool) preg_match( '/name="simple_seo_backup" value="1" required/', $sseo_html ) && str_contains( $sseo_html, 'value="clean"' )
	);
	add_filter( 'option_active_plugins', $sseo_yc_yoast );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	remove_filter( 'option_active_plugins', $sseo_yc_yoast );
	update_option( Simple_SEO_Yoast_Import::STATE, array( 'status' => 'pending' ) );
	$sseo_html .= sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	update_option( Simple_SEO_Yoast_Import::STATE, array( 'status' => 'done' ) );
	sseo_check( 'with Yoast active, or before the import, only why not', str_contains( $sseo_html, 'Yoast SEO is active, so its data is in use.' ) && str_contains( $sseo_html, 'Run the import first: removing comes after it.' ) && ! str_contains( $sseo_html, 'value="clean"' ) );
	remove_filter( 'stylesheet_directory', $sseo_yc_dir );

	// A run: a part or a batch per step, the settings left as asked.
	$sseo_vals = array( get_post_meta( $sseo_yc, '_yoast_wpseo_bctitle', true ), get_user_meta( $sseo_yc_user, 'wpseo_title', true ) );
	Simple_SEO_Yoast_Cleanup::start( $sseo_yc_all['simple_seo_groups'] );
	$sseo_yc_steps = 0;
	do {
		$sseo_yc_state = Simple_SEO_Yoast_Cleanup::run( 0.0 );
		++$sseo_yc_steps;
		if ( 1 === $sseo_yc_steps ) {
			$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
		}
		if ( 4 === $sseo_yc_steps ) {
			$sseo_yc_batch = (int) $sseo_db->get_var( "SELECT COUNT(*) FROM {$sseo_db->postmeta} WHERE meta_key LIKE '\\_yoast\\_%'" );
		}
	} while ( 'running' === $sseo_yc_state['status'] && $sseo_yc_steps < 20 );
	sseo_check( 'while running: the progress and a Continue button the page presses by itself', str_contains( $sseo_html, 'Removing is under way' ) && str_contains( $sseo_html, 'value="clean_continue"' ) && str_contains( $sseo_html, 'data-simple-seo-continue="1"' ) );
	sseo_check( 'one step per part, the post meta in batches: a full batch, then the rest', 'done' === $sseo_yc_state['status'] && 5 === $sseo_yc_steps && 1 === $sseo_yc_batch && isset( $sseo_yc_state['finished'] ), array( $sseo_yc_steps, $sseo_yc_batch ) );
	$sseo_yc_left = array(
		'tables'   => (array) $sseo_db->get_col( $sseo_db->prepare( 'SHOW TABLES LIKE %s', $sseo_db->esc_like( $sseo_db->prefix . 'yoast_' ) . '%' ) ),
		'options'  => (array) $sseo_db->get_col( "SELECT option_name FROM {$sseo_db->options} WHERE {$sseo_yoast_options} ORDER BY option_name" ),
		'usermeta' => (int) $sseo_db->get_var( "SELECT COUNT(*) FROM {$sseo_db->usermeta} WHERE {$sseo_yoast_users}" ),
		'postmeta' => (int) $sseo_db->get_var( "SELECT COUNT(*) FROM {$sseo_db->postmeta} WHERE meta_key LIKE '\\_yoast\\_%'" ),
	);
	sseo_check( "Yoast's tables, other options, tasks and meta are gone; its settings stay, as they were not ticked", array( 'tables' => array(), 'options' => array( 'wpseo', 'wpseo_social', 'wpseo_taxonomy_meta', 'wpseo_titles', 'wpseo_titles_en' ), 'usermeta' => 0, 'postmeta' => 0 ) === $sseo_yc_left && false === get_transient( 'wpseo_sseo_test' ) && false === wp_next_scheduled( 'wpseo_sseo_daily' ) && false === wp_next_scheduled( 'yoast_sseo_once', array( 1 ) ) && false === wp_next_scheduled( 'yoast_sseo_once', array( 2 ) ), $sseo_yc_left );
	sseo_check(
		'what is not Yoast\'s stays, Simple SEO\'s fields and state included',
		'x' === get_option( 'sseo_wpseo_keep' ) && (bool) wp_next_scheduled( 'sseo_keep_hook' ) && 'x' === get_user_meta( $sseo_yc_user, 'sseo_keep', true ) && 'x' === get_post_meta( $sseo_yc, 'sseo_keep', true )
			&& 'Kept' === Simple_SEO_Fields::post_value( $sseo_yc, 'title' ) && 'done' === Simple_SEO_Import::state( 'yoast' )['status'] && array( $sseo_db->prefix . 'sseo_yoast_keep' ) === $sseo_db->get_col( $sseo_db->prepare( 'SHOW TABLES LIKE %s', $sseo_db->esc_like( $sseo_db->prefix . 'sseo_yoast_keep' ) ) )
	);
	sseo_check( 'the cached meta of the posts and users goes too', array( 'kw', 'Author SEO title' ) === $sseo_vals && '' === get_post_meta( $sseo_yc, '_yoast_wpseo_bctitle', true ) && '' === get_user_meta( $sseo_yc_user, 'wpseo_title', true ) );
	$sseo_yc_report = $sseo_yc_state['report'];
	sseo_check( 'the report counts what went', count( $sseo_yc_tables ) === $sseo_yc_report['tables'] && 3 === $sseo_yc_report['usermeta'] && Simple_SEO_Yoast_Cleanup::BATCH + 1 === $sseo_yc_report['postmeta'] && $sseo_yc_internal === $sseo_yc_report['options'] + $sseo_yc_report['cron'] && $sseo_yc_report['cron'] === $sseo_yc_cron && ! isset( $sseo_yc_report['settings'] ), $sseo_yc_report );

	// The settings, on their own; nothing while Yoast is active.
	Simple_SEO_Yoast_Cleanup::start( array( 'settings' ) );
	add_filter( 'option_active_plugins', $sseo_yc_yoast );
	$sseo_vals = Simple_SEO_Yoast_Cleanup::run();
	remove_filter( 'option_active_plugins', $sseo_yc_yoast );
	sseo_check( 'nothing is removed while Yoast is active', 'running' === $sseo_vals['status'] && 'x' !== get_option( 'wpseo_titles', 'x' ) );
	$sseo_vals = array( Simple_SEO_Import::act( 'clean', array( 'simple_seo_source' => 'yoast', 'simple_seo_groups' => array( 'tables' ), 'simple_seo_backup' => '1' ) ), Simple_SEO_Yoast_Cleanup::state()['status'], Simple_SEO_Yoast_Cleanup::state()['report']['settings'] ?? 0, Simple_SEO_Yoast_Cleanup::preview() );
	sseo_check( 'starting while a clean-up runs goes on with it; then nothing of Yoast\'s is left', array( '', 'done', 5, array() ) === $sseo_vals, $sseo_vals );
	$sseo_yc_done = Simple_SEO_Yoast_Cleanup::state();
	update_option( Simple_SEO_Yoast_Cleanup::OPTION, array( 'status' => 'running', 'groups' => array( 'postmeta' ), 'report' => array( 'postmeta' => 7 ) ) );
	sseo_check( 'Continue goes on with a clean-up', '' === Simple_SEO_Import::act( 'clean_continue', array( 'simple_seo_source' => 'yoast' ) ) && 'done' === Simple_SEO_Yoast_Cleanup::state()['status'] && 7 === Simple_SEO_Yoast_Cleanup::state()['report']['postmeta'] );
	update_option( Simple_SEO_Yoast_Cleanup::OPTION, $sseo_yc_done );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'], $GLOBALS['current_screen'] );
	wp_set_current_user( 0 );
	sseo_check( 'after: nothing left, and the report', str_contains( $sseo_html, 'No data of Yoast SEO is left on this site.' ) && str_contains( $sseo_html, 'The last clean-up' ) && str_contains( $sseo_html, 'Removed: rows of settings: 5.' ) && ! str_contains( $sseo_html, 'value="clean"' ) );

	$sseo_db->query( "DROP TABLE IF EXISTS `{$sseo_db->prefix}sseo_yoast_keep`" );
	delete_option( 'sseo_wpseo_keep' );
	wp_clear_scheduled_hook( 'sseo_keep_hook' );
	delete_user_meta( $sseo_yc_user, 'sseo_keep' );
	foreach ( array( '/inc/schema.php', '/inc/other.php', '/inc/readme.txt', '/vendor/lib/x.php', '/vendor/lib', '/vendor', '/inc', '' ) as $sseo_name ) {
		$sseo_name = $sseo_yc_theme . $sseo_name;
		is_dir( $sseo_name ) ? rmdir( $sseo_name ) : unlink( $sseo_name );
	}
	foreach ( array( Simple_SEO_Yoast_Import::STATE, Simple_SEO_Yoast_Cleanup::OPTION ) as $sseo_name ) {
		delete_option( $sseo_name );
	}

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Rank Math import' );

	$sseo_rm_db = $GLOBALS['wpdb'];
	// A site fresh from Rank Math: Simple SEO's rows as install() makes them.
	foreach ( $sseo_yi_rows as $sseo_name ) {
		remove_all_filters( 'sanitize_option_' . $sseo_name );
		delete_option( $sseo_name );
	}
	foreach ( array( Simple_SEO_Files::ROBOTS_OPTION, Simple_SEO_Yoast_Import::STATE, Simple_SEO_Rank_Math_Import::STATE ) as $sseo_name ) {
		delete_option( $sseo_name );
	}
	// With wp-loc, the copies for other languages the Yoast section made would stand in for the rows.
	foreach ( (array) $sseo_rm_db->get_col( "SELECT option_name FROM {$sseo_rm_db->options} WHERE option_name LIKE 'simple\\_seo\\_special\\_%' OR option_name LIKE 'simple\\_seo\\_breadcrumb\\_labels\\_%'" ) as $sseo_name ) {
		delete_option( (string) $sseo_name );
	}
	Simple_SEO_Settings::install();
	Simple_SEO_Settings::flush();
	sseo_check( 'no data of Rank Math: nothing found, nothing to import', ! Simple_SEO_Rank_Math_Import::found() && array( 'settings' => false, 'posts' => 0, 'terms' => 0 ) === Simple_SEO_Rank_Math_Import::summary() );
	Simple_SEO_Import::detect();
	sseo_check( 'the first admin request finds nothing of either plugin to offer', 'none' === Simple_SEO_Import::state( 'rank_math' )['status'] && 'none' === Simple_SEO_Import::state( 'yoast' )['status'] );
	wp_set_current_user( $sseo_u['administrator'] );
	Simple_SEO_Import::add_admin_tab();
	set_current_screen( 'toplevel_page_' . Simple_SEO_Admin::PAGE );
	$_GET['tab'] = 'import';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'], $GLOBALS['current_screen'] );
	wp_set_current_user( 0 );
	sseo_check( 'the tab without data of either plugin says so, naming both', str_contains( $sseo_html, 'There is no data of Yoast SEO, Rank Math, and All in One SEO on this site.' ) && ! str_contains( $sseo_html, 'value="start"' ), $sseo_html );
	// Found by Rank Math's post meta alone, or by its term meta alone.
	$sseo_rm_by = array();
	foreach ( array( 'postmeta' => 'post_id', 'termmeta' => 'term_id' ) as $sseo_key => $sseo_name ) {
		$sseo_rm_db->insert( $sseo_rm_db->$sseo_key, array( $sseo_name => 99999998, 'meta_key' => 'rank_math_description', 'meta_value' => 'x' ) );
		$sseo_rm_by[] = Simple_SEO_Rank_Math_Import::found();
		$sseo_rm_db->query( "DELETE FROM {$sseo_rm_db->$sseo_key} WHERE {$sseo_name} = 99999998" );
	}
	$sseo_rm_db->insert( $sseo_rm_db->postmeta, array( 'post_id' => 99999998, 'meta_key' => 'rank_math_robots', 'meta_value' => 'a:0:{}' ) );
	$sseo_rm_by[] = Simple_SEO_Rank_Math_Import::found();
	$sseo_rm_db->query( "DELETE FROM {$sseo_rm_db->postmeta} WHERE post_id = 99999998" );
	sseo_check( "found by Rank Math's post meta alone or by its term meta; an empty robots list is none", array( true, true, false ) === $sseo_rm_by, $sseo_rm_by );
	delete_option( Simple_SEO_Rank_Math_Import::STATE );
	delete_option( Simple_SEO_Yoast_Import::STATE );

	register_post_type(
		'sseo_rm_book',
		array(
			'public'      => true,
			'has_archive' => true,
			'label'       => 'Books',
		)
	);
	foreach ( array( 'sseo_rm_shelf', 'sseo_rm_room' ) as $sseo_name ) {
		register_taxonomy(
			$sseo_name,
			'sseo_rm_book',
			array(
				'public'       => true,
				'hierarchical' => true,
				'label'        => $sseo_name,
			)
		);
	}
	Simple_SEO_Fields::flush_types();
	$sseo_rm_terms = array();
	foreach ( array(
		'tag'  => 'post_tag',
		'cat'  => 'category',
		'cat2' => 'category',
		'fmt'  => 'post_format',
		'cat3' => 'category',
	) as $sseo_key => $sseo_name ) {
		$sseo_term                  = wp_insert_term( 'post_format' === $sseo_name ? 'post-format-sseo-rm' : 'Rm ' . $sseo_key, $sseo_name );
		$sseo_rm_terms[ $sseo_key ] = (int) $sseo_term['term_id'];
		$sseo['terms'][]            = array( (int) $sseo_term['term_id'], $sseo_name );
	}

	// Rank Math's settings, as Rank Math keeps them.
	update_option(
		'rank-math-options-titles',
		array(
			'title_separator'                  => '|',
			'robots_global'                    => array( 'index' ),
			'homepage_title'                   => 'Welcome %sep% %sitename%',
			'homepage_description'             => 'All about %customfield(price)% %sitename%',
			'homepage_facebook_image_id'       => $sseo_og,
			'homepage_facebook_title'          => 'Social home',
			'404_title'                        => 'Nothing here %sep% %sitename%',
			'search_title'                     => 'Search: %search_query% %page% %sep% %sitename%',
			'author_archive_title'             => '%name% at %sitename%',
			'author_archive_description'       => '',
			'date_archive_title'               => '%date(F Y)% %page% %sep% %sitename%',
			'pt_post_title'                    => '%title% %page% %sep% %sitename%',
			'pt_post_description'              => '%excerpt% %focuskw%',
			'pt_page_title'                    => 'Page %title% %count(title)%',
			'pt_page_description'              => 'About %title%, 50% off %unknown_word%',
			'pt_sseo_rm_book_archive_title'    => '%pt_plural% Archive %sep% %sitename%',
			'pt_post_archive_title'            => 'Never used: posts have no archive',
			'tax_category_title'               => '%term% Archives %sep% %sitename%',
			'tax_category_description'         => '%term_description%',
			'pt_sseo_rm_book_custom_robots'    => 'on',
			'pt_sseo_rm_book_robots'           => array( 'noindex' ),
			// The installer writes switches as 'on' and 'off', the settings screen as true and false.
			'pt_attachment_custom_robots'      => true,
			'pt_attachment_robots'             => array( 'noindex' ),
			'tax_post_tag_custom_robots'       => 'on',
			'tax_post_tag_robots'              => array( 'noindex', 'nofollow' ),
			'tax_category_custom_robots'       => 'off',
			'tax_category_robots'              => array( 'noindex' ),
			'author_custom_robots'             => 'on',
			'author_robots'                    => array( 'noindex' ),
			'disable_author_archives'          => 'off',
			'disable_date_archives'            => 'on',
			'open_graph_image'                 => $sseo_yi_url( $sseo_default ),
			'twitter_author_names'             => 'rmsite',
			'pt_post_primary_taxonomy'         => 'category',
			'pt_sseo_rm_book_primary_taxonomy' => 'sseo_rm_room',
		)
	);
	update_option(
		'rank-math-options-general',
		array(
			'attachment_redirect_urls'      => true,
			'breadcrumbs_separator'         => '&raquo;',
			'breadcrumbs_home_label'        => 'Start',
			'breadcrumbs_prefix'            => 'You are here:',
			'breadcrumbs_remove_post_title' => 'on',
			'breadcrumbs_blog_page'         => false,
			'breadcrumbs_archive_format'    => 'Archives for %s',
			'robots_txt_content'            => "User-agent: *\r\nDisallow: /private/",
			'google_verify'                 => 'abc123',
			'strip_category_base'           => 'on',
		)
	);
	update_option( 'rank_math_modules', array( 'link-counter', 'redirections' ) );
	$sseo_rm_redirections = $sseo_rm_db->prefix . 'rank_math_redirections';
	$sseo_rm_db->query( "CREATE TABLE IF NOT EXISTS `{$sseo_rm_redirections}` ( id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, url_to TEXT NOT NULL, status VARCHAR(25) NOT NULL DEFAULT 'active' )" );
	foreach ( array( 'active', 'active', 'inactive' ) as $sseo_name ) {
		$sseo_rm_db->insert(
			$sseo_rm_redirections,
			array(
				'url_to' => home_url( '/new/' ),
				'status' => $sseo_name,
			)
		);
	}

	// Posts and terms with Rank Math's fields, written as Rank Math writes them.
	$sseo_rm_post             = static fn( array $args ): int => (int) wp_insert_post( $args + array( 'post_status' => 'publish' ) );
	$sseo['posts']['rm_1']    = $sseo_rm_post( array( 'post_title' => 'Rm One', 'post_category' => array( $sseo_news, $sseo_sport ) ) );
	$sseo['posts']['rm_2']    = $sseo_rm_post( array( 'post_title' => 'Rm Two' ) );
	$sseo['posts']['rm_page'] = $sseo_rm_post( array( 'post_title' => 'Rm Page', 'post_type' => 'page' ) );
	$sseo['posts']['rm_menu'] = $sseo_rm_post( array( 'post_title' => 'Rm Menu', 'post_type' => 'nav_menu_item' ) );
	$sseo['posts']['rm_none'] = $sseo_rm_post( array( 'post_title' => 'Rm None' ) );
	$sseo_rm_1                = $sseo['posts']['rm_1'];
	$sseo_rm_2                = $sseo['posts']['rm_2'];
	$sseo_rm_page             = $sseo['posts']['rm_page'];
	$sseo_rm_add              = static function ( string $type, array $objects ): void {
		foreach ( $objects as $sseo_id => $sseo_rows ) {
			foreach ( $sseo_rows as $sseo_key => $sseo_value ) {
				add_metadata( $type, $sseo_id, $sseo_key, $sseo_value );
			}
		}
	};
	$sseo_rm_add(
		'post',
		array(
			$sseo_rm_1                => array(
				'rank_math_title'              => '%title% %sep% %sitename% %page%',
				'rank_math_description'        => 'Desc %modified% here',
				'rank_math_facebook_title'     => 'OG %title%',
				'rank_math_facebook_image_id'  => (string) $sseo_pdf,
				'rank_math_facebook_image'     => $sseo_yi_url( $sseo_og ),
				'rank_math_robots'             => array( 'noindex', 'nofollow' ),
				'rank_math_canonical_url'      => 'https://example.com/rm-one/',
				'rank_math_primary_category'   => (string) $sseo_news,
				'rank_math_focus_keyword'      => ' one ,two,, three  four ',
				'rank_math_twitter_title'      => 'X one',
				'rank_math_schema_BlogPosting' => array( '@type' => 'BlogPosting' ),
				'rank_math_seo_score'          => '64',
			),
			$sseo_rm_2                => array(
				'rank_math_title'           => 'Rm two',
				'rank_math_robots'          => array( 'index' ),
				'rank_math_facebook_image'  => 'https://elsewhere.example/picture.jpg',
				'rank_math_advanced_robots' => array( 'max-snippet' => '-1' ),
				// Of two rows of a key the first counts, as get_post_meta() reads it.
				'rank_math_canonical_url'   => 'https://example.com/first/',
			),
			$sseo_rm_page             => array(
				'rank_math_title'            => 'Page T',
				'rank_math_primary_category' => '5',
				'rank_math_robots'           => array(),
				'rank_math_focus_keyword'    => 'page,more',
			),
			$sseo['posts']['rm_menu'] => array( 'rank_math_title' => 'A menu item' ),
			$sseo['posts']['rm_none'] => array( 'rank_math_robots' => array() ),
		)
	);
	add_post_meta( $sseo_rm_2, 'rank_math_canonical_url', 'https://example.com/second/' );
	$sseo_rm_add(
		'term',
		array(
			$sseo_rm_terms['cat']  => array(
				'rank_math_title'          => '%term% best %sep% %sitename%',
				'rank_math_description'    => 'Cat desc',
				'rank_math_robots'         => array( 'noindex' ),
				'rank_math_canonical_url'  => 'https://example.com/rm-cat/',
				'rank_math_facebook_image' => $sseo_yi_url( $sseo_feat ),
				'rank_math_focus_keyword'  => 'cat',
			),
			$sseo_rm_terms['cat2'] => array( 'rank_math_title' => 'Rm cat two' ),
			$sseo_rm_terms['tag']  => array(
				'rank_math_description' => 'Tag desc',
				'rank_math_robots'      => array( 'nofollow' ),
			),
			$sseo_rm_terms['fmt']  => array( 'rank_math_title' => 'A format' ),
			// Terms have no keyphrases: a term with nothing else is not one with SEO fields.
			$sseo_rm_terms['cat3'] => array( 'rank_math_focus_keyword' => 'only a keyphrase' ),
		)
	);
	// The rows of a term that is gone.
	$sseo_rm_db->insert(
		$sseo_rm_db->termmeta,
		array(
			'term_id'    => 99999999,
			'meta_key'   => 'rank_math_title',
			'meta_value' => 'Gone',
		)
	);
	update_user_meta( (int) $sseo_u['administrator'], 'rank_math_title', 'Author %name%' );
	update_post_meta( $sseo_rm_2, Simple_SEO_Fields::meta_key( 'title' ), 'Own two' );
	update_post_meta( $sseo_rm_page, Simple_SEO_Fields::meta_key( 'keyphrase' ), 'Own focus' );
	update_term_meta( $sseo_rm_terms['cat2'], Simple_SEO_Fields::meta_key( 'title' ), 'Own cat two' );
	$sseo_rm_raw = (int) $sseo_rm_db->get_var( "SELECT COUNT(*) FROM {$sseo_rm_db->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%'" );

	sseo_check( "Rank Math's data found: settings, posts and terms with SEO fields (an empty robots list is none)", Simple_SEO_Rank_Math_Import::found() && array( 'settings' => true, 'posts' => 4, 'terms' => 5 ) === Simple_SEO_Rank_Math_Import::summary(), Simple_SEO_Rank_Math_Import::summary() );
	Simple_SEO_Import::detect();
	sseo_check( 'the first admin request offers the import from Rank Math', 'pending' === Simple_SEO_Import::state( 'rank_math' )['status'] && 'none' === Simple_SEO_Import::state( 'yoast' )['status'] );

	// Simple SEO's own choices, saved before the import.
	update_option( Simple_SEO_Titles::OPTION, array( 'templates' => array( 'post:page' => array( 'title' => 'Own page {title}' ) ) ) );
	update_option( Simple_SEO_Social::OPTION, array( 'x_account' => '@mine' ) );
	update_option( Simple_SEO_Special::OPTION, array( '404' => array( 'title' => 'Own 404' ) ) );
	update_option( Simple_SEO_Breadcrumbs::LABELS, array( 'home' => 'My home' ) );
	update_option( Simple_SEO_Breadcrumbs::OPTION, array( 'separator' => '/' ) );
	Simple_SEO_Settings::flush();

	// Settings.
	$sseo_rm_report = array();
	Simple_SEO_Rank_Math_Import::settings( $sseo_rm_report );
	Simple_SEO_Settings::flush();
	$sseo_rm_titles = get_option( Simple_SEO_Titles::OPTION );
	sseo_check( 'the separator, one of Simple SEO\'s', '|' === $sseo_rm_titles['separator'] );
	sseo_check(
		"templates in Simple SEO's syntax; Simple SEO's own one stays; ones equal to its defaults are not kept; a %word% that is none of Rank Math's variables stays text",
		array(
			'post:page'            => array(
				'title'       => 'Own page {title}',
				'description' => 'About {title}, 50% off %unknown_word%',
			),
			'author'               => array( 'title' => '{author} at {site}' ),
			'date'                 => array( 'title' => '{date} {page} {sep} {site}' ),
			'search'               => array( 'title' => 'Search: {search} {page} {sep} {site}' ),
			'archive:sseo_rm_book' => array( 'title' => '{title} Archive {sep} {site}' ),
			'term:category'        => array( 'title' => '{title} Archives {sep} {site}' ),
		) == $sseo_rm_titles['templates'], // phpcs:ignore Universal.Operators.StrictComparisons -- the order of slots is the importer's.
		$sseo_rm_titles['templates']
	);
	sseo_check( "a variable of Rank Math's Simple SEO has none for is left out and counted, with or without arguments; one in a kept template is not", array( 'focuskw' => 1, 'customfield' => 1 ) == $sseo_rm_report['dropped'], $sseo_rm_report['dropped'] ); // phpcs:ignore Universal.Operators.StrictComparisons
	$sseo_rm_index = get_option( Simple_SEO_Indexing::OPTION );
	sseo_check( "indexing: kinds with robots of their own follow them, the rest the global robots; date archives switched off; attachment pages to their post", array( 'post:sseo_rm_book', 'archive:sseo_rm_book', 'post:attachment', 'term:post_tag', 'author', 'date' ) === $sseo_rm_index['noindex'] && Simple_SEO_Indexing::ATTACHMENTS_PARENT === $sseo_rm_index['attachments'], $sseo_rm_index );
	sseo_check( "social networks: the default image by its address, Simple SEO's own X account stays", array( 'default_image' => $sseo_default, 'x_account' => '@mine' ) === get_option( Simple_SEO_Social::OPTION ) );
	sseo_check( "home and 404: Rank Math's home page with its image, Simple SEO's own 404 title stays", array( '404' => array( 'title' => 'Own 404' ), 'home' => array( 'title' => 'Welcome {sep} {site}', 'description' => 'All about {site}', 'image' => $sseo_og ) ) == get_option( Simple_SEO_Special::OPTION ), get_option( Simple_SEO_Special::OPTION ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$sseo_rm_bc = get_option( Simple_SEO_Breadcrumbs::OPTION );
	sseo_check( "breadcrumbs: Simple SEO's own separator stays, the current page and the posts page left out, a type's taxonomy", '/' === $sseo_rm_bc['separator'] && false === $sseo_rm_bc['show_current'] && false === $sseo_rm_bc['show_blog'] && array( 'sseo_rm_book' => 'sseo_rm_room' ) === $sseo_rm_bc['taxonomies'], $sseo_rm_bc );
	sseo_check( "breadcrumb labels as Rank Math had them; Simple SEO's own one stays", array( 'home' => 'My home', 'prefix' => 'You are here:' ) === get_option( Simple_SEO_Breadcrumbs::LABELS ) );
	sseo_check( 'no sitemap module, no sitemap', array( 'enabled' => false ) === get_option( Simple_SEO_Sitemaps::OPTION ) );
	sseo_check( "Rank Math's robots.txt, its line endings made one", "User-agent: *\nDisallow: /private/\n" === Simple_SEO_Files::robots_option(), Simple_SEO_Files::robots_option() );
	sseo_check(
		'the report names what was taken and what Simple SEO has nothing for',
		array(
			'separator'     => 1,
			'templates'     => 6,
			'indexing'      => 1,
			'attachments'   => 1,
			'special'       => 3,
			'default_image' => 1,
			'breadcrumbs'   => 3,
			'labels'        => 1,
			'sitemaps'      => 1,
			'robots_txt'    => 1,
		) == $sseo_rm_report['settings'] && array( 'social_templates' => 1, 'breadcrumb_texts' => 1, 'verification' => 1, 'url_bases' => 1 ) == $sseo_rm_report['left'], // phpcs:ignore Universal.Operators.StrictComparisons
		$sseo_rm_report
	);
	$sseo_rm_again = array();
	Simple_SEO_Rank_Math_Import::settings( $sseo_rm_again );
	sseo_check( 'run again: nothing more to take', ! isset( $sseo_rm_again['settings'] ), $sseo_rm_again );
	delete_option( Simple_SEO_Breadcrumbs::OPTION );
	update_option( 'rank-math-options-titles', array_merge( get_option( 'rank-math-options-titles' ), array( 'title_separator' => 'custom' ) ) );
	delete_option( Simple_SEO_Titles::OPTION );
	$sseo_rm_again = array();
	Simple_SEO_Rank_Math_Import::settings( $sseo_rm_again );
	sseo_check( "Rank Math's breadcrumb separator, its HTML entity decoded; a separator Simple SEO does not have is not taken", '»' === get_option( Simple_SEO_Breadcrumbs::OPTION )['separator'] && 4 === $sseo_rm_again['settings']['breadcrumbs'] && ! isset( $sseo_rm_again['settings']['separator'] ) && Simple_SEO_Titles::DEFAULT_SEPARATOR === get_option( Simple_SEO_Titles::OPTION )['separator'], $sseo_rm_again );

	$sseo_rm_saved = array( get_option( 'rank-math-options-titles' ), get_option( 'rank-math-options-general' ) );
	update_option(
		'rank-math-options-titles',
		array_merge(
			$sseo_rm_saved[0],
			array(
				'robots_global'           => array( 'noindex' ),
				'author_robots'           => array( 'index' ),
				'disable_author_archives' => true,
				'disable_date_archives'   => 'off',
				'date_archive_robots'     => array( 'index' ),
			)
		)
	);
	update_option( 'rank-math-options-general', array( 'robots_txt_content' => Simple_SEO_Files::robots_default(), 'strip_category_base' => 'off', 'wc_remove_product_base' => 'off' ) );
	$sseo_rm_own = array();
	foreach ( array( Simple_SEO_Indexing::OPTION, Simple_SEO_Social::OPTION, Simple_SEO_Files::ROBOTS_OPTION ) as $sseo_name ) {
		$sseo_rm_own[ $sseo_name ] = get_option( $sseo_name );
		delete_option( $sseo_name );
	}
	Simple_SEO_Settings::flush();
	$sseo_rm_again = array();
	Simple_SEO_Rank_Math_Import::settings( $sseo_rm_again );
	$sseo_rm_index = get_option( Simple_SEO_Indexing::OPTION );
	sseo_check(
		"with noindex as the global robots: every kind without robots of its own; author archives switched off; the robots of date archives; Rank Math's X account; robots.txt as WordPress has it is not taken",
		in_array( 'post:post', $sseo_rm_index['noindex'], true ) && in_array( 'term:category', $sseo_rm_index['noindex'], true ) && in_array( 'author', $sseo_rm_index['noindex'], true ) && ! in_array( 'date', $sseo_rm_index['noindex'], true ) && ! isset( $sseo_rm_again['settings']['attachments'] )
			&& '@rmsite' === get_option( Simple_SEO_Social::OPTION )['x_account'] && '' === Simple_SEO_Files::robots_option() && ! isset( $sseo_rm_again['settings']['robots_txt'] ) && ! isset( $sseo_rm_again['left']['url_bases'] ),
		array( $sseo_rm_index, $sseo_rm_again )
	);
	update_option( 'rank-math-options-titles', $sseo_rm_saved[0] );
	update_option( 'rank-math-options-general', $sseo_rm_saved[1] );
	foreach ( $sseo_rm_own as $sseo_name => $sseo_value ) {
		update_option( $sseo_name, $sseo_value, false );
	}
	Simple_SEO_Settings::flush();

	// Posts.
	$sseo_rm_report = array();
	$sseo_rm_next   = Simple_SEO_Rank_Math_Import::posts( 0, 1, $sseo_rm_report );
	sseo_check( 'posts in batches, by ID', $sseo_rm_1 === $sseo_rm_next && 1 === $sseo_rm_report['post']['seen'] );
	$sseo_rm_next = Simple_SEO_Rank_Math_Import::posts( $sseo_rm_next, 100, $sseo_rm_report );
	sseo_check( 'the last batch ends with the last post, then nothing is left', $sseo['posts']['rm_menu'] === $sseo_rm_next && 0 === Simple_SEO_Rank_Math_Import::posts( $sseo_rm_next, 100, $sseo_rm_report ) );
	$sseo_rm_fields = array();
	foreach ( array( 'title', 'description', 'og_title', 'og_image', 'index', 'nofollow', 'canonical', 'primary_category', 'keyphrase', 'keyphrases' ) as $sseo_key ) {
		$sseo_rm_fields[ $sseo_key ] = Simple_SEO_Fields::post_value( $sseo_rm_1, $sseo_key );
	}
	sseo_check(
		"a post's fields: texts in Simple SEO's syntax, the image by its address where its ID is no image, both robots from the list, canonical, primary category; of the keyphrases the first is the focus one, the rest one on a line",
		array(
			'title'            => '{title} {sep} {site} {page}',
			'description'      => 'Desc here',
			'og_title'         => 'OG {title}',
			'og_image'         => (string) $sseo_og,
			'index'            => 'noindex',
			'nofollow'         => '1',
			'canonical'        => 'https://example.com/rm-one/',
			'primary_category' => (string) $sseo_news,
			'keyphrase'        => 'one',
			'keyphrases'       => "two\nthree four",
		) === $sseo_rm_fields,
		$sseo_rm_fields
	);
	sseo_check( "Simple SEO's own field stays; Rank Math's explicit index is index; an image elsewhere is not taken; of two rows the first", 'Own two' === Simple_SEO_Fields::post_value( $sseo_rm_2, 'title' ) && 'index' === Simple_SEO_Fields::post_value( $sseo_rm_2, 'index' ) && '' === Simple_SEO_Fields::post_value( $sseo_rm_2, 'nofollow' ) && '' === Simple_SEO_Fields::post_value( $sseo_rm_2, 'og_image' ) && 'https://example.com/first/' === Simple_SEO_Fields::post_value( $sseo_rm_2, 'canonical' ) );
	sseo_check( 'a page: its title, no primary category (pages have none), no robots from an empty list; a post type without SEO fields is left alone', 'Page T' === Simple_SEO_Fields::post_value( $sseo_rm_page, 'title' ) && '' === Simple_SEO_Fields::post_value( $sseo_rm_page, 'primary_category' ) && '' === Simple_SEO_Fields::post_value( $sseo_rm_page, 'index' ) && '' === Simple_SEO_Fields::post_value( $sseo['posts']['rm_menu'], 'title' ) );
	sseo_check( "Simple SEO's own focus keyphrase stays, the other keyphrases are taken", 'Own focus' === Simple_SEO_Fields::post_value( $sseo_rm_page, 'keyphrase' ) && 'more' === Simple_SEO_Fields::post_value( $sseo_rm_page, 'keyphrases' ) );
	sseo_check( 'the report of the posts', array( 'seen' => 3, 'fields' => 14, 'changed' => 3, 'kept' => 2, 'refused' => 1 ) == $sseo_rm_report['post'] && array( 'modified' => 1 ) === $sseo_rm_report['dropped'], $sseo_rm_report ); // phpcs:ignore Universal.Operators.StrictComparisons
	sseo_check( "Rank Math's rows are left as they were", $sseo_rm_raw === (int) $sseo_rm_db->get_var( "SELECT COUNT(*) FROM {$sseo_rm_db->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%'" ) && '%title% %sep% %sitename% %page%' === get_post_meta( $sseo_rm_1, 'rank_math_title', true ) );

	// Terms.
	$sseo_rm_report = array();
	$sseo_rm_next   = Simple_SEO_Rank_Math_Import::terms( 0, 2, $sseo_rm_report );
	$sseo_rm_steps  = array( $sseo_rm_next );
	while ( $sseo_rm_next && count( $sseo_rm_steps ) < 10 ) {
		$sseo_rm_next    = Simple_SEO_Rank_Math_Import::terms( $sseo_rm_next, 2, $sseo_rm_report );
		$sseo_rm_steps[] = $sseo_rm_next;
	}
	sseo_check( 'terms in batches, by ID', array( $sseo_rm_terms['cat'], $sseo_rm_terms['fmt'], 99999999, 0 ) === $sseo_rm_steps, $sseo_rm_steps );
	$sseo_rm_fields = array();
	foreach ( array( 'title', 'description', 'index', 'nofollow', 'canonical', 'og_image' ) as $sseo_key ) {
		$sseo_rm_fields[ $sseo_key ] = Simple_SEO_Fields::term_value( $sseo_rm_terms['cat'], $sseo_key );
	}
	sseo_check(
		"a term's fields: noindex, canonical, the image by its address",
		array(
			'title'       => '{title} best {sep} {site}',
			'description' => 'Cat desc',
			'index'       => 'noindex',
			'nofollow'    => '',
			'canonical'   => 'https://example.com/rm-cat/',
			'og_image'    => (string) $sseo_feat,
		) === $sseo_rm_fields,
		$sseo_rm_fields
	);
	sseo_check( "Simple SEO's own field stays; nofollow alone leaves indexing as set for the type; terms that are gone, or of a taxonomy without SEO fields, are left alone", 'Own cat two' === Simple_SEO_Fields::term_value( $sseo_rm_terms['cat2'], 'title' ) && 'Tag desc' === Simple_SEO_Fields::term_value( $sseo_rm_terms['tag'], 'description' ) && '' === Simple_SEO_Fields::term_value( $sseo_rm_terms['tag'], 'index' ) && '1' === Simple_SEO_Fields::term_value( $sseo_rm_terms['tag'], 'nofollow' ) && '' === Simple_SEO_Fields::term_value( $sseo_rm_terms['fmt'], 'title' ) );
	sseo_check( 'the report of the terms; a keyphrase of a term is no field', array( 'seen' => 3, 'fields' => 7, 'changed' => 2, 'kept' => 1 ) == $sseo_rm_report['term'] && ! metadata_exists( 'term', $sseo_rm_terms['cat'], Simple_SEO_Fields::meta_key( 'keyphrase' ) ), $sseo_rm_report ); // phpcs:ignore Universal.Operators.StrictComparisons
	sseo_check( 'what Simple SEO has nothing for: by posts, authors, and the redirections that worked', array( 'twitter' => 1, 'robots_adv' => 1, 'schema' => 1, 'authors' => 1, 'redirections' => 2 ) == Simple_SEO_Rank_Math_Import::left(), Simple_SEO_Rank_Math_Import::left() ); // phpcs:ignore Universal.Operators.StrictComparisons
	update_option( 'rank_math_modules', array( 'link-counter' ) );
	sseo_check( 'redirections with their module off did nothing: not named', ! isset( Simple_SEO_Rank_Math_Import::left()['redirections'] ) );
	update_option( 'rank_math_modules', array( 'link-counter', 'redirections' ) );

	// The whole run, through the tab's forms.
	sseo_check( 'a form of an unknown plugin does nothing', '' === Simple_SEO_Import::act( 'start', array( 'simple_seo_source' => 'nope' ) ) && 'pending' === Simple_SEO_Import::state( 'rank_math' )['status'] );
	Simple_SEO_Import::start( 'rank_math' );
	$sseo_rm_count = 0;
	do {
		$sseo_rm_state = Simple_SEO_Import::run( 'rank_math', 0.0 );
		++$sseo_rm_count;
	} while ( 'running' === $sseo_rm_state['status'] && $sseo_rm_count < 20 );
	sseo_check( 'a run of zero seconds still takes one batch, and goes on from where it stopped', 'done' === $sseo_rm_state['status'] && 5 === $sseo_rm_count && 'none' === Simple_SEO_Import::state( 'yoast' )['status'], array( $sseo_rm_count, $sseo_rm_state['status'] ) );
	sseo_check( "run again: everything is Simple SEO's already, kept", ! isset( $sseo_rm_state['report']['settings'] ) && 0 === ( $sseo_rm_state['report']['post']['fields'] ?? 0 ) && 16 === $sseo_rm_state['report']['post']['kept'] && 8 === $sseo_rm_state['report']['term']['kept'] && 2 === $sseo_rm_state['report']['left']['redirections'] && 1 === $sseo_rm_state['report']['left']['verification'], $sseo_rm_state['report'] );
	update_option( Simple_SEO_Rank_Math_Import::STATE, array( 'status' => 'running', 'phase' => 'terms', 'cursor' => 99999999, 'report' => array( 'post' => array( 'seen' => 99 ) ) ) );
	Simple_SEO_Import::act( 'start', array( 'simple_seo_source' => 'rank_math' ) );
	sseo_check( 'starting while a run goes on continues it', 'done' === Simple_SEO_Import::state( 'rank_math' )['status'] && 99 === Simple_SEO_Import::state( 'rank_math' )['report']['post']['seen'] );

	// The admin.
	wp_set_current_user( $sseo_u['administrator'] );
	Simple_SEO_Import::add_admin_tab();
	update_option( Simple_SEO_Rank_Math_Import::STATE, array( 'status' => 'pending' ) );
	update_option( Simple_SEO_Yoast_Import::STATE, array( 'status' => 'pending' ) );
	set_current_screen( 'dashboard' );
	$sseo_html = sseo_capture( array( Simple_SEO_Import::class, 'notice' ) );
	sseo_check( 'the notice names every plugin with data to import', str_contains( $sseo_html, 'Simple SEO found the data of Yoast SEO and Rank Math on this site' ), $sseo_html );
	Simple_SEO_Import::act( 'dismiss' );
	sseo_check( 'Not now puts off both', 'dismissed' === Simple_SEO_Import::state( 'rank_math' )['status'] && 'dismissed' === Simple_SEO_Import::state( 'yoast' )['status'] );
	set_current_screen( 'toplevel_page_' . Simple_SEO_Admin::PAGE );
	$_GET['tab'] = 'import';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check(
		'the tab: Rank Math with what was found and its button; Yoast, which left nothing, is not named',
		str_contains( $sseo_html, '<h2>Rank Math</h2>' ) && str_contains( $sseo_html, 'Import from Rank Math' ) && str_contains( $sseo_html, 'Rank Math&#039;s %variables% become' ) && str_contains( $sseo_html, 'posts with SEO fields: 4' ) && str_contains( $sseo_html, 'terms with SEO fields: 5' ) && str_contains( $sseo_html, 'name="simple_seo_source" value="rank_math"' ) && ! str_contains( $sseo_html, '<h2>Yoast SEO</h2>' ),
		$sseo_html
	);
	update_option(
		Simple_SEO_Rank_Math_Import::STATE,
		array(
			'status'   => 'done',
			'finished' => time(),
			'report'   => array(
				'post'    => array( 'seen' => 3 ),
				'dropped' => array( 'focuskw' => 2 ),
				'left'    => array(
					'redirections' => 2,
					'verification' => 1,
					'authors'      => 1,
				),
			),
		)
	);
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( "after a run: Rank Math's variables as Rank Math writes them, and what to do about the redirections and the codes", str_contains( $sseo_html, 'Posts with SEO fields: 3, 0 of them changed.' ) && str_contains( $sseo_html, '%focuskw% (2)' ) && str_contains( $sseo_html, 'redirections (2): with the plugin off they stop working' ) && str_contains( $sseo_html, 'the codes that confirm the site to Google' ) && str_contains( $sseo_html, 'author archives (authors: 1)' ), $sseo_html );
	update_option( Simple_SEO_Rank_Math_Import::STATE, array( 'status' => 'running', 'report' => array( 'post' => array( 'seen' => 7 ) ) ) );
	update_option( Simple_SEO_Yoast_Import::STATE, array( 'status' => 'done' ) );
	update_option( Simple_SEO_Rank_Math_Cleanup::OPTION, array( 'status' => 'done', 'report' => array( 'tables' => 1 ) ) );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	delete_option( Simple_SEO_Rank_Math_Cleanup::OPTION );
	sseo_check( 'while one import runs, the tab shows only its progress', str_contains( $sseo_html, '7 posts and 0 terms so far' ) && str_contains( $sseo_html, 'value="rank_math"' ) && ! str_contains( $sseo_html, 'Yoast SEO' ) && ! str_contains( $sseo_html, 'The last clean-up' ) );
	unset( $_GET['tab'], $GLOBALS['current_screen'] );
	wp_set_current_user( 0 );
	update_option( Simple_SEO_Rank_Math_Import::STATE, $sseo_rm_state );
	delete_option( Simple_SEO_Yoast_Import::STATE );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Rank Math clean-up' );

	// Rank Math's leftovers: those of the import section and these.
	$sseo_rm_db->query( "CREATE TABLE IF NOT EXISTS `{$sseo_rm_db->prefix}rank_math_internal_meta` ( object_id BIGINT NOT NULL PRIMARY KEY )" );
	$sseo_rm_db->query( "CREATE TABLE IF NOT EXISTS `{$sseo_rm_db->prefix}sseo_rank_math_keep` ( id INT NOT NULL PRIMARY KEY )" );
	update_option( 'rank_math_version', '1.0.282' );
	update_option( 'rank-math-options-sitemap', array( 'items_per_page' => 200 ) );
	update_option( 'sseo_rank_math_keep', 'x' );
	set_transient( 'rank_math_sseo', 'x', HOUR_IN_SECONDS );
	set_transient( '_rank_math_sseo_redirect', 1, HOUR_IN_SECONDS );
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rank_math/sseo_daily' );
	wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, 'rank_math_sseo_once', array( 1 ) );
	wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, 'rank_math_sseo_once', array( 2 ) );
	wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, 'sseo_keep_hook' );
	get_role( 'administrator' )->add_cap( 'rank_math_titles' );
	get_role( 'editor' )->add_cap( 'rank_math_onpage_general' );
	get_role( 'editor' )->add_cap( 'sseo_keep_cap' );
	$sseo_rc_user = (int) $sseo_u['administrator'];
	update_user_meta( $sseo_rc_user, 'rank_math_sseo_pref', 'x' );
	update_user_meta( $sseo_rc_user, 'sseo_keep', 'x' );
	add_term_meta( $sseo_rm_terms['cat'], 'sseo_keep', 'x' );
	update_post_meta( $sseo_rm_1, 'sseo_keep', 'x' );
	// One batch and one row more of post meta.
	$sseo_rc_posts = (int) $sseo_rm_db->get_var( "SELECT COUNT(*) FROM {$sseo_rm_db->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%'" );
	$sseo_rm_db->query( "INSERT INTO {$sseo_rm_db->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ',', array_map( static fn( int $i ): string => "( {$sseo_rm_1}, 'rank_math_filler_{$i}', 'x' )", range( 1, Simple_SEO_Rank_Math_Cleanup::BATCH + 1 - $sseo_rc_posts ) ) ) );
	wp_cache_delete( $sseo_rm_1, 'post_meta' );
	$sseo_rc_terms   = (int) $sseo_rm_db->get_var( "SELECT COUNT(*) FROM {$sseo_rm_db->termmeta} WHERE meta_key LIKE 'rank\\_math\\_%'" );
	$sseo_rc_tables  = (array) $sseo_rm_db->get_col( $sseo_rm_db->prepare( 'SHOW TABLES LIKE %s', $sseo_rm_db->esc_like( $sseo_rm_db->prefix . 'rank_math_' ) . '%' ) );
	$sseo_rc_options = (int) $sseo_rm_db->get_var( "SELECT COUNT(*) FROM {$sseo_rm_db->options} WHERE option_name LIKE 'rank\\_math\\_%' OR option_name LIKE 'rank-math-%' OR option_name LIKE '\\_transient\\_%rank\\_math%'" ) - 4;

	// When it may run.
	$sseo_rc_active = static fn( $plugins ): array => array_merge( (array) $plugins, array( 'seo-by-rank-math/rank-math.php' ) );
	update_option( Simple_SEO_Rank_Math_Import::STATE, array( 'status' => 'pending' ) );
	$sseo_vals = array( Simple_SEO_Rank_Math_Cleanup::blocked() );
	update_option( Simple_SEO_Rank_Math_Import::STATE, array( 'status' => 'done' ) );
	$sseo_vals[] = Simple_SEO_Rank_Math_Cleanup::blocked();
	add_filter( 'option_active_plugins', $sseo_rc_active );
	$sseo_vals[] = Simple_SEO_Rank_Math_Cleanup::blocked();
	remove_filter( 'option_active_plugins', $sseo_rc_active );
	sseo_check( 'only after the import, and never while Rank Math is active', array( 'import', '', 'active' ) === $sseo_vals, $sseo_vals );

	// What is left.
	$sseo_rc_parts = Simple_SEO_Rank_Math_Cleanup::preview();
	sseo_check(
		"what is left of Rank Math's, by part: tables, the rest of its options with its tasks and the capabilities of roles, settings, user, term and post meta",
		array( 'tables', 'internals', 'settings', 'usermeta', 'termmeta', 'postmeta' ) === array_keys( $sseo_rc_parts ) && count( $sseo_rc_tables ) === $sseo_rc_parts['tables']['rows'] && 4 === $sseo_rc_parts['settings']['rows'] && 2 === $sseo_rc_parts['usermeta']['rows'] && $sseo_rc_terms === $sseo_rc_parts['termmeta']['rows'] && Simple_SEO_Rank_Math_Cleanup::BATCH + 1 === $sseo_rc_parts['postmeta']['rows'] && $sseo_rc_options + 3 + 2 === $sseo_rc_parts['internals']['rows'],
		array( $sseo_rc_parts, $sseo_rc_options, $sseo_rc_terms )
	);
	$sseo_rc_theme = get_theme_root() . '/sseo-rc-theme';
	wp_mkdir_p( $sseo_rc_theme . '/inc' );
	wp_mkdir_p( $sseo_rc_theme . '/vendor' );
	file_put_contents( $sseo_rc_theme . '/inc/schema.php', "<?php \$name = get_option( 'rank-math-options-titles' )['knowledgegraph_name'];" );
	file_put_contents( $sseo_rc_theme . '/inc/meta.twig', "{{ fn('get_post_meta', post.id, 'rank_math_title', true) }}" );
	file_put_contents( $sseo_rc_theme . '/inc/readme.txt', 'rank_math_title' );
	file_put_contents( $sseo_rc_theme . '/vendor/x.php', "<?php get_post_meta( 1, 'rank_math_title', true );" );
	$sseo_rc_dir = static fn(): string => $sseo_rc_theme;
	add_filter( 'stylesheet_directory', $sseo_rc_dir );
	sseo_check( 'the theme is read only for the parts asked for', array() === Simple_SEO_Rank_Math_Cleanup::theme_reads( array( 'tables', 'usermeta' ) ) );
	$sseo_vals = Simple_SEO_Rank_Math_Cleanup::theme_reads( array( 'tables', 'settings', 'termmeta', 'postmeta' ) );
	sseo_check(
		"the active theme's files that read Rank Math's settings, or its meta (Simple SEO has no aliases for it)",
		array(
			'settings' => array( 'sseo-rc-theme/inc/schema.php' ),
			'termmeta' => array( 'sseo-rc-theme/inc/meta.twig' ),
			'postmeta' => array( 'sseo-rc-theme/inc/meta.twig' ),
		) == $sseo_vals, // phpcs:ignore Universal.Operators.StrictComparisons -- the order of parts follows the files.
		$sseo_vals
	);

	// The form.
	$sseo_rc_all = array(
		'simple_seo_source' => 'rank_math',
		'simple_seo_groups' => array( 'tables', 'internals', 'usermeta', 'termmeta', 'postmeta' ),
		'simple_seo_backup' => '1',
	);
	$sseo_vals   = array(
		Simple_SEO_Import::act( 'clean', array( 'simple_seo_source' => 'rank_math', 'simple_seo_groups' => array( 'nope' ), 'simple_seo_backup' => '1' ) ),
		Simple_SEO_Import::act( 'clean', array( 'simple_seo_source' => 'rank_math', 'simple_seo_groups' => array( 'tables' ) ) ),
	);
	add_filter( 'option_active_plugins', $sseo_rc_active );
	$sseo_vals[] = Simple_SEO_Import::act( 'clean', $sseo_rc_all );
	remove_filter( 'option_active_plugins', $sseo_rc_active );
	sseo_check( 'refused without a part, without a backup, and with Rank Math active; nothing started', array( 'groups', 'backup', 'blocked' ) === $sseo_vals && array() === Simple_SEO_Rank_Math_Cleanup::state(), $sseo_vals );

	// The page, before.
	wp_set_current_user( $sseo_rc_user );
	set_current_screen( 'toplevel_page_' . Simple_SEO_Admin::PAGE );
	$_GET['tab'] = 'import';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check(
		"the tab offers Rank Math's parts; what the theme reads is unticked and its files named",
		str_contains( $sseo_html, 'Remove the data of Rank Math' ) && str_contains( $sseo_html, 'Rank Math&#039;s fields of terms' ) && str_contains( $sseo_html, 'the capabilities it gave to roles' )
			&& (bool) preg_match( '/value="usermeta"\s+checked=\'checked\'/', $sseo_html ) && (bool) preg_match( '/value="postmeta"\s*\/>/', $sseo_html ) && (bool) preg_match( '/value="termmeta"\s*\/>/', $sseo_html ) && (bool) preg_match( '/value="settings"\s*\/>/', $sseo_html )
			&& str_contains( $sseo_html, 'The active theme reads the fields of posts of Rank Math' ) && str_contains( $sseo_html, '<code>sseo-rc-theme/inc/meta.twig</code>' ) && str_contains( $sseo_html, 'rows: 2,001, size:' ) && str_contains( $sseo_html, 'id="simple-seo-cleanup-rank_math"' ),
		$sseo_html
	);
	remove_filter( 'stylesheet_directory', $sseo_rc_dir );

	// A run: a part or a batch per step.
	$sseo_vals = array( get_post_meta( $sseo_rm_1, 'rank_math_focus_keyword', true ), get_term_meta( $sseo_rm_terms['cat'], 'rank_math_focus_keyword', true ), get_user_meta( $sseo_rc_user, 'rank_math_title', true ) );
	Simple_SEO_Rank_Math_Cleanup::start( $sseo_rc_all['simple_seo_groups'] );
	$sseo_rc_steps = 0;
	do {
		$sseo_rc_state = Simple_SEO_Rank_Math_Cleanup::run( 0.0 );
		++$sseo_rc_steps;
		if ( 5 === $sseo_rc_steps ) {
			$sseo_rc_batch = (int) $sseo_rm_db->get_var( "SELECT COUNT(*) FROM {$sseo_rm_db->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%'" );
		}
	} while ( 'running' === $sseo_rc_state['status'] && $sseo_rc_steps < 20 );
	sseo_check( 'one step per part, the post meta in batches', 'done' === $sseo_rc_state['status'] && 6 === $sseo_rc_steps && 1 === $sseo_rc_batch, array( $sseo_rc_steps, $sseo_rc_batch ) );
	$sseo_rc_caps = array();
	foreach ( wp_roles()->role_objects as $sseo_role ) {
		foreach ( array_keys( (array) $sseo_role->capabilities ) as $sseo_name ) {
			if ( str_starts_with( (string) $sseo_name, 'rank_math_' ) ) {
				$sseo_rc_caps[] = $sseo_name;
			}
		}
	}
	$sseo_rc_left = array(
		'tables'   => (array) $sseo_rm_db->get_col( $sseo_rm_db->prepare( 'SHOW TABLES LIKE %s', $sseo_rm_db->esc_like( $sseo_rm_db->prefix . 'rank_math_' ) . '%' ) ),
		'options'  => (array) $sseo_rm_db->get_col( "SELECT option_name FROM {$sseo_rm_db->options} WHERE option_name LIKE 'rank\\_math\\_%' OR option_name LIKE 'rank-math-%' OR option_name LIKE '\\_transient\\_%rank\\_math%'" ),
		'caps'     => $sseo_rc_caps,
		'usermeta' => (int) $sseo_rm_db->get_var( "SELECT COUNT(*) FROM {$sseo_rm_db->usermeta} WHERE meta_key LIKE 'rank\\_math\\_%'" ),
		'termmeta' => (int) $sseo_rm_db->get_var( "SELECT COUNT(*) FROM {$sseo_rm_db->termmeta} WHERE meta_key LIKE 'rank\\_math\\_%'" ),
		'postmeta' => (int) $sseo_rm_db->get_var( "SELECT COUNT(*) FROM {$sseo_rm_db->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%'" ),
	);
	sort( $sseo_rc_left['options'] );
	sseo_check( "Rank Math's tables, other options, tasks, capabilities and meta are gone; its settings stay, as they were not ticked", array( 'tables' => array(), 'options' => array( 'rank-math-options-general', 'rank-math-options-sitemap', 'rank-math-options-titles', 'rank_math_modules' ), 'caps' => array(), 'usermeta' => 0, 'termmeta' => 0, 'postmeta' => 0 ) === $sseo_rc_left && false === get_transient( '_rank_math_sseo_redirect' ) && false === wp_next_scheduled( 'rank_math/sseo_daily' ) && false === wp_next_scheduled( 'rank_math_sseo_once', array( 2 ) ), $sseo_rc_left );
	sseo_check(
		'what is not Rank Math\'s stays, Simple SEO\'s imported fields included',
		'x' === get_option( 'sseo_rank_math_keep' ) && (bool) wp_next_scheduled( 'sseo_keep_hook' ) && get_role( 'editor' )->has_cap( 'sseo_keep_cap' ) && 'x' === get_user_meta( $sseo_rc_user, 'sseo_keep', true ) && 'x' === get_term_meta( $sseo_rm_terms['cat'], 'sseo_keep', true ) && 'x' === get_post_meta( $sseo_rm_1, 'sseo_keep', true )
			&& '{title} {sep} {site} {page}' === Simple_SEO_Fields::post_value( $sseo_rm_1, 'title' ) && 'Cat desc' === Simple_SEO_Fields::term_value( $sseo_rm_terms['cat'], 'description' ) && 'done' === Simple_SEO_Import::state( 'rank_math' )['status']
			&& array( $sseo_rm_db->prefix . 'sseo_rank_math_keep' ) === $sseo_rm_db->get_col( $sseo_rm_db->prepare( 'SHOW TABLES LIKE %s', $sseo_rm_db->esc_like( $sseo_rm_db->prefix . 'sseo_rank_math_keep' ) ) )
	);
	sseo_check( 'the cached meta of the posts, terms and users goes too', array( ' one ,two,, three  four ', 'cat', 'Author %name%' ) === $sseo_vals && '' === get_post_meta( $sseo_rm_1, 'rank_math_focus_keyword', true ) && '' === get_term_meta( $sseo_rm_terms['cat'], 'rank_math_focus_keyword', true ) && '' === get_user_meta( $sseo_rc_user, 'rank_math_title', true ) );
	$sseo_rc_report = $sseo_rc_state['report'];
	sseo_check( 'the report counts what went', array( 'tables' => count( $sseo_rc_tables ), 'options' => $sseo_rc_options, 'cron' => 3, 'caps' => 2, 'usermeta' => 2, 'termmeta' => $sseo_rc_terms, 'postmeta' => Simple_SEO_Rank_Math_Cleanup::BATCH + 1 ) == $sseo_rc_report, $sseo_rc_report ); // phpcs:ignore Universal.Operators.StrictComparisons

	// The settings, through the form.
	$sseo_vals = Simple_SEO_Import::act( 'clean', array( 'simple_seo_source' => 'rank_math', 'simple_seo_groups' => array( 'settings' ), 'simple_seo_backup' => '1' ) );
	sseo_check( 'the settings, on their own; then nothing of Rank Math\'s is left', '' === $sseo_vals && 4 === Simple_SEO_Rank_Math_Cleanup::state()['report']['settings'] && array() === Simple_SEO_Rank_Math_Cleanup::preview() );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'], $GLOBALS['current_screen'] );
	wp_set_current_user( 0 );
	sseo_check( 'after: nothing left, and the report', str_contains( $sseo_html, 'No data of Rank Math is left on this site.' ) && str_contains( $sseo_html, 'Removed: rows of settings: 4.' ) && ! str_contains( $sseo_html, 'value="clean"' ), $sseo_html );

	$sseo_rm_db->query( "DROP TABLE IF EXISTS `{$sseo_rm_db->prefix}sseo_rank_math_keep`" );
	delete_option( 'sseo_rank_math_keep' );
	wp_clear_scheduled_hook( 'sseo_keep_hook' );
	get_role( 'editor' )->remove_cap( 'sseo_keep_cap' );
	delete_user_meta( $sseo_rc_user, 'sseo_keep' );
	foreach ( array( '/inc/schema.php', '/inc/meta.twig', '/inc/readme.txt', '/vendor/x.php', '/vendor', '/inc', '' ) as $sseo_name ) {
		$sseo_name = $sseo_rc_theme . $sseo_name;
		is_dir( $sseo_name ) ? rmdir( $sseo_name ) : unlink( $sseo_name );
	}
	foreach ( array_merge( $sseo_yi_rows, array( Simple_SEO_Files::ROBOTS_OPTION, Simple_SEO_Rank_Math_Import::STATE, Simple_SEO_Rank_Math_Cleanup::OPTION ) ) as $sseo_name ) {
		delete_option( $sseo_name );
	}
	Simple_SEO_Settings::install();
	Simple_SEO_Settings::flush();
	unregister_taxonomy( 'sseo_rm_shelf' );
	unregister_taxonomy( 'sseo_rm_room' );
	unregister_post_type( 'sseo_rm_book' );
	Simple_SEO_Fields::flush_types();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'AIOSEO import' );

	$sseo_ai_db = $GLOBALS['wpdb'];
	foreach ( $sseo_yi_rows as $sseo_name ) {
		remove_all_filters( 'sanitize_option_' . $sseo_name );
		delete_option( $sseo_name );
	}
	foreach ( array( Simple_SEO_Files::ROBOTS_OPTION, Simple_SEO_Yoast_Import::STATE, Simple_SEO_Rank_Math_Import::STATE, Simple_SEO_Aioseo_Import::STATE ) as $sseo_name ) {
		delete_option( $sseo_name );
	}
	Simple_SEO_Settings::install();
	Simple_SEO_Settings::flush();
	sseo_check( 'no data of AIOSEO: nothing found, nothing to import', ! Simple_SEO_Aioseo_Import::found() && array( 'settings' => false, 'posts' => 0, 'terms' => 0 ) === Simple_SEO_Aioseo_Import::summary() && array() === Simple_SEO_Aioseo_Import::left() );

	// AIOSEO's tables as AIOSEO has them (the columns the import reads); its Pro edition's table of terms with fewer.
	$sseo_ai_posts = $sseo_ai_db->prefix . 'aioseo_posts';
	$sseo_ai_terms = $sseo_ai_db->prefix . 'aioseo_terms';
	$sseo_ai_db->query( "CREATE TABLE IF NOT EXISTS `{$sseo_ai_posts}` ( id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id BIGINT NOT NULL, title TEXT NULL, description TEXT NULL, keyphrases LONGTEXT NULL, focus_keyword VARCHAR(255) NULL, additional_keywords TEXT NULL, primary_term LONGTEXT NULL, canonical_url TEXT NULL, og_title TEXT NULL, og_description TEXT NULL, og_image_type VARCHAR(64) NULL, og_image_custom_url TEXT NULL, twitter_title TEXT NULL, twitter_description TEXT NULL, `schema` LONGTEXT NULL, robots_default TINYINT(1) NOT NULL DEFAULT 1, robots_noindex TINYINT(1) NOT NULL DEFAULT 0, robots_nofollow TINYINT(1) NOT NULL DEFAULT 0 )" );
	$sseo_ai_row = static function ( string $table, string $column, int $id, array $row ) use ( $sseo_ai_db ): void {
		$sseo_ai_db->insert( $table, array( $column => $id ) + $row );
	};
	$sseo_ai_row( $sseo_ai_posts, 'post_id', 99999998, array( 'description' => 'x' ) );
	$sseo_ai_by = array( Simple_SEO_Aioseo_Import::found() );
	$sseo_ai_db->query( "TRUNCATE TABLE `{$sseo_ai_posts}`" );
	$sseo_ai_row( $sseo_ai_posts, 'post_id', 99999998, array( 'robots_default' => 1 ) );
	$sseo_ai_by[] = Simple_SEO_Aioseo_Import::found();
	$sseo_ai_db->query( "TRUNCATE TABLE `{$sseo_ai_posts}`" );
	$sseo_ai_db->query( "CREATE TABLE IF NOT EXISTS `{$sseo_ai_terms}` ( id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, term_id BIGINT NOT NULL, title TEXT NULL, description TEXT NULL, keyphrases LONGTEXT NULL, canonical_url TEXT NULL, robots_default TINYINT(1) NOT NULL DEFAULT 1, robots_noindex TINYINT(1) NOT NULL DEFAULT 0, robots_nofollow TINYINT(1) NOT NULL DEFAULT 0 )" );
	$sseo_ai_row( $sseo_ai_terms, 'term_id', 99999998, array( 'robots_default' => 0 ) );
	$sseo_ai_by[] = Simple_SEO_Aioseo_Import::found();
	$sseo_ai_db->query( "TRUNCATE TABLE `{$sseo_ai_terms}`" );
	sseo_check( "found by AIOSEO's row of a post or of a term with something to take; a row that follows the defaults is nothing", array( true, false, true ) === $sseo_ai_by, $sseo_ai_by );

	register_post_type( 'sseo_ai_book', array( 'public' => true, 'has_archive' => true, 'label' => 'Books' ) );
	foreach ( array( 'sseo_ai_shelf', 'sseo_ai_room' ) as $sseo_name ) {
		register_taxonomy( $sseo_name, 'sseo_ai_book', array( 'public' => true, 'hierarchical' => true, 'label' => $sseo_name ) );
	}
	Simple_SEO_Fields::flush_types();
	$sseo_ai_t = array();
	foreach ( array( 'tag' => 'post_tag', 'cat' => 'category', 'cat2' => 'category', 'fmt' => 'post_format' ) as $sseo_key => $sseo_name ) {
		$sseo_term               = wp_insert_term( 'post_format' === $sseo_name ? 'post-format-sseo-ai' : 'Ai ' . $sseo_key, $sseo_name );
		$sseo_ai_t[ $sseo_key ] = (int) $sseo_term['term_id'];
		$sseo['terms'][]         = array( (int) $sseo_term['term_id'], $sseo_name );
	}

	// AIOSEO's settings, as AIOSEO keeps them: JSON, separators as HTML entities.
	update_option(
		'aioseo_options',
		wp_json_encode(
			array(
				'webmasterTools'   => array( 'google' => 'abc123', 'bing' => null ),
				'breadcrumbs'      => array( 'separator' => '&raquo;', 'homepageLabel' => 'Start', 'breadcrumbPrefix' => 'You are here:', 'archiveFormat' => 'Archives for #breadcrumb_archive_post_type_name', 'showCurrentItem' => false, 'showBlogHome' => true ),
				'social'           => array(
					'facebook' => array(
						'general'  => array( 'defaultImageSourcePosts' => 'default', 'defaultImagePosts' => $sseo_yi_url( $sseo_default ) ),
						'homePage' => array( 'image' => $sseo_yi_url( $sseo_og ), 'title' => 'Social home' ),
					),
					'profiles' => array( 'urls' => array( 'twitterUrl' => 'https://x.com/aisite' ) ),
				),
				'sitemap'          => array( 'general' => array( 'enable' => false ) ),
				'tools'            => array( 'robots' => array( 'enable' => true, 'rules' => array( '{"userAgent":"*","rule":"disallow","directoryPath":"/x/"}' ) ) ),
				'searchAppearance' => array(
					'global'   => array( 'separator' => '&#124;', 'siteTitle' => 'Welcome #separator_sa #site_title', 'metaDescription' => 'All about #custom_field-price #site_title' ),
					'advanced' => array(
						'globalRobotsMeta'   => array( 'default' => true, 'noindex' => true ),
						'removeCategoryBase' => true,
						'crawlCleanup'       => array(
							'enable' => true,
							'feeds'  => array( 'global' => true, 'globalComments' => false, 'postComments' => true, 'authors' => false, 'search' => true, 'taxonomies' => array( 'all' => false, 'included' => array( 'category' ) ) ),
						),
					),
					'archives' => array(
						'author' => array( 'show' => true, 'title' => '#author_name at #site_title', 'metaDescription' => '', 'advanced' => array( 'robotsMeta' => array( 'default' => false, 'noindex' => true ) ) ),
						'date'   => array( 'show' => false, 'title' => '#archive_date #separator_sa #site_title' ),
						'search' => array( 'title' => 'Search: #search_term #separator_sa #site_title' ),
					),
				),
			)
		)
	);
	update_option(
		'aioseo_options_dynamic',
		wp_json_encode(
			array(
				'searchAppearance' => array(
					'postTypes'  => array(
						'post'         => array( 'show' => true, 'title' => '#post_title #separator_sa #site_title', 'metaDescription' => '#post_excerpt #post_modified_date', 'advanced' => array( 'robotsMeta' => array( 'default' => true, 'noindex' => true ) ) ),
						'page'         => array( 'title' => 'Page #post_title #categories', 'metaDescription' => 'About #post_title, #1 choice #unknown_word' ),
						'sseo_ai_book' => array( 'show' => false ),
						'attachment'   => array( 'show' => true, 'advanced' => array( 'robotsMeta' => array( 'default' => false, 'noindex' => true ) ), 'redirectAttachmentUrls' => 'attachment_parent' ),
					),
					'archives'   => array( 'sseo_ai_book' => array( 'show' => true, 'title' => '#archive_title Archive #separator_sa #site_title', 'advanced' => array( 'robotsMeta' => array( 'default' => false, 'noindex' => true ) ) ) ),
					'taxonomies' => array(
						'category' => array( 'show' => true, 'title' => '#taxonomy_title Archives #separator_sa #site_title', 'metaDescription' => '#taxonomy_description', 'advanced' => array( 'robotsMeta' => array( 'default' => false, 'noindex' => false ) ) ),
						'post_tag' => array( 'show' => false ),
					),
				),
			)
		)
	);

	// Posts and terms with AIOSEO's fields, in its tables.
	$sseo_ai_post             = static fn( array $args ): int => (int) wp_insert_post( $args + array( 'post_status' => 'publish' ) );
	$sseo['posts']['ai_1']    = $sseo_ai_post( array( 'post_title' => 'Ai One', 'post_category' => array( $sseo_news, $sseo_sport ) ) );
	$sseo['posts']['ai_2']    = $sseo_ai_post( array( 'post_title' => 'Ai Two' ) );
	$sseo['posts']['ai_3']    = $sseo_ai_post( array( 'post_title' => 'Ai Three' ) );
	$sseo['posts']['ai_page'] = $sseo_ai_post( array( 'post_title' => 'Ai Page', 'post_type' => 'page' ) );
	$sseo['posts']['ai_menu'] = $sseo_ai_post( array( 'post_title' => 'Ai Menu', 'post_type' => 'nav_menu_item' ) );
	$sseo['posts']['ai_none'] = $sseo_ai_post( array( 'post_title' => 'Ai None' ) );
	$sseo['posts']['ai_kw']   = $sseo_ai_post( array( 'post_title' => 'Ai Keyphrases' ) );
	$sseo['posts']['ai_fk']   = $sseo_ai_post( array( 'post_title' => 'Ai Focus' ) );
	$sseo['posts']['ai_add']  = $sseo_ai_post( array( 'post_title' => 'Ai Additional' ) );
	$sseo_ai_1                = $sseo['posts']['ai_1'];
	$sseo_ai_2                = $sseo['posts']['ai_2'];
	$sseo_ai_row( $sseo_ai_posts, 'post_id', $sseo_ai_1, array( 'title' => '#post_title #separator_sa #site_title #page_number', 'description' => 'Desc #post_modified_date here', 'og_title' => 'OG #post_title', 'og_image_type' => 'custom_image', 'og_image_custom_url' => $sseo_yi_url( $sseo_og ), 'canonical_url' => 'https://example.com/ai-one/', 'robots_default' => 0, 'robots_noindex' => 1, 'robots_nofollow' => 1, 'primary_term' => '{"category":' . $sseo_news . '}', 'focus_keyword' => 'one', 'additional_keywords' => wp_json_encode( array( array( 'word' => 'two', 'score' => 50 ), array( 'word' => '' ), array( 'score' => 0 ), array( 'word' => 'три  слова' ) ) ), 'keyphrases' => '{"focus":{"keyphrase":"old one"},"additional":[{"keyphrase":"old two"}]}', 'twitter_title' => 'X one', 'schema' => '{"blockGraphs":[],"customGraphs":[],"graphs":[{"id":"x"}]}' ) );
	$sseo_ai_row( $sseo_ai_posts, 'post_id', $sseo_ai_2, array( 'title' => 'Ai two', 'robots_default' => 0, 'robots_noindex' => 0, 'robots_nofollow' => 0, 'og_image_type' => 'custom_image', 'og_image_custom_url' => 'https://elsewhere.example/picture.jpg', 'focus_keyword' => 'ai two focus', 'keyphrases' => '{"focus":{"keyphrase":"stale"},"additional":[{"keyphrase":"two more"}]}', 'schema' => '{"blockGraphs":[],"customGraphs":[],"graphs":[]}' ) );
	$sseo_ai_row( $sseo_ai_posts, 'post_id', $sseo['posts']['ai_3'], array( 'title' => 'Three', 'og_image_type' => 'default', 'og_image_custom_url' => $sseo_yi_url( $sseo_og ), 'keyphrases' => '{"focus":{"keyphrase":"three focus"},"additional":[]}' ) );
	$sseo_ai_row( $sseo_ai_posts, 'post_id', $sseo['posts']['ai_page'], array( 'title' => 'Page T', 'primary_term' => '{"category":5}' ) );
	$sseo_ai_row( $sseo_ai_posts, 'post_id', $sseo['posts']['ai_menu'], array( 'title' => 'A menu item' ) );
	$sseo_ai_row( $sseo_ai_posts, 'post_id', $sseo['posts']['ai_none'], array( 'primary_term' => '[]', 'keyphrases' => '{"focus":{"keyphrase":""},"additional":[{"keyphrase":""}]}', 'focus_keyword' => '', 'additional_keywords' => '[{"word":""}]' ) );
	// Found by another keyphrase alone: Cyrillic as \u escapes, as AIOSEO writes it, and spaces a value written by hand may have.
	$sseo_ai_row( $sseo_ai_posts, 'post_id', $sseo['posts']['ai_kw'], array( 'keyphrases' => '{"focus": {"keyphrase": ""}, "additional": [{"keyphrase": "\u043a\u043b\u044e\u0447"}]}' ) );
	// Found by AIOSEO 5.0's focus keyword alone, and by its additional keywords alone.
	$sseo_ai_row( $sseo_ai_posts, 'post_id', $sseo['posts']['ai_fk'], array( 'focus_keyword' => 'фокус' ) );
	$sseo_ai_row( $sseo_ai_posts, 'post_id', $sseo['posts']['ai_add'], array( 'additional_keywords' => '[{"word": "\u0441\u043b\u043e\u0432\u043e", "score": 0}]' ) );
	$sseo_ai_row( $sseo_ai_terms, 'term_id', $sseo_ai_t['cat'], array( 'title' => '#taxonomy_title best #separator_sa #site_title', 'description' => 'Cat desc', 'robots_default' => 0, 'robots_noindex' => 1, 'canonical_url' => 'https://example.com/ai-cat/', 'keyphrases' => '{"focus":{"keyphrase":"cat"},"additional":[]}' ) );
	// Terms have no keyphrases: a term with nothing else is not one with SEO fields.
	$sseo_ai_row( $sseo_ai_terms, 'term_id', 99999997, array( 'keyphrases' => '{"focus":{"keyphrase":"gone"},"additional":[]}' ) );
	$sseo_ai_row( $sseo_ai_terms, 'term_id', $sseo_ai_t['cat2'], array( 'title' => 'Ai cat two' ) );
	$sseo_ai_row( $sseo_ai_terms, 'term_id', $sseo_ai_t['tag'], array( 'description' => 'Tag desc' ) );
	$sseo_ai_row( $sseo_ai_terms, 'term_id', $sseo_ai_t['fmt'], array( 'title' => 'A format' ) );
	$sseo_ai_row( $sseo_ai_terms, 'term_id', 99999999, array( 'title' => 'Gone' ) );
	update_post_meta( $sseo_ai_2, Simple_SEO_Fields::meta_key( 'title' ), 'Own two' );
	update_post_meta( $sseo_ai_2, Simple_SEO_Fields::meta_key( 'keyphrase' ), 'Own ai focus' );
	update_post_meta( $sseo['posts']['ai_kw'], Simple_SEO_Fields::meta_key( 'keyphrase' ), 'Own kw focus' );
	update_term_meta( $sseo_ai_t['cat2'], Simple_SEO_Fields::meta_key( 'title' ), 'Own cat two' );
	$sseo_ai_rows = (int) $sseo_ai_db->get_var( "SELECT COUNT(*) FROM `{$sseo_ai_posts}`" );

	sseo_check( "AIOSEO's data found: settings, posts and terms with SEO fields", Simple_SEO_Aioseo_Import::found() && array( 'settings' => true, 'posts' => 8, 'terms' => 5 ) === Simple_SEO_Aioseo_Import::summary(), Simple_SEO_Aioseo_Import::summary() );
	Simple_SEO_Import::detect();
	sseo_check( 'the first admin request offers the import from AIOSEO', 'pending' === Simple_SEO_Import::state( 'aioseo' )['status'] );

	// Simple SEO's own choices, saved before the import.
	update_option( Simple_SEO_Titles::OPTION, array( 'templates' => array( 'post:page' => array( 'title' => 'Own page {title}' ) ) ) );
	update_option( Simple_SEO_Social::OPTION, array( 'x_account' => '@mine' ) );
	update_option( Simple_SEO_Special::OPTION, array( '404' => array( 'title' => 'Own 404' ) ) );
	update_option( Simple_SEO_Breadcrumbs::LABELS, array( 'home' => 'My home' ) );
	update_option( Simple_SEO_Breadcrumbs::OPTION, array( 'separator' => '/' ) );
	update_option( Simple_SEO_Cleanup::OPTION, array( 'feed_authors' => false ) );
	Simple_SEO_Settings::flush();

	// Settings.
	$sseo_ai_report = array();
	Simple_SEO_Aioseo_Import::settings( $sseo_ai_report );
	Simple_SEO_Settings::flush();
	$sseo_ai_titles = get_option( Simple_SEO_Titles::OPTION );
	sseo_check( 'the separator from its HTML entity', '|' === $sseo_ai_titles['separator'] );
	sseo_check(
		"templates in Simple SEO's syntax; Simple SEO's own one stays; ones equal to its defaults are not kept; a #word that is none of AIOSEO's tags stays text",
		array(
			'post:page'            => array( 'title' => 'Own page {title}', 'description' => 'About {title}, #1 choice #unknown_word' ),
			'post:post'            => array( 'title' => '{title} {sep} {site}' ),
			'author'               => array( 'title' => '{author} at {site}' ),
			'date'                 => array( 'title' => '{date} {sep} {site}' ),
			'search'               => array( 'title' => 'Search: {search} {sep} {site}' ),
			'archive:sseo_ai_book' => array( 'title' => '{title} Archive {sep} {site}' ),
			'term:category'        => array( 'title' => '{title} Archives {sep} {site}' ),
		) == $sseo_ai_titles['templates'], // phpcs:ignore Universal.Operators.StrictComparisons -- the order of slots is the importer's.
		$sseo_ai_titles['templates']
	);
	sseo_check( "a tag of AIOSEO's Simple SEO has none for is left out and counted, a custom field too; one in a kept template is not", array( 'post_modified_date' => 1, 'custom_field' => 1 ) == $sseo_ai_report['dropped'], $sseo_ai_report['dropped'] ); // phpcs:ignore Universal.Operators.StrictComparisons
	$sseo_ai_index = get_option( Simple_SEO_Indexing::OPTION );
	sseo_check( 'indexing: a kind not shown in search results, or with robots of its own, is hidden; the rest follow the global robots; attachment pages to their post', array( 'post:sseo_ai_book', 'archive:sseo_ai_book', 'post:attachment', 'term:post_tag', 'author', 'date' ) === $sseo_ai_index['noindex'] && Simple_SEO_Indexing::ATTACHMENTS_PARENT === $sseo_ai_index['attachments'], $sseo_ai_index ); // phpcs:ignore Universal.Operators.StrictComparisons
	sseo_check( "social networks: the default image by its address, Simple SEO's own X account stays", array( 'default_image' => $sseo_default, 'x_account' => '@mine' ) === get_option( Simple_SEO_Social::OPTION ) );
	sseo_check( "the home page with its image, Simple SEO's own 404 title stays", array( '404' => array( 'title' => 'Own 404' ), 'home' => array( 'title' => 'Welcome {sep} {site}', 'description' => 'All about {site}', 'image' => $sseo_og ) ) == get_option( Simple_SEO_Special::OPTION ), get_option( Simple_SEO_Special::OPTION ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$sseo_ai_bc = get_option( Simple_SEO_Breadcrumbs::OPTION );
	sseo_check( "breadcrumbs: Simple SEO's own separator stays, the current page left out, the posts page in", '/' === $sseo_ai_bc['separator'] && false === $sseo_ai_bc['show_current'] && true === $sseo_ai_bc['show_blog'], $sseo_ai_bc );
	sseo_check( "breadcrumb labels as AIOSEO had them; Simple SEO's own one stays", array( 'home' => 'My home', 'prefix' => 'You are here:' ) === get_option( Simple_SEO_Breadcrumbs::LABELS ) );
	sseo_check( 'the sitemap AIOSEO switched off', array( 'enabled' => false ) === get_option( Simple_SEO_Sitemaps::OPTION ) );
	$sseo_ai_clean = get_option( Simple_SEO_Cleanup::OPTION );
	sseo_check( "clean-up: the feeds AIOSEO switched off; Simple SEO's own choice stays; nothing without AIOSEO's crawl clean-up", '1' === $sseo_ai_clean['feed_comments'] && '1' === $sseo_ai_clean['feed_tags'] && '0' === $sseo_ai_clean['feed_authors'] && '0' === $sseo_ai_clean['feed_posts'] && '0' === $sseo_ai_clean['feed_categories'] && '0' === $sseo_ai_clean['feed_post_comments'], $sseo_ai_clean );
	sseo_check(
		'the report names what was taken and what Simple SEO has nothing for',
		array( 'separator' => 1, 'templates' => 7, 'indexing' => 1, 'attachments' => 1, 'special' => 3, 'default_image' => 1, 'breadcrumbs' => 1, 'labels' => 1, 'sitemaps' => 1, 'cleanup' => 2 ) == $sseo_ai_report['settings'] // phpcs:ignore Universal.Operators.StrictComparisons
			&& array( 'social_templates' => 1, 'breadcrumb_texts' => 1, 'verification' => 1, 'url_bases' => 1, 'robots_rules' => 1 ) == $sseo_ai_report['left'], // phpcs:ignore Universal.Operators.StrictComparisons
		$sseo_ai_report
	);
	$sseo_ai_again = array();
	Simple_SEO_Aioseo_Import::settings( $sseo_ai_again );
	sseo_check( 'run again: nothing more to take', ! isset( $sseo_ai_again['settings'] ), $sseo_ai_again );

	// Another set of AIOSEO's settings: noindex as the global robots, crawl clean-up off, no separator Simple SEO has.
	$sseo_ai_saved = array( get_option( 'aioseo_options' ), get_option( 'aioseo_options_dynamic' ) );
	$sseo_ai_own   = array();
	foreach ( array( Simple_SEO_Indexing::OPTION, Simple_SEO_Social::OPTION, Simple_SEO_Titles::OPTION, Simple_SEO_Cleanup::OPTION ) as $sseo_name ) {
		$sseo_ai_own[ $sseo_name ] = get_option( $sseo_name );
		delete_option( $sseo_name );
	}
	$sseo_ai_opts = json_decode( $sseo_ai_saved[0], true );
	$sseo_ai_opts['searchAppearance']['global']['separator']                              = 'custom';
	$sseo_ai_opts['searchAppearance']['advanced']['globalRobotsMeta']                     = array( 'default' => false, 'noindex' => true );
	$sseo_ai_opts['searchAppearance']['advanced']['crawlCleanup']['enable']               = false;
	$sseo_ai_opts['searchAppearance']['advanced']['removeCategoryBase']                   = false;
	$sseo_ai_opts['searchAppearance']['archives']['author']['advanced']['robotsMeta']     = array( 'default' => false, 'noindex' => false );
	$sseo_ai_opts['social']['facebook']['general']['defaultImageSourcePosts']             = 'featured';
	$sseo_ai_opts['tools']['robots']['enable']                                            = false;
	update_option( 'aioseo_options', wp_json_encode( $sseo_ai_opts ) );
	$sseo_ai_dyn = json_decode( $sseo_ai_saved[1], true );
	$sseo_ai_dyn['searchAppearance']['postTypes']['attachment']['redirectAttachmentUrls'] = 'disabled';
	update_option( 'aioseo_options_dynamic', wp_json_encode( $sseo_ai_dyn ) );
	Simple_SEO_Settings::flush();
	$sseo_ai_again = array();
	Simple_SEO_Aioseo_Import::settings( $sseo_ai_again );
	$sseo_ai_index = get_option( Simple_SEO_Indexing::OPTION );
	sseo_check(
		"with noindex as the global robots: every kind without robots of its own, a taxonomy AIOSEO has nothing for included, no archive of a type without one; a type's own default follows the global; the author archive's own index; attachment pages kept; AIOSEO's X account; no default image but AIOSEO's own; no clean-up without crawl clean-up; no rules of robots.txt while its editor is off",
		in_array( 'post:post', $sseo_ai_index['noindex'], true ) && in_array( 'post:page', $sseo_ai_index['noindex'], true ) && in_array( 'term:sseo_ai_room', $sseo_ai_index['noindex'], true ) && ! in_array( 'archive:post', $sseo_ai_index['noindex'], true ) && ! in_array( 'term:category', $sseo_ai_index['noindex'], true ) && ! in_array( 'author', $sseo_ai_index['noindex'], true )
			&& Simple_SEO_Indexing::ATTACHMENTS_KEEP === $sseo_ai_index['attachments'] && '@aisite' === get_option( Simple_SEO_Social::OPTION )['x_account'] && empty( get_option( Simple_SEO_Social::OPTION )['default_image'] )
			&& ! isset( $sseo_ai_again['settings']['separator'] ) && ! isset( $sseo_ai_again['settings']['cleanup'] ) && ! isset( $sseo_ai_again['left']['url_bases'] ) && ! isset( $sseo_ai_again['left']['robots_rules'] ),
		array( $sseo_ai_index, $sseo_ai_again )
	);
	update_option( 'aioseo_options', $sseo_ai_saved[0] );
	update_option( 'aioseo_options_dynamic', $sseo_ai_saved[1] );
	foreach ( $sseo_ai_own as $sseo_name => $sseo_value ) {
		update_option( $sseo_name, $sseo_value );
	}
	Simple_SEO_Settings::flush();

	// Posts.
	$sseo_ai_report = array();
	$sseo_ai_next   = Simple_SEO_Aioseo_Import::posts( 0, 1, $sseo_ai_report );
	sseo_check( 'posts in batches, by ID', $sseo_ai_1 === $sseo_ai_next && 1 === $sseo_ai_report['post']['seen'] );
	$sseo_ai_next = Simple_SEO_Aioseo_Import::posts( $sseo_ai_next, 100, $sseo_ai_report );
	sseo_check( 'the last batch ends with the last post with something to take, then nothing is left', $sseo['posts']['ai_add'] === $sseo_ai_next && 0 === Simple_SEO_Aioseo_Import::posts( $sseo_ai_next, 100, $sseo_ai_report ) );
	$sseo_ai_fields = array();
	foreach ( array( 'title', 'description', 'og_title', 'og_image', 'index', 'nofollow', 'canonical', 'primary_category', 'keyphrase', 'keyphrases' ) as $sseo_key ) {
		$sseo_ai_fields[ $sseo_key ] = Simple_SEO_Fields::post_value( $sseo_ai_1, $sseo_key );
	}
	sseo_check(
		"a post's fields: texts in Simple SEO's syntax, the custom image by its address, its own robots, canonical, primary category, the focus keyword and the additional ones on lines from AIOSEO 5.0's columns, not the older JSON",
		array( 'title' => '{title} {sep} {site}', 'description' => 'Desc here', 'og_title' => 'OG {title}', 'og_image' => (string) $sseo_og, 'index' => 'noindex', 'nofollow' => '1', 'canonical' => 'https://example.com/ai-one/', 'primary_category' => (string) $sseo_news, 'keyphrase' => 'one', 'keyphrases' => "two\nтри слова" ) === $sseo_ai_fields,
		$sseo_ai_fields
	);
	sseo_check( "Simple SEO's own field stays; its own robots without noindex is index; an image elsewhere is not taken; an image AIOSEO did not pick itself is not taken", 'Own two' === Simple_SEO_Fields::post_value( $sseo_ai_2, 'title' ) && 'index' === Simple_SEO_Fields::post_value( $sseo_ai_2, 'index' ) && '' === Simple_SEO_Fields::post_value( $sseo_ai_2, 'nofollow' ) && '' === Simple_SEO_Fields::post_value( $sseo_ai_2, 'og_image' ) && 'Three' === Simple_SEO_Fields::post_value( $sseo['posts']['ai_3'], 'title' ) && '' === Simple_SEO_Fields::post_value( $sseo['posts']['ai_3'], 'og_image' ) );
	sseo_check( 'a page: its title, no primary category; a post type without SEO fields is left alone', 'Page T' === Simple_SEO_Fields::post_value( $sseo['posts']['ai_page'], 'title' ) && '' === Simple_SEO_Fields::post_value( $sseo['posts']['ai_page'], 'primary_category' ) && '' === Simple_SEO_Fields::post_value( $sseo['posts']['ai_menu'], 'title' ) );
	$sseo_vals = array();
	foreach ( array( $sseo_ai_2, $sseo['posts']['ai_3'], $sseo['posts']['ai_kw'], $sseo['posts']['ai_fk'], $sseo['posts']['ai_add'] ) as $sseo_id ) {
		$sseo_vals[] = array( Simple_SEO_Fields::post_value( $sseo_id, 'keyphrase' ), Simple_SEO_Fields::post_value( $sseo_id, 'keyphrases' ) );
	}
	sseo_check(
		"Simple SEO's own focus keyphrase stays; each column falls back on the older JSON on its own; older rows' keyphrases; the focus keyword alone; the additional keywords alone",
		array( array( 'Own ai focus', 'two more' ), array( 'three focus', '' ), array( 'Own kw focus', 'ключ' ), array( 'фокус', '' ), array( '', 'слово' ) ) === $sseo_vals,
		$sseo_vals
	);
	sseo_check( 'the report of the posts', array( 'seen' => 7, 'fields' => 18, 'changed' => 7, 'kept' => 2, 'refused' => 1 ) == $sseo_ai_report['post'] && array( 'page_number' => 1, 'post_modified_date' => 1 ) == $sseo_ai_report['dropped'], $sseo_ai_report ); // phpcs:ignore Universal.Operators.StrictComparisons
	sseo_check( "AIOSEO's rows are left as they were", $sseo_ai_rows === (int) $sseo_ai_db->get_var( "SELECT COUNT(*) FROM `{$sseo_ai_posts}`" ) && 'Ai two' === $sseo_ai_db->get_var( $sseo_ai_db->prepare( "SELECT title FROM `{$sseo_ai_posts}` WHERE post_id = %d", $sseo_ai_2 ) ) );

	// Terms, from the table of AIOSEO Pro.
	$sseo_ai_report = array();
	$sseo_ai_next   = Simple_SEO_Aioseo_Import::terms( 0, 2, $sseo_ai_report );
	$sseo_ai_steps  = array( $sseo_ai_next );
	while ( $sseo_ai_next && count( $sseo_ai_steps ) < 10 ) {
		$sseo_ai_next    = Simple_SEO_Aioseo_Import::terms( $sseo_ai_next, 2, $sseo_ai_report );
		$sseo_ai_steps[] = $sseo_ai_next;
	}
	sseo_check( 'terms in batches, by ID', array( $sseo_ai_t['cat'], $sseo_ai_t['fmt'], 99999999, 0 ) === $sseo_ai_steps, $sseo_ai_steps );
	$sseo_ai_fields = array();
	foreach ( array( 'title', 'description', 'index', 'nofollow', 'canonical' ) as $sseo_key ) {
		$sseo_ai_fields[ $sseo_key ] = Simple_SEO_Fields::term_value( $sseo_ai_t['cat'], $sseo_key );
	}
	sseo_check( "a term's fields: #taxonomy_title is the term's own title, its robots, canonical; columns the table lacks are skipped", array( 'title' => '{title} best {sep} {site}', 'description' => 'Cat desc', 'index' => 'noindex', 'nofollow' => '', 'canonical' => 'https://example.com/ai-cat/' ) === $sseo_ai_fields, $sseo_ai_fields );
	sseo_check( "Simple SEO's own field stays; terms that are gone, or of a taxonomy without SEO fields, are left alone", 'Own cat two' === Simple_SEO_Fields::term_value( $sseo_ai_t['cat2'], 'title' ) && 'Tag desc' === Simple_SEO_Fields::term_value( $sseo_ai_t['tag'], 'description' ) && '' === Simple_SEO_Fields::term_value( $sseo_ai_t['fmt'], 'title' ) );
	sseo_check( 'the report of the terms; a keyphrase of a term is no field', array( 'seen' => 3, 'fields' => 5, 'changed' => 2, 'kept' => 1 ) == $sseo_ai_report['term'] && ! metadata_exists( 'term', $sseo_ai_t['cat'], Simple_SEO_Fields::meta_key( 'keyphrase' ) ), $sseo_ai_report ); // phpcs:ignore Universal.Operators.StrictComparisons
	sseo_check( 'what Simple SEO has nothing for, by posts', array( 'twitter' => 1, 'schema' => 1 ) == Simple_SEO_Aioseo_Import::left(), Simple_SEO_Aioseo_Import::left() ); // phpcs:ignore Universal.Operators.StrictComparisons

	// The whole run.
	Simple_SEO_Import::start( 'aioseo' );
	$sseo_ai_count = 0;
	do {
		$sseo_ai_state = Simple_SEO_Import::run( 'aioseo', 0.0 );
		++$sseo_ai_count;
	} while ( 'running' === $sseo_ai_state['status'] && $sseo_ai_count < 20 );
	sseo_check( 'a run of zero seconds still takes one batch, and goes on from where it stopped', 'done' === $sseo_ai_state['status'] && 5 === $sseo_ai_count, array( $sseo_ai_count, $sseo_ai_state['status'] ) );
	sseo_check( "run again: everything is Simple SEO's already, kept", ! isset( $sseo_ai_state['report']['settings'] ) && 0 === ( $sseo_ai_state['report']['post']['fields'] ?? 0 ) && 20 === $sseo_ai_state['report']['post']['kept'] && 6 === $sseo_ai_state['report']['term']['kept'] && 1 === $sseo_ai_state['report']['left']['robots_rules'] && 1 === $sseo_ai_state['report']['left']['schema'], $sseo_ai_state['report'] );

	// The admin.
	wp_set_current_user( $sseo_u['administrator'] );
	Simple_SEO_Import::add_admin_tab();
	foreach ( Simple_SEO_Import::SOURCES as $sseo_class ) {
		update_option( $sseo_class::STATE, array( 'status' => 'pending' ) );
	}
	set_current_screen( 'dashboard' );
	$sseo_html = sseo_capture( array( Simple_SEO_Import::class, 'notice' ) );
	sseo_check( 'the notice names every plugin with data to import', str_contains( $sseo_html, 'Simple SEO found the data of Yoast SEO, Rank Math, and All in One SEO on this site' ), $sseo_html );
	set_current_screen( 'toplevel_page_' . Simple_SEO_Admin::PAGE );
	$_GET['tab'] = 'import';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check( 'the tab: All in One SEO with what was found and its button', str_contains( $sseo_html, '<h2>All in One SEO</h2>' ) && str_contains( $sseo_html, 'Import from All in One SEO' ) && str_contains( $sseo_html, 'AIOSEO&#039;s #tags become' ) && str_contains( $sseo_html, 'posts with SEO fields: 8' ) && str_contains( $sseo_html, 'name="simple_seo_source" value="aioseo"' ), $sseo_html );
	update_option( Simple_SEO_Aioseo_Import::STATE, array( 'status' => 'done', 'finished' => time(), 'report' => array( 'dropped' => array( 'page_number' => 2 ), 'left' => array( 'robots_rules' => 1 ) ) ) );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'], $GLOBALS['current_screen'] );
	wp_set_current_user( 0 );
	sseo_check( "after a run: AIOSEO's tags as AIOSEO writes them, and the rules of robots.txt", str_contains( $sseo_html, '#page_number (2)' ) && str_contains( $sseo_html, 'the rules added to robots.txt' ), $sseo_html );
	update_option( Simple_SEO_Aioseo_Import::STATE, $sseo_ai_state );
	foreach ( array( Simple_SEO_Yoast_Import::STATE, Simple_SEO_Rank_Math_Import::STATE ) as $sseo_name ) {
		delete_option( $sseo_name );
	}

	// The table of AIOSEO 4, before its keyword columns.
	$sseo_ai_db->query( "ALTER TABLE `{$sseo_ai_posts}` DROP COLUMN focus_keyword, DROP COLUMN additional_keywords" );
	sseo_check( 'a table of AIOSEO 4, without the keyword columns: posts found by the rest, keyphrases by the older JSON', 6 === Simple_SEO_Aioseo_Import::summary()['posts'], Simple_SEO_Aioseo_Import::summary() );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'AIOSEO clean-up' );

	// AIOSEO's leftovers: those of the import section and these.
	$sseo_ai_db->query( "CREATE TABLE IF NOT EXISTS `{$sseo_ai_db->prefix}aioseo_cache` ( id BIGINT NOT NULL PRIMARY KEY )" );
	$sseo_ai_db->query( "CREATE TABLE IF NOT EXISTS `{$sseo_ai_db->prefix}sseo_aioseo_keep` ( id INT NOT NULL PRIMARY KEY )" );
	update_option( 'aioseo_options_internal', wp_json_encode( array( 'internal' => array( 'lastActiveVersion' => '5.0.5.1' ) ) ) );
	update_option( 'aioseo_migrations_log', 'x' );
	update_option( 'sseo_aioseo_keep', 'x' );
	set_transient( 'aioseo_sseo', 'x', HOUR_IN_SECONDS );
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aioseo_sseo_daily' );
	wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, 'aioseo_sseo_once', array( 1 ) );
	wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, 'aioseo_sseo_once', array( 2 ) );
	wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, 'sseo_keep_hook' );
	get_role( 'administrator' )->add_cap( 'aioseo_manage_seo' );
	get_role( 'editor' )->add_cap( 'aioseo_page_analysis' );
	get_role( 'editor' )->add_cap( 'sseo_keep_cap' );
	$sseo_ac_user = (int) $sseo_u['administrator'];
	update_user_meta( $sseo_ac_user, '_aioseo_plugin_review_dismissed', '1' );
	update_user_meta( $sseo_ac_user, 'aioseo_sseo', 'x' );
	update_user_meta( $sseo_ac_user, 'sseo_keep', 'x' );
	update_post_meta( $sseo_ai_1, 'sseo_keep', 'x' );
	// AIOSEO's copies of the fields in post meta: one batch and one row more.
	update_post_meta( $sseo_ai_1, '_aioseo_title', '#post_title #separator_sa #site_title' );
	$sseo_ai_db->query( "INSERT INTO {$sseo_ai_db->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ',', array_map( static fn( int $i ): string => "( {$sseo_ai_1}, '_aioseo_filler_{$i}', 'x' )", range( 1, Simple_SEO_Aioseo_Cleanup::BATCH ) ) ) );
	wp_cache_delete( $sseo_ai_1, 'post_meta' );
	$sseo_ac_tables  = (array) $sseo_ai_db->get_col( $sseo_ai_db->prepare( 'SHOW TABLES LIKE %s', $sseo_ai_db->esc_like( $sseo_ai_db->prefix . 'aioseo_' ) . '%' ) );
	$sseo_ac_options = (int) $sseo_ai_db->get_var( "SELECT COUNT(*) FROM {$sseo_ai_db->options} WHERE option_name LIKE 'aioseo\\_%' OR option_name LIKE '\\_transient\\_%aioseo%'" ) - 2;

	// When it may run.
	$sseo_ac_active = static fn( $plugins ): array => array_merge( (array) $plugins, array( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) );
	update_option( Simple_SEO_Aioseo_Import::STATE, array( 'status' => 'pending' ) );
	$sseo_vals = array( Simple_SEO_Aioseo_Cleanup::blocked() );
	update_option( Simple_SEO_Aioseo_Import::STATE, array( 'status' => 'done' ) );
	$sseo_vals[] = Simple_SEO_Aioseo_Cleanup::blocked();
	add_filter( 'option_active_plugins', $sseo_ac_active );
	$sseo_vals[] = Simple_SEO_Aioseo_Cleanup::blocked();
	remove_filter( 'option_active_plugins', $sseo_ac_active );
	sseo_check( 'only after the import, and never while AIOSEO is active', array( 'import', '', 'active' ) === $sseo_vals, $sseo_vals );

	// What is left.
	$sseo_ac_parts = Simple_SEO_Aioseo_Cleanup::preview();
	sseo_check(
		"what is left of AIOSEO's, by part: tables, the rest of its options with its tasks and the capabilities of roles, settings, user and post meta",
		array( 'tables', 'internals', 'settings', 'usermeta', 'postmeta' ) === array_keys( $sseo_ac_parts ) && count( $sseo_ac_tables ) === $sseo_ac_parts['tables']['rows'] && 2 === $sseo_ac_parts['settings']['rows'] && 2 === $sseo_ac_parts['usermeta']['rows'] && Simple_SEO_Aioseo_Cleanup::BATCH + 1 === $sseo_ac_parts['postmeta']['rows'] && $sseo_ac_options + 3 + 2 === $sseo_ac_parts['internals']['rows'],
		array( $sseo_ac_parts, $sseo_ac_options )
	);
	$sseo_ac_theme = get_theme_root() . '/sseo-ac-theme';
	wp_mkdir_p( $sseo_ac_theme . '/inc' );
	file_put_contents( $sseo_ac_theme . '/inc/seo.php', "<?php \$row = \$wpdb->get_row( \"SELECT title FROM {\$wpdb->prefix}aioseo_posts\" ); \$t = get_post_meta( 1, '_aioseo_title', true );" );
	file_put_contents( $sseo_ac_theme . '/inc/schema.php', "<?php \$o = json_decode( get_option( 'aioseo_options' ), true );" );
	$sseo_ac_dir = static fn(): string => $sseo_ac_theme;
	add_filter( 'stylesheet_directory', $sseo_ac_dir );
	$sseo_vals = Simple_SEO_Aioseo_Cleanup::theme_reads( array( 'tables', 'internals', 'settings', 'usermeta', 'postmeta' ) );
	sseo_check( "the active theme's files that read AIOSEO's tables, settings or post meta", array( 'tables' => array( 'sseo-ac-theme/inc/seo.php' ), 'settings' => array( 'sseo-ac-theme/inc/schema.php' ), 'postmeta' => array( 'sseo-ac-theme/inc/seo.php' ) ) == $sseo_vals, $sseo_vals ); // phpcs:ignore Universal.Operators.StrictComparisons

	// The page and the form.
	wp_set_current_user( $sseo_ac_user );
	set_current_screen( 'toplevel_page_' . Simple_SEO_Admin::PAGE );
	$_GET['tab'] = 'import';
	$sseo_html   = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	sseo_check(
		"the tab offers AIOSEO's parts; what the theme reads is unticked",
		str_contains( $sseo_html, 'Remove the data of All in One SEO' ) && str_contains( $sseo_html, 'AIOSEO&#039;s copies of the fields of posts' ) && (bool) preg_match( '/value="tables"\s*\/>/', $sseo_html ) && (bool) preg_match( '/value="usermeta"\s+checked=\'checked\'/', $sseo_html ) && str_contains( $sseo_html, 'id="simple-seo-cleanup-aioseo"' ),
		$sseo_html
	);
	remove_filter( 'stylesheet_directory', $sseo_ac_dir );
	$sseo_vals = array(
		Simple_SEO_Import::act( 'clean', array( 'simple_seo_source' => 'aioseo', 'simple_seo_groups' => array( 'tables' ) ) ),
		Simple_SEO_Import::act( 'clean', array( 'simple_seo_source' => 'aioseo', 'simple_seo_groups' => array( 'termmeta' ), 'simple_seo_backup' => '1' ) ),
	);
	sseo_check( 'refused without a backup, and without a part AIOSEO has', array( 'backup', 'groups' ) === $sseo_vals && array() === Simple_SEO_Aioseo_Cleanup::state(), $sseo_vals );

	// A run.
	$sseo_vals = array( get_post_meta( $sseo_ai_1, '_aioseo_title', true ), get_user_meta( $sseo_ac_user, 'aioseo_sseo', true ) );
	Simple_SEO_Aioseo_Cleanup::start( array( 'tables', 'internals', 'usermeta', 'postmeta' ) );
	$sseo_ac_steps = 0;
	do {
		$sseo_ac_state = Simple_SEO_Aioseo_Cleanup::run( 0.0 );
		++$sseo_ac_steps;
		if ( 4 === $sseo_ac_steps ) {
			$sseo_ac_batch = (int) $sseo_ai_db->get_var( "SELECT COUNT(*) FROM {$sseo_ai_db->postmeta} WHERE meta_key LIKE '\\_aioseo\\_%'" );
		}
	} while ( 'running' === $sseo_ac_state['status'] && $sseo_ac_steps < 20 );
	sseo_check( 'one step per part, the post meta in batches', 'done' === $sseo_ac_state['status'] && 5 === $sseo_ac_steps && 1 === $sseo_ac_batch, array( $sseo_ac_steps, $sseo_ac_batch ) );
	$sseo_ac_caps = array();
	foreach ( wp_roles()->role_objects as $sseo_role ) {
		foreach ( array_keys( (array) $sseo_role->capabilities ) as $sseo_name ) {
			if ( str_starts_with( (string) $sseo_name, 'aioseo_' ) ) {
				$sseo_ac_caps[] = $sseo_name;
			}
		}
	}
	$sseo_ac_left = array(
		'tables'   => (array) $sseo_ai_db->get_col( $sseo_ai_db->prepare( 'SHOW TABLES LIKE %s', $sseo_ai_db->esc_like( $sseo_ai_db->prefix . 'aioseo_' ) . '%' ) ),
		'options'  => (array) $sseo_ai_db->get_col( "SELECT option_name FROM {$sseo_ai_db->options} WHERE option_name LIKE 'aioseo\\_%' OR option_name LIKE '\\_transient\\_%aioseo%'" ),
		'caps'     => $sseo_ac_caps,
		'usermeta' => (int) $sseo_ai_db->get_var( "SELECT COUNT(*) FROM {$sseo_ai_db->usermeta} WHERE meta_key LIKE '\\_aioseo%' OR meta_key LIKE 'aioseo%'" ),
		'postmeta' => (int) $sseo_ai_db->get_var( "SELECT COUNT(*) FROM {$sseo_ai_db->postmeta} WHERE meta_key LIKE '\\_aioseo\\_%'" ),
	);
	sort( $sseo_ac_left['options'] );
	sseo_check( "AIOSEO's tables, other options, tasks, capabilities and meta are gone; its settings stay, as they were not ticked", array( 'tables' => array(), 'options' => array( 'aioseo_options', 'aioseo_options_dynamic' ), 'caps' => array(), 'usermeta' => 0, 'postmeta' => 0 ) === $sseo_ac_left && false === get_transient( 'aioseo_sseo' ) && false === wp_next_scheduled( 'aioseo_sseo_daily' ) && false === wp_next_scheduled( 'aioseo_sseo_once', array( 2 ) ), $sseo_ac_left );
	sseo_check(
		'what is not AIOSEO\'s stays, Simple SEO\'s imported fields included',
		'x' === get_option( 'sseo_aioseo_keep' ) && (bool) wp_next_scheduled( 'sseo_keep_hook' ) && get_role( 'editor' )->has_cap( 'sseo_keep_cap' ) && 'x' === get_user_meta( $sseo_ac_user, 'sseo_keep', true ) && 'x' === get_post_meta( $sseo_ai_1, 'sseo_keep', true )
			&& '{title} {sep} {site}' === Simple_SEO_Fields::post_value( $sseo_ai_1, 'title' ) && 'Cat desc' === Simple_SEO_Fields::term_value( $sseo_ai_t['cat'], 'description' )
			&& array( $sseo_ai_db->prefix . 'sseo_aioseo_keep' ) === $sseo_ai_db->get_col( $sseo_ai_db->prepare( 'SHOW TABLES LIKE %s', $sseo_ai_db->esc_like( $sseo_ai_db->prefix . 'sseo_aioseo_keep' ) ) )
	);
	sseo_check( 'the cached meta of the posts and users goes too', array( '#post_title #separator_sa #site_title', 'x' ) === $sseo_vals && '' === get_post_meta( $sseo_ai_1, '_aioseo_title', true ) && '' === get_user_meta( $sseo_ac_user, 'aioseo_sseo', true ) );
	sseo_check( 'the report counts what went', array( 'tables' => count( $sseo_ac_tables ), 'options' => $sseo_ac_options, 'cron' => 3, 'caps' => 2, 'usermeta' => 2, 'postmeta' => Simple_SEO_Aioseo_Cleanup::BATCH + 1 ) == $sseo_ac_state['report'], $sseo_ac_state['report'] ); // phpcs:ignore Universal.Operators.StrictComparisons
	sseo_check( 'without its table, nothing of AIOSEO is found of posts and terms', array( 'settings' => true, 'posts' => 0, 'terms' => 0 ) === Simple_SEO_Aioseo_Import::summary() && array() === Simple_SEO_Aioseo_Import::left() );
	$sseo_vals = Simple_SEO_Import::act( 'clean', array( 'simple_seo_source' => 'aioseo', 'simple_seo_groups' => array( 'settings' ), 'simple_seo_backup' => '1' ) );
	$sseo_html = sseo_capture( array( Simple_SEO_Admin::class, 'render_page' ) );
	unset( $_GET['tab'], $GLOBALS['current_screen'] );
	wp_set_current_user( 0 );
	sseo_check( 'the settings, on their own; then nothing is left, and the report', '' === $sseo_vals && 2 === Simple_SEO_Aioseo_Cleanup::state()['report']['settings'] && array() === Simple_SEO_Aioseo_Cleanup::preview() && str_contains( $sseo_html, 'No data of All in One SEO is left on this site.' ) && str_contains( $sseo_html, 'Removed: rows of settings: 2.' ), $sseo_html );

	$sseo_ai_db->query( "DROP TABLE IF EXISTS `{$sseo_ai_db->prefix}sseo_aioseo_keep`" );
	delete_option( 'sseo_aioseo_keep' );
	wp_clear_scheduled_hook( 'sseo_keep_hook' );
	get_role( 'editor' )->remove_cap( 'sseo_keep_cap' );
	delete_user_meta( $sseo_ac_user, 'sseo_keep' );
	foreach ( array( '/inc/seo.php', '/inc/schema.php', '/inc', '' ) as $sseo_name ) {
		$sseo_name = $sseo_ac_theme . $sseo_name;
		is_dir( $sseo_name ) ? rmdir( $sseo_name ) : unlink( $sseo_name );
	}
	foreach ( array_merge( $sseo_yi_rows, array( Simple_SEO_Aioseo_Import::STATE, Simple_SEO_Aioseo_Cleanup::OPTION ) ) as $sseo_name ) {
		delete_option( $sseo_name );
	}
	Simple_SEO_Settings::install();
	Simple_SEO_Settings::flush();
	unregister_taxonomy( 'sseo_ai_shelf' );
	unregister_taxonomy( 'sseo_ai_room' );
	unregister_post_type( 'sseo_ai_book' );
	Simple_SEO_Fields::flush_types();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Translation' );

	$sseo_mo = SIMPLE_SEO_DIR . 'languages/simple-seo-uk.mo';
	sseo_check( 'the Ukrainian translation exists', is_readable( $sseo_mo ) );
	unload_textdomain( 'simple-seo' );
	load_textdomain( 'simple-seo', $sseo_mo );
	sseo_check( 'a field label is translated', 'SEO-заголовок' === __( 'SEO title', 'simple-seo' ), __( 'SEO title', 'simple-seo' ) );
	sseo_check( 'a tab name is translated', 'Доступ' === __( 'Access', 'simple-seo' ) && 'Заголовки' === __( 'Titles', 'simple-seo' ), __( 'Titles', 'simple-seo' ) );
	sseo_check( 'the structure button is translated', 'Вставити структуру' === __( 'Insert structure', 'simple-seo' ) );
	sseo_check( 'the Indexing tab is translated', 'Індексація' === __( 'Indexing', 'simple-seo' ) );
	sseo_check( 'the Social networks tab is translated', 'Соцмережі' === __( 'Social networks', 'simple-seo' ) );
	sseo_check( 'the Special pages tab is translated', 'Спецсторінки' === __( 'Special pages', 'simple-seo' ) );
	sseo_check( 'the sitemap switch is translated', 'Створювати XML-карту сайту' === __( 'Create an XML sitemap', 'simple-seo' ) );
	sseo_check( 'the breadcrumbs are translated', 'Хлібні крихти' === __( 'Breadcrumbs', 'simple-seo' ) && 'Головна' === __( 'Home', 'simple-seo' ) && '%s у хлібних крихтах' === __( '%s in breadcrumbs', 'simple-seo' ) );
	sseo_check( 'the llms.txt tab is translated', 'Посилань у розділі' === __( 'Links in a section', 'simple-seo' ) && 'Інші мови:' === __( 'Other languages:', 'simple-seo' ) );
	sseo_check( 'the Clean-up tab is translated', 'Чистка коду' === __( 'Clean-up', 'simple-seo' ) && 'Версія WordPress' === __( 'WordPress version', 'simple-seo' ) );
	sseo_check( 'the Files tab is translated', 'Файли' === __( 'Files', 'simple-seo' ) && 'Історія' === __( 'History', 'simple-seo' ) );
	sseo_check( 'the Import tab is translated', 'Імпорт' === __( 'Import', 'simple-seo' ) && 'Імпортувати з %s' === __( 'Import from %s', 'simple-seo' ) && 'шаблони: %s' === __( 'templates: %s', 'simple-seo' ) && 'Прибрати дані %s' === __( 'Remove the data of %s', 'simple-seo' ) );
	sseo_check( 'the search result preview is translated', 'Вигляд у пошуковій видачі' === __( 'Search result preview', 'simple-seo' ) && 'SEO-заголовок: символів — %s, пошуковик його обріже.' === __( 'SEO title: %s characters, search engines will cut it.', 'simple-seo' ) );
	sseo_check( 'the content analysis is translated', 'Аналіз контенту' === __( 'Content analysis', 'simple-seo' ) && 'SEO-оцінка: %s зі 100' === __( 'SEO score: %s of 100', 'simple-seo' ) );
	sseo_check( 'a string with context is translated', 'F Y' === _x( 'F Y', 'month archive date format', 'simple-seo' ) && 'Сторінка %1$d з %2$d' === __( 'Page %1$d of %2$d', 'simple-seo' ) );
	unload_textdomain( 'simple-seo' );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Uninstall' );

	update_option( 'simple_seo_sseo_extra', array( 'x' => 1 ) );
	file_put_contents( get_home_path() . Simple_SEO_Files::DISK_COPY, "# copy\n" );
	set_transient( 'simple_seo_llms_default', 'x', DAY_IN_SECONDS );
	// uninstall.php runs without the plugin: none of its hooks drop the cache on the way.
	foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $sseo_name ) {
		remove_action( $sseo_name, array( Simple_SEO_Llms::class, 'option_changed' ) );
	}
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'simple-seo/simple-seo.php' );
	}
	include SIMPLE_SEO_DIR . 'uninstall.php';
	$sseo_left = $GLOBALS['wpdb']->get_col( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'simple\\_seo\\_%'" );
	sseo_check( 'uninstall removes every simple_seo_ option', array() === $sseo_left, $sseo_left );
	sseo_check( 'uninstall removes the copy of .htaccess', ! is_file( get_home_path() . Simple_SEO_Files::DISK_COPY ) );
	sseo_check( 'uninstall removes the cached llms.txt', array() === $GLOBALS['wpdb']->get_col( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\\_transient%simple\\_seo\\_%'" ) );
	sseo_check( 'uninstall leaves the options cache consistent', null === get_option( Simple_SEO_Plugin::VERSION_OPTION, null ) && null === get_option( Simple_SEO_Access::OPTION, null ) );
	sseo_check( 'uninstall keeps the SEO fields of posts', 'Welcome {sep} {site}' === Simple_SEO_Fields::post_value( $sseo_p['front'], 'title' ) );
	sseo_check( 'uninstall keeps the SEO fields of terms', 'Saved from the term form' === Simple_SEO_Fields::term_value( $sseo_cat_id, 'title' ) );
	Simple_SEO_Settings::flush();
	Simple_SEO_Plugin::maybe_upgrade();
	sseo_check( 'the next request installs the rows again', null !== get_option( Simple_SEO_Access::OPTION, null ) && SIMPLE_SEO_VERSION === get_option( Simple_SEO_Plugin::VERSION_OPTION ) );
} catch ( Throwable $sseo_error ) {
	sseo_check( 'no exception', false, get_class( $sseo_error ) . ': ' . $sseo_error->getMessage() . ' at ' . $sseo_error->getFile() . ':' . $sseo_error->getLine() );
} finally {
	$sseo_cleanup();
}

$sseo_r = $GLOBALS['sseo_results'];
echo "\n{$sseo_r['pass']} passed, {$sseo_r['fail']} failed\n";
if ( $sseo_r['fail'] > 0 ) {
	WP_CLI::halt( 1 );
}
