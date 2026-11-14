<?php
/**
 * The variables that templates can use, folded under a "Variables" heading, and the styles of the
 * template fields (input and "Insert structure" button side by side). Shared by the Titles and the
 * Special pages tabs.
 *
 * @var array<string, string> $variables Name => label.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<style>
	.simple-seo-template { display: flex; gap: 6px; align-items: flex-start; margin: 2px 0 10px; }
	.simple-seo-template input { flex: 1 1 auto; min-width: 0; }
	.simple-seo-template .button { flex: 0 0 auto; }
	.simple-seo-variables code { white-space: nowrap; }
	@media (max-width: 600px) { .simple-seo-template { flex-wrap: wrap; } }
</style>
<details class="simple-seo-variables">
	<summary><strong><?php esc_html_e( 'Variables', 'simple-seo' ); ?></strong></summary>
	<table class="widefat striped" style="max-width:860px;margin-top:8px">
		<tbody>
		<?php foreach ( $variables as $simple_seo_name => $simple_seo_label ) : ?>
			<tr>
				<td style="width:9em"><code>{<?php echo esc_html( $simple_seo_name ); ?>}</code></td>
				<td><?php echo esc_html( $simple_seo_label ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</details>
