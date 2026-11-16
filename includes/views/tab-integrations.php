<?php
/**
 * Integrations tab: the codes of webmaster tools, the tags of analytics and pixels, how they load,
 * consent, and what the theme prints of them already.
 *
 * @var array<string, mixed>    $settings
 * @var array<string, string[]> $theme    What the theme prints already: field => IDs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Integrations::OPTION;
$simple_seo_codes  = (array) ( $settings['verification'] ?? array() );
$simple_seo_tags   = (array) ( $settings['tags'] ?? array() );
$simple_seo_tools  = array(
	'google'    => __( 'Google Search Console', 'simple-seo' ),
	'bing'      => __( 'Bing Webmaster Tools', 'simple-seo' ),
	'baidu'     => __( 'Baidu Webmaster Tools', 'simple-seo' ),
	'pinterest' => __( 'Pinterest', 'simple-seo' ),
	'facebook'  => __( 'Meta (Facebook) domain verification', 'simple-seo' ),
	'ahrefs'    => __( 'Ahrefs', 'simple-seo' ),
);
$simple_seo_services = array(
	'google' => array( __( 'Google Analytics 4 or Google tag', 'simple-seo' ), 'G-XXXXXXXXXX' ),
	'gtm'    => array( __( 'Google Tag Manager', 'simple-seo' ), 'GTM-XXXXXXX' ),
	'meta'   => array( __( 'Meta Pixel', 'simple-seo' ), '123456789012345' ),
	'tiktok' => array( __( 'TikTok Pixel', 'simple-seo' ), 'C1A2B3C4D5E6F7G8H9I0' ),
);
?>
<p><?php esc_html_e( 'The codes that confirm the site to webmaster tools, and the tags of analytics and advertising: paste the code or the ID, or the whole snippet the service gives, and Simple SEO takes what it needs from it.', 'simple-seo' ); ?></p>

<?php Simple_SEO_Admin::section( 'verification' ); ?>
<p><?php esc_html_e( 'Each code is a meta tag on the home page, where these tools look for it.', 'simple-seo' ); ?></p>
<table class="form-table" role="presentation">
	<tbody>
		<?php foreach ( $simple_seo_tools as $simple_seo_field => $simple_seo_label ) : ?>
			<tr>
				<th scope="row"><label for="simple-seo-verification-<?php echo esc_attr( $simple_seo_field ); ?>"><?php echo esc_html( $simple_seo_label ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="simple-seo-verification-<?php echo esc_attr( $simple_seo_field ); ?>" name="<?php echo esc_attr( $simple_seo_option ); ?>[verification][<?php echo esc_attr( $simple_seo_field ); ?>]" value="<?php echo esc_attr( (string) ( $simple_seo_codes[ $simple_seo_field ] ?? '' ) ); ?>" spellcheck="false">
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php Simple_SEO_Admin::section_end(); ?>

<?php Simple_SEO_Admin::section( 'analytics' ); ?>
<?php if ( $theme ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			$simple_seo_list = array();
			foreach ( $theme as $simple_seo_field => $simple_seo_ids ) {
				$simple_seo_list[] = $simple_seo_services[ $simple_seo_field ][0] . ' ' . implode( ', ', $simple_seo_ids );
			}
			/* translators: %s: services with their IDs, e.g. Google Tag Manager GTM-ABC123. */
			echo esc_html( sprintf( __( 'The theme prints these itself (its options of custom code): %s. A tag printed by both is counted twice: keep it in one place.', 'simple-seo' ), implode( '; ', $simple_seo_list ) ) );
			?>
		</p>
	</div>
<?php endif; ?>
<table class="form-table" role="presentation">
	<tbody>
		<?php foreach ( $simple_seo_services as $simple_seo_field => $simple_seo_service ) : ?>
			<tr>
				<th scope="row"><label for="simple-seo-tag-<?php echo esc_attr( $simple_seo_field ); ?>"><?php echo esc_html( $simple_seo_service[0] ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="simple-seo-tag-<?php echo esc_attr( $simple_seo_field ); ?>" name="<?php echo esc_attr( $simple_seo_option ); ?>[tags][<?php echo esc_attr( $simple_seo_field ); ?>]" value="<?php echo esc_attr( (string) ( $simple_seo_tags[ $simple_seo_field ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $simple_seo_service[1] ); ?>" spellcheck="false">
					<?php if ( in_array( (string) ( $simple_seo_tags[ $simple_seo_field ] ?? '' ), $theme[ $simple_seo_field ] ?? array(), true ) ) : ?>
						<p class="description simple-seo-twice"><?php esc_html_e( 'The theme prints this one too: it is counted twice.', 'simple-seo' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Loading', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Loading', 'simple-seo' ); ?></legend>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[lazy]" value="0">
					<label style="display:block;margin-bottom:6px">
						<input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[lazy]" value="1" <?php checked( ! empty( $settings['lazy'] ) ); ?>>
						<?php esc_html_e( 'Load the tags on the visitor\'s first move', 'simple-seo' ); ?>
					</label>
					<p class="description">
						<?php
						/* translators: %s: number of seconds. */
						echo esc_html( sprintf( __( 'The events are taken at once, the scripts of Google, Meta and TikTok come when the visitor scrolls, touches or types, leaves the page, or after %s seconds: pages are faster and PageSpeed does not see them. Off: they load at once, as their own snippets do.', 'simple-seo' ), number_format_i18n( Simple_SEO_Integrations::LAZY_TIMER / 1000 ) ) );
						?>
					</p>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[consent]" value="0">
					<label style="display:block;margin:12px 0 6px">
						<input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[consent]" value="1" <?php checked( ! empty( $settings['consent'] ) ); ?>>
						<?php esc_html_e( 'Wait for the visitor\'s consent to cookies', 'simple-seo' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Google\'s consent mode starts denied and the pixels wait, until the theme\'s banner calls simpleSeoConsent( true ) or sends the event simple-seo-consent with { granted: true }. Without a banner that does it, nothing is counted.', 'simple-seo' ); ?></p>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[editors]" value="0">
					<label style="display:block;margin:12px 0 6px">
						<input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[editors]" value="1" <?php checked( ! empty( $settings['editors'] ) ); ?>>
						<?php esc_html_e( 'Count those who edit the site too', 'simple-seo' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off: no tags for users who can edit posts, so their visits do not count.', 'simple-seo' ); ?></p>
				</fieldset>
			</td>
		</tr>
	</tbody>
</table>
<?php Simple_SEO_Admin::section_end(); ?>
