<?php
/**
 * Titles tab: the separator and the title and description templates of every kind of page.
 *
 * @var string                                                         $separator
 * @var array<string, array{label: string, slots: array<string, string>}> $groups
 * @var array<string, string>                                          $variables Name => label, for views/variables.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Titles::OPTION;
?>
<style>
	.simple-seo-separators { display: flex; flex-wrap: wrap; gap: 6px; }
	.simple-seo-separators label { position: relative; }
	.simple-seo-separators input { position: absolute; opacity: 0; }
	.simple-seo-separators span { display: inline-block; min-width: 2.4em; padding: 6px 0; border: 1px solid #8c8f94; border-radius: 4px; background: #fff; font-size: 18px; line-height: 1.2; text-align: center; cursor: pointer; }
	.simple-seo-separators input:checked + span { border-color: #2271b1; background: #2271b1; color: #fff; }
	.simple-seo-separators input:focus-visible + span { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2271b1; }
</style>

<?php Simple_SEO_Admin::section( 'general' ); ?>
<p><?php esc_html_e( 'Templates decide the title and the description of every page that has no SEO title or description of its own. Variables in curly braces are replaced on each page; a variable with nothing to show disappears together with the separator next to it. An empty template leaves the page with the theme\'s title and without a description.', 'simple-seo' ); ?></p>
<p>
	<?php
	printf(
		/* translators: %s: link to the Special pages tab. */
		esc_html__( 'The home page, the 404 page and password-protected content have their own templates on the %s tab.', 'simple-seo' ),
		'<a href="' . esc_url( Simple_SEO_Admin::url( 'special' ) ) . '">' . esc_html__( 'Special pages', 'simple-seo' ) . '</a>'
	);
	?>
</p>

<?php require __DIR__ . '/variables.php'; ?>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Separator', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Separator', 'simple-seo' ); ?></legend>
					<div class="simple-seo-separators">
						<?php foreach ( Simple_SEO_Titles::SEPARATORS as $simple_seo_char ) : ?>
							<label>
								<input type="radio" name="<?php echo esc_attr( $simple_seo_option ); ?>[separator]" value="<?php echo esc_attr( $simple_seo_char ); ?>" <?php checked( $separator, $simple_seo_char ); ?>>
								<span><?php echo esc_html( $simple_seo_char ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Goes where a template says {sep}.', 'simple-seo' ); ?></p>
				</fieldset>
			</td>
		</tr>
	</tbody>
</table>

<?php Simple_SEO_Admin::section_end(); ?>

<?php foreach ( $groups as $simple_seo_key => $simple_seo_group ) : ?>
	<?php
	if ( ! $simple_seo_group['slots'] ) {
		continue;
	}
	?>
	<?php Simple_SEO_Admin::section( (string) $simple_seo_key ); ?>
	<table class="form-table" role="presentation">
		<tbody>
		<?php foreach ( $simple_seo_group['slots'] as $simple_seo_slot => $simple_seo_label ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $simple_seo_label ); ?></th>
				<td>
					<?php
					// What the variables become on an example page of the slot, for the chips.
					$simple_seo_vars = Simple_SEO_Variables::attribute( Simple_SEO_Variables::values( Simple_SEO_Variables::example( $simple_seo_slot ) ) );
					foreach ( array(
						'title'       => __( 'Title template', 'simple-seo' ),
						'description' => __( 'Description template', 'simple-seo' ),
					) as $simple_seo_which => $simple_seo_which_label ) :
						$simple_seo_id      = 'simple-seo-template-' . sanitize_html_class( str_replace( ':', '-', $simple_seo_slot ) ) . '-' . $simple_seo_which;
						$simple_seo_default = Simple_SEO_Titles::default_template( $simple_seo_slot, $simple_seo_which );
						?>
						<label for="<?php echo esc_attr( $simple_seo_id ); ?>"><?php echo esc_html( $simple_seo_which_label ); ?></label>
						<div class="simple-seo-template">
							<input type="text" class="large-text code" id="<?php echo esc_attr( $simple_seo_id ); ?>"
								name="<?php echo esc_attr( $simple_seo_option . '[templates][' . $simple_seo_slot . '][' . $simple_seo_which . ']' ); ?>"
								value="<?php echo esc_attr( Simple_SEO_Titles::template( $simple_seo_slot, $simple_seo_which ) ); ?>"<?php echo $simple_seo_vars; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by attribute(). ?>>
							<?php
							if ( '' !== $simple_seo_default ) {
								echo Simple_SEO_Admin::mask_button( $simple_seo_id, $simple_seo_default ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
							}
							?>
						</div>
					<?php endforeach; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php Simple_SEO_Admin::section_end(); ?>
<?php endforeach; ?>
