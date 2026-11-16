<?php
/**
 * Files tab: robots.txt and .htaccess, each with the history of its versions.
 *
 * @var array<string, array<string, mixed>> $files       File => path, exists, current, text, loaded, refusal, conflict, history.
 * @var bool                                $robots_file Whether robots.txt is a file in the site root.
 * @var string                              $sitemap     Address of the XML sitemap, '' when Simple SEO serves none.
 * @var string                              $home_path   Folder of the site under its domain, '' at the root.
 * @var string                              $disk_copy   Path of the copy of the previous .htaccess, '' when there is none.
 * @var string                              $server      What the web server calls itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_action = Simple_SEO_Admin::url( 'files' );
?>
<p><?php esc_html_e( 'Two files in the root of the site: robots.txt tells search engines what to crawl, .htaccess tells the web server how to answer. Every change keeps the previous version in the history under the file, to be put back with one click.', 'simple-seo' ); ?></p>

<?php
foreach ( array( Simple_SEO_Files::ROBOTS, Simple_SEO_Files::HTACCESS ) as $simple_seo_file ) :
	$simple_seo_state = $files[ $simple_seo_file ];
	$simple_seo_name  = Simple_SEO_Files::ROBOTS === $simple_seo_file ? 'robots.txt' : '.htaccess';
	$simple_seo_ro    = '' !== $simple_seo_state['refusal'];
	$simple_seo_id    = 'simple-seo-' . $simple_seo_file;
	?>
	<?php Simple_SEO_Admin::section( $simple_seo_file ); ?>
	<span id="<?php echo esc_attr( $simple_seo_id ); ?>"></span>

	<?php if ( Simple_SEO_Files::ROBOTS === $simple_seo_file ) : ?>
		<?php if ( '' !== $home_path ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: %s: folder of the site, e.g. /blog/. */
				printf( esc_html__( 'The site lives in the folder %s, not at the root of its domain. Search engines read robots.txt only from the root of the domain, so they do not see this one.', 'simple-seo' ), '<code>/' . esc_html( $home_path ) . '/</code>' );
				?>
			</p></div>
		<?php endif; ?>
		<?php if ( $robots_file ) : ?>
			<p>
				<?php
				/* translators: %s: path of robots.txt. */
				printf( esc_html__( 'There is a robots.txt file in the site root (%s). The server sends it as it is, so it is the file you edit here; the robots.txt of WordPress, with the sitemap line Simple SEO adds to it, is not used.', 'simple-seo' ), '<code>' . esc_html( $simple_seo_state['path'] ) . '</code>' );
				?>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'There is no robots.txt file in the site root, so WordPress serves this text. Saved empty, it is the text of WordPress again.', 'simple-seo' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<?php if ( ! Simple_SEO_Files::server_reads_htaccess() ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				echo esc_html(
					'' !== $server
						/* translators: %s: name of the web server, e.g. nginx/1.27. */
						? sprintf( __( 'This server (%s) is not Apache or LiteSpeed and does not read .htaccess: the file changes nothing here.', 'simple-seo' ), $server )
						: __( 'This server does not look like Apache or LiteSpeed, so .htaccess probably changes nothing here.', 'simple-seo' )
				);
				?>
			</p></div>
		<?php endif; ?>
		<p>
			<?php
			/* translators: %s: path of .htaccess. */
			printf( esc_html__( 'The .htaccess file in the site root (%s). A mistake in it can stop the whole site, so after saving Simple SEO opens the home page and the REST API from the server and puts the previous file back at once if either stops answering.', 'simple-seo' ), '<code>' . esc_html( $simple_seo_state['path'] ) . '</code>' );
			?>
		</p>
		<p class="description"><?php esc_html_e( 'WordPress writes the lines between # BEGIN WordPress and # END WordPress itself whenever permalinks are saved: keep your own rules outside them.', 'simple-seo' ); ?></p>
		<?php if ( ! $simple_seo_state['exists'] ) : ?>
			<p class="description"><?php esc_html_e( 'There is no .htaccess yet; saving creates it.', 'simple-seo' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $simple_seo_ro ) : ?>
		<div class="notice notice-info inline"><p><?php echo esc_html( $simple_seo_state['refusal'] ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( Simple_SEO_Admin::url( 'files', $simple_seo_file ) . '#' . $simple_seo_id ); ?>">
		<?php wp_nonce_field( 'simple_seo_file_' . $simple_seo_file ); ?>
		<input type="hidden" name="simple_seo_file" value="<?php echo esc_attr( $simple_seo_file ); ?>">
		<input type="hidden" name="simple_seo_loaded" value="<?php echo esc_attr( $simple_seo_state['loaded'] ); ?>">
		<label class="screen-reader-text" for="<?php echo esc_attr( $simple_seo_id . '-text' ); ?>"><?php echo esc_html( $simple_seo_name ); ?></label>
		<textarea id="<?php echo esc_attr( $simple_seo_id . '-text' ); ?>" name="simple_seo_content" class="large-text code" rows="<?php echo Simple_SEO_Files::ROBOTS === $simple_seo_file ? 10 : 18; ?>" spellcheck="false" style="max-width:860px"<?php echo $simple_seo_ro ? ' readonly' : ''; ?>><?php echo esc_textarea( $simple_seo_state['text'] ); ?></textarea>

		<?php if ( $simple_seo_state['conflict'] ) : ?>
			<details open style="max-width:860px;margin:8px 0">
				<summary><?php esc_html_e( 'The file as it is now', 'simple-seo' ); ?></summary>
				<pre class="simple-seo-file-text"><?php echo esc_html( (string) $simple_seo_state['current'] ); ?></pre>
			</details>
		<?php endif; ?>

		<?php if ( Simple_SEO_Files::ROBOTS === $simple_seo_file && '' !== $sitemap ) : ?>
			<?php if ( ! $robots_file ) : ?>
				<p class="description">
					<?php
					/* translators: %s: the sitemap line, e.g. Sitemap: https://example.com/sitemap_index.xml. */
					printf( esc_html__( 'Simple SEO adds %s at the end, unless the text has it already.', 'simple-seo' ), '<code>Sitemap: ' . esc_html( $sitemap ) . '</code>' );
					?>
				</p>
			<?php elseif ( ! str_contains( strtolower( (string) $simple_seo_state['current'] ), strtolower( $sitemap ) ) ) : ?>
				<p class="description">
					<?php
					/* translators: %s: the sitemap line, e.g. Sitemap: https://example.com/sitemap_index.xml. */
					printf( esc_html__( 'Search engines find the XML sitemap through robots.txt. The file does not name it; add the line %s.', 'simple-seo' ), '<code>Sitemap: ' . esc_html( $sitemap ) . '</code>' );
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<p class="submit">
			<?php if ( ! $simple_seo_ro ) : ?>
				<button type="submit" class="button button-primary" name="simple_seo_do" value="save">
					<?php
					/* translators: %s: robots.txt or .htaccess. */
					echo esc_html( sprintf( __( 'Save %s', 'simple-seo' ), $simple_seo_name ) );
					?>
				</button>
				<?php if ( Simple_SEO_Files::ROBOTS === $simple_seo_file && ! $robots_file && '' !== Simple_SEO_Files::robots_option() ) : ?>
					<button type="submit" class="button" name="simple_seo_do" value="default"><?php esc_html_e( 'Back to the text of WordPress', 'simple-seo' ); ?></button>
				<?php endif; ?>
				<?php if ( Simple_SEO_Files::ROBOTS === $simple_seo_file && $robots_file ) : ?>
					<button type="submit" class="button" name="simple_seo_do" value="virtual"><?php esc_html_e( 'Delete the file, let WordPress serve this text', 'simple-seo' ); ?></button>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( Simple_SEO_Files::ROBOTS === $simple_seo_file ) : ?>
				<a class="button-link" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener" style="margin-left:8px"><?php esc_html_e( 'Open robots.txt', 'simple-seo' ); ?></a>
			<?php endif; ?>
		</p>
	</form>

	<?php if ( Simple_SEO_Files::ROBOTS === $simple_seo_file && ! $robots_file ) : ?>
		<h3><?php esc_html_e( 'What the site serves', 'simple-seo' ); ?></h3>
		<p class="description"><?php esc_html_e( 'The saved text with what Simple SEO and other plugins add to it.', 'simple-seo' ); ?></p>
		<pre class="simple-seo-file-text"><?php echo esc_html( Simple_SEO_Files::robots_served() ); ?></pre>
	<?php endif; ?>

	<?php if ( Simple_SEO_Files::HTACCESS === $simple_seo_file && '' !== $disk_copy ) : ?>
		<p class="description">
			<?php
			/* translators: %s: path of the copy, e.g. /var/www/.htaccess.simple-seo-backup. */
			printf( esc_html__( 'A copy of the previous file is also kept next to it: %s. If the site ever stops opening after a change, rename it to .htaccess over FTP or SSH.', 'simple-seo' ), '<code>' . esc_html( $disk_copy ) . '</code>' );
			?>
		</p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'History', 'simple-seo' ); ?></h3>
	<?php if ( ! $simple_seo_state['history'] ) : ?>
		<p class="description"><?php esc_html_e( 'No versions yet: the text is kept here before each change.', 'simple-seo' ); ?></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( Simple_SEO_Admin::url( 'files', $simple_seo_file ) . '#' . $simple_seo_id ); ?>">
			<?php wp_nonce_field( 'simple_seo_file_' . $simple_seo_file ); ?>
			<input type="hidden" name="simple_seo_file" value="<?php echo esc_attr( $simple_seo_file ); ?>">
			<input type="hidden" name="simple_seo_do" value="restore">
			<input type="hidden" name="simple_seo_loaded" value="<?php echo esc_attr( $simple_seo_state['loaded'] ); ?>">
			<table class="widefat striped" style="max-width:860px">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Replaced', 'simple-seo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'By', 'simple-seo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'simple-seo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Text', 'simple-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $simple_seo_state['history'] as $simple_seo_entry ) : ?>
					<?php $simple_seo_user = $simple_seo_entry['user'] ? get_userdata( $simple_seo_entry['user'] ) : false; ?>
					<tr>
						<td><?php echo esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $simple_seo_entry['time'] ) ); ?></td>
						<td><?php echo esc_html( $simple_seo_user ? $simple_seo_user->display_name : '—' ); ?></td>
						<td><?php echo esc_html( '' === $simple_seo_entry['content'] ? __( 'empty', 'simple-seo' ) : (string) size_format( strlen( $simple_seo_entry['content'] ) ) ); ?></td>
						<td>
							<details>
								<summary><?php esc_html_e( 'Show', 'simple-seo' ); ?></summary>
								<pre class="simple-seo-file-text"><?php echo esc_html( $simple_seo_entry['content'] ); ?></pre>
							</details>
							<?php if ( ! $simple_seo_ro ) : ?>
								<button type="submit" class="button button-small" name="simple_seo_backup" value="<?php echo esc_attr( $simple_seo_entry['id'] ); ?>"><?php esc_html_e( 'Restore', 'simple-seo' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>
	<?php endif; ?>
	<?php Simple_SEO_Admin::section_end(); ?>
<?php endforeach; ?>

<style>
	.simple-seo-file-text { background: #fff; border: 1px solid #c3c4c7; padding: 8px 12px; max-width: 860px; max-height: 320px; overflow: auto; white-space: pre-wrap; word-break: break-all; }
</style>
