<?php
/**
 * Picking a group.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin\Pickers;

/**
 * Groups number in the tens, so their picker is a plain select — the right
 * control at that size — with the group's UUID as the value. The caller
 * supplies the groups; identity lookups stay `Access_Taxonomy`'s.
 */
final class Group_Picker extends Picker {

	/**
	 * The select and its groups.
	 *
	 * @param string                $name       The submitted field: the chosen group's UUID.
	 * @param string                $element_id Id, unique per surface.
	 * @param array<string, string> $groups     UUID to name.
	 * @param string                $value      A pre-chosen UUID, '' for none.
	 */
	public function __construct(
		string $name,
		string $element_id,
		private array $groups,
		string $value = ''
	) {
		parent::__construct( $name, $element_id, $value );
	}

	/**
	 * The select, UUID-valued.
	 */
	public function render(): void {
		printf( '<select name="%s" id="%s">', esc_attr( $this->name ), esc_attr( $this->element_id ) );
		printf( '<option value="">%s</option>', esc_html__( '— Select a group —', 'gated-media-access' ) );

		foreach ( $this->groups as $uuid => $group_name ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $uuid ),
				selected( $this->value, $uuid, false ),
				esc_html( $group_name )
			);
		}

		echo '</select>';
	}
}
