<?php
/**
 * The tab of the keyword research: its sources, and the language and country searched in.
 *
 * @var array{suggest: bool, search_console: bool, ai: bool, language: string, country: string} $settings
 * @var array<string, string>                                                                   $languages Code => name.
 * @var array<string, string>                                                                   $countries Code => name.
 * @var array{language: string, country: string}                                                $site      Those of the site.
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
