<?php
/**
 * Shown when restrict-media-file-access is missing or inactive. Nothing else of ours runs in that state.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{plugins_url: string} $data
 */

?>
<div class="notice notice-error">
	<p><?php esc_html_e( 'Gated Media Access needs the Restrict Media File Access plugin, which is not active. Until it is, no files are protected and nothing else in this plugin runs.', 'gated-media-access' ); ?></p>
	<p><a href="<?php echo esc_url( $data['plugins_url'] ); ?>"><?php esc_html_e( 'Go to Plugins', 'gated-media-access' ); ?></a></p>
</div>
