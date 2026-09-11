<?php
/**
 * The search-as-you-type pair: the box a person types in, and the hidden input carrying what they chose.
 *
 * The admin bundle binds autocomplete to `data-gatedmedia-picker` and writes the choice into the element named by `data-gatedmedia-target`, so a form only ever submits the hidden one.
 *
 * @package PinkCrab\Gated_Access
 *
 * `label_value` is the chosen thing's display text, which is what the typed box shows when the filter or the form arrives already filled in.
 *
 * @var array{name: string, id: string, endpoint: string, value?: string, label_value?: string, placeholder?: string} $data
 */

?>
<input
	type="text"
	class="regular-text gatedmedia-picker"
	id="<?php echo esc_attr( (string) $data['id'] ); ?>_search"
	data-gatedmedia-picker="<?php echo esc_attr( (string) $data['endpoint'] ); ?>"
	data-gatedmedia-target="<?php echo esc_attr( (string) $data['id'] ); ?>"
	value="<?php echo esc_attr( (string) ( $data['label_value'] ?? '' ) ); ?>"
	placeholder="<?php echo esc_attr( (string) ( $data['placeholder'] ?? '' ) ); ?>"
	autocomplete="off"
/>
<input type="hidden" name="<?php echo esc_attr( (string) $data['name'] ); ?>" id="<?php echo esc_attr( (string) $data['id'] ); ?>" value="<?php echo esc_attr( (string) ( $data['value'] ?? '' ) ); ?>" />
