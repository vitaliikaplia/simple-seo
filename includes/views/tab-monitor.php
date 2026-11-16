<?php
/**
 * The 404 tab: the switch of the monitor of pages not found.
 *
 * @var array{enabled: bool} $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Pages not found', 'simple-seo' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( Simple_SEO_Monitor::OPTION . '[enabled]' ); ?>" value="0">
				<label><input type="checkbox" name="<?php echo esc_attr( Simple_SEO_Monitor::OPTION . '[enabled]' ); ?>" value="1"<?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php esc_html_e( 'Count the addresses of this site that answer 404', 'simple-seo' ); ?></label>
				<p class="description">
					<?php
					/* translators: %s: number of days. */
					echo esc_html( sprintf( __( 'For each: how many times it was asked for, the first and the last time, where the last visitor came from; no IP addresses. An address is forgotten %s days after it was last asked for. A redirect of the theme or of WordPress answers first, so an address it sends elsewhere is not counted.', 'simple-seo' ), number_format_i18n( Simple_SEO_Monitor::DAYS ) ) );
					?>
				</p>
				<?php if ( current_user_can( Simple_SEO_Access::CAP_REPORTS ) ) : ?>
					<p><a href="<?php echo esc_url( Simple_SEO_Monitor::page_url() ); ?>"><?php esc_html_e( 'Open the list of pages not found', 'simple-seo' ); ?></a></p>
				<?php endif; ?>
			</td>
		</tr>
	</tbody>
</table>
