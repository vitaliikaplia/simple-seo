<?php
/**
 * With wp-loc: which language the settings on this tab are edited for.
 *
 * @var string $language Name of the language being edited, '' without wp-loc (no notice then).
 * @var string $simple_seo_notice The text, with %s for the language name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( '' === $language ) {
	return;
}
?>
<div class="notice notice-info inline">
	<p><?php echo wp_kses( sprintf( esc_html( $simple_seo_notice ), '<strong>' . esc_html( $language ) . '</strong>' ), array( 'strong' => array() ) ); ?></p>
</div>
