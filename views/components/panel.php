<?php
/**
 * A foldable panel: its title, whatever sits opposite the title, and a body.
 *
 * A `<details>`, so folding needs no script.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{title: string, open?: bool, url?: string, aside?: string, body?: string} $data
 */

?>
<details class="gatedmedia-admin-panel"<?php echo (bool) ( $data['open'] ?? false ) ? ' open' : ''; ?>>
	<summary>
		<span class="gatedmedia-admin-panel-title">
			<?php if ( isset( $data['url'] ) ) : ?>
				<a href="<?php echo esc_url( (string) $data['url'] ); ?>"><?php echo esc_html( (string) $data['title'] ); ?></a>
			<?php else : ?>
				<?php echo esc_html( (string) $data['title'] ); ?>
			<?php endif; ?>
		</span>

		<?php echo (string) ( $data['aside'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Components escape their own output. ?>
	</summary>

	<?php if ( '' !== (string) ( $data['body'] ?? '' ) ) : ?>
		<div class="gatedmedia-admin-panel-body">
			<?php echo $data['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Components escape their own output. ?>
		</div>
	<?php endif; ?>
</details>
