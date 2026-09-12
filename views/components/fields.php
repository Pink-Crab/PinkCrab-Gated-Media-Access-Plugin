<?php
/**
 * A set of fields, each drawn the same way: label, control, help.
 *
 * A field is a definition, not markup: `type` picks the control in `components/control/`, and everything else is that control's business. This is the only place the field wrapper is written, so a screen cannot invent its own.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{fields: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<?php foreach ( $data['fields'] as $field ) : ?>
	<?php
	$control = View::get( 'components/control/' . (string) ( $field['type'] ?? 'text' ), $field );

	// A checkbox carries its own label beside the box, so it is not wrapped in the labelled row.
	if ( 'checkbox' === ( $field['type'] ?? '' ) ) {
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The control escapes its own output.

		if ( '' !== (string) ( $field['help'] ?? '' ) ) {
			View::render( 'components/help', array( 'help' => $field['help'] ) );
		}

		continue;
	}
	?>
	<div class="gatedmedia-admin-field"<?php echo isset( $field['row'] ) ? ' data-gatedmedia-row="' . esc_attr( (string) $field['row'] ) . '"' : ''; ?>>
		<?php if ( isset( $field['id'] ) ) : ?>
			<label class="gatedmedia-admin-caps" for="<?php echo esc_attr( (string) $field['id'] ); ?>"><?php echo esc_html( (string) $field['label'] ); ?></label>
		<?php else : ?>
			<span class="gatedmedia-admin-caps"><?php echo esc_html( (string) $field['label'] ); ?></span>
		<?php endif; ?>

		<?php if ( isset( $field['action'] ) ) : ?>
			<span class="gatedmedia-admin-inline">
				<?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The control escapes its own output. ?>
				<button type="submit" class="gatedmedia-admin-button"><?php echo esc_html( (string) $field['action'] ); ?></button>
			</span>
		<?php else : ?>
			<?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The control escapes its own output. ?>
		<?php endif; ?>

		<?php if ( '' !== (string) ( $field['help'] ?? '' ) ) : ?>
			<?php View::render( 'components/help', array( 'help' => $field['help'] ) ); ?>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
