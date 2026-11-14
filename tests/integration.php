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
	'titles'         => get_option( Simple_SEO_Titles::OPTION ),
	'indexing'       => get_option( Simple_SEO_Indexing::OPTION ),
	'social'         => get_option( Simple_SEO_Social::OPTION ),
	'blog_public'    => get_option( 'blog_public' ),
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
	sseo_check( 'fields come in editor order', array( 'title', 'description', 'og_title', 'og_description', 'og_image', 'index', 'nofollow', 'canonical', 'test_advanced', 'test_choice' ) === array_keys( Simple_SEO_Fields::all( 'post' ) ), array_keys( Simple_SEO_Fields::all( 'post' ) ) );

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
	remove_all_filters( 'simple_seo_head_tags' );
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
	sseo_check( 'meta box: what the page shows now', str_contains( $sseo_html, 'Now: Editor post – ' . esc_html( $sseo_site ) ) && str_contains( $sseo_html, 'Now: sibling saved in the same request' ) );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo_p['bare'] ) ) );
	sseo_check( 'meta box: no description says so', str_contains( $sseo_html, 'Now: none from Simple SEO.' ) );
	$sseo['posts']['draft'] = wp_insert_post( array( 'post_title' => 'Auto Draft', 'post_status' => 'auto-draft' ) );
	$sseo_html              = sseo_capture( static fn() => Simple_SEO_Editor::render_meta_box( get_post( $sseo['posts']['draft'] ) ) );
	sseo_check( 'meta box of a new post: no "Now"', ! str_contains( $sseo_html, 'Now:' ) && str_contains( $sseo_html, 'simple-seo-mask' ) );
	$sseo_html = sseo_capture( static fn() => Simple_SEO_Editor::render_term_fields( get_term( $sseo_cat_id ) ) );
	sseo_check( 'term screen: structure button and what the term shows now', str_contains( $sseo_html, 'data-mask="{excerpt}"' ) && str_contains( $sseo_html, 'Now: Saved from the term form' ) );

	foreach ( array( 'toplevel_page_simple-seo' => '', 'post.php' => 'post', 'edit.php' => 'edit-post', 'term.php' => 'edit-category' ) as $sseo_hook => $sseo_screen ) {
		wp_dequeue_script( 'simple-seo-admin' );
		if ( '' !== $sseo_screen ) {
			set_current_screen( $sseo_screen );
		}
		Simple_SEO_Admin::enqueue( $sseo_hook );
		$sseo_expected = 'edit.php' !== $sseo_hook;
		sseo_check( "admin.js " . ( $sseo_expected ? 'loads' : 'does not load' ) . " on {$sseo_hook}", $sseo_expected === wp_script_is( 'simple-seo-admin', 'enqueued' ) );
	}
	wp_dequeue_script( 'simple-seo-admin' );
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
	sseo_check( 'meta box: three choices for search engines', 3 === substr_count( $sseo_html, '<option value=' ) - substr_count( $sseo_html, 'name="simple_seo[test_choice]"' ) * 2 );
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
	sseo_check( 'a string with context is translated', 'F Y' === _x( 'F Y', 'month archive date format', 'simple-seo' ) && 'Сторінка %1$d з %2$d' === __( 'Page %1$d of %2$d', 'simple-seo' ) );
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
	sseo_check( 'uninstall keeps the SEO fields of posts', 'Welcome {sep} {site}' === Simple_SEO_Fields::post_value( $sseo_p['front'], 'title' ) );
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
	// A run broken by a mutation can leave the row empty; the next run then starts from the defaults.
	update_option( Simple_SEO_Access::OPTION, is_array( $sseo_saved_options['access'] ) && $sseo_saved_options['access'] ? $sseo_saved_options['access'] : Simple_SEO_Access::defaults() );
	// Rows that were not there go away (back to the defaults); a sanitizer keeps a saved state as it was.
	foreach ( array( Simple_SEO_Titles::OPTION => 'titles', Simple_SEO_Indexing::OPTION => 'indexing', Simple_SEO_Social::OPTION => 'social' ) as $sseo_name => $sseo_key ) {
		delete_option( $sseo_name );
		if ( is_array( $sseo_saved_options[ $sseo_key ] ) && $sseo_saved_options[ $sseo_key ] ) {
			add_option( $sseo_name, $sseo_saved_options[ $sseo_key ] );
		}
	}
	update_option( 'blog_public', '0' === (string) $sseo_saved_options['blog_public'] ? '0' : '1' );
}

$sseo_r = $GLOBALS['sseo_results'];
echo "\n{$sseo_r['pass']} passed, {$sseo_r['fail']} failed\n";
if ( $sseo_r['fail'] > 0 ) {
	WP_CLI::halt( 1 );
}
