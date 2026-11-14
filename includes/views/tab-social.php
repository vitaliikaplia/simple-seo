<?php
/**
 * Social networks tab: the default image and the site's X account.
 *
 * @var array<string, mixed> $social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Social::OPTION;
?>
<p><?php esc_html_e( 'When a page is shared on Facebook, Telegram, Viber, LinkedIn, X and the like, the preview shows its title, description and image: the SEO title and meta description, unless a post or term has its own for social networks.', 'simple-seo' ); ?></p>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Default image', 'simple-seo' ); ?></th>
			<td>
				<?php
				echo Simple_SEO_Admin::image_picker( $simple_seo_option . '[default_image]', 'simple-seo-default-image', (int) ( $social['default_image'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
				?>
				<p class="description"><?php esc_html_e( 'For pages without an image of their own or a featured image: a logo or a picture of the site, best 1200 × 630 pixels.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-x-account"><?php esc_html_e( 'X account', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-x-account" name="<?php echo esc_attr( $simple_seo_option ); ?>[x_account]" value="<?php echo esc_attr( (string) ( $social['x_account'] ?? '' ) ); ?>" placeholder="@account">
				<p class="description"><?php esc_html_e( 'Optional: the site\'s account on X (Twitter), as @name or a link to it.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>
