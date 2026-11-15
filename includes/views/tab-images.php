<?php
/**
 * Images tab: alt texts and titles of new images from their names, and the way to the images that
 * have no alt text yet.
 *
 * @var array<string, mixed> $settings
 * @var string               $bulk     Address of bulk editing with the images without alt text.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Images::OPTION;
?>
<p><?php esc_html_e( 'The alt text of an image tells search engines, and people who do not see it, what the picture shows; image search finds pictures by it. When the name of a file says what is in it, Simple SEO makes the words of it: without the extension, the dashes and the numbers WordPress adds. Names such as IMG_1234, a screenshot or a code of a stock photo give nothing.', 'simple-seo' ); ?></p>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'New images', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'New images', 'simple-seo' ); ?></legend>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[alt]" value="0">
					<label style="display:block;margin-bottom:6px">
						<input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[alt]" value="1" <?php checked( ! empty( $settings['alt'] ) ); ?>>
						<?php esc_html_e( 'Give an image without an alt text one from its name when it is uploaded', 'simple-seo' ); ?>
					</label>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[title]" value="0">
					<label style="display:block;margin-bottom:6px">
						<input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[title]" value="1" <?php checked( ! empty( $settings['title'] ) ); ?>>
						<?php esc_html_e( 'Write the title of an uploaded image as words instead of the name of its file', 'simple-seo' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'For example, the file chervonyi-dyvan-1024x768.jpg is the title and alt text "Chervonyi dyvan"; a file named with its words in Cyrillic keeps them. An alt text that is there already stays.', 'simple-seo' ); ?></p>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images already uploaded', 'simple-seo' ); ?></th>
			<td>
				<p>
					<a class="button" href="<?php echo esc_url( $bulk ); ?>"><?php esc_html_e( 'Images without alt text', 'simple-seo' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'Bulk editing lists them with the text of each name: fill the empty alt texts with it, look them over and save.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>
