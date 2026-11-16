<?php
/**
 * The section of the llms.txt tab for the Markdown versions of pages: the switch, then each kind of
 * page with its parts.
 *
 * @var array<string, mixed>  $settings
 * @var array<string, string> $slots    Slot => label.
 * @var string                $example  Address of the home page's Markdown version, '' while there is none.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_option = Simple_SEO_Markdown::OPTION;
$simple_seo_parts  = array(
	'breadcrumbs' => __( 'Breadcrumbs', 'simple-seo' ),
	'description' => __( 'Description', 'simple-seo' ),
	'meta'        => __( 'Details: address, dates, author, terms', 'simple-seo' ),
	'image'       => __( 'Featured image', 'simple-seo' ),
);
?>
<p>
	<?php
	printf(
		/* translators: 1: an example address, 2: the same with index.md. */
		esc_html__( 'Each page can also be served as clean Markdown at its own address with index.md added (%1$s → %2$s): the title and the text without menus, scripts and styles, for AI assistants and agents. Pages name their Markdown version in their head, and llms.txt links to it. Only pages search engines may show have one.', 'simple-seo' ),
		'<code>/about/</code>',
		'<code>/about/index.md</code>'
	);
	?>
</p>

<table class="form-table" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="simple-seo-markdown-enabled"><?php esc_html_e( 'Markdown versions', 'simple-seo' ); ?></label></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $simple_seo_option ); ?>[enabled]" value="0">
				<label>
					<input type="checkbox" id="simple-seo-markdown-enabled" name="<?php echo esc_attr( $simple_seo_option ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
					<?php esc_html_e( 'Serve Markdown versions of pages', 'simple-seo' ); ?>
				</label>
				<?php if ( '' !== $example ) : ?>
					<p class="description"><?php esc_html_e( 'The home page:', 'simple-seo' ); ?> <a href="<?php echo esc_url( $example ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $example ); ?></a></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'A post or a term can say otherwise in the Indexing group of its SEO fields.', 'simple-seo' ); ?></p>
			</td>
		</tr>
	<?php foreach ( $slots as $simple_seo_slot => $simple_seo_label ) : ?>
		<?php
		$simple_seo_id   = 'simple-seo-markdown-' . sanitize_html_class( str_replace( ':', '-', $simple_seo_slot ) );
		$simple_seo_name = $simple_seo_option . '[slots][' . $simple_seo_slot . ']';
		$simple_seo_post = str_starts_with( $simple_seo_slot, 'post:' );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $simple_seo_label ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php echo esc_html( $simple_seo_label ); ?></legend>
					<input type="hidden" name="<?php echo esc_attr( $simple_seo_name . '[on]' ); ?>" value="0">
					<label style="display:inline-block;margin:0 1.5em .5em 0">
						<input type="checkbox" id="<?php echo esc_attr( $simple_seo_id ); ?>" name="<?php echo esc_attr( $simple_seo_name . '[on]' ); ?>" value="1" <?php checked( Simple_SEO_Markdown::slot_on( $simple_seo_slot ) ); ?>>
						<strong><?php esc_html_e( 'Markdown version', 'simple-seo' ); ?></strong>
					</label>
					<br>
					<?php foreach ( $simple_seo_parts as $simple_seo_part => $simple_seo_part_label ) : ?>
						<?php if ( ! $simple_seo_post && in_array( $simple_seo_part, array( 'meta', 'image' ), true ) ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<input type="hidden" name="<?php echo esc_attr( $simple_seo_name . '[' . $simple_seo_part . ']' ); ?>" value="0">
						<label style="display:inline-block;margin:0 1.5em .5em 0">
							<input type="checkbox" name="<?php echo esc_attr( $simple_seo_name . '[' . $simple_seo_part . ']' ); ?>" value="1" <?php checked( Simple_SEO_Markdown::part_on( $simple_seo_slot, $simple_seo_part ) ); ?>>
							<?php echo esc_html( $simple_seo_part_label ); ?>
						</label>
					<?php endforeach; ?>
					<?php if ( ! $simple_seo_post ) : ?>
						<p class="description"><?php esc_html_e( 'With the list of posts on that page of the archive.', 'simple-seo' ); ?></p>
					<?php elseif ( Simple_SEO_Indexing::slot_is_noindex( $simple_seo_slot ) ) : ?>
						<p class="description"><?php esc_html_e( 'Hidden from search engines on the Indexing tab: only those shown on their own have one.', 'simple-seo' ); ?></p>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
