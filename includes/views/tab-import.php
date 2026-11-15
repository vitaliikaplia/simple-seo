<?php
/**
 * Import tab, a plugin's part: what the plugin left on the site, the button that takes it over, the
 * run's progress and the report of the last run.
 *
 * @var string                                                  $source  Key of the plugin (Simple_SEO_Import::SOURCES).
 * @var string                                                  $class   Its import class.
 * @var string                                                  $name    The plugin's name.
 * @var string                                                  $status  pending, none, running, done, dismissed or ''.
 * @var array{settings: bool, posts: int, terms: int}|null      $summary What there is to import; null while running.
 * @var array<string, mixed>                                    $report  Report of the last run.
 * @var array<string, mixed>                                    $state   The whole state.
 * @var string                                                  $nonce   Nonce action of the forms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_n     = static fn( $value ): string => number_format_i18n( (int) $value );
$simple_seo_count = static fn( string $group, string $what ): int => (int) ( $report[ $group ][ $what ] ?? 0 );
?>
<h2><?php echo esc_html( $name ); ?></h2>

<?php if ( 'running' === $status ) : ?>
	<form method="post" action="<?php echo esc_url( Simple_SEO_Admin::url( 'import' ) ); ?>">
		<?php wp_nonce_field( $nonce ); ?>
		<input type="hidden" name="simple_seo_source" value="<?php echo esc_attr( $source ); ?>" />
		<p>
			<?php
			/* translators: 1: number of posts, 2: number of terms. */
			printf( esc_html__( 'The import is running: %1$s posts and %2$s terms so far. The page goes on by itself; if it stops, press Continue.', 'simple-seo' ), esc_html( $simple_seo_n( $simple_seo_count( 'post', 'seen' ) ) ), esc_html( $simple_seo_n( $simple_seo_count( 'term', 'seen' ) ) ) );
			?>
		</p>
		<p><button type="submit" class="button button-primary" name="simple_seo_import" value="continue" data-simple-seo-continue="1"><?php esc_html_e( 'Continue', 'simple-seo' ); ?></button></p>
	</form>
	<?php
	return;
endif;
?>

<?php if ( $summary && ( $summary['settings'] || $summary['posts'] || $summary['terms'] ) ) : ?>
	<p><?php esc_html_e( 'Found on this site:', 'simple-seo' ); ?></p>
	<ul class="ul-disc">
		<?php if ( $summary['settings'] ) : ?>
			<li><?php /* translators: %s: name of an SEO plugin. */ printf( esc_html__( 'the settings of %s', 'simple-seo' ), esc_html( $name ) ); ?></li>
		<?php endif; ?>
		<?php if ( $summary['posts'] ) : ?>
			<li><?php /* translators: %s: number of posts. */ printf( esc_html__( 'posts with SEO fields: %s', 'simple-seo' ), esc_html( $simple_seo_n( $summary['posts'] ) ) ); ?></li>
		<?php endif; ?>
		<?php if ( $summary['terms'] ) : ?>
			<li><?php /* translators: %s: number of terms. */ printf( esc_html__( 'terms with SEO fields: %s', 'simple-seo' ), esc_html( $simple_seo_n( $summary['terms'] ) ) ); ?></li>
		<?php endif; ?>
	</ul>
	<p><?php /* translators: %s: name of an SEO plugin. */ printf( esc_html__( 'The import fills only what Simple SEO does not have yet: the settings of a tab saved here, and a template or a field that has a value, stay as they are. The data of %s is not changed, so the import can be run again and the plugin can be switched back on.', 'simple-seo' ), esc_html( $name ) ); ?></p>
	<p><?php echo esc_html( $class::variables_note() ); ?></p>
	<form method="post" action="<?php echo esc_url( Simple_SEO_Admin::url( 'import' ) ); ?>">
		<?php wp_nonce_field( $nonce ); ?>
		<input type="hidden" name="simple_seo_source" value="<?php echo esc_attr( $source ); ?>" />
		<p>
			<button type="submit" class="button button-primary" name="simple_seo_import" value="start">
				<?php
				if ( 'done' === $status ) {
					esc_html_e( 'Import again', 'simple-seo' );
				} else {
					/* translators: %s: name of an SEO plugin. */
					printf( esc_html__( 'Import from %s', 'simple-seo' ), esc_html( $name ) );
				}
				?>
			</button>
		</p>
	</form>
