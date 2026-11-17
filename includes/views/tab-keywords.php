<?php
/**
 * The tab of the keyword research: its sources, and the language and country searched in.
 *
 * @var array<string, mixed>                                                                   $settings
 * @var array<string, string>                                                                   $languages Code => name.
 * @var array<string, string>                                                                   $countries Code => name.
 * @var array{language: string, country: string}                                                $site      Those of the site.
 * @var array{time: int, balance: float|null, error: string}|null                               $balance   Of DataForSEO, as last asked.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Keywords::OPTION;
$simple_seo_boxes  = array(
	'suggest'        => array(
		__( 'Suggestions of Google search', 'simple-seo' ),
		__( 'What people type: Google’s own suggestions for the phrase, for the phrase with each letter, with question words before it and prepositions after it. Free and without a key; this server asks Google some 50 times for a new phrase, and keeps the answer a week.', 'simple-seo' ),
	),
	'search_console' => array(
		__( 'Search Console', 'simple-seo' ),
		Simple_SEO_Search_Console::connected()
			? __( 'The queries this site was shown for in the last 90 days with the words of the phrase, and its impressions, clicks and position for each search.', 'simple-seo' )
			: __( 'Not connected yet: connect it on the Search Console tab, and the searches get the impressions, clicks and position of this site.', 'simple-seo' ),
	),
	'ai'             => array(
		__( 'AI', 'simple-seo' ),
		Simple_SEO_AI::ready()
			? __( 'The AI connected in Settings → Connectors groups the searches into topics under a parent topic and plans an article: its title, sections, subheadings and questions. It gives no numbers.', 'simple-seo' )
			: __( 'No connected AI can write yet: connect a provider in Settings → Connectors for topics and the structure of an article.', 'simple-seo' ),
	),
);
?>
<?php Simple_SEO_Admin::section( 'sources' ); ?>
<p>
	<?php
	printf(
		/* translators: %s: link to the page of the keyword research. */
		esc_html__( 'The searches around a phrase, for choosing keyphrases and planning articles: on the page %s under the SEO menu, and by the focus keyphrase in the SEO block of a post.', 'simple-seo' ),
		'<a href="' . esc_url( Simple_SEO_Keywords::page_url() ) . '">' . esc_html__( 'Keywords', 'simple-seo' ) . '</a>'
	);
	?>
