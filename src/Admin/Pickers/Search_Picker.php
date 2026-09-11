<?php
/**
 * The search-as-you-type picker.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin\Pickers;

use PinkCrab\Gated_Access\Support\View;

/**
 * A picker whose visible control is a search input against one of `Picker_Search`'s admin-ajax endpoints, where the admin bundle binds autocomplete to the data attributes and writes the choice into the hidden input.
 *
 * A concrete picker names its endpoint and its placeholder, and the markup is decided here, once.
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
		View::render(
			'components/control/search',
			array(
				'name'        => $this->name,
				'id'          => $this->element_id,
				'endpoint'    => $this->endpoint(),
				'value'       => $this->value,
				'label_value' => $this->label,
				'placeholder' => $this->placeholder(),
			)
		);
	}
}
