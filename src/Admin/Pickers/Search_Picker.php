<?php
/**
 * The search-as-you-type picker.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin\Pickers;

/**
 * A picker whose visible control is a search input against one of
 * `Picker_Search`'s admin-ajax endpoints. The admin bundle binds
 * autocomplete to the data attributes and writes the choice into the
 * hidden input.
 *
 * A concrete picker names its endpoint and its placeholder; the markup is
 * decided here, once.
 */
abstract class Search_Picker extends Picker {

	/**
	 * The admin-ajax action this picker searches.
	 */
	abstract protected function endpoint(): string;

	/**
	 * What the empty input suggests typing.
	 */
	abstract protected function placeholder(): string;

	/**
	 * The pair: search input, hidden id.
	 */
	public function render(): void {
		printf(
			'<input type="text" class="regular-text gatedmedia-picker" id="%1$s_search" data-gatedmedia-picker="%2$s" data-gatedmedia-target="%1$s" value="%3$s" placeholder="%4$s" autocomplete="off" />
			<input type="hidden" name="%5$s" id="%1$s" value="%6$s" />',
			esc_attr( $this->element_id ),
			esc_attr( $this->endpoint() ),
			esc_attr( $this->label ),
			esc_attr( $this->placeholder() ),
			esc_attr( $this->name ),
			esc_attr( $this->value )
		);
	}
}
