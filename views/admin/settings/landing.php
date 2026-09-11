<?php
/**
 * The top-level menu page.
 *
 * The ACCESS post type's menu placement means the top-level click lands on the Access list, so this is only ever seen on a direct visit to the slug.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{settings_url: string} $data
 */

?>
<div class="wrap">
	<h1><?php esc_html_e( 'Gated Media Access', 'gated-media-access' ); ?></h1>
	<p><a href="<?php echo esc_url( $data['settings_url'] ); ?>"><?php esc_html_e( 'Settings', 'gated-media-access' ); ?></a></p>
</div>
