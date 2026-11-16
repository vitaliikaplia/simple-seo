<?php
/**
 * The page "Audit of pages" under the SEO menu: the state of the last run, how many pages have each
 * problem, and the pages with their problems.
 *
 * @var string                                                                    $issue       The problem chosen, '' for all.
 * @var int                                                                       $paged
 * @var string                                                                    $done        What the last form did.
 * @var array{state: string, started: int, finished: int, total: int, capped: bool} $run
 * @var array{state: string, total: int, done: int}                               $progress
 * @var array<string, int>                                                        $counts      Pages with each problem.
 * @var int                                                                       $problems    Pages with any problem.
 * @var array{rows: array<int, array<string, mixed>>, total: int}                 $found
 * @var string                                                                    $explanation Why no page opened.
 * @var bool                                                                      $hidden      Whether the site asks search engines not to index it.
 * @var string                                                                    $nonce       Nonce action of the forms and the steps.
 * @var string                                                                    $link_view   The view of links chosen: broken, redirects or waiting.
 * @var int                                                                       $link_paged
 * @var array<string, int>                                                        $link_counts Links in each view.
 * @var array{rows: array<int, array<string, mixed>>, total: int}                 $link_found
 * @var int                                                                       $link_total  Links checked.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_labels = array(
	'failed'           => __( 'Did not open', 'simple-seo' ),
	'no_title'         => __( 'No title', 'simple-seo' ),
	'same_title'       => __( 'The same title as other pages', 'simple-seo' ),
	'no_description'   => __( 'No meta description', 'simple-seo' ),
	'same_description' => __( 'The same description as other pages', 'simple-seo' ),
	'no_h1'            => __( 'No H1 heading', 'simple-seo' ),
	'many_h1'          => __( 'More than one H1 heading', 'simple-seo' ),
	'images_alt'       => __( 'Images without alt text', 'simple-seo' ),
	'canonical'        => __( 'The canonical address is another one', 'simple-seo' ),
	'noindex'          => __( 'Hidden from search results (noindex)', 'simple-seo' ),
	'orphan'           => __( 'No other page links to it', 'simple-seo' ),
);
$simple_seo_notes  = array(
	'started' => __( 'The audit has started. It goes on in the background; this page shows how far it is.', 'simple-seo' ),
	'stopped' => __( 'The audit is stopped. The pages checked so far are in the report.', 'simple-seo' ),
	'empty'   => __( 'There is no page to check: the sitemap of this site lists none.', 'simple-seo' ),
);
$simple_seo_when   = static fn( int $time ): string => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
$simple_seo_button = static function ( string $do, string $label, string $class ) use ( $nonce ): string {
	return sprintf(
		'<form method="post" action="%1$s" class="simple-seo-audit-form">%2$s<input type="hidden" name="action" value="%3$s"><input type="hidden" name="do" value="%4$s"><button type="submit" class="%5$s">%6$s</button></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		wp_nonce_field( $nonce, '_wpnonce', true, false ),
		esc_attr( $nonce ),
		esc_attr( $do ),
		esc_attr( $class ),
		esc_html( $label )
	);
};
$simple_seo_pages  = (int) ceil( $found['total'] / Simple_SEO_Audit::PER_PAGE );
$simple_seo_views  = array(
	'broken'    => __( 'Broken links', 'simple-seo' ),
	'redirects' => __( 'Links of this site that redirect', 'simple-seo' ),
	'waiting'   => __( 'To check again', 'simple-seo' ),
);
$simple_seo_path   = static fn( string $url ): string => rawurldecode( (string) preg_replace( '#^https?://[^/]+#i', '', $url ) );
?>
<div class="wrap simple-seo-audit-page">
	<h1><?php esc_html_e( 'Audit of pages', 'simple-seo' ); ?></h1>
	<p>
		<?php
		/* translators: %s: number of pages. */
		echo esc_html( sprintf( __( 'The pages of the sitemap of this site, opened as a visitor sees them and read for what search engines read: the title, the meta description, the H1 headings, images without alt text, the canonical address and noindex; then the links on them, on this site and elsewhere. Up to %s pages, a few at a time in the background.', 'simple-seo' ), number_format_i18n( Simple_SEO_Audit::max_pages() ) ) );
		?>
	</p>

	<?php if ( isset( $simple_seo_notes[ $done ] ) ) : ?>
		<div class="notice <?php echo 'empty' === $done ? 'notice-warning' : 'notice-success'; ?> inline"><p><?php echo esc_html( $simple_seo_notes[ $done ] ); ?></p></div>
	<?php endif; ?>
	<?php if ( '' !== $explanation ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $explanation ); ?></p></div>
	<?php endif; ?>
	<?php if ( $hidden ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'This site asks search engines not to index it (Settings → Reading): WordPress marks every page noindex.', 'simple-seo' ); ?></p></div>
	<?php endif; ?>

	<div class="simple-seo-audit-run">
		<?php if ( 'running' === $run['state'] ) : ?>
			<div id="simple-seo-audit-progress" class="simple-seo-audit-progress" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( $nonce ) ); ?>" data-done="<?php echo esc_url( Simple_SEO_Audit::page_url() ); ?>">
				<progress max="<?php echo esc_attr( (string) max( 1, $progress['total'] ) ); ?>" value="<?php echo esc_attr( (string) $progress['done'] ); ?>"></progress>
				<p class="simple-seo-audit-text"><?php echo esc_html( Simple_SEO_Audit::progress_text( $progress ) ); ?></p>
			</div>
			<?php
			echo $simple_seo_button( 'stop', __( 'Stop the audit', 'simple-seo' ), 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			wp_print_inline_script_tag(
				<<<'JS'
( function () {
	var box = document.getElementById( 'simple-seo-audit-progress' );
	if ( ! box || ! window.fetch || ! window.FormData ) {
		return;
	}
	var bar = box.querySelector( 'progress' );
	var text = box.querySelector( '.simple-seo-audit-text' );
	function step() {
		var body = new FormData();
		body.append( 'action', 'simple_seo_audit' );
		body.append( '_ajax_nonce', box.getAttribute( 'data-nonce' ) );
		fetch( box.getAttribute( 'data-url' ), { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( answer ) {
				if ( ! answer || ! answer.success ) {
					return;
				}
				bar.max = Math.max( 1, answer.data.total );
				bar.value = answer.data.done;
				text.textContent = answer.data.text;
				if ( 'running' === answer.data.state ) {
					window.setTimeout( step, 1000 );
				} else {
					window.location.href = box.getAttribute( 'data-done' );
				}
			} )
			.catch( function () {
				window.setTimeout( step, 5000 );
			} );
	}
	step();
}() );
JS
			);
			?>
		<?php else : ?>
			<p>
				<?php
				if ( 'idle' === $run['state'] ) {
					esc_html_e( 'The audit has not run yet.', 'simple-seo' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: 1: date and time, 2: number of pages. */
							'stopped' === $run['state'] ? __( 'The last audit was stopped on %1$s, with %2$s pages checked.', 'simple-seo' ) : __( 'The last audit finished on %1$s: %2$s pages checked.', 'simple-seo' ),
							$simple_seo_when( $run['finished'] ),
							number_format_i18n( $progress['done'] )
						)
					);
				}
				if ( $run['capped'] ) {
					echo ' ';
					/* translators: %s: number of pages. */
					echo esc_html( sprintf( __( 'The sitemap lists more pages: the first %s are checked.', 'simple-seo' ), number_format_i18n( Simple_SEO_Audit::max_pages() ) ) );
				}
				?>
			</p>
			<?php echo $simple_seo_button( 'start', 'idle' === $run['state'] ? __( 'Run the audit', 'simple-seo' ) : __( 'Run it again', 'simple-seo' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
		<?php endif; ?>
	</div>

	<?php if ( $progress['done'] > 0 ) : ?>
		<h2><?php esc_html_e( 'Problems', 'simple-seo' ); ?></h2>
		<ul class="simple-seo-audit-summary">
			<li>
				<?php if ( '' === $issue ) : ?>
					<strong><?php esc_html_e( 'All problems', 'simple-seo' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $problems ) ); ?>)</span></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( Simple_SEO_Audit::page_url() ); ?>"><?php esc_html_e( 'All problems', 'simple-seo' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $problems ) ); ?>)</span></a>
				<?php endif; ?>
			</li>
			<?php foreach ( $simple_seo_labels as $simple_seo_key => $simple_seo_label ) : ?>
				<?php if ( $counts[ $simple_seo_key ] > 0 ) : ?>
					<li>
						<?php if ( $issue === $simple_seo_key ) : ?>
							<strong><?php echo esc_html( $simple_seo_label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $simple_seo_key ] ) ); ?>)</span></strong>
						<?php else : ?>
							<a href="<?php echo esc_url( Simple_SEO_Audit::page_url( $simple_seo_key ) ); ?>"><?php echo esc_html( $simple_seo_label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $simple_seo_key ] ) ); ?>)</span></a>
						<?php endif; ?>
					</li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>

		<?php if ( ! $found['rows'] ) : ?>
			<p><?php echo esc_html( '' === $issue ? __( 'No problem found on the pages checked.', 'simple-seo' ) : __( 'No page checked has this problem.', 'simple-seo' ) ); ?></p>
		<?php else : ?>
			<table class="widefat striped simple-seo-audit-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Page', 'simple-seo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Problems', 'simple-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $found['rows'] as $simple_seo_row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $simple_seo_row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rawurldecode( (string) preg_replace( '#^https?://[^/]+#i', '', $simple_seo_row['url'] ) ) ); ?></a>
								<?php if ( '' !== $simple_seo_row['title'] ) : ?>
									<span class="simple-seo-audit-title"><?php echo esc_html( $simple_seo_row['title'] ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Problems', 'simple-seo' ); ?>">
								<ul class="simple-seo-audit-problems">
									<?php foreach ( $simple_seo_row['issues'] as $simple_seo_key ) : ?>
										<li>
											<?php
											switch ( $simple_seo_key ) {
												case 'failed':
													if ( 0 === $simple_seo_row['code'] ) {
														/* translators: %s: the error of the request. */
														echo esc_html( sprintf( __( 'Did not open: %s', 'simple-seo' ), $simple_seo_row['error'] ) );
													} elseif ( $simple_seo_row['code'] >= 300 && $simple_seo_row['code'] < 400 && '' !== $simple_seo_row['error'] ) {
														/* translators: 1: HTTP status code, 2: address. */
														echo esc_html( sprintf( __( 'Redirects (%1$d) to %2$s', 'simple-seo' ), $simple_seo_row['code'], rawurldecode( $simple_seo_row['error'] ) ) );
													} else {
														/* translators: %d: HTTP status code. */
														echo esc_html( sprintf( __( 'Answers %d instead of the page', 'simple-seo' ), $simple_seo_row['code'] ) );
													}
													break;
												case 'same_title':
													/* translators: %s: number of pages. */
													echo esc_html( sprintf( __( 'Pages with this title: %s', 'simple-seo' ), number_format_i18n( $simple_seo_row['same_title'] ) ) );
													break;
												case 'same_description':
													/* translators: %s: number of pages. */
													echo esc_html( sprintf( __( 'Pages with this description: %s', 'simple-seo' ), number_format_i18n( $simple_seo_row['same_description'] ) ) );
													break;
												case 'many_h1':
													/* translators: 1: number of headings, 2: the headings. */
													echo esc_html( sprintf( __( 'H1 headings: %1$s — %2$s', 'simple-seo' ), number_format_i18n( $simple_seo_row['h1_count'] ), implode( ' · ', $simple_seo_row['h1'] ) ) );
													break;
												case 'images_alt':
													/* translators: %s: number of images. */
													echo esc_html( sprintf( __( 'Images without alt text: %s', 'simple-seo' ), number_format_i18n( $simple_seo_row['images_missing'] ) ) );
													foreach ( array_slice( $simple_seo_row['images'], 0, 5 ) as $simple_seo_image ) {
														printf( ' <a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $simple_seo_image ), esc_html( wp_basename( (string) wp_parse_url( $simple_seo_image, PHP_URL_PATH ) ) ) );
													}
													break;
												case 'canonical':
													/* translators: %s: address. */
													echo esc_html( sprintf( __( 'The canonical address is %s', 'simple-seo' ), rawurldecode( $simple_seo_row['canonical'] ) ) );
													break;
												default:
													echo esc_html( $simple_seo_labels[ $simple_seo_key ] );
											}
											?>
										</li>
									<?php endforeach; ?>
								</ul>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $simple_seo_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							(string) paginate_links(
								array(
									'base'    => str_replace( '999999999', '%#%', Simple_SEO_Audit::page_url( $issue, 999999999 ) ),
									'format'  => '',
									'current' => $paged,
									'total'   => $simple_seo_pages,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $link_total > 0 ) : ?>
		<h2 id="links"><?php esc_html_e( 'Links', 'simple-seo' ); ?></h2>
		<p>
			<?php
			/* translators: %s: number of links. */
			echo esc_html( sprintf( __( 'The links on the pages checked, on this site and elsewhere: %s checked, each once.', 'simple-seo' ), number_format_i18n( $link_total ) ) );
			if ( $run['links_capped'] ) {
				echo ' ';
				/* translators: %s: number of links. */
				echo esc_html( sprintf( __( 'The pages link to more addresses: the first %s are checked.', 'simple-seo' ), number_format_i18n( Simple_SEO_Links::max_links() ) ) );
			}
			?>
		</p>
		<ul class="simple-seo-audit-summary">
			<?php foreach ( $simple_seo_views as $simple_seo_key => $simple_seo_label ) : ?>
				<li>
					<?php if ( $link_view === $simple_seo_key ) : ?>
						<strong><?php echo esc_html( $simple_seo_label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $link_counts[ $simple_seo_key ] ) ); ?>)</span></strong>
					<?php else : ?>
						<a href="<?php echo esc_url( Simple_SEO_Links::page_url( $simple_seo_key ) ); ?>"><?php echo esc_html( $simple_seo_label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $link_counts[ $simple_seo_key ] ) ); ?>)</span></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( ! $link_found['rows'] ) : ?>
			<p><?php echo esc_html( 'broken' === $link_view ? __( 'No broken link found.', 'simple-seo' ) : ( 'redirects' === $link_view ? __( 'No link of this site redirects.', 'simple-seo' ) : __( 'No link waits for a check.', 'simple-seo' ) ) ); ?></p>
		<?php else : ?>
			<table class="widefat striped simple-seo-audit-table simple-seo-links-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Link', 'simple-seo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Answer', 'simple-seo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'On the pages', 'simple-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $link_found['rows'] as $simple_seo_link ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $simple_seo_link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $simple_seo_link['internal'] ? $simple_seo_path( $simple_seo_link['url'] ) : rawurldecode( $simple_seo_link['url'] ) ); ?></a></td>
							<td data-label="<?php esc_attr_e( 'Answer', 'simple-seo' ); ?>">
								<?php
								if ( 0 === $simple_seo_link['code'] ) {
									/* translators: %s: the error of the request. */
									echo esc_html( sprintf( __( 'No answer: %s', 'simple-seo' ), $simple_seo_link['error'] ) );
								} elseif ( $simple_seo_link['code'] >= 300 && $simple_seo_link['code'] < 400 && '' !== $simple_seo_link['error'] ) {
									/* translators: 1: HTTP status code, 2: address. */
									echo esc_html( sprintf( __( 'Redirects (%1$d) to %2$s', 'simple-seo' ), $simple_seo_link['code'], rawurldecode( $simple_seo_link['error'] ) ) );
								} else {
									/* translators: %d: HTTP status code. */
									echo esc_html( sprintf( __( 'Answers %d', 'simple-seo' ), $simple_seo_link['code'] ) );
								}
								if ( $simple_seo_link['waiting'] ) {
									echo '<br>';
									/* translators: %s: date and time. */
									echo esc_html( sprintf( __( 'Checked again on %s.', 'simple-seo' ), $simple_seo_when( $simple_seo_link['next_at'] ) ) );
								}
								?>
							</td>
							<td data-label="<?php esc_attr_e( 'On the pages', 'simple-seo' ); ?>">
								<?php
								foreach ( $simple_seo_link['pages'] as $simple_seo_page ) {
									printf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a><br>', esc_url( $simple_seo_page ), esc_html( $simple_seo_path( $simple_seo_page ) ) );
								}
								if ( $simple_seo_link['on'] > count( $simple_seo_link['pages'] ) ) {
									/* translators: %s: number of pages. */
									echo esc_html( sprintf( __( 'and %s more', 'simple-seo' ), number_format_i18n( $simple_seo_link['on'] - count( $simple_seo_link['pages'] ) ) ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $simple_seo_link_pages = (int) ceil( $link_found['total'] / Simple_SEO_Links::PER_PAGE ); ?>
			<?php if ( $simple_seo_link_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							(string) paginate_links(
								array(
									'base'    => str_replace( '999999999', '%#%', Simple_SEO_Links::page_url( $link_view, 999999999 ) ),
									'format'  => '',
									'current' => $link_paged,
									'total'   => $simple_seo_link_pages,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
