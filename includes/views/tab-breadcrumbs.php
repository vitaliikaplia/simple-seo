<?php
/**
 * Breadcrumbs tab: how to put them into a theme, the separator, the labels (per language with
 * wp-loc), what the trail shows and which taxonomy each post type's trail goes through.
 *
 * @var array<string, mixed>    $settings
 * @var array<string, mixed>    $labels
 * @var string                  $language Name of the language being edited, '' without wp-loc.
 * @var array<string, string[]> $types    Post type => its hierarchical taxonomies.
 * @var string                  $example  The trail of the newest post, '' without posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Breadcrumbs::OPTION;
$simple_seo_labels = Simple_SEO_Breadcrumbs::LABELS;
?>
<?php Simple_SEO_Admin::section( 'use' ); ?>
<p><?php esc_html_e( 'The trail from the home page to the page shown: home, the blog page or the archive, the category with its parents, the page itself. The theme decides where it goes:', 'simple-seo' ); ?></p>
<table class="widefat striped" style="max-width:860px">
	<tbody>
		<tr><td style="width:9em"><?php esc_html_e( 'PHP template', 'simple-seo' ); ?></td><td><code>&lt;?php if ( function_exists( 'simple_seo_the_breadcrumbs' ) ) { simple_seo_the_breadcrumbs(); } ?&gt;</code></td></tr>
		<tr><td><?php esc_html_e( 'Twig (Timber)', 'simple-seo' ); ?></td><td><code>{{ simple_seo_breadcrumbs() }}</code></td></tr>
		<tr><td><?php esc_html_e( 'Shortcode', 'simple-seo' ); ?></td><td><code>[simple_seo_breadcrumbs]</code></td></tr>
	</tbody>
</table>
<?php if ( '' !== $example ) : ?>
	<p>
		<?php
		/* translators: %s: breadcrumbs of the newest post, e.g. Home › News › Post title. */
		printf( esc_html__( 'The newest post now: %s', 'simple-seo' ), '<strong>' . esc_html( $example ) . '</strong>' );
		?>
	</p>
<?php endif; ?>

<?php Simple_SEO_Admin::section_end(); ?>

<?php Simple_SEO_Admin::section( 'look' ); ?>
<?php
/* translators: %s: language name, e.g. English. */
$simple_seo_notice = __( 'The labels are kept for each language; you are editing them for: %s. The other settings on this tab are common to all languages.', 'simple-seo' );
require __DIR__ . '/language-notice.php';
?>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="simple-seo-breadcrumbs-separator"><?php esc_html_e( 'Separator', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="small-text" id="simple-seo-breadcrumbs-separator" name="<?php echo esc_attr( $simple_seo_option ); ?>[separator]" value="<?php echo esc_attr( Simple_SEO_Breadcrumbs::separator() ); ?>" maxlength="10">
				<p class="description"><?php esc_html_e( 'Between the items, for example › » / or |.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-breadcrumbs-home"><?php esc_html_e( 'Home page', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-breadcrumbs-home" name="<?php echo esc_attr( $simple_seo_labels ); ?>[home]" value="<?php echo esc_attr( (string) ( $labels['home'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'Home', 'simple-seo' ); ?>">
				<p class="description"><?php esc_html_e( 'The first item. Empty: "Home" in the language of the page.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-breadcrumbs-prefix"><?php esc_html_e( 'Text before', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-breadcrumbs-prefix" name="<?php echo esc_attr( $simple_seo_labels ); ?>[prefix]" value="<?php echo esc_attr( (string) ( $labels['prefix'] ?? '' ) ); ?>">
				<p class="description"><?php esc_html_e( 'Optional, for example "You are here:".', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'The trail', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'The trail', 'simple-seo' ); ?></legend>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[show_current]" value="0">
					<label style="display:block;margin-bottom:6px">
						<input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[show_current]" value="1" <?php checked( ! empty( $settings['show_current'] ) ); ?>>
						<?php esc_html_e( 'End with the page itself, as text', 'simple-seo' ); ?>
					</label>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[show_blog]" value="0">
					<label style="display:block">
						<input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[show_blog]" value="1" <?php checked( ! empty( $settings['show_blog'] ) ); ?>>
						<?php esc_html_e( 'Show the posts page (Settings → Reading) before posts and their categories', 'simple-seo' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>
	</tbody>
</table>

<?php Simple_SEO_Admin::section_end(); ?>

<?php if ( $types ) : ?>
	<?php Simple_SEO_Admin::section( 'categories' ); ?>
	<p><?php esc_html_e( 'Which taxonomy the trail of each post type goes through. A post with several terms there goes through its primary one, chosen in the SEO box of the editor; otherwise through the deepest of them.', 'simple-seo' ); ?></p>
	<table class="form-table" role="presentation">
		<tbody>
		<?php foreach ( $types as $simple_seo_type => $simple_seo_choices ) : ?>
			<?php
			$simple_seo_object = get_post_type_object( $simple_seo_type );
			$simple_seo_id     = 'simple-seo-breadcrumbs-taxonomy-' . sanitize_html_class( $simple_seo_type );
			$simple_seo_chosen = Simple_SEO_Breadcrumbs::taxonomy_for( $simple_seo_type );
			?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $simple_seo_id ); ?>"><?php echo esc_html( $simple_seo_object ? (string) $simple_seo_object->labels->name : $simple_seo_type ); ?></label></th>
				<td>
					<select id="<?php echo esc_attr( $simple_seo_id ); ?>" name="<?php echo esc_attr( $simple_seo_option . '[taxonomies][' . $simple_seo_type . ']' ); ?>">
						<option value="" <?php selected( $simple_seo_chosen, '' ); ?>><?php echo esc_html( is_post_type_hierarchical( $simple_seo_type ) ? __( 'None — parent pages instead', 'simple-seo' ) : __( 'None', 'simple-seo' ) ); ?></option>
						<?php foreach ( $simple_seo_choices as $simple_seo_taxonomy ) : ?>
							<?php $simple_seo_tax = get_taxonomy( $simple_seo_taxonomy ); ?>
							<option value="<?php echo esc_attr( $simple_seo_taxonomy ); ?>" <?php selected( $simple_seo_chosen, $simple_seo_taxonomy ); ?>><?php echo esc_html( $simple_seo_tax ? (string) $simple_seo_tax->labels->name : $simple_seo_taxonomy ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php Simple_SEO_Admin::section_end(); ?>
<?php endif; ?>
