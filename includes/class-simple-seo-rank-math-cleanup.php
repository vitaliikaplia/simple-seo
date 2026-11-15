<?php
/**
 * What Rank Math leaves in the database: its tables {prefix}rank_math_* (redirections, the 404 log,
 * internal links), its options rank_math_* with their cached data, its scheduled tasks, the
 * capabilities rank_math_* it gives to roles and keeps after deactivation, its settings
 * (rank-math-options-* and the list of its modules), and its user, term and post meta rank_math_*.
 * The tables of Action Scheduler, which Rank Math brings along, are shared with other plugins
 * (WooCommerce among them) and stay. How the rows are removed: Simple_SEO_Source_Cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_SEO_Rank_Math_Cleanup extends Simple_SEO_Source_Cleanup {
	public const OPTION = 'simple_seo_rank_math_cleanup';

	public const IMPORT = 'Simple_SEO_Rank_Math_Import';

	public const GROUPS = array( 'tables', 'internals', 'settings', 'usermeta', 'termmeta', 'postmeta' );

	protected const TABLES = 'rank_math_';

	protected const CRON = '/^rank_math/i';

	protected const CAPS = '/^rank_math_/';

	/** Simple SEO has no aliases for Rank Math's meta: a theme that reads it would find nothing. */
	protected const THEME_READS = array(
		'settings' => '/rank-math-options-/',
		'termmeta' => '/[\'"]rank_math_[a-z_]+[\'"]/',
		'postmeta' => '/[\'"]rank_math_[a-z_]+[\'"]/',
	);

	protected static function active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || is_plugin_active( 'seo-by-rank-math/rank-math.php' ) || is_plugin_active( 'seo-by-rank-math-pro/rank-math-pro.php' );
	}

	protected static function options_sql(): string {
		return "( option_name LIKE 'rank\\_math\\_%' OR option_name LIKE 'rank-math-%'"
			. " OR option_name LIKE '\\_transient\\_rank\\_math%' OR option_name LIKE '\\_transient\\_timeout\\_rank\\_math%'"
			. " OR option_name LIKE '\\_transient\\_\\_rank\\_math%' OR option_name LIKE '\\_transient\\_timeout\\_\\_rank\\_math%'"
			. " OR option_name LIKE '\\_site\\_transient\\_rank\\_math%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_rank\\_math%' )";
	}

	protected static function is_setting( string $name ): bool {
		return str_starts_with( $name, 'rank-math-options-' ) || 'rank_math_modules' === $name;
	}

	protected static function meta_sql( string $type ): string {
		return "meta_key LIKE 'rank\\_math\\_%'";
	}

	public static function labels(): array {
		return array(
			'tables'    => __( 'Rank Math\'s tables: its redirections, the 404 log, internal links and the like', 'simple-seo' ),
			'internals' => __( 'Rank Math\'s other options, cached data, scheduled tasks and the capabilities it gave to roles', 'simple-seo' ),
			'settings'  => __( 'Rank Math\'s settings: titles and meta, general settings, the sitemap, the list of its modules', 'simple-seo' ),
			'usermeta'  => __( 'Rank Math\'s data of users: SEO fields of author archives and the like', 'simple-seo' ),
			'termmeta'  => __( 'Rank Math\'s fields of terms: what the import took, focus keywords and the rest', 'simple-seo' ),
			'postmeta'  => __( 'Rank Math\'s fields of posts: what the import took, focus keywords, Schema and the rest', 'simple-seo' ),
		);
	}
}
