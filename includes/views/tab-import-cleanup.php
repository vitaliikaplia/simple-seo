<?php
/**
 * Import tab, its second part: removing what Yoast SEO left in the database.
 *
 * @var array<string, mixed>                          $cleanup State of the clean-up.
 * @var string                                        $blocked Why removing cannot run now: yoast, import or ''.
 * @var array<string, array{rows: int, bytes: int}>   $parts   What is left of Yoast's, by part.
 * @var string[]                                      $reads   Files of the active theme that read Yoast's settings.
 * @var string                                        $refused Why the last form was refused: backup, groups, blocked or ''.
 * @var string                                        $nonce   Nonce action of the forms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_state = (string) ( $cleanup['status'] ?? '' );
if ( ! $parts && '' === $simple_seo_state ) {
	return;
}
$simple_seo_n = static fn( $value ): string => number_format_i18n( (int) $value );
?>
<hr />
<h2 id="simple-seo-cleanup"><?php esc_html_e( 'Remove the data of Yoast SEO', 'simple-seo' ); ?></h2>

<?php
$simple_seo_refusals = array(
	'backup'  => __( 'Confirm that you have a backup of the database first.', 'simple-seo' ),
	'groups'  => __( 'Tick at least one part to remove.', 'simple-seo' ),
	'blocked' => __( 'Removing is not possible now: Yoast SEO is active, or the import is not done.', 'simple-seo' ),
);
if ( isset( $simple_seo_refusals[ $refused ] ) ) :
	?>
	<div class="notice notice-error inline"><p><?php echo esc_html( $simple_seo_refusals[ $refused ] ); ?></p></div>
<?php endif; ?>

<?php if ( 'running' === $simple_seo_state ) : ?>
	<form method="post" action="<?php echo esc_url( Simple_SEO_Admin::url( 'import' ) ); ?>">
		<?php wp_nonce_field( $nonce ); ?>
		<p>
			<?php
			/* translators: 1: rows of post meta removed so far. */
			printf( esc_html__( 'Removing is under way: %1$s rows of post meta so far. The page goes on by itself; if it stops, press Continue.', 'simple-seo' ), esc_html( $simple_seo_n( $cleanup['report']['postmeta'] ?? 0 ) ) );
			?>
		</p>
		<p><button type="submit" class="button button-primary" name="simple_seo_import" value="clean_continue" data-simple-seo-continue="1"><?php esc_html_e( 'Continue', 'simple-seo' ); ?></button></p>
	</form>
	<?php
	return;
endif;
?>

<?php if ( ! $parts ) : ?>
	<p><?php esc_html_e( 'No data of Yoast SEO is left on this site.', 'simple-seo' ); ?></p>
<?php elseif ( 'yoast' === $blocked ) : ?>
	<p><?php esc_html_e( 'Yoast SEO is active, so its data is in use. Deactivate it first.', 'simple-seo' ); ?></p>
<?php elseif ( 'import' === $blocked ) : ?>
	<p><?php esc_html_e( 'Run the import first: removing comes after it.', 'simple-seo' ); ?></p>
