<?php
/**
 * Remove plugin-owned settings when Simple SEO is deleted.
 *
 * The SEO fields of posts and terms (_simple_seo_* meta) stay: they are content the editors
 * wrote, and deleting the plugin to reinstall it must not wipe every title and description.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// delete_option() one by one keeps the options cache (and a persistent object cache) consistent.
$simple_seo_options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'simple\_seo\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( (array) $simple_seo_options as $simple_seo_option ) {
	delete_option( (string) $simple_seo_option );
}
unset( $simple_seo_options, $simple_seo_option );

delete_site_transient( 'simple_seo_github_update_data' );

// The copy of the previous .htaccess kept next to it (Simple_SEO_Files::DISK_COPY).
require_once ABSPATH . 'wp-admin/includes/file.php';
$simple_seo_copy = get_home_path() . '.htaccess.simple-seo-backup';
if ( is_file( $simple_seo_copy ) ) {
	wp_delete_file( $simple_seo_copy );
}
unset( $simple_seo_copy );
