<?php
/**
 * Import tab, the second part of a plugin's: removing what the plugin left in the database.
 *
 * @var string                                        $source  Key of the plugin (Simple_SEO_Import::SOURCES).
 * @var string                                        $name    The plugin's name.
 * @var array<string, mixed>                          $cleanup State of the clean-up.
 * @var string                                        $blocked Why removing cannot run now: active, import or ''.
 * @var array<string, array{rows: int, bytes: int}>   $parts   What is left of the plugin's, by part.
 * @var array<string, string>                         $labels  What each part holds.
 * @var array<string, string[]>                       $reads   Files of the active theme that read a part: part => files.
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
<h3 id="simple-seo-cleanup-<?php echo esc_attr( $source ); ?>"><?php /* translators: %s: name of an SEO plugin. */ printf( esc_html__( 'Remove the data of %s', 'simple-seo' ), esc_html( $name ) ); ?></h3>

<?php
$simple_seo_refusals = array(
	'backup'  => __( 'Confirm that you have a backup of the database first.', 'simple-seo' ),
	'groups'  => __( 'Tick at least one part to remove.', 'simple-seo' ),
	/* translators: %s: name of an SEO plugin. */
	'blocked' => sprintf( __( 'Removing is not possible now: %s is active, or the import is not done.', 'simple-seo' ), $name ),
);
if ( isset( $simple_seo_refusals[ $refused ] ) ) :
	?>
	<div class="notice notice-error inline"><p><?php echo esc_html( $simple_seo_refusals[ $refused ] ); ?></p></div>
<?php endif; ?>

<?php if ( 'running' === $simple_seo_state ) : ?>
	<form method="post" action="<?php echo esc_url( Simple_SEO_Admin::url( 'import' ) ); ?>">
		<?php wp_nonce_field( $nonce ); ?>
		<input type="hidden" name="simple_seo_source" value="<?php echo esc_attr( $source ); ?>" />
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
	<p><?php /* translators: %s: name of an SEO plugin. */ printf( esc_html__( 'No data of %s is left on this site.', 'simple-seo' ), esc_html( $name ) ); ?></p>
<?php elseif ( 'active' === $blocked ) : ?>
	<p><?php /* translators: %s: name of an SEO plugin. */ printf( esc_html__( '%s is active, so its data is in use. Deactivate it first.', 'simple-seo' ), esc_html( $name ) ); ?></p>
<?php elseif ( 'import' === $blocked ) : ?>
	<p><?php esc_html_e( 'Run the import first: removing comes after it.', 'simple-seo' ); ?></p>
<?php else : ?>
	<p><?php /* translators: %s: name of an SEO plugin. */ printf( esc_html__( 'What %s left in the database. Simple SEO does not need it: the import has taken what Simple SEO has a place for.', 'simple-seo' ), esc_html( $name ) ); ?></p>
	<form method="post" action="<?php echo esc_url( Simple_SEO_Admin::url( 'import' ) ); ?>">
		<?php wp_nonce_field( $nonce ); ?>
		<input type="hidden" name="simple_seo_source" value="<?php echo esc_attr( $source ); ?>" />
		<fieldset>
			<?php
			foreach ( $labels as $simple_seo_group => $simple_seo_label ) :
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
						<input type="checkbox" name="simple_seo_groups[]" value="<?php echo esc_attr( $simple_seo_group ); ?>"<?php checked( ! isset( $reads[ $simple_seo_group ] ) ); ?> />
						<?php echo esc_html( $simple_seo_label ); ?>
						<span class="description">(<?php echo esc_html( sprintf( $simple_seo_size, $simple_seo_n( $parts[ $simple_seo_group ]['rows'] ), size_format( $parts[ $simple_seo_group ]['bytes'] ) ?: '0 B' ) ); ?>)</span>
					</label>
				</p>
			<?php endforeach; ?>
		</fieldset>
		<?php
		$simple_seo_warnings = array(
			/* translators: %s: name of an SEO plugin. */
			'settings' => __( 'The active theme reads the settings of %s, so they are left unticked. Removed, they are gone for the theme too: an organization\'s name and logo in its Schema, say. The files:', 'simple-seo' ),
			/* translators: %s: name of an SEO plugin. */
			'termmeta' => __( 'The active theme reads the fields of terms of %s, so they are left unticked. Removed, the theme finds nothing there: Simple SEO keeps what it imported in its own fields. The files:', 'simple-seo' ),
			/* translators: %s: name of an SEO plugin. */
			'postmeta' => __( 'The active theme reads the fields of posts of %s, so they are left unticked. Removed, the theme finds nothing there: Simple SEO keeps what it imported in its own fields. The files:', 'simple-seo' ),
		);
		foreach ( $reads as $simple_seo_group => $simple_seo_files ) :
			if ( ! isset( $simple_seo_warnings[ $simple_seo_group ] ) ) {
				continue;
			}
			?>
			<div class="notice notice-warning inline">
				<p><?php echo esc_html( sprintf( $simple_seo_warnings[ $simple_seo_group ], $name ) ); ?></p>
				<ul class="ul-disc">
					<?php foreach ( $simple_seo_files as $simple_seo_file ) : ?>
						<li><code><?php echo esc_html( $simple_seo_file ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
		<p><?php /* translators: %s: name of an SEO plugin. */ printf( esc_html__( 'Removing cannot be undone. The files of %s stay: delete the plugin on the Plugins screen. Switched on again, it starts from its defaults.', 'simple-seo' ), esc_html( $name ) ); ?></p>
		<p>
			<label>
				<input type="checkbox" name="simple_seo_backup" value="1" required />
				<?php esc_html_e( 'I have a backup of the database', 'simple-seo' ); ?>
			</label>
		</p>
		<p><button type="submit" class="button" name="simple_seo_import" value="clean"><?php /* translators: %s: name of an SEO plugin. */ printf( esc_html__( 'Remove the ticked data of %s', 'simple-seo' ), esc_html( $name ) ); ?></button></p>
	</form>
<?php endif; ?>

<?php if ( 'done' === $simple_seo_state ) : ?>
	<h4><?php esc_html_e( 'The last clean-up', 'simple-seo' ); ?></h4>
	<ul class="ul-disc">
		<?php if ( isset( $cleanup['finished'] ) ) : ?>
			<li><?php /* translators: %s: date and time. */ printf( esc_html__( 'Finished: %s.', 'simple-seo' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $cleanup['finished'] ) ) ); ?></li>
		<?php endif; ?>
		<li>
			<?php
			$simple_seo_done  = (array) ( $cleanup['report'] ?? array() );
			$simple_seo_items = array();
			foreach ( Simple_SEO_Source_Cleanup::removed_labels() as $simple_seo_what => $simple_seo_label ) {
				if ( ! empty( $simple_seo_done[ $simple_seo_what ] ) ) {
					$simple_seo_items[] = sprintf( $simple_seo_label, $simple_seo_n( $simple_seo_done[ $simple_seo_what ] ) );
				}
			}
			echo esc_html(
				$simple_seo_items
					/* translators: %s: list of what was removed. */
					? sprintf( __( 'Removed: %s.', 'simple-seo' ), implode( '; ', $simple_seo_items ) )
					: __( 'Removed: nothing, it was gone already.', 'simple-seo' )
			);
			?>
		</li>
	</ul>
<?php endif; ?>