<?php else : ?>
	<p><?php /* translators: %s: name of an SEO plugin. */ printf( esc_html__( 'There is no data of %s on this site.', 'simple-seo' ), esc_html( $name ) ); ?></p>
<?php endif; ?>

<?php if ( 'done' === $status ) : ?>
	<h3><?php esc_html_e( 'The last import', 'simple-seo' ); ?></h3>
	<?php
	$simple_seo_settings = array();
	$simple_seo_labels   = array(
		'separator'     => __( 'the separator', 'simple-seo' ),
		/* translators: %s: number. */
		'templates'     => __( 'templates: %s', 'simple-seo' ),
		'indexing'      => __( 'what search engines leave out', 'simple-seo' ),
		'attachments'   => __( 'where attachment pages go', 'simple-seo' ),
		'default_image' => __( 'the default image', 'simple-seo' ),
		'x_account'     => __( 'the X account', 'simple-seo' ),
		/* translators: %s: number. */
		'special'       => __( 'the home and 404 pages: %s', 'simple-seo' ),
		/* translators: %s: number. */
		'breadcrumbs'   => __( 'breadcrumbs: %s', 'simple-seo' ),
		/* translators: %s: number. */
		'labels'        => __( 'breadcrumb labels: %s', 'simple-seo' ),
		'sitemaps'      => __( 'the XML sitemap switched off', 'simple-seo' ),
		/* translators: %s: number. */
		'cleanup'       => __( 'clean-up: %s', 'simple-seo' ),
		'robots_txt'    => __( 'the text of robots.txt', 'simple-seo' ),
		/* translators: %s: number. */
		'schema'        => __( 'Schema: %s', 'simple-seo' ),
	);
	foreach ( $simple_seo_labels as $simple_seo_what => $simple_seo_label ) {
		if ( $simple_seo_count( 'settings', $simple_seo_what ) ) {
			$simple_seo_settings[] = sprintf( $simple_seo_label, $simple_seo_n( $simple_seo_count( 'settings', $simple_seo_what ) ) );
		}
	}
	?>
	<ul class="ul-disc">
		<?php if ( isset( $state['finished'] ) ) : ?>
			<li><?php /* translators: %s: date and time. */ printf( esc_html__( 'Finished: %s.', 'simple-seo' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['finished'] ) ) ); ?></li>
		<?php endif; ?>
		<li>
			<?php
			echo esc_html(
				$simple_seo_settings
					/* translators: %s: list of settings. */
					? sprintf( __( 'Settings taken: %s.', 'simple-seo' ), implode( ', ', $simple_seo_settings ) )
					: __( 'Settings: nothing to take, Simple SEO has its own.', 'simple-seo' )
			);
			?>
		</li>
		<?php if ( ! empty( $report['languages'] ) ) : ?>
			<li><?php /* translators: %s: language codes. */ printf( esc_html__( 'Also the home and 404 pages, the breadcrumb labels and the Schema names of the languages: %s.', 'simple-seo' ), esc_html( implode( ', ', (array) $report['languages'] ) ) ); ?></li>
		<?php endif; ?>
		<?php
		foreach ( array(
			/* translators: 1: posts with the plugin's fields, 2: of them changed, 3: fields filled, 4: fields kept, 5: values not taken. */
			'post' => __( 'Posts with SEO fields: %1$s, %2$s of them changed. Fields filled: %3$s; kept as Simple SEO had them: %4$s; not taken, as Simple SEO cannot hold the value: %5$s.', 'simple-seo' ),
			/* translators: 1: terms with the plugin's fields, 2: of them changed, 3: fields filled, 4: fields kept, 5: values not taken. */
			'term' => __( 'Terms with SEO fields: %1$s, %2$s of them changed. Fields filled: %3$s; kept as Simple SEO had them: %4$s; not taken, as Simple SEO cannot hold the value: %5$s.', 'simple-seo' ),
		) as $simple_seo_type => $simple_seo_line ) :
			?>
			<li><?php echo esc_html( sprintf( $simple_seo_line, $simple_seo_n( $simple_seo_count( $simple_seo_type, 'seen' ) ), $simple_seo_n( $simple_seo_count( $simple_seo_type, 'changed' ) ), $simple_seo_n( $simple_seo_count( $simple_seo_type, 'fields' ) ), $simple_seo_n( $simple_seo_count( $simple_seo_type, 'kept' ) ), $simple_seo_n( $simple_seo_count( $simple_seo_type, 'refused' ) ) ) ); ?></li>
		<?php endforeach; ?>
		<?php if ( ! empty( $report['dropped'] ) ) : ?>
			<li>
				<?php
				$simple_seo_dropped = array();
				foreach ( (array) $report['dropped'] as $simple_seo_name => $simple_seo_times ) {
					$simple_seo_dropped[] = $class::variable( (string) $simple_seo_name ) . ' (' . $simple_seo_n( $simple_seo_times ) . ')';
				}
				/* translators: %s: list of variables with how often each was found. */
				printf( esc_html__( 'Left out of the texts, as Simple SEO has no such variable: %s.', 'simple-seo' ), esc_html( implode( ', ', $simple_seo_dropped ) ) );
				?>
			</li>
		<?php endif; ?>
		<?php if ( ! empty( $report['left'] ) ) : ?>
			<li>
				<?php esc_html_e( 'Not taken, as Simple SEO has nothing for it yet:', 'simple-seo' ); ?>
				<ul class="ul-disc">
					<?php
					$simple_seo_left = array(
						/* translators: %s: number of posts. */
						'bctitle'          => __( 'titles for breadcrumbs (posts: %s)', 'simple-seo' ),
						/* translators: %s: number of posts. */
						'twitter'          => __( 'titles, descriptions and images for X (posts: %s)', 'simple-seo' ),
						/* translators: %s: number of posts. */
						'robots_adv'       => __( 'advanced robots settings (posts: %s)', 'simple-seo' ),
						/* translators: %s: number of posts. */
						'schema'           => __( 'Schema of posts (posts: %s)', 'simple-seo' ),
						/* translators: %s: number of users. */
						'authors'          => __( 'SEO titles and descriptions of author archives (authors: %s)', 'simple-seo' ),
						'social_templates' => __( 'templates of titles and descriptions for social networks', 'simple-seo' ),
						'breadcrumb_texts' => __( 'the texts the breadcrumbs put before archives and search results and on the 404 page', 'simple-seo' ),
						'schema_person'    => __( 'the person the site represents: choose the user on the Schema tab', 'simple-seo' ),
						'verification'     => __( 'the codes that confirm the site to Google, Bing and other webmaster tools: put them where the site keeps such codes, or they stop working', 'simple-seo' ),
						'url_bases'        => __( 'addresses without the category base: with the plugin off, the addresses of categories get it back', 'simple-seo' ),
						'robots_rules'     => __( 'the rules added to robots.txt: put them on the Files tab', 'simple-seo' ),
						/* translators: %s: number of redirections. */
						'redirections'     => __( 'redirections (%s): with the plugin off they stop working; move them to a redirection plugin first', 'simple-seo' ),
					);
					foreach ( $simple_seo_left as $simple_seo_what => $simple_seo_label ) :
						if ( empty( $report['left'][ $simple_seo_what ] ) ) {
							continue;
						}
						?>
						<li><?php echo esc_html( sprintf( $simple_seo_label, $simple_seo_n( $report['left'][ $simple_seo_what ] ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</li>
		<?php endif; ?>
	</ul>
<?php endif; ?>