<?php else : ?>
	<p><?php esc_html_e( 'What Yoast SEO left in the database. Simple SEO does not need it: the import has taken what Simple SEO has a place for, and themes read Yoast\'s fields of posts through Simple SEO.', 'simple-seo' ); ?></p>
	<form method="post" action="<?php echo esc_url( Simple_SEO_Admin::url( 'import' ) ); ?>">
		<?php wp_nonce_field( $nonce ); ?>
		<fieldset>
			<?php
			$simple_seo_labels = array(
				'tables'    => __( 'Yoast\'s tables: its index of pages, links, primary terms and the like', 'simple-seo' ),
				'internals' => __( 'Yoast\'s other options, cached data and scheduled tasks', 'simple-seo' ),
				'settings'  => __( 'Yoast\'s settings: titles, social networks, the SEO of terms (with wp-loc, the copies for other languages)', 'simple-seo' ),
				'usermeta'  => __( 'Yoast\'s data of users: SEO titles of author archives, editor preferences, notices', 'simple-seo' ),
				'postmeta'  => __( 'Yoast\'s fields of posts: what the import took, focus keyphrases and the rest', 'simple-seo' ),
			);
			foreach ( $simple_seo_labels as $simple_seo_group => $simple_seo_label ) :
				if ( ! isset( $parts[ $simple_seo_group ] ) ) {
					continue;
				}
				$simple_seo_size = 'tables' === $simple_seo_group
					/* translators: 1: number of tables, 2: their size. */
					? __( 'tables: %1$s, size: %2$s', 'simple-seo' )
					/* translators: 1: number of rows, 2: their size. */
					: __( 'rows: %1$s, size: %2$s', 'simple-seo' );
				?>
				<p>
					<label>
						<input type="checkbox" name="simple_seo_groups[]" value="<?php echo esc_attr( $simple_seo_group ); ?>"<?php checked( 'settings' !== $simple_seo_group || ! $reads ); ?> />
						<?php echo esc_html( $simple_seo_label ); ?>
						<span class="description">(<?php echo esc_html( sprintf( $simple_seo_size, $simple_seo_n( $parts[ $simple_seo_group ]['rows'] ), size_format( $parts[ $simple_seo_group ]['bytes'] ) ?: '0 B' ) ); ?>)</span>
					</label>
				</p>
			<?php endforeach; ?>
		</fieldset>
		<?php if ( $reads ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'The active theme reads Yoast\'s settings, so they are left unticked. Removed, they are gone for the theme too: an organization\'s name and logo in its Schema, say. The files:', 'simple-seo' ); ?></p>
				<ul class="ul-disc">
					<?php foreach ( $reads as $simple_seo_file ) : ?>
						<li><code><?php echo esc_html( $simple_seo_file ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Removing cannot be undone. The files of the Yoast SEO plugin stay: delete the plugin on the Plugins screen. Switched on again, Yoast starts from its defaults.', 'simple-seo' ); ?></p>
		<p>
			<label>
				<input type="checkbox" name="simple_seo_backup" value="1" required />
				<?php esc_html_e( 'I have a backup of the database', 'simple-seo' ); ?>
			</label>
		</p>
		<p><button type="submit" class="button" name="simple_seo_import" value="clean"><?php esc_html_e( 'Remove the ticked data of Yoast SEO', 'simple-seo' ); ?></button></p>
	</form>
<?php endif; ?>

<?php if ( 'done' === $simple_seo_state ) : ?>
	<h3><?php esc_html_e( 'The last clean-up', 'simple-seo' ); ?></h3>
	<ul class="ul-disc">
		<?php if ( isset( $cleanup['finished'] ) ) : ?>
			<li><?php /* translators: %s: date and time. */ printf( esc_html__( 'Finished: %s.', 'simple-seo' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $cleanup['finished'] ) ) ); ?></li>
		<?php endif; ?>
		<li>
			<?php
			$simple_seo_done = (array) ( $cleanup['report'] ?? array() );
			/* translators: 1: tables, 2: options, 3: scheduled tasks, 4: rows of settings, 5: rows of user meta, 6: rows of post meta. */
			printf( esc_html__( 'Removed: tables %1$s; options %2$s; scheduled tasks %3$s; rows of settings %4$s; rows of user meta %5$s; rows of post meta %6$s.', 'simple-seo' ), esc_html( $simple_seo_n( $simple_seo_done['tables'] ?? 0 ) ), esc_html( $simple_seo_n( $simple_seo_done['options'] ?? 0 ) ), esc_html( $simple_seo_n( $simple_seo_done['cron'] ?? 0 ) ), esc_html( $simple_seo_n( $simple_seo_done['settings'] ?? 0 ) ), esc_html( $simple_seo_n( $simple_seo_done['usermeta'] ?? 0 ) ), esc_html( $simple_seo_n( $simple_seo_done['postmeta'] ?? 0 ) ) );
			?>
		</li>
	</ul>
<?php endif; ?>
