<?php
/**
 * The page of the keyword research under the SEO menu: a phrase, its language and country, then the
 * searches around it — the questions and topics to filter by, the data of Search Console, and with
 * the AI topics and the structure of an article (assets/js/keywords.js).
 *
 * @var string                              $seed
 * @var string                              $language
 * @var string                              $country
 * @var array<string, mixed>|WP_Error|null  $result    Simple_SEO_Keywords::research(), null before a search.
 * @var array<string, string>               $languages Code => name.
 * @var array<string, string>               $countries Code => name.
 * @var bool                                $ai        Whether the AI groups and plans.
 * @var bool                                $gsc       Whether the searches have the data of Search Console.
 * @var bool                                $draft     Whether the user may create a draft.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_sources = array(
	'suggest'        => __( 'suggestions of Google search', 'simple-seo' ),
	'search_console' => __( 'Search Console', 'simple-seo' ),
);
$simple_seo_tab     = current_user_can( Simple_SEO_Access::CAP_SETTINGS )
	? sprintf( ' <a href="%1$s">%2$s</a>', esc_url( Simple_SEO_Admin::url( 'keywords' ) ), esc_html__( 'Open the Keyword research tab of the settings', 'simple-seo' ) )
	: '';
?>
<div class="wrap simple-seo-kw-page">
	<h1><?php esc_html_e( 'Keyword research', 'simple-seo' ); ?></h1>

	<p class="description"><?php esc_html_e( 'The searches people make around a phrase: what they type, the questions they ask, the topics these fall into, and how this site does in them — for choosing keyphrases and planning articles.', 'simple-seo' ); ?></p>

	<?php if ( ! Simple_SEO_Keywords::available() ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'Every source of searches is turned off.', 'simple-seo' ); ?>
				<?php echo $simple_seo_tab; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
			</p>
		</div>
	<?php else : ?>
		<form class="simple-seo-kw-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Simple_SEO_Keywords::PAGE ); ?>">
			<label for="simple-seo-kw-seed" class="screen-reader-text"><?php esc_html_e( 'Phrase', 'simple-seo' ); ?></label>
			<input type="search" id="simple-seo-kw-seed" name="q" value="<?php echo esc_attr( $seed ); ?>" class="regular-text" maxlength="<?php echo esc_attr( (string) Simple_SEO_Keywords::MAX_SEED ); ?>" placeholder="<?php esc_attr_e( 'A phrase, e.g. what the site sells', 'simple-seo' ); ?>" required>
			<label for="simple-seo-kw-language" class="screen-reader-text"><?php esc_html_e( 'Language', 'simple-seo' ); ?></label>
			<select id="simple-seo-kw-language" name="lang">
				<?php foreach ( $languages as $simple_seo_code => $simple_seo_name ) : ?>
					<option value="<?php echo esc_attr( $simple_seo_code ); ?>" <?php selected( $language, $simple_seo_code ); ?>><?php echo esc_html( $simple_seo_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="simple-seo-kw-country" class="screen-reader-text"><?php esc_html_e( 'Country', 'simple-seo' ); ?></label>
			<select id="simple-seo-kw-country" name="country">
				<?php foreach ( $countries as $simple_seo_code => $simple_seo_name ) : ?>
					<option value="<?php echo esc_attr( $simple_seo_code ); ?>" <?php selected( $country, $simple_seo_code ); ?>><?php echo esc_html( $simple_seo_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Find searches', 'simple-seo' ); ?></button>
		</form>

		<?php if ( is_wp_error( $result ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
		<?php elseif ( is_array( $result ) ) : ?>
			<?php foreach ( $result['errors'] as $simple_seo_source => $simple_seo_error ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						/* translators: 1: a source of searches, 2: what went wrong. */
						echo esc_html( sprintf( __( 'No searches from %1$s: %2$s', 'simple-seo' ), $simple_seo_sources[ $simple_seo_source ] ?? $simple_seo_source, $simple_seo_error ) );
						?>
					</p>
				</div>
			<?php endforeach; ?>

			<?php if ( ! $result['items'] ) : ?>
				<p><?php esc_html_e( 'No searches found around this phrase.', 'simple-seo' ); ?></p>
			<?php else : ?>
				<?php
				$simple_seo_questions = count( array_filter( array_column( $result['items'], 'question' ) ) );
				$simple_seo_refresh   = add_query_arg( 'refresh', '1', Simple_SEO_Keywords::page_url( $result['seed'], $result['language'], $result['country'] ) );
				$simple_seo_google    = static fn( string $keyword ): string => add_query_arg(
					array(
						'q'  => rawurlencode( $keyword ),
						'hl' => $result['language'],
						'gl' => strtolower( $result['country'] ),
					),
					'https://www.google.com/search'
				);
				?>
				<div class="simple-seo-kw-results" data-simple-seo-kw-page data-seed="<?php echo esc_attr( $result['seed'] ); ?>" data-language="<?php echo esc_attr( $result['language'] ); ?>"<?php echo $draft ? ' data-draft' : ''; ?>>
					<p class="simple-seo-kw-summary">
						<?php
						printf(
							/* translators: 1: number of searches, 2: number of questions among them. */
							esc_html__( 'Searches found: %1$s, questions among them: %2$s.', 'simple-seo' ),
							esc_html( number_format_i18n( count( $result['items'] ) ) ),
							esc_html( number_format_i18n( $simple_seo_questions ) )
						);
						?>
						<?php esc_html_e( 'The suggestions of Google are kept a week.', 'simple-seo' ); ?>
						<a href="<?php echo esc_url( $simple_seo_refresh ); ?>"><?php esc_html_e( 'Ask Google again', 'simple-seo' ); ?></a>
					</p>

					<div class="simple-seo-kw-filters" role="group" aria-label="<?php esc_attr_e( 'Show', 'simple-seo' ); ?>">
						<button type="button" class="button" data-kw-filter="all" aria-pressed="true">
							<?php
							/* translators: %s: number of searches. */
							echo esc_html( sprintf( __( 'All (%s)', 'simple-seo' ), number_format_i18n( count( $result['items'] ) ) ) );
							?>
						</button>
						<button type="button" class="button" data-kw-filter="questions" aria-pressed="false">
							<?php
							/* translators: %s: number of questions. */
							echo esc_html( sprintf( __( 'Questions (%s)', 'simple-seo' ), number_format_i18n( $simple_seo_questions ) ) );
							?>
						</button>
						<span class="simple-seo-kw-topics" data-kw-topics>
							<?php foreach ( $result['topics'] as $simple_seo_topic ) : ?>
								<button type="button" class="button button-small" data-kw-filter="topic" data-kw-topic="<?php echo esc_attr( $simple_seo_topic['name'] ); ?>" aria-pressed="false"><?php echo esc_html( $simple_seo_topic['name'] . ' (' . number_format_i18n( $simple_seo_topic['count'] ) . ')' ); ?></button>
							<?php endforeach; ?>
						</span>
					</div>

					<?php if ( $ai ) : ?>
						<p class="simple-seo-kw-ai">
							<button type="button" class="button" data-kw-group><?php esc_html_e( 'Group into topics with AI', 'simple-seo' ); ?></button>
							<button type="button" class="button button-primary" data-kw-outline><?php esc_html_e( 'Plan an article on what is shown', 'simple-seo' ); ?></button>
							<span class="simple-seo-kw-status description" role="status" aria-live="polite"></span>
						</p>
						<p class="simple-seo-kw-parent" hidden></p>
						<div class="simple-seo-kw-outline" hidden></div>
					<?php endif; ?>

					<table class="widefat striped simple-seo-kw-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Search', 'simple-seo' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Topic', 'simple-seo' ); ?></th>
								<?php if ( $gsc ) : ?>
									<th scope="col" class="num"><?php esc_html_e( 'Impressions', 'simple-seo' ); ?></th>
									<th scope="col" class="num"><?php esc_html_e( 'Clicks', 'simple-seo' ); ?></th>
									<th scope="col" class="num"><?php esc_html_e( 'Position', 'simple-seo' ); ?></th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['items'] as $simple_seo_item ) : ?>
								<tr data-kw="<?php echo esc_attr( $simple_seo_item['keyword'] ); ?>" data-kw-topic="<?php echo esc_attr( $simple_seo_item['topic'] ); ?>" data-kw-question="<?php echo $simple_seo_item['question'] ? '1' : '0'; ?>">
									<td>
										<a href="<?php echo esc_url( $simple_seo_google( $simple_seo_item['keyword'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $simple_seo_item['keyword'] ); ?></a>
										<?php if ( $simple_seo_item['question'] ) : ?>
											<span class="simple-seo-kw-tag"><?php esc_html_e( 'question', 'simple-seo' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="simple-seo-kw-topic"><?php echo esc_html( $simple_seo_item['topic'] ); ?></td>
									<?php if ( $gsc ) : ?>
										<?php if ( is_array( $simple_seo_item['gsc'] ) ) : ?>
											<td class="num"><?php echo esc_html( number_format_i18n( $simple_seo_item['gsc']['impressions'] ) ); ?></td>
											<td class="num"><?php echo esc_html( number_format_i18n( $simple_seo_item['gsc']['clicks'] ) ); ?></td>
											<td class="num"><?php echo esc_html( number_format_i18n( $simple_seo_item['gsc']['position'], 1 ) ); ?></td>
										<?php else : ?>
											<td class="num">—</td>
											<td class="num">—</td>
											<td class="num">—</td>
										<?php endif; ?>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
