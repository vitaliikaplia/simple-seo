<?php
/**
 * The AI tab: whether a connected AI can write, the alt texts of new images, and how many variants
 * it writes at a time.
 *
 * @var array{variants: int, alt_upload: bool} $settings
 * @var string                                 $status   ready, none (no model can write text) or off
 *                                                       (this site turned AI off).
 * @var int                                    $checked  When the AI Client was asked last, 0 for never.
 * @var string                                 $check    The link that asks it now.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'AI', 'simple-seo' ); ?></th>
			<td>
				<?php if ( 'ready' === $status ) : ?>
					<p class="simple-seo-ai-ready"><?php esc_html_e( 'A connected AI can write: the SEO block has the button “Write with AI” next to the SEO title, the meta description and the focus keyphrase.', 'simple-seo' ); ?></p>
				<?php elseif ( 'off' === $status ) : ?>
					<p class="simple-seo-ai-off"><?php esc_html_e( 'AI is turned off on this site (the WP_AI_SUPPORT constant or the wp_supports_ai filter), so the SEO block has no AI buttons.', 'simple-seo' ); ?></p>
				<?php else : ?>
					<p class="simple-seo-ai-none"><?php esc_html_e( 'No connected AI can write text yet. Connect a provider (Anthropic, Google, OpenAI or another) with its key in Settings → Connectors, and the buttons appear.', 'simple-seo' ); ?></p>
				<?php endif; ?>
				<?php if ( 'off' !== $status ) : ?>
					<p class="simple-seo-ai-checked">
						<?php
						if ( $checked ) {
							printf(
								/* translators: %s: a span of time, e.g. 5 mins. */
								esc_html__( 'Checked %s ago; it is checked again by itself, in the background.', 'simple-seo' ),
								esc_html( human_time_diff( $checked ) )
							);
						}
						?>
						<a href="<?php echo esc_url( $check ); ?>"><?php esc_html_e( 'Check now', 'simple-seo' ); ?></a>
					</p>
				<?php endif; ?>
				<?php if ( 'off' !== $status && current_user_can( 'manage_options' ) ) : ?>
					<p><a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Open Connectors', 'simple-seo' ); ?></a></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'The AI writes from the text of the page, in the language of the post or term (or of the site). In the SEO title and the meta description it writes only the words in place of {title} or {excerpt} of the template; the other variables stay. Whoever may edit the SEO fields may use it.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Alt text of images', 'simple-seo' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( Simple_SEO_AI::OPTION . '[alt_upload]' ); ?>" value="0">
				<label><input type="checkbox" name="<?php echo esc_attr( Simple_SEO_AI::OPTION . '[alt_upload]' ); ?>" value="1"<?php checked( $settings['alt_upload'] ); ?>> <?php esc_html_e( 'Write the alt text of a new image with AI after it is uploaded', 'simple-seo' ); ?></label>
				<p class="description"><?php esc_html_e( 'In the background, a moment after the upload: the AI looks at the picture and describes it, in the language of the image. Each new image is one request to the AI. An alt text someone wrote stays; one made from the name of the file gives way. In the media library each image has the button too, and bulk editing writes the empty alt texts of the images listed.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-ai-variants"><?php esc_html_e( 'Variants', 'simple-seo' ); ?></label></th>
			<td>
				<select id="simple-seo-ai-variants" name="<?php echo esc_attr( Simple_SEO_AI::OPTION . '[variants]' ); ?>">
					<?php for ( $simple_seo_count = 1; $simple_seo_count <= Simple_SEO_AI::MAX_VARIANTS; $simple_seo_count++ ) : ?>
						<option value="<?php echo esc_attr( (string) $simple_seo_count ); ?>"<?php selected( $settings['variants'], $simple_seo_count ); ?>><?php echo esc_html( number_format_i18n( $simple_seo_count ) ); ?></option>
					<?php endfor; ?>
				</select>
				<p class="description"><?php esc_html_e( 'How many variants the AI writes at a time: one goes straight into the field; of more, you choose one.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>
