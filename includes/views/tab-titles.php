<?php
/**
 * Titles tab: the separator and the title and description templates of every kind of page.
 *
 * @var string                                                         $separator
 * @var array<string, array{label: string, slots: array<string, string>}> $groups
 * @var array<string, string>                                          $variables
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
	.simple-seo-template { display: flex; gap: 6px; align-items: flex-start; margin: 2px 0 10px; }
	.simple-seo-template input { flex: 1 1 auto; min-width: 0; }
	.simple-seo-template .button { flex: 0 0 auto; }
	.simple-seo-variables code { white-space: nowrap; }
	@media (max-width: 600px) { .simple-seo-template { flex-wrap: wrap; } }
</style>

<p><?php esc_html_e( 'Templates decide the title and the description of every page that has no SEO title or description of its own. Variables in curly braces are replaced on each page; a variable with nothing to show disappears together with the separator next to it. An empty template leaves the page with the theme\'s title and without a description.', 'simple-seo' ); ?></p>

<details class="simple-seo-variables">
	<summary><strong><?php esc_html_e( 'Variables', 'simple-seo' ); ?></strong></summary>
	<table class="widefat striped" style="max-width:860px;margin-top:8px">
		<tbody>
		<?php foreach ( $variables as $simple_seo_name => $simple_seo_label ) : ?>
			<tr>
				<td style="width:9em"><code>{<?php echo esc_html( $simple_seo_name ); ?>}</code></td>
				<td><?php echo esc_html( $simple_seo_label ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</details>

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

<?php foreach ( $groups as $simple_seo_group ) : ?>
	<?php
	if ( ! $simple_seo_group['slots'] ) {
		continue;
	}
	?>
	<h2><?php echo esc_html( $simple_seo_group['label'] ); ?></h2>
	<table class="form-table" role="presentation">
		<tbody>
		<?php foreach ( $simple_seo_group['slots'] as $simple_seo_slot => $simple_seo_label ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $simple_seo_label ); ?></th>
				<td>
					<?php
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
								value="<?php echo esc_attr( Simple_SEO_Titles::template( $simple_seo_slot, $simple_seo_which ) ); ?>">
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
<?php endforeach; ?>