</p>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Sources', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Sources', 'simple-seo' ); ?></legend>
					<?php foreach ( $simple_seo_boxes as $simple_seo_key => $simple_seo_box ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $simple_seo_option . '[' . $simple_seo_key . ']' ); ?>" value="0">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $simple_seo_option . '[' . $simple_seo_key . ']' ); ?>" value="1" <?php checked( $settings[ $simple_seo_key ] ); ?>>
							<?php echo esc_html( $simple_seo_box[0] ); ?>
						</label>
						<p class="description" style="margin:0 0 1em 1.75em"><?php echo esc_html( $simple_seo_box[1] ); ?></p>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-keywords-language"><?php esc_html_e( 'Language', 'simple-seo' ); ?></label></th>
			<td>
				<select id="simple-seo-keywords-language" name="<?php echo esc_attr( $simple_seo_option ); ?>[language]">
					<option value="">
						<?php
						/* translators: %s: name of a language. */
						echo esc_html( sprintf( __( 'That of the site (%s)', 'simple-seo' ), $site['language'] ) );
						?>
					</option>
					<?php foreach ( $languages as $simple_seo_code => $simple_seo_name ) : ?>
						<option value="<?php echo esc_attr( $simple_seo_code ); ?>" <?php selected( $settings['language'], $simple_seo_code ); ?>><?php echo esc_html( $simple_seo_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-keywords-country"><?php esc_html_e( 'Country', 'simple-seo' ); ?></label></th>
			<td>
				<select id="simple-seo-keywords-country" name="<?php echo esc_attr( $simple_seo_option ); ?>[country]">
					<option value="">
						<?php
						/* translators: %s: name of a country. */
						echo esc_html( sprintf( __( 'That of the site (%s)', 'simple-seo' ), $site['country'] ) );
						?>
					</option>
					<?php foreach ( $countries as $simple_seo_code => $simple_seo_name ) : ?>
						<option value="<?php echo esc_attr( $simple_seo_code ); ?>" <?php selected( $settings['country'], $simple_seo_code ); ?>><?php echo esc_html( $simple_seo_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Where and in what language the searches are looked for, unless the page asks for others.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>
<?php Simple_SEO_Admin::section_end(); ?>

<?php Simple_SEO_Admin::section( 'volumes' ); ?>
<p><?php esc_html_e( 'How many search each phrase a month, how hard it is to rank for, what a click costs in ads and how the searches went over the last twelve months: Google gives these only to its paid tools, so they come from a service of your own.', 'simple-seo' ); ?></p>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="simple-seo-keywords-volumes"><?php esc_html_e( 'Volumes from', 'simple-seo' ); ?></label></th>
			<td>
				<select id="simple-seo-keywords-volumes" name="<?php echo esc_attr( $simple_seo_option ); ?>[volumes]">
					<option value="" <?php selected( $settings['volumes'], '' ); ?>><?php esc_html_e( 'None', 'simple-seo' ); ?></option>
					<option value="dataforseo" <?php selected( $settings['volumes'], 'dataforseo' ); ?>>DataForSEO</option>
				</select>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to DataForSEO. */
						esc_html__( '%s charges its account by the request, with no subscription: about $0.15–0.20 for a new phrase (more searches of it, their volumes and difficulty) and $0.01 for its Google Trends; each answer is kept a month. The account is topped up from $50.', 'simple-seo' ),
						'<a href="https://dataforseo.com/" target="_blank" rel="noopener noreferrer">DataForSEO</a>'
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-keywords-dfs-login"><?php esc_html_e( 'API login of DataForSEO', 'simple-seo' ); ?></label></th>
			<td>
				<input type="text" id="simple-seo-keywords-dfs-login" name="<?php echo esc_attr( $simple_seo_option ); ?>[dfs_login]" value="<?php echo esc_attr( $settings['dfs_login'] ); ?>" class="regular-text" autocomplete="off">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="simple-seo-keywords-dfs-password"><?php esc_html_e( 'API password of DataForSEO', 'simple-seo' ); ?></label></th>
			<td>
				<input type="password" id="simple-seo-keywords-dfs-password" name="<?php echo esc_attr( $simple_seo_option ); ?>[dfs_password]" value="" class="regular-text" autocomplete="new-password"<?php echo '' !== $settings['dfs_secret'] ? ' placeholder="' . esc_attr__( 'Saved — leave empty to keep it', 'simple-seo' ) . '"' : ''; ?>>
				<?php if ( '' !== $settings['dfs_secret'] ) : ?>
					<label style="margin-left:1em"><input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[dfs_forget]" value="1"> <?php esc_html_e( 'Remove the saved password', 'simple-seo' ); ?></label>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'The API login and password from app.dataforseo.com (API access): the API password is not that of the account. It is kept encrypted with the keys of this site.', 'simple-seo' ); ?></p>
				<?php if ( null !== $balance ) : ?>
					<?php if ( '' !== $balance['error'] ) : ?>
						<p class="simple-seo-dfs-error"><strong><?php echo esc_html( $balance['error'] ); ?></strong></p>
					<?php else : ?>
						<p class="simple-seo-dfs-balance">
							<?php
							printf(
								/* translators: 1: the balance in USD, 2: a span of time, e.g. 5 mins. */
								esc_html__( 'Connected: the balance is $%1$s (checked %2$s ago, when the settings were saved).', 'simple-seo' ),
								esc_html( number_format_i18n( (float) $balance['balance'], 2 ) ),
								esc_html( human_time_diff( $balance['time'] ) )
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Options', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Options', 'simple-seo' ); ?></legend>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[trends]" value="0">
					<label><input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[trends]" value="1" <?php checked( $settings['trends'] ); ?>> <?php esc_html_e( 'Google Trends of the phrase: the interest over the last five years', 'simple-seo' ); ?></label><br>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[dfs_sandbox]" value="0">
					<label><input type="checkbox" name="<?php echo esc_attr( $simple_seo_option ); ?>[dfs_sandbox]" value="1" <?php checked( $settings['dfs_sandbox'] ); ?>> <?php esc_html_e( 'Sandbox: made-up data, free — to try the connection', 'simple-seo' ); ?></label>
				</fieldset>
			</td>
		</tr>
	</tbody>
</table>
<?php Simple_SEO_Admin::section_end(); ?>
