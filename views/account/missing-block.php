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
			esc_html( \PinkCrab\Gated_Access\Support\Labels::text( 'account.missing_block' ) ),
			esc_html( $data['block'] )
		);
		?>
	</div>
</div>
