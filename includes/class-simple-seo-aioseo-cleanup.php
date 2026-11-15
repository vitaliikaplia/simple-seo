<?php
/**
 * What All in One SEO leaves in the database: its tables {prefix}aioseo_* (the SEO fields of posts
 * among them, its cache, notifications), its options aioseo_* with their cached data, its scheduled
 * tasks, the capabilities aioseo_* it gives to roles and keeps after deactivation, its settings
 * (the JSON rows aioseo_options*), its user meta and the copies of the fields of posts it keeps in
 * post meta _aioseo_*. The tables of Action Scheduler, which AIOSEO brings along, are shared with
 * other plugins and stay. How the rows are removed: Simple_SEO_Source_Cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Aioseo_Cleanup extends Simple_SEO_Source_Cleanup {
	public const OPTION = 'simple_seo_aioseo_cleanup';

	public const IMPORT = 'Simple_SEO_Aioseo_Import';

	protected const TABLES = 'aioseo_';

	protected const CRON = '/^aioseo/i';

	protected const CAPS = '/^aioseo_/';

	/** Simple SEO has no aliases for AIOSEO's data: a theme that reads it would find nothing. */
	protected const THEME_READS = array(
		'tables'   => '/aioseo_(?:posts|terms)\b/',
		'settings' => '/aioseo_options/',
		'postmeta' => '/[\'"]_aioseo_[a-z_]+[\'"]/',
	);

	/** AIOSEO's rows of settings: what the import reads, and their copies for its other editions and languages. */
	private const SETTINGS = array( 'aioseo_options', 'aioseo_options_dynamic', 'aioseo_options_lite', 'aioseo_options_pro', 'aioseo_options_dynamic_pro', 'aioseo_options_localized' );

	protected static function active(): bool {
		return defined( 'AIOSEO_VERSION' ) || is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) || is_plugin_active( 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' );
	}

	protected static function options_sql(): string {
		return "( option_name LIKE 'aioseo\\_%' OR option_name LIKE 'aioseo-%'"
			. " OR option_name LIKE '\\_transient\\_aioseo%' OR option_name LIKE '\\_transient\\_timeout\\_aioseo%'"
			. " OR option_name LIKE '\\_site\\_transient\\_aioseo%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_aioseo%' )";
	}

	protected static function is_setting( string $name ): bool {
		return in_array( $name, self::SETTINGS, true );
	}

	protected static function meta_sql( string $type ): string {
		if ( 'post' === $type ) {
			return "meta_key LIKE '\\_aioseo\\_%'";
		}

		// Users: notices put off and the like.
		return "( meta_key LIKE '\\_aioseo%' OR meta_key LIKE 'aioseo%' )";
	}

	public static function labels(): array {
		return array(
			'tables'    => __( 'AIOSEO\'s tables: the SEO fields of posts (what the import took), its cache, notifications and the like', 'simple-seo' ),
			'internals' => __( 'AIOSEO\'s other options, cached data, scheduled tasks and the capabilities it gave to roles', 'simple-seo' ),
			'settings'  => __( 'AIOSEO\'s settings: search appearance, social networks, sitemaps and the rest', 'simple-seo' ),
			'usermeta'  => __( 'AIOSEO\'s data of users: notices put off and the like', 'simple-seo' ),
			'postmeta'  => __( 'AIOSEO\'s copies of the fields of posts in post meta (_aioseo_title and the like)', 'simple-seo' ),
		);
	}
}
