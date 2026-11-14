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
);

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
	sseo_check( 'translated field labels are registered on init, not when plugins load', 5 === has_action( 'init', array( Simple_SEO_Fields::class, 'register_builtin' ) ) );
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
	sseo_check( 'fields come in editor order', array( 'title', 'description', 'test_advanced', 'test_choice' ) === array_keys( Simple_SEO_Fields::all( 'post' ) ), array_keys( Simple_SEO_Fields::all( 'post' ) ) );

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
	remove_all_filters( 'simple_seo_head_tags' );
	Simple_SEO_Head::remove_provider( 'sseo_test' );
	Simple_SEO_Head::remove_provider( 'sseo_test_late' );
	sseo_check( 'removed providers print nothing', '' === sseo_capture( array( Simple_SEO_Head::class, 'output' ) ) );

	$GLOBALS['wp_query'] = $sseo_saved_query;
	if ( null === $sseo_saved_wp_actions ) {
		unset( $GLOBALS['wp_actions']['wp'] );
	} else {
		$GLOBALS['wp_actions']['wp'] = $sseo_saved_wp_actions;
	}
	Simple_SEO_Context::reset();

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Translation' );

	$sseo_mo = SIMPLE_SEO_DIR . 'languages/simple-seo-uk.mo';
	sseo_check( 'the Ukrainian translation exists', is_readable( $sseo_mo ) );
	unload_textdomain( 'simple-seo' );
	load_textdomain( 'simple-seo', $sseo_mo );
	sseo_check( 'a field label is translated', 'SEO-заголовок' === __( 'SEO title', 'simple-seo' ), __( 'SEO title', 'simple-seo' ) );
	sseo_check( 'a tab name is translated', 'Доступ' === __( 'Access', 'simple-seo' ), __( 'Access', 'simple-seo' ) );
	unload_textdomain( 'simple-seo' );

	// ------------------------------------------------------------------------------------------
	sseo_section( 'Uninstall' );

	update_option( 'simple_seo_sseo_extra', array( 'x' => 1 ) );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'simple-seo/simple-seo.php' );
	}
	include SIMPLE_SEO_DIR . 'uninstall.php';
	$sseo_left = $GLOBALS['wpdb']->get_col( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'simple\\_seo\\_%'" );
	sseo_check( 'uninstall removes every simple_seo_ option', array() === $sseo_left, $sseo_left );
	sseo_check( 'uninstall leaves the options cache consistent', null === get_option( Simple_SEO_Plugin::VERSION_OPTION, null ) && null === get_option( Simple_SEO_Access::OPTION, null ) );
	sseo_check( 'uninstall keeps the SEO fields of posts', 'Only for the edited post' === Simple_SEO_Fields::post_value( $sseo_p['author_post'], 'title' ) );
	sseo_check( 'uninstall keeps the SEO fields of terms', 'Saved from the term form' === Simple_SEO_Fields::term_value( $sseo_cat_id, 'title' ) );
	Simple_SEO_Settings::flush();
	Simple_SEO_Plugin::maybe_upgrade();
	sseo_check( 'the next request installs the rows again', null !== get_option( Simple_SEO_Access::OPTION, null ) && SIMPLE_SEO_VERSION === get_option( Simple_SEO_Plugin::VERSION_OPTION ) );
} catch ( Throwable $sseo_error ) {
	sseo_check( 'no exception', false, get_class( $sseo_error ) . ': ' . $sseo_error->getMessage() . ' at ' . $sseo_error->getFile() . ':' . $sseo_error->getLine() );
} finally {
	$_POST = array();
	wp_set_current_user( 0 );
	foreach ( $sseo['posts'] as $sseo_id ) {
		wp_delete_post( (int) $sseo_id, true );
	}
	foreach ( $sseo['terms'] as $sseo_term ) {
		wp_delete_term( $sseo_term[0], $sseo_term[1] );
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
	update_option( Simple_SEO_Access::OPTION, is_array( $sseo_saved_options['access'] ) ? $sseo_saved_options['access'] : array() );
}

$sseo_r = $GLOBALS['sseo_results'];
echo "\n{$sseo_r['pass']} passed, {$sseo_r['fail']} failed\n";
if ( $sseo_r['fail'] > 0 ) {
	WP_CLI::halt( 1 );
}
