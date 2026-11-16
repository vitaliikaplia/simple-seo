<?php
/**
 * The Search Console page under the SEO menu: the totals of a period beside those of the period
 * before it, the top queries and the top pages of the site.
 *
 * @var int                        $days      The period, in days.
 * @var bool                       $connected Whether a key is saved.
 * @var array<string, mixed>|WP_Error|null $report    Simple_SEO_Search_Console::site_report().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_settings_link = current_user_can( Simple_SEO_Access::CAP_SETTINGS )
	? sprintf( ' <a href="%1$s">%2$s</a>', esc_url( Simple_SEO_Admin::url( 'search-console' ) ), esc_html__( 'Open the Search Console tab of the settings', 'simple-seo' ) )
	: '';
?>
<div class="wrap simple-seo-gsc-page">
	<h1><?php esc_html_e( 'Search Console', 'simple-seo' ); ?></h1>

	<?php if ( ! $connected ) : ?>
		<p>
			<?php esc_html_e( 'Simple SEO is not connected to Google Search Console yet: an administrator connects it with a key of a service account of Google on the Search Console tab of the settings.', 'simple-seo' ); ?>
			<?php echo $simple_seo_settings_link; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
		</p>
	<?php elseif ( is_wp_error( $report ) ) : ?>
		<div class="notice notice-error inline">
			<p>
				<?php echo esc_html( $report->get_error_message() ); ?>
				<?php echo $simple_seo_settings_link; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
			</p>
		</div>
	<?php else : ?>
		<ul class="subsubsub simple-seo-gsc-periods">
			<?php
			$simple_seo_links = array();
			foreach ( Simple_SEO_Search_Console::PERIODS as $simple_seo_period ) {
				/* translators: %s: number of days. */
				$simple_seo_label   = sprintf( __( '%s days', 'simple-seo' ), number_format_i18n( $simple_seo_period ) );
				$simple_seo_links[] = $simple_seo_period === $days
					? sprintf( '<li><a href="%1$s" class="current" aria-current="page">%2$s</a></li>', esc_url( Simple_SEO_Search_Console::page_url( $simple_seo_period ) ), esc_html( $simple_seo_label ) )
					: sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( Simple_SEO_Search_Console::page_url( $simple_seo_period ) ), esc_html( $simple_seo_label ) );
			}
			echo implode( ' | ', $simple_seo_links ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			?>
		</ul>
		<br class="clear">

		<?php if ( ! $report['window'] ) : ?>
			<p>
				<?php
				/* translators: %s: property of Search Console. */
				printf( esc_html__( 'Search Console has no data of %s for these days yet.', 'simple-seo' ), '<code>' . esc_html( $report['property'] ) . '</code>' );
				?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: property of Search Console, 2: first day, 3: last day, 4: number of days. */
					esc_html__( 'Google search, property %1$s: %2$s – %3$s, compared with the %4$s days before. Search Console has its data two or three days late.', 'simple-seo' ),
					'<code>' . esc_html( $report['property'] ) . '</code>',
					esc_html( Simple_SEO_Search_Console::date( $report['window']['start'] ) ),
					esc_html( Simple_SEO_Search_Console::date( $report['window']['end'] ) ),
					esc_html( number_format_i18n( $report['days'] ) )
				);
				?>
			</p>
			<?php echo Simple_SEO_Search_Console::render_totals( $report['current'], $report['previous'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>

			<div class="simple-seo-gsc-columns">
				<div>
					<h2><?php esc_html_e( 'Top queries', 'simple-seo' ); ?></h2>
					<?php if ( $report['queries'] ) : ?>
						<?php echo Simple_SEO_Search_Console::render_table( $report['queries'], __( 'Query', 'simple-seo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
						<p class="description"><?php esc_html_e( 'Google leaves out rare queries, so the queries add up to less than the totals.', 'simple-seo' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'No queries in these days.', 'simple-seo' ); ?></p>
					<?php endif; ?>
				</div>
				<div>
					<h2><?php esc_html_e( 'Top pages', 'simple-seo' ); ?></h2>
					<?php if ( $report['pages'] ) : ?>
						<?php echo Simple_SEO_Search_Console::render_table( $report['pages'], __( 'Page', 'simple-seo' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
					<?php else : ?>
						<p><?php esc_html_e( 'No pages in these days.', 'simple-seo' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
