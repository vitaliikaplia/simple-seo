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

// The cached llms.txt of each language (transients simple_seo_llms_*).
$simple_seo_transients = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_simple\\_seo\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( (array) $simple_seo_transients as $simple_seo_transient ) {
	delete_transient( substr( (string) $simple_seo_transient, strlen( '_transient_' ) ) );
}
unset( $simple_seo_transients, $simple_seo_transient );

delete_site_transient( 'simple_seo_github_update_data' );

// A batch of IndexNow waiting (Simple_SEO_IndexNow::EVENT).
wp_clear_scheduled_hook( 'simple_seo_indexnow_send' );

// The addresses of the monitor of 404s and its daily clean-up (Simple_SEO_Monitor).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}simple_seo_404" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
wp_clear_scheduled_hook( 'simple_seo_404_cleanup' );

// The report of the audit of pages and of the links on them, a step of it or a check of links
// waiting (Simple_SEO_Audit, Simple_SEO_Links).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}simple_seo_audit" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
wp_clear_scheduled_hook( 'simple_seo_audit_step' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}simple_seo_links" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
wp_clear_scheduled_hook( 'simple_seo_links_retry' );
// The alt texts of new images the AI has yet to write, one event for each image.
wp_unschedule_hook( 'simple_seo_ai_alt' );

// The copy of the previous .htaccess kept next to it (Simple_SEO_Files::DISK_COPY).
require_once ABSPATH . 'wp-admin/includes/file.php';
$simple_seo_copy = get_home_path() . '.htaccess.simple-seo-backup';
if ( is_file( $simple_seo_copy ) ) {
	wp_delete_file( $simple_seo_copy );
}
unset( $simple_seo_copy );
