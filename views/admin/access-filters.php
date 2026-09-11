<?php
/**
 * The Access list's toolbar filters: holder, item type, item, source.
 *
 * The labels are read by assistive technology only, as core's own toolbar does: each control's meaning is carried by its first option, so a visible label would say it twice, and a placeholder is not a label because it goes as soon as anything is typed.
 *
 * Core's toolbar is a row of controls, not a stack of fields, so these are drawn bare rather than through `components/fields`.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{controls: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<?php foreach ( $data['controls'] as $control ) : ?>
	<?php
	// A search control's visible box is the `_search` one; the bare id is its hidden partner.
	$for = 'search' === $control['type'] ? $control['id'] . '_search' : $control['id'];
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( (string) $for ); ?>"><?php echo esc_html( (string) $control['label'] ); ?></label>
	<?php View::render( 'components/control/' . (string) $control['type'], $control ); ?>
<?php endforeach; ?>
