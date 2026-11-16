<?php
/**
 * llms.txt tab: the switch, the summary and details (per language with wp-loc), which post types
 * and taxonomies get a section, how many links each, the Markdown versions of pages, and the file
 * as the site serves it.
 *
 * @var array<string, mixed> $settings
 * @var array<string, mixed> $text
 * @var string               $language Name of the language being edited, '' without wp-loc.
 * @var string               $file     Path of an llms.txt file in the site root, '' when there is none.
 * @var string               $address  Address of the file in the language being edited.
 * @var string               $preview  The file in that language, '' while it is not served.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Llms::OPTION;
$simple_seo_text   = Simple_SEO_Llms::TEXT;
$simple_seo_where  = array(
	'main'     => __( 'Section of its own', 'simple-seo' ),
	'optional' => __( 'Optional section', 'simple-seo' ),
	''         => __( 'Not listed', 'simple-seo' ),
);
?>
<p>
	<?php
	printf(
		/* translators: %s: link to llmstxt.org. */
		esc_html__( 'llms.txt is a Markdown file that tells AI assistants and agents what the site is about and which of its pages matter (%s). Simple SEO builds it from the pages search engines may show, with their descriptions, and keeps it up to date.', 'simple-seo' ),
		'<a href="https://llmstxt.org/" target="_blank" rel="noopener">llmstxt.org</a>'
	);
	?>
</p>

<?php if ( ! get_option( 'blog_public' ) ) : ?>
	<div class="notice notice-warning inline"><p>
		<?php
		printf(
			/* translators: %s: link to Settings → Reading. */
			esc_html__( 'The site is hidden from search engines in %s, so there is no llms.txt either.', 'simple-seo' ),
			'<a href="' . esc_url( admin_url( 'options-reading.php' ) ) . '">' . esc_html__( 'Settings → Reading', 'simple-seo' ) . '</a>'
		);
		?>
	</p></div>
<?php endif; ?>
<?php if ( '' !== $file ) : ?>
	<div class="notice notice-warning inline"><p>
		<?php
		/* translators: %s: path of the file. */
		printf( esc_html__( 'There is an llms.txt file in the site root (%s): the server sends it instead of the one Simple SEO builds.', 'simple-seo' ), '<code>' . esc_html( $file ) . '</code>' );
		?>
	</p></div>
<?php endif; ?>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="simple-seo-llms-enabled"><?php esc_html_e( 'llms.txt', 'simple-seo' ); ?></label></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[enabled]" value="0">
				<label>
					<input type="checkbox" id="simple-seo-llms-enabled" name="<?php echo esc_attr( $simple_seo_option ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
					<?php esc_html_e( 'Build llms.txt', 'simple-seo' ); ?>
				</label>
				<?php if ( '' !== $preview ) : ?>
					<p class="description"><a href="<?php echo esc_url( $address ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $address ); ?></a></p>
				<?php endif; ?>
			</td>
		</tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'About the site', 'simple-seo' ); ?></h2>
<?php
/* translators: %s: language name, e.g. English. */
$simple_seo_notice = __( 'The summary and the details are kept for each language, and each language has its own llms.txt under its prefix; you are editing them for: %s. The other settings on this tab are common to all languages.', 'simple-seo' );
require __DIR__ . '/language-notice.php';
?>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="simple-seo-llms-summary"><?php esc_html_e( 'Summary', 'simple-seo' ); ?></label></th>
			<td>
				<textarea id="simple-seo-llms-summary" name="<?php echo esc_attr( $simple_seo_text ); ?>[summary]" class="large-text" rows="2" style="max-width:860px"><?php echo esc_textarea( (string) ( $text['summary'] ?? '' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One or two sentences on what the site is and for whom. Empty: the description of the home page, or the tagline.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-llms-details"><?php esc_html_e( 'Details', 'simple-seo' ); ?></label></th>
			<td>
				<textarea id="simple-seo-llms-details" name="<?php echo esc_attr( $simple_seo_text ); ?>[details]" class="large-text" rows="5" style="max-width:860px"><?php echo esc_textarea( (string) ( $text['details'] ?? '' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Optional: what an assistant should know besides the summary — services, prices, contacts, how to read the sections. Markdown without headings.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'Sections', 'simple-seo' ); ?></h2>
<p><?php esc_html_e( 'Each post type and taxonomy can have a section of links of its own or go into the Optional section, which an assistant may skip when it is short of space. Only what search engines may show is listed: pages in their order, other posts newest first, terms with the most posts first.', 'simple-seo' ); ?></p>
<table class="form-table" role="presentation">
	<tbody>
	<?php foreach ( Simple_SEO_Llms::slots() as $simple_seo_slot => $simple_seo_label ) : ?>
		<?php $simple_seo_id = 'simple-seo-llms-' . sanitize_html_class( str_replace( ':', '-', $simple_seo_slot ) ); ?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $simple_seo_id ); ?>"><?php echo esc_html( $simple_seo_label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $simple_seo_id ); ?>" name="<?php echo esc_attr( $simple_seo_option . '[sections][' . $simple_seo_slot . ']' ); ?>">
					<?php foreach ( $simple_seo_where as $simple_seo_value => $simple_seo_name ) : ?>
						<option value="<?php echo esc_attr( $simple_seo_value ); ?>" <?php selected( Simple_SEO_Llms::section_of( $simple_seo_slot ), $simple_seo_value ); ?>><?php echo esc_html( $simple_seo_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( Simple_SEO_Indexing::slot_is_noindex( $simple_seo_slot ) ) : ?>
					<p class="description"><?php esc_html_e( 'Hidden from search engines on the Indexing tab: only those shown on their own are listed.', 'simple-seo' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
		<tr>
			<th scope="row"><label for="simple-seo-llms-limit"><?php esc_html_e( 'Links in a section', 'simple-seo' ); ?></label></th>
			<td>
				<input type="number" id="simple-seo-llms-limit" class="small-text" name="<?php echo esc_attr( $simple_seo_option ); ?>[limit]" value="<?php echo esc_attr( (string) Simple_SEO_Llms::limit() ); ?>" min="1" max="500">
				<p class="description"><?php esc_html_e( 'At most this many from each post type or taxonomy: the file should fit into what an assistant reads at once.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>

<?php Simple_SEO_Markdown::render_section(); ?>

<?php if ( '' !== $preview ) : ?>
	<h2><?php esc_html_e( 'What the site serves', 'simple-seo' ); ?></h2>
	<p class="description"><?php esc_html_e( 'As saved; it is built again whenever content or settings change.', 'simple-seo' ); ?></p>
	<pre style="background:#fff;border:1px solid #c3c4c7;padding:8px 12px;max-width:860px;max-height:420px;overflow:auto;white-space:pre-wrap;word-break:break-word"><?php echo esc_html( $preview ); ?></pre>
<?php endif; ?>
