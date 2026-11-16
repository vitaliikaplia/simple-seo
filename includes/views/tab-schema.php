<?php
/**
 * Schema tab: whether Simple SEO prints the graph, whom the site represents, the names (per language
 * with wp-loc), the logo, the profiles elsewhere, the breadcrumb trail, and the graph of the home page.
 *
 * @var array<string, mixed>  $settings
 * @var array<string, string> $names
 * @var string                $language Name of the language being edited, '' without wp-loc.
 * @var array<int, mixed>     $example  The graph of the home page.
 * @var array<string, string> $types    Post types with SEO fields => their names.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Schema::OPTION;
$simple_seo_names  = Simple_SEO_Schema::NAMES;
$simple_seo_site   = Simple_SEO_Variables::plain( (string) get_option( 'blogname' ) );
?>
<p><?php esc_html_e( 'Structured data tells search engines what a page is and who stands behind the site: the site, the organization or person, the page with its title and image, the article with its author, the breadcrumb trail. Simple SEO puts it into the head of every page as one JSON-LD graph.', 'simple-seo' ); ?></p>

<?php if ( ! Simple_SEO_Schema::theme_allows() ) : ?>
	<div class="notice notice-warning inline">
		<p><?php esc_html_e( 'The active theme switches off the graph of SEO plugins (the filter wpseo_json_ld_output), most likely because it prints a graph of its own. In the mode Auto Simple SEO prints none on this site.', 'simple-seo' ); ?></p>
	</div>
<?php endif; ?>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'The graph', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'The graph', 'simple-seo' ); ?></legend>
					<?php
					foreach ( array(
						'auto'   => __( 'Auto: printed unless the theme switches off the graph of SEO plugins (recommended)', 'simple-seo' ),
						'always' => __( 'Always: printed whatever the theme says', 'simple-seo' ),
						'off'    => __( 'Off: Simple SEO prints no graph', 'simple-seo' ),
					) as $simple_seo_mode => $simple_seo_label ) :
						?>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="<?php echo esc_attr( $simple_seo_option ); ?>[mode]" value="<?php echo esc_attr( $simple_seo_mode ); ?>" <?php checked( Simple_SEO_Schema::mode(), $simple_seo_mode ); ?>>
							<?php echo esc_html( $simple_seo_label ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Two graphs on one page confuse search engines. Choose Always on a theme that switches off the graph of SEO plugins without printing its own, or after switching off its own.', 'simple-seo' ); ?></p>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'The site represents', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'The site represents', 'simple-seo' ); ?></legend>
					<label style="display:block;margin-bottom:6px">
						<input type="radio" name="<?php echo esc_attr( $simple_seo_option ); ?>[represents]" value="organization" <?php checked( Simple_SEO_Schema::represents(), 'organization' ); ?>>
						<?php esc_html_e( 'An organization: a company, a shop, a studio', 'simple-seo' ); ?>
					</label>
					<label style="display:block;margin-bottom:6px">
						<input type="radio" name="<?php echo esc_attr( $simple_seo_option ); ?>[represents]" value="person" <?php checked( Simple_SEO_Schema::represents(), 'person' ); ?>>
						<?php esc_html_e( 'A person:', 'simple-seo' ); ?>
					</label>
					<label for="simple-seo-schema-person" class="screen-reader-text"><?php esc_html_e( 'The person', 'simple-seo' ); ?></label>
					<?php
					wp_dropdown_users(
						array(
							'name'              => $simple_seo_option . '[person]',
							'id'                => 'simple-seo-schema-person',
							'selected'          => (int) ( $settings['person'] ?? 0 ),
							'show_option_none'  => __( '— Choose a user —', 'simple-seo' ),
							'option_none_value' => 0,
							'capability'        => array( 'edit_posts' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'A person is a user of the site: the name, the avatar, the biography and the website of their profile.', 'simple-seo' ); ?></p>
				</fieldset>
			</td>
		</tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'Names', 'simple-seo' ); ?></h2>
<?php
/* translators: %s: language name, e.g. English. */
$simple_seo_notice = __( 'The names are kept for each language; you are editing them for: %s. The other settings on this tab are common to all languages.', 'simple-seo' );
require __DIR__ . '/language-notice.php';
?>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="simple-seo-schema-name"><?php esc_html_e( 'Name of the organization', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-schema-name" name="<?php echo esc_attr( $simple_seo_names ); ?>[name]" value="<?php echo esc_attr( (string) ( $names['name'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $simple_seo_site ); ?>">
				<p class="description"><?php esc_html_e( 'Empty: the title of the site.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-schema-alternate"><?php esc_html_e( 'Other name of the organization', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-schema-alternate" name="<?php echo esc_attr( $simple_seo_names ); ?>[alternate]" value="<?php echo esc_attr( (string) ( $names['alternate'] ?? '' ) ); ?>">
				<p class="description"><?php esc_html_e( 'Optional: the legal name, an abbreviation.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-schema-site-name"><?php esc_html_e( 'Name of the site', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-schema-site-name" name="<?php echo esc_attr( $simple_seo_names ); ?>[site_name]" value="<?php echo esc_attr( (string) ( $names['site_name'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $simple_seo_site ); ?>">
				<p class="description"><?php esc_html_e( 'What search results may show as the site\'s name. Empty: the title of the site.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-schema-site-alternate"><?php esc_html_e( 'Other name of the site', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="simple-seo-schema-site-alternate" name="<?php echo esc_attr( $simple_seo_names ); ?>[site_alternate]" value="<?php echo esc_attr( (string) ( $names['site_alternate'] ?? '' ) ); ?>">
			</td>
		</tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'Types of pages', 'simple-seo' ); ?></h2>
<p><?php esc_html_e( 'What the posts of each type are, unless a post has a type of its own in its SEO block. A page with questions and answers becomes a FAQ page as well.', 'simple-seo' ); ?></p>
<table class="widefat striped simple-seo-schema-types" style="max-width:860px">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Post type', 'simple-seo' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Type of page', 'simple-seo' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Type of article', 'simple-seo' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach ( $types as $simple_seo_type => $simple_seo_type_name ) :
			$simple_seo_page    = Simple_SEO_Schema::known( $settings['page_types'][ $simple_seo_type ] ?? '', Simple_SEO_Schema::PAGE_TYPES ) ?: Simple_SEO_Schema::builtin_page_type( $simple_seo_type );
			$simple_seo_article = Simple_SEO_Schema::known( $settings['article_types'][ $simple_seo_type ] ?? '', Simple_SEO_Schema::ARTICLE_TYPES ) ?: Simple_SEO_Schema::builtin_article_type( $simple_seo_type );
			?>
			<tr>
				<td><?php echo esc_html( $simple_seo_type_name ); ?> <code><?php echo esc_html( $simple_seo_type ); ?></code></td>
				<td>
					<label for="simple-seo-schema-page-<?php echo esc_attr( $simple_seo_type ); ?>" class="screen-reader-text"><?php esc_html_e( 'Type of page', 'simple-seo' ); ?></label>
					<select id="simple-seo-schema-page-<?php echo esc_attr( $simple_seo_type ); ?>" name="<?php echo esc_attr( $simple_seo_option ); ?>[page_types][<?php echo esc_attr( $simple_seo_type ); ?>]">
						<?php foreach ( Simple_SEO_Schema::page_type_labels() as $simple_seo_value => $simple_seo_label ) : ?>
							<option value="<?php echo esc_attr( $simple_seo_value ); ?>"<?php selected( $simple_seo_page, $simple_seo_value ); ?>><?php echo esc_html( $simple_seo_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td>
					<label for="simple-seo-schema-article-<?php echo esc_attr( $simple_seo_type ); ?>" class="screen-reader-text"><?php esc_html_e( 'Type of article', 'simple-seo' ); ?></label>
					<select id="simple-seo-schema-article-<?php echo esc_attr( $simple_seo_type ); ?>" name="<?php echo esc_attr( $simple_seo_option ); ?>[article_types][<?php echo esc_attr( $simple_seo_type ); ?>]">
						<?php foreach ( Simple_SEO_Schema::article_type_labels() as $simple_seo_value => $simple_seo_label ) : ?>
							<option value="<?php echo esc_attr( $simple_seo_value ); ?>"<?php selected( $simple_seo_article, $simple_seo_value ); ?>><?php echo esc_html( $simple_seo_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Logo and profiles', 'simple-seo' ); ?></h2>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Logo', 'simple-seo' ); ?></th>
			<td>
				<?php
				echo Simple_SEO_Admin::image_picker( $simple_seo_option . '[logo]', 'simple-seo-schema-logo', (int) ( $settings['logo'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
				?>
				<p class="description"><?php esc_html_e( 'At least 112 × 112 pixels. Empty: the logo of the theme, then the site icon.', 'simple-seo' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-schema-profiles"><?php esc_html_e( 'Profiles elsewhere', 'simple-seo' ); ?></label></th>
			<td>
				<textarea id="simple-seo-schema-profiles" name="<?php echo esc_attr( $simple_seo_option ); ?>[profiles]" class="large-text code" rows="4" style="max-width:860px" placeholder="https://www.facebook.com/…"><?php echo esc_textarea( implode( "\n", (array) ( $settings['profiles'] ?? array() ) ) ); ?></textarea>
				<p class="description">
					<?php
					/* translators: %d: number of addresses. */
					echo esc_html( sprintf( __( 'Addresses of the organization\'s or person\'s pages on Facebook, Instagram, LinkedIn, YouTube, Wikipedia and the like, one per line, %d at most. The X account from the Social networks tab is added by itself.', 'simple-seo' ), Simple_SEO_Schema::MAX_PROFILES ) );
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Breadcrumbs', 'simple-seo' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[breadcrumbs]" value="0">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[breadcrumbs]" value="1" <?php checked( ! empty( $settings['breadcrumbs'] ) ); ?>>
					<?php esc_html_e( 'Put the breadcrumb trail of each page into the graph', 'simple-seo' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Search results may show the trail instead of the address. Leave it on when the theme shows breadcrumbs.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>

<?php if ( $example ) : ?>
	<details>
		<summary><?php esc_html_e( 'The graph of the home page as saved now', 'simple-seo' ); ?></summary>
		<pre class="simple-seo-schema-example" style="max-width:860px;max-height:400px;overflow:auto;white-space:pre-wrap;background:#fff;border:1px solid #c3c4c7;padding:8px"><?php echo esc_html( (string) wp_json_encode( $example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
	</details>
<?php endif; ?>
