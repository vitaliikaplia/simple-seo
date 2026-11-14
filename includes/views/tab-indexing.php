<?php
/**
 * Indexing tab: which kinds of pages search engines may show, and where attachment pages go.
 *
 * @var array<string, array{label: string, slots: array<string, string>}> $groups
 * @var string                                                           $attachments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Indexing::OPTION;

// Attachment pages are a post type of their own, without SEO fields: their row joins the post types.
$groups['posts']['slots']['post:attachment'] = __( 'Attachment pages', 'simple-seo' );
?>
<p><?php esc_html_e( 'Choose which kinds of pages search engines may show. A hidden kind gets "noindex" and stays out of the XML sitemap; links on it are still followed. Each post, page or term can override its kind in its own SEO fields. Search results and the 404 page are always hidden.', 'simple-seo' ); ?></p>

<?php if ( ! get_option( 'blog_public' ) ) : ?>
	<div class="notice notice-warning inline"><p>
		<?php
		printf(
			/* translators: %s: link to Settings → Reading. */
			esc_html__( 'The whole site is hidden from search engines in %s, whatever is chosen here.', 'simple-seo' ),
			'<a href="' . esc_url( admin_url( 'options-reading.php' ) ) . '">' . esc_html__( 'Settings → Reading', 'simple-seo' ) . '</a>'
		);
		?>
	</p></div>
<?php endif; ?>

<h2><?php esc_html_e( 'XML sitemap', 'simple-seo' ); ?></h2>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Sitemap', 'simple-seo' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( Simple_SEO_Sitemaps::OPTION ); ?>[enabled]" value="0">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Simple_SEO_Sitemaps::OPTION ); ?>[enabled]" value="1" <?php checked( (bool) Simple_SEO_Settings::value( Simple_SEO_Sitemaps::OPTION, 'enabled' ) ); ?>>
					<?php esc_html_e( 'Create an XML sitemap', 'simple-seo' ); ?>
				</label>
				<?php if ( Simple_SEO_Sitemaps::enabled() ) : ?>
					<p><a href="<?php echo esc_url( Simple_SEO_Sitemaps::index_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Simple_SEO_Sitemaps::index_url() ); ?></a></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'It lists only what search engines may show, with the images of posts; with wp-loc each language has its own sitemaps under its prefix, every address with its translations. Its address is the one Yoast SEO used, so a sitemap already submitted to Google Search Console keeps working, and robots.txt names it. Switched off, the WordPress sitemap (wp-sitemap.xml) takes its place.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'Attachment pages', 'simple-seo' ); ?></h2>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'An attachment page', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'An attachment page', 'simple-seo' ); ?></legend>
					<?php
					foreach ( array(
						Simple_SEO_Indexing::ATTACHMENTS_FILE   => __( 'redirects to the file itself (recommended)', 'simple-seo' ),
						Simple_SEO_Indexing::ATTACHMENTS_PARENT => __( 'redirects to the post it is attached to, or to the file when there is none', 'simple-seo' ),
						Simple_SEO_Indexing::ATTACHMENTS_KEEP   => __( 'stays a page of its own', 'simple-seo' ),
					) as $simple_seo_mode => $simple_seo_label ) :
						?>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="<?php echo esc_attr( $simple_seo_option ); ?>[attachments]" value="<?php echo esc_attr( $simple_seo_mode ); ?>" <?php checked( $attachments, $simple_seo_mode ); ?>>
							<?php echo esc_html( $simple_seo_label ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'WordPress makes a page for every uploaded file. It has nothing but the image, so search engines rate it as thin content.', 'simple-seo' ); ?></p>
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
			<?php $simple_seo_name = $simple_seo_option . '[index][' . $simple_seo_slot . ']'; ?>
			<tr>
				<th scope="row"><?php echo esc_html( $simple_seo_label ); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_name ); ?>" value="0">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $simple_seo_name ); ?>" value="1" <?php checked( ! Simple_SEO_Indexing::slot_is_noindex( $simple_seo_slot ) ); ?>>
						<?php esc_html_e( 'Show in search results', 'simple-seo' ); ?>
					</label>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endforeach; ?>
