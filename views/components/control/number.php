<?php
/**
 * A number control, optionally with its unit beside it.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{name: string, id: string, value?: string, min?: int, step?: int, suffix?: string, class?: string} $data
 */

?>
<?php if ( '' !== (string) ( $data['suffix'] ?? '' ) ) : ?>
	<span class="gatedmedia-admin-inline">
<?php endif; ?>

<input
	type="number"
	name="<?php echo esc_attr( (string) $data['name'] ); ?>"
	id="<?php echo esc_attr( (string) $data['id'] ); ?>"
	value="<?php echo esc_attr( (string) ( $data['value'] ?? '' ) ); ?>"
	class="<?php echo esc_attr( (string) ( $data['class'] ?? 'gatedmedia-admin-days' ) ); ?>"
	min="<?php echo esc_attr( (string) ( $data['min'] ?? 1 ) ); ?>"
	step="<?php echo esc_attr( (string) ( $data['step'] ?? '1' ) ); ?>"
	<?php if ( '' !== (string) ( $data['placeholder'] ?? '' ) ) : ?>
		placeholder="<?php echo esc_attr( (string) $data['placeholder'] ); ?>"
	<?php endif; ?>
/>

<?php if ( '' !== (string) ( $data['suffix'] ?? '' ) ) : ?>
		<span class="gatedmedia-admin-caps"><?php echo esc_html( (string) $data['suffix'] ); ?></span>
	</span>
<?php endif; ?>
