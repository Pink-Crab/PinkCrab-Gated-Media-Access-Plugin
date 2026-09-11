<?php
/**
 * A section naming a block that is not registered.
 *
 * Only reachable through a third party's own mistake, so it names the block rather than failing silently.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{block: string} $data
 */

?>
<div class="gatedmedia-notice gatedmedia-notice--error">
	<div class="gatedmedia-notice__body">
		<?php
		printf(
			/* translators: %s: block name, e.g. my-plugin/subscriptions */
			esc_html__( 'The block "%s" is not registered, so this section cannot render.', 'gated-media-access' ),
			esc_html( $data['block'] )
		);
		?>
	</div>
</div>
