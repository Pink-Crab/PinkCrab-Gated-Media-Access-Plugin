<?php
/**
 * The frame every admin screen of ours sits in.
 *
 * The empty `wp-header-end` is load-bearing: `wp-admin/js/common.js` moves every notice on the page to just after the first `.wrap h1` unless it finds that marker, and our h1 is inside the sheet, so without it another plugin's notice is drawn between our title and our save button.
 *
 * `form` wraps the sheet in a posting form, so the header's button can submit it.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{body: string, form?: array{url: string, id?: string, fields?: string}} $data
 */

$form = $data['form'] ?? null;

?>
<div class="wrap">
	<hr class="wp-header-end" />

	<?php if ( null !== $form ) : ?>
		<form method="post" action="<?php echo esc_url( (string) $form['url'] ); ?>" <?php echo isset( $form['id'] ) ? 'id="' . esc_attr( (string) $form['id'] ) . '"' : ''; ?>>
			<?php echo (string) ( $form['fields'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hidden fields and nonces, composed by the screen. ?>
			<div class="gatedmedia-admin">
				<?php echo $data['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Components escape their own output. ?>
			</div>
		</form>
	<?php else : ?>
		<div class="gatedmedia-admin">
			<?php echo $data['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Components escape their own output. ?>
		</div>
	<?php endif; ?>
</div>
