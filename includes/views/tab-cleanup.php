<?php
/**
 * Clean-up tab: what WordPress adds to the code of every page, each item with its switch.
 *
 * @var array<string, bool> $settings true: removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Cleanup::OPTION;

$simple_seo_items = array(
	'generator' => array(
		__( 'WordPress version', 'simple-seo' ),
		'<meta name="generator" content="WordPress …">',
		__( 'Tells everyone which version runs the site; feeds lose it too.', 'simple-seo' ),
	),
	'shortlink' => array(
		__( 'Shortlink', 'simple-seo' ),
		'<link rel="shortlink" href="…/?p=123">',
		__( 'The ?p= address of a post, also as a Link header. Search engines use the canonical address.', 'simple-seo' ),
	),
	'rsd'       => array(
		__( 'XML-RPC discovery', 'simple-seo' ),
		'<link rel="EditURI" …>, X-Pingback',
		__( 'Point old blogging apps and pingbacks to xmlrpc.php. XML-RPC itself keeps working.', 'simple-seo' ),
	),
	'rest'      => array(
		__( 'REST API links', 'simple-seo' ),
		'<link rel="https://api.w.org/" …>',
		__( 'The address of the REST API and of the page in JSON, also as Link headers. The REST API itself keeps working: the block editor needs it.', 'simple-seo' ),
	),
	'oembed'    => array(
		__( 'oEmbed discovery', 'simple-seo' ),
		'<link rel="alternate" type="application/json+oembed" …>',
		__( 'Let other sites show a link to your post as a card with its title and image. Without them the link stays a plain link.', 'simple-seo' ),
	),
	'emoji'     => array(
		__( 'Emoji script and styles', 'simple-seo' ),
		'<script>…_wpemojiSettings…</script>',
		__( 'Replace emoji with images where the browser cannot draw them; today\'s browsers draw emoji themselves.', 'simple-seo' ),
	),
);

$simple_seo_feeds = array(
	'feed_posts'         => array( __( 'Posts', 'simple-seo' ), __( 'The main feed of the site; feed readers find the site by it.', 'simple-seo' ) ),
	'feed_comments'      => array( __( 'Comments of the whole site', 'simple-seo' ), __( 'WordPress then leaves out the comments feeds of posts too.', 'simple-seo' ) ),
	'feed_post_comments' => array( __( 'Comments of each post', 'simple-seo' ), '' ),
	'feed_categories'    => array( __( 'Categories', 'simple-seo' ), '' ),
	'feed_tags'          => array( __( 'Tags', 'simple-seo' ), '' ),
	'feed_taxonomies'    => array( __( 'Other taxonomies', 'simple-seo' ), '' ),
	'feed_authors'       => array( __( 'Authors', 'simple-seo' ), '' ),
	'feed_search'        => array( __( 'Search results', 'simple-seo' ), '' ),
	'feed_post_types'    => array( __( 'Post type archives', 'simple-seo' ), '' ),
);
?>
<p><?php esc_html_e( 'What WordPress adds to the code of every page. Ticked items are removed; only the links, tags and scripts go, the features behind them keep working. Out of the box only what nobody uses is removed.', 'simple-seo' ); ?></p>

<?php Simple_SEO_Admin::section( 'links' ); ?>
<table class="form-table" role="presentation">
	<tbody>
	<?php foreach ( $simple_seo_items as $simple_seo_key => $simple_seo_item ) : ?>
		<?php $simple_seo_id = 'simple-seo-cleanup-' . $simple_seo_key; ?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $simple_seo_id ); ?>"><?php echo esc_html( $simple_seo_item[0] ); ?></label></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $simple_seo_option . '[' . $simple_seo_key . ']' ); ?>" value="0">
				<label>
					<input type="checkbox" id="<?php echo esc_attr( $simple_seo_id ); ?>" name="<?php echo esc_attr( $simple_seo_option . '[' . $simple_seo_key . ']' ); ?>" value="1" <?php checked( ! empty( $settings[ $simple_seo_key ] ) ); ?>>
					<?php esc_html_e( 'Remove', 'simple-seo' ); ?> <code><?php echo esc_html( $simple_seo_item[1] ); ?></code>
				</label>
				<p class="description"><?php echo esc_html( $simple_seo_item[2] ); ?></p>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php Simple_SEO_Admin::section_end(); ?>

<?php Simple_SEO_Admin::section( 'feeds' ); ?>
<p><?php esc_html_e( 'Feed readers find feeds through these links. The feeds keep working at their addresses; only the links go.', 'simple-seo' ); ?></p>
<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Remove the links to', 'simple-seo' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Remove the links to', 'simple-seo' ); ?></legend>
					<?php foreach ( $simple_seo_feeds as $simple_seo_key => $simple_seo_feed ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $simple_seo_option . '[' . $simple_seo_key . ']' ); ?>" value="0">
						<label for="<?php echo esc_attr( 'simple-seo-cleanup-' . $simple_seo_key ); ?>" style="display:block;margin-bottom:6px">
							<input type="checkbox" id="<?php echo esc_attr( 'simple-seo-cleanup-' . $simple_seo_key ); ?>" name="<?php echo esc_attr( $simple_seo_option . '[' . $simple_seo_key . ']' ); ?>" value="1" <?php checked( ! empty( $settings[ $simple_seo_key ] ) ); ?>>
							<?php echo esc_html( $simple_seo_feed[0] ); ?>
							<?php if ( '' !== $simple_seo_feed[1] ) : ?>
								<span class="description">— <?php echo esc_html( $simple_seo_feed[1] ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
	</tbody>
</table>
<?php Simple_SEO_Admin::section_end(); ?>
