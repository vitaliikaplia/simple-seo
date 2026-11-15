<?php
/**
 * Bulk SEO editing: what to list, the table of SEO titles and descriptions (or of the alt texts
 * and titles of images), pages.
 *
 * @var array<string, string>                                     $objects  Value => label.
 * @var string                                                    $object   The one listed.
 * @var string                                                    $filter
 * @var string                                                    $search
 * @var int                                                       $page
 * @var int                                                       $per_page
 * @var array{items: array<int, WP_Post|WP_Term>, total: int}     $result
 * @var array<string, string>                                     $data     For assets/js/admin.js.
 * @var string                                                    $language With wp-loc: the language listed.
 * @var bool                                                      $images   Whether the images are listed.
 * @var string[]                                                  $columns  Names of the three columns.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_seo_pages = (int) ceil( $result['total'] / $per_page );
$simple_seo_links = paginate_links(
	array(
		'base'      => add_query_arg( 'paged', '%#%' ),
		'format'    => '',
		'current'   => min( $page, max( 1, $simple_seo_pages ) ),
		'total'     => $simple_seo_pages,
		'prev_text' => '&laquo;',
		'next_text' => '&raquo;',
	)
);
?>
<div class="wrap simple-seo-bulk" data-simple-seo-bulk="<?php echo esc_attr( (string) wp_json_encode( $data ) ); ?>">
	<h1><?php esc_html_e( 'Bulk SEO editing', 'simple-seo' ); ?></h1>

	<?php if ( ! $objects ) : ?>
		<p><?php esc_html_e( 'There is nothing here you may edit.', 'simple-seo' ); ?></p>
	<?php else : ?>
		<form method="get" class="simple-seo-bulk-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Simple_SEO_Bulk::PAGE ); ?>">
			<label for="simple-seo-bulk-object" class="screen-reader-text"><?php esc_html_e( 'What to list', 'simple-seo' ); ?></label>
			<select id="simple-seo-bulk-object" name="object">
				<?php foreach ( $objects as $simple_seo_value => $simple_seo_label ) : ?>
					<option value="<?php echo esc_attr( $simple_seo_value ); ?>"<?php selected( $object, $simple_seo_value ); ?>><?php echo esc_html( $simple_seo_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="simple-seo-bulk-filter" class="screen-reader-text"><?php esc_html_e( 'Which of them', 'simple-seo' ); ?></label>
			<select id="simple-seo-bulk-filter" name="filter">
				<?php foreach ( Simple_SEO_Bulk::filters( $object ) as $simple_seo_value => $simple_seo_label ) : ?>
					<option value="<?php echo esc_attr( $simple_seo_value ); ?>"<?php selected( $filter, $simple_seo_value ); ?>><?php echo esc_html( $simple_seo_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="simple-seo-bulk-search" class="screen-reader-text"><?php esc_html_e( 'Search', 'simple-seo' ); ?></label>
			<input type="search" id="simple-seo-bulk-search" name="s" value="<?php echo esc_attr( $search ); ?>">
			<?php submit_button( __( 'Show', 'simple-seo' ), '', '', false ); ?>
		</form>

		<?php
		/* translators: %s: name of a language. */
		$simple_seo_notice = __( 'Listed in %s, the language of the admin: switch it to edit the others.', 'simple-seo' );
		require SIMPLE_SEO_DIR . 'includes/views/language-notice.php';
		?>

		<?php if ( $images ) : ?>
			<p><?php esc_html_e( 'The alt text tells search engines and people who do not see the picture what it shows. A name of a file that says what is in the picture gives one: fill the empty alt texts with them, look them over, mend what is wrong, then save.', 'simple-seo' ); ?></p>
		<?php endif; ?>

		<div class="simple-seo-bulk-bar">
			<button type="button" class="button button-primary simple-seo-bulk-save" disabled><?php esc_html_e( 'Save changes', 'simple-seo' ); ?></button>
			<?php if ( $images ) : ?>
				<button type="button" class="button simple-seo-bulk-suggest"><?php esc_html_e( 'Fill the empty alt texts from the names', 'simple-seo' ); ?></button>
			<?php endif; ?>
			<span class="simple-seo-bulk-status" role="status" aria-live="polite"></span>
			<?php /* translators: %s: number of posts or terms. */ ?>
			<span class="displaying-num"><?php echo esc_html( sprintf( __( 'Found: %s', 'simple-seo' ), number_format_i18n( $result['total'] ) ) ); ?></span>
		</div>

		<table class="widefat fixed striped simple-seo-bulk-table">
			<thead>
				<tr>
					<th scope="col" class="column-primary"><?php echo esc_html( $columns[0] ); ?></th>
					<th scope="col"><?php echo esc_html( $columns[1] ); ?></th>
					<th scope="col"><?php echo esc_html( $columns[2] ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $result['items'] ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Nothing found.', 'simple-seo' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $result['items'] as $simple_seo_item ) {
					echo Simple_SEO_Bulk::row( $simple_seo_item ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
				}
				?>
			</tbody>
		</table>

		<div class="simple-seo-bulk-bar">
			<button type="button" class="button button-primary simple-seo-bulk-save" disabled><?php esc_html_e( 'Save changes', 'simple-seo' ); ?></button>
			<?php if ( $simple_seo_links ) : ?>
				<span class="pagination-links"><?php echo wp_kses_post( $simple_seo_links ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
