<?php
/**
 * The picker component.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin\Pickers;

/**
 * One drop-in control for choosing a thing in wp-admin: a visible control paired with a hidden input carrying the choice, which is all a form ever submits.
 *
 * The Add Access form and the quick edit box compose these, and anything later drops one in the same way:
 *
 *     ( new User_Picker( 'gatedmedia_user', 'gatedmedia_user' ) )->render();
 *
 * Markup only. What the choice means belongs to the caller.
 */
abstract class Picker {

	/**
	 * The pair every picker submits.
	 *
	 * @param string $name       The submitted field: the chosen thing's identifier.
	 * @param string $element_id Id prefix for the pair, unique per surface.
	 * @param string $value      A pre-chosen identifier, '' for none.
	 * @param string $label      The pre-chosen identifier's display text.
	 */
	public function __construct(
		protected string $name,
		protected string $element_id,
		protected string $value = '',
		protected string $label = ''
	) {
	}

	/**
	 * Prints the control.
	 */
	abstract public function render(): void;
}
