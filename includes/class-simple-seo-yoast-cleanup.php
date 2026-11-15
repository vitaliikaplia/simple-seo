<?php
/**
 * What Yoast SEO leaves in the database: its tables {prefix}yoast_*, its options wpseo* and yoast*
 * with their cached data, its scheduled tasks, its settings (with wp-loc's copies for other
 * languages), its user meta and its post meta _yoast_*. Yoast keeps the SEO of terms in one of its
 * settings and takes its capabilities away from roles itself when deactivated. How they are
 * removed: Simple_SEO_Source_Cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Yoast_Cleanup extends Simple_SEO_Source_Cleanup {
	public const OPTION = 'simple_seo_yoast_cleanup';

	public const IMPORT = 'Simple_SEO_Yoast_Import';

	protected const TABLES = 'yoast_';

	protected const CRON = '/^(?:wpseo|yoast)/i';

	/** Themes read Yoast's fields of posts through Simple SEO's meta aliases; its settings, only as they are. */
	protected const THEME_READS = array( 'settings' => '/wpseo_titles|wpseo_social|wpseo_taxonomy_meta|WPSEO_Options|get_option\(\s*[\'"]wpseo[\'"]/' );

	/** Yoast's rows of settings: what the import takes from, and what themes may read. */
	private const SETTINGS = array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_taxonomy_meta' );

	protected static function active(): bool {
		return defined( 'WPSEO_VERSION' ) || is_plugin_active( 'wordpress-seo/wp-seo.php' );
	}

	protected static function options_sql(): string {
		return "( option_name = 'wpseo' OR option_name LIKE 'wpseo\\_%' OR option_name LIKE 'wpseo-%' OR option_name LIKE 'yoast\\_%' OR option_name LIKE 'yoast-%'"
			. " OR option_name LIKE '\\_transient\\_wpseo%' OR option_name LIKE '\\_transient\\_timeout\\_wpseo%' OR option_name LIKE '\\_transient\\_yoast%' OR option_name LIKE '\\_transient\\_timeout\\_yoast%'"
			. " OR option_name LIKE '\\_site\\_transient\\_wpseo%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_wpseo%' )";
	}

	protected static function is_setting( string $name ): bool {
		return in_array( $name, self::SETTINGS, true ) || str_starts_with( $name, 'wpseo_titles_' );
	}

	protected static function meta_sql( string $type ): string {
		global $wpdb;

		if ( 'post' === $type ) {
			return "meta_key LIKE '\\_yoast\\_%'";
		}

		// Users: SEO titles of author archives and editor settings (wpseo…), notices (_yoast…, {prefix}yoast_notifications).
		return "( meta_key LIKE 'wpseo%' OR meta_key LIKE '\\_yoast\\_%' OR meta_key = '" . esc_sql( $wpdb->get_blog_prefix() . 'yoast_notifications' ) . "' )";
	}

	public static function labels(): array {
		return array(
			'tables'    => __( 'Yoast\'s tables: its index of pages, links, primary terms and the like', 'simple-seo' ),
			'internals' => __( 'Yoast\'s other options, cached data and scheduled tasks', 'simple-seo' ),
			'settings'  => __( 'Yoast\'s settings: titles, social networks, the SEO of terms (with wp-loc, the copies for other languages)', 'simple-seo' ),
			'usermeta'  => __( 'Yoast\'s data of users: SEO titles of author archives, editor preferences, notices', 'simple-seo' ),
			'postmeta'  => __( 'Yoast\'s fields of posts: what the import took, focus keyphrases and the rest', 'simple-seo' ),
		);
	}
}
