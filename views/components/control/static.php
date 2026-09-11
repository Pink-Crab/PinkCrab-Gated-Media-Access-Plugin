<?php
/**
 * A fact rather than a control: the record's holder, its item, what Stripe left.
 *
 * `markup` is for a value that is already a link or a rendered cell; `value` is plain text.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{value?: string, markup?: string, action?: string} $data
 */

?>
<span class="gatedmedia-admin-static">
	<?php
	// A cell rendered elsewhere (the Access list's own columns) arrives as markup; anything else is plain text.
	echo isset( $data['markup'] )
		? $data['markup'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it was rendered.
		: esc_html( (string) ( $data['value'] ?? '' ) );
	?>

	<?php echo (string) ( $data['action'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it was composed. ?>
</span>
