<?php
/**
 * Special pages tab: title and description templates of the home page, the 404 page and
 * password-protected content, and the social image of the home page and protected content.
 *
 * @var array<string, array{label: string, text: string, link?: string}> $sections
 * @var string                                                          $language  Name of the language being edited, '' without wp-loc.
 * @var array<string, string>                                           $variables Name => label, for views/variables.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Special::OPTION;
?>
<p><?php esc_html_e( 'The home page, the page for addresses that lead nowhere and content behind a password get their title and description from the templates below. Variables work as on the Titles tab; an empty template leaves the page with the theme\'s title and without a description.', 'simple-seo' ); ?></p>

<?php
/* translators: %s: language name, e.g. English. */
$simple_seo_notice = __( 'These settings are kept for each language. You are editing them for: %s. Switch the language in the admin bar to edit another one; a language not saved here yet uses the settings of the default language.', 'simple-seo' );
require __DIR__ . '/language-notice.php';
?>

<?php require __DIR__ . '/variables.php'; ?>

<?php foreach ( $sections as $simple_seo_slot => $simple_seo_section ) : ?>
	<?php $simple_seo_slot = (string) $simple_seo_slot; ?>
	<h2><?php echo esc_html( $simple_seo_section['label'] ); ?></h2>
	<p>
		<?php echo esc_html( $simple_seo_section['text'] ); ?>
		<?php if ( ! empty( $simple_seo_section['link'] ) ) : ?>
			<a href="<?php echo esc_url( $simple_seo_section['link'] ); ?>"><?php esc_html_e( 'Edit the page', 'simple-seo' ); ?></a>
		<?php endif; ?>
	</p>
	<table class="form-table" role="presentation">
		<tbody>
		<?php
		foreach ( array(
			'title'       => __( 'Title template', 'simple-seo' ),
			'description' => __( 'Description template', 'simple-seo' ),
		) as $simple_seo_which => $simple_seo_which_label ) :
			$simple_seo_id      = 'simple-seo-special-' . $simple_seo_slot . '-' . $simple_seo_which;
			$simple_seo_default = Simple_SEO_Titles::default_template( $simple_seo_slot, $simple_seo_which );
			?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $simple_seo_id ); ?>"><?php echo esc_html( $simple_seo_which_label ); ?></label></th>
				<td>
					<div class="simple-seo-template">
						<input type="text" class="large-text code" id="<?php echo esc_attr( $simple_seo_id ); ?>"
							name="<?php echo esc_attr( $simple_seo_option . '[' . $simple_seo_slot . '][' . $simple_seo_which . ']' ); ?>"
							value="<?php echo esc_attr( Simple_SEO_Titles::template( $simple_seo_slot, $simple_seo_which ) ); ?>">
						<?php
						if ( '' !== $simple_seo_default ) {
							echo Simple_SEO_Admin::mask_button( $simple_seo_id, $simple_seo_default ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
						}
						?>
					</div>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! empty( Simple_SEO_Special::SLOTS[ $simple_seo_slot ] ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Image for social networks', 'simple-seo' ); ?></th>
				<td>
					<?php
					echo Simple_SEO_Admin::image_picker( $simple_seo_option . '[' . $simple_seo_slot . '][image]', 'simple-seo-special-' . $simple_seo_slot . '-image', Simple_SEO_Special::image_id( $simple_seo_slot ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
					?>
					<p class="description"><?php esc_html_e( 'Without it: the default image from the Social networks tab.', 'simple-seo' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
		</tbody>
	</table>
<?php endforeach; ?>
