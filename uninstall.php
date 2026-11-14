<?php
/**
 * Remove plugin-owned data when Simple SEO is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'simple_seo_version' );
delete_site_transient( 'simple_seo_github_update_data' );
