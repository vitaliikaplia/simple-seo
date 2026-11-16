<?php
/**
 * IndexNow tab: the switch, the key and its file, why nothing is sent (if so), the batch waiting
 * and the last answers.
 *
 * @var array<string, mixed>                                                                      $settings
 * @var array<string, string>                                                                     $blockers Why nothing is sent, besides the switch.
 * @var array{urls: array<string, bool>, attempts: int}                                           $queue
 * @var int|false                                                                                 $next     When the batch goes.
 * @var array<int, array{time: int, count: int, url: string, code: int, ok: bool, message: string}> $entries
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_IndexNow::OPTION;
$simple_seo_key    = Simple_SEO_IndexNow::clean_key( $settings['key'] ?? '' );
$simple_seo_when   = static fn( int $time ): string => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
?>
<p><?php esc_html_e( 'IndexNow tells search engines at once when a page is published, changed, moved or taken down, so they visit it sooner than they would by themselves. Simple SEO sends the addresses of such pages to Bing, together, a minute after the change; the search engines that take part in IndexNow share them. Google does not take part: it finds changes through the sitemap.', 'simple-seo' ); ?></p>

<?php foreach ( $blockers as $simple_seo_reason ) : ?>
	<div class="notice notice-warning inline"><p><?php echo esc_html( $simple_seo_reason ); ?></p></div>
<?php endforeach; ?>

<?php Simple_SEO_Admin::section( 'settings' ); ?>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'IndexNow', 'simple-seo' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $simple_seo_option . '[enabled]' ); ?>" value="0">
				<label><input type="checkbox" name="<?php echo esc_attr( $simple_seo_option . '[enabled]' ); ?>" value="1"<?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php esc_html_e( 'Tell search engines about the changes of pages', 'simple-seo' ); ?></label>
				<p class="description"><?php esc_html_e( 'Only pages search engines may show are sent, as the sitemap lists them; a page taken down or moved is sent too, so they see it is gone.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Key', 'simple-seo' ); ?></th>
			<td>
				<?php if ( '' !== $simple_seo_key ) : ?>
					<p><code><?php echo esc_html( $simple_seo_key ); ?></code></p>
					<p class="description simple-seo-indexnow-file">
						<?php
						if ( ! empty( $settings['enabled'] ) ) {
							printf(
								/* translators: %s: link to the key file. */
								esc_html__( 'Bing checks that the site owns the key by its file: %s', 'simple-seo' ),
								'<a href="' . esc_url( Simple_SEO_IndexNow::key_location() ) . '" target="_blank" rel="noopener">' . esc_html( Simple_SEO_IndexNow::key_location() ) . '</a>'
							);
						} else {
							esc_html_e( 'Its file is answered at the root of the site while IndexNow is on.', 'simple-seo' );
						}
						?>
					</p>
					<p>
						<input type="hidden" name="<?php echo esc_attr( $simple_seo_option . '[new_key]' ); ?>" value="0">
						<label><input type="checkbox" name="<?php echo esc_attr( $simple_seo_option . '[new_key]' ); ?>" value="1"> <?php esc_html_e( 'Make a new key', 'simple-seo' ); ?></label>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'A key is made when the settings are saved.', 'simple-seo' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	</tbody>
</table>

<?php Simple_SEO_Admin::section_end(); ?>

<?php Simple_SEO_Admin::section( 'answers' ); ?>
<?php if ( $queue['urls'] ) : ?>
	<p>
		<?php
		echo esc_html(
			false !== $next
				/* translators: 1: number of addresses, 2: date and time. */
				? sprintf( __( 'Waiting to be sent: %1$s addresses, at %2$s.', 'simple-seo' ), number_format_i18n( count( $queue['urls'] ) ), $simple_seo_when( (int) $next ) )
				/* translators: %s: number of addresses. */
				: sprintf( __( 'Waiting to be sent: %s addresses.', 'simple-seo' ), number_format_i18n( count( $queue['urls'] ) ) )
		);
		?>
	</p>
<?php endif; ?>
<?php if ( $entries ) : ?>
	<table class="widefat striped simple-seo-indexnow-log">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'When', 'simple-seo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Addresses', 'simple-seo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Answer', 'simple-seo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $entries as $simple_seo_entry ) : ?>
				<tr>
					<td><?php echo esc_html( $simple_seo_when( (int) ( $simple_seo_entry['time'] ?? 0 ) ) ); ?></td>
					<td>
						<?php
						$simple_seo_count = (int) ( $simple_seo_entry['count'] ?? 0 );
						echo esc_html(
							$simple_seo_count > 1
								/* translators: 1: an address, 2: how many more. */
								? sprintf( __( '%1$s and %2$s more', 'simple-seo' ), rawurldecode( (string) ( $simple_seo_entry['url'] ?? '' ) ), number_format_i18n( $simple_seo_count - 1 ) )
								: rawurldecode( (string) ( $simple_seo_entry['url'] ?? '' ) )
						);
						?>
					</td>
					<td class="<?php echo ! empty( $simple_seo_entry['ok'] ) ? 'simple-seo-indexnow-ok' : 'simple-seo-indexnow-failed'; ?>"><?php echo esc_html( (string) ( $simple_seo_entry['message'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php else : ?>
	<p><?php esc_html_e( 'Nothing has been sent yet.', 'simple-seo' ); ?></p>
<?php endif; ?>
<?php Simple_SEO_Admin::section_end(); ?>
