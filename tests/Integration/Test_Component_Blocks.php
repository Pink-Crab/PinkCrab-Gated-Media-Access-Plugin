<?php
/**
 * The §6 components, as blocks.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use WP_Block_Type_Registry;
use PinkCrab\Gated_Access\Support\Block;

/**
 * ui-spec.md §6 is the complete vocabulary — sixteen components, each with its
 * states written down. These assert that every one of them **exists and
 * renders**, which is the check that was missing when nine of them existed as
 * CSS and nothing else.
 *
 * @group integration
 */
class Test_Component_Blocks extends WP_UnitTestCase {

	/**
	 * Every §6 component, by block name.
	 *
	 * @return array<string, array{string}>
	 */
	public static function components(): array {
		return array(
			'§6.1 account nav'    => array( 'gated-media-access/account-nav' ),
			'§6.2 row'            => array( 'gated-media-access/row' ),
			'§6.3 button'         => array( 'gated-media-access/button' ),
			'§6.4 notice'         => array( 'gated-media-access/notice' ),
			'§6.5 expiry'         => array( 'gated-media-access/expiry' ),
			'§6.6 status pill'    => array( 'gated-media-access/status-pill' ),
			'§6.7 price'          => array( 'gated-media-access/price' ),
			'§6.8 field'          => array( 'gated-media-access/field' ),
			'§6.9 section head'   => array( 'gated-media-access/section-heading' ),
			'§6.10 empty state'   => array( 'gated-media-access/empty-state' ),
			'§6.11 filter'        => array( 'gated-media-access/filter' ),
			'§6.12 contents'      => array( 'gated-media-access/contents' ),
			'§6.13 price block'   => array( 'gated-media-access/price-block' ),
			'§6.14 coupon'        => array( 'gated-media-access/coupon' ),
			'§6.15 action bar'    => array( 'gated-media-access/action-bar' ),
			'§6.16 summary'       => array( 'gated-media-access/summary' ),
		);
	}

	/**
	 * @testdox Every component in §6 is a registered block.
	 *
	 * @dataProvider components
	 *
	 * @param string $name The block name.
	 */
	public function test_every_component_is_registered( string $name ): void {
		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( $name ),
			sprintf( '%s is not registered.', $name )
		);
	}

	/**
	 * @testdox Every component is hidden from the inserter for now.
	 *
	 * @dataProvider components
	 *
	 * @param string $name The block name.
	 */
	public function test_components_are_not_placeable( string $name ): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

		$this->assertNotNull( $type );
		$this->assertFalse(
			$type->supports['inserter'] ?? true,
			sprintf( '%s is offered in the inserter.', $name )
		);
	}

	/** @testdox Every component renders server-side, so a site can filter its output. */
	public function test_every_component_renders_on_the_server(): void {
		foreach ( self::components() as $case ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( $case[0] );

			$this->assertNotNull( $type );
			$this->assertTrue(
				$type->is_dynamic(),
				sprintf( '%s has no render callback.', $case[0] )
			);
		}
	}

	// -------------------------------------------------------------------------
	// §6.2 Row — four states, all drawn in the corpus.
	// -------------------------------------------------------------------------

	/** @testdox A row renders its title and meta. */
	public function test_row_renders(): void {
		$html = Block::render(
			'gated-media-access/row',
			array(
				'title' => 'Report.pdf',
				'meta'  => 'PDF · 4.2 MB',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-row', $html );
		$this->assertStringContainsString( 'Report.pdf', $html );
		$this->assertStringContainsString( 'PDF · 4.2 MB', $html );
	}

	/** @testdox An unavailable row is marked, and says so instead of offering an action. */
	public function test_row_unavailable(): void {
		$html = Block::render(
			'gated-media-access/row',
			array(
				'title'       => 'Gone.pdf',
				'state'       => 'unavailable',
				'actionLabel' => 'Download',
			)
		);

		$this->assertStringContainsString( 'is-unavailable', $html );
		$this->assertStringContainsString( 'No longer available', $html );
		// A row that cannot be acted on must not offer the action anyway.
		$this->assertStringNotContainsString( 'Download', $html );
	}

	/** @testdox A loading row draws skeletons and is hidden from assistive technology. */
	public function test_row_loading(): void {
		$html = Block::render( 'gated-media-access/row', array( 'state' => 'loading' ) );

		$this->assertStringContainsString( 'is-loading', $html );
		$this->assertStringContainsString( 'gatedmedia-skeleton', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	/** @testdox A row composes its aside from whatever blocks it is given. */
	public function test_row_composes_its_aside(): void {
		$html = Block::render(
			'gated-media-access/row',
			array( 'title' => 'Report.pdf' ),
			Block::render(
				'gated-media-access/expiry',
				array(
					'state' => 'soon',
					'label' => 'Expires in 3 days',
				)
			)
		);

		$this->assertStringContainsString( 'gatedmedia-expiry--warning', $html );
		$this->assertStringContainsString( 'Expires in 3 days', $html );
	}

	/** @testdox The orders row takes the variant that keeps it unstacked on narrow. */
	public function test_row_order_variant(): void {
		$html = Block::render(
			'gated-media-access/row',
			array(
				'title'   => 'Order #1',
				'variant' => 'order',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-row--order', $html );
	}

	// -------------------------------------------------------------------------
	// §6.5 Expiry — four states, only two of them coloured.
	// -------------------------------------------------------------------------

	/**
	 * @testdox Each expiry state draws its own icon and treatment.
	 *
	 * @dataProvider expiry_states
	 *
	 * @param string $state    The state.
	 * @param string $icon     Expected sprite symbol.
	 * @param string $modifier Expected class, or '' for none.
	 */
	public function test_expiry_states( string $state, string $icon, string $modifier ): void {
		$html = Block::render(
			'gated-media-access/expiry',
			array(
				'state' => $state,
				'label' => 'Some label',
			)
		);

		$this->assertStringContainsString( $icon, $html );

		if ( '' !== $modifier ) {
			$this->assertStringContainsString( $modifier, $html );
		}
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function expiry_states(): array {
		return array(
			'lifetime' => array( 'lifetime', 'i-infinity', '' ),
			'dated'    => array( 'dated', 'i-calendar', '' ),
			'soon'     => array( 'soon', 'i-warning', 'gatedmedia-expiry--warning' ),
			'expired'  => array( 'expired', 'i-blocked', 'gatedmedia-expiry--expired' ),
		);
	}

	/** @testdox The chip form is available for detail panels. */
	public function test_expiry_chip(): void {
		$html = Block::render(
			'gated-media-access/expiry',
			array(
				'label' => 'Lifetime',
				'chip'  => true,
			)
		);

		$this->assertStringContainsString( 'gatedmedia-expiry--chip', $html );
	}

	// -------------------------------------------------------------------------
	// §6.6 Status pill — five values.
	// -------------------------------------------------------------------------

	/**
	 * @testdox Each of the five status values renders.
	 *
	 * @dataProvider status_values
	 *
	 * @param string $value The status.
	 */
	public function test_status_pill_values( string $value ): void {
		$html = Block::render( 'gated-media-access/status-pill', array( 'value' => $value ) );

		$this->assertStringContainsString( 'gatedmedia-status-pill', $html );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function status_values(): array {
		return array(
			'complete' => array( 'complete' ),
			'refunded' => array( 'refunded' ),
			'active'   => array( 'active' ),
			'expired'  => array( 'expired' ),
			'revoked'  => array( 'revoked' ),
		);
	}

	// -------------------------------------------------------------------------
	// §6.7 / §6.13 Price — the Free rule.
	// -------------------------------------------------------------------------

	/** @testdox Zero renders as the word Free, never as an amount. */
	public function test_price_zero_is_free(): void {
		$html = Block::render( 'gated-media-access/price', array( 'amount' => 0 ) );

		$this->assertStringContainsString( 'Free', $html );
		$this->assertStringNotContainsString( '0.00', $html );
	}

	/** @testdox A discount shows the original struck through beside what was paid. */
	public function test_price_shows_the_original(): void {
		$html = Block::render(
			'gated-media-access/price',
			array(
				'amount'   => 3675,
				'original' => 4900,
			)
		);

		$this->assertStringContainsString( 'gatedmedia-price__original', $html );
		$this->assertStringContainsString( '49.00', $html );
		$this->assertStringContainsString( '36.75', $html );
	}

	/** @testdox An original that is not a reduction is not shown, so nothing reads as a fake discount. */
	public function test_price_ignores_a_non_discount(): void {
		$html = Block::render(
			'gated-media-access/price',
			array(
				'amount'   => 4900,
				'original' => 4900,
			)
		);

		$this->assertStringNotContainsString( 'gatedmedia-price__original', $html );
	}

	/** @testdox Where no price exists at all, an em dash. */
	public function test_price_not_applicable(): void {
		$html = Block::render( 'gated-media-access/price', array( 'notApplicable' => true ) );

		$this->assertStringContainsString( '—', $html );
	}

	// -------------------------------------------------------------------------
	// §6.4 Notice — three kinds, and the dismiss that must not always be there.
	// -------------------------------------------------------------------------

	/**
	 * @testdox Each notice kind renders with its own treatment.
	 *
	 * @dataProvider notice_kinds
	 *
	 * @param string $kind     The kind.
	 * @param string $expected A class or icon it must carry.
	 */
	public function test_notice_kinds( string $kind, string $expected ): void {
		$html = Block::render(
			'gated-media-access/notice',
			array(
				'kind' => $kind,
				'text' => 'Something happened.',
			)
		);

		$this->assertStringContainsString( $expected, $html );
		$this->assertStringContainsString( 'Something happened.', $html );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function notice_kinds(): array {
		return array(
			'information' => array( 'info', 'i-info' ),
			'error'       => array( 'error', 'gatedmedia-notice--error' ),
			'success'     => array( 'success', 'gatedmedia-notice--success' ),
		);
	}

	/**
	 * @testdox A notice is only dismissible when it says so.
	 *
	 * §7.5's forced completion is defined by being the notice you cannot
	 * dismiss, so a stray close button would break that view rather than just
	 * looking wrong.
	 */
	public function test_notice_dismiss_is_opt_in(): void {
		$plain = Block::render( 'gated-media-access/notice', array( 'text' => 'Fixed.' ) );
		$other = Block::render(
			'gated-media-access/notice',
			array(
				'text'        => 'Dismiss me.',
				'dismissible' => true,
			)
		);

		$this->assertStringNotContainsString( 'gatedmedia-notice__dismiss', $plain );
		$this->assertStringContainsString( 'gatedmedia-notice__dismiss', $other );
	}

	/** @testdox A notice can hold other blocks, so it can contain a link. */
	public function test_notice_composes_inner_blocks(): void {
		$html = Block::render(
			'gated-media-access/notice',
			array( 'kind' => 'success' ),
			Block::render(
				'gated-media-access/button',
				array(
					'label'   => 'View your access',
					'href'    => 'https://example.org/account/',
					'variant' => 'link',
				)
			)
		);

		$this->assertStringContainsString( 'gatedmedia-text-link', $html );
		$this->assertStringContainsString( 'View your access', $html );
	}

	// -------------------------------------------------------------------------
	// §6.8 Field — the invalid treatment, which had no way to render before.
	// -------------------------------------------------------------------------

	/** @testdox A field renders its label, input and helper line. */
	public function test_field_renders(): void {
		$html = Block::render(
			'gated-media-access/field',
			array(
				'name'    => 'company',
				'label'   => 'Company',
				'value'   => 'Pink Crab',
				'message' => 'Optional.',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-field__label', $html );
		$this->assertStringContainsString( 'id="gatedmedia-company"', $html );
		$this->assertStringContainsString( 'Pink Crab', $html );
		$this->assertStringContainsString( 'Optional.', $html );
	}

	/** @testdox An invalid field is marked and pointed at its message for screen readers. */
	public function test_field_invalid(): void {
		$html = Block::render(
			'gated-media-access/field',
			array(
				'name'  => 'email',
				'label' => 'Email',
				'error' => 'That address is not valid.',
			)
		);

		$this->assertStringContainsString( 'is-invalid', $html );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'aria-describedby="gatedmedia-email-message"', $html );
		$this->assertStringContainsString( 'That address is not valid.', $html );
	}

	/** @testdox An error replaces the helper line rather than joining it. */
	public function test_field_error_replaces_help(): void {
		$html = Block::render(
			'gated-media-access/field',
			array(
				'name'    => 'email',
				'message' => 'We never share this.',
				'error'   => 'That address is not valid.',
			)
		);

		$this->assertStringContainsString( 'That address is not valid.', $html );
		$this->assertStringNotContainsString( 'We never share this.', $html );
	}

	/** @testdox A disabled field renders disabled. */
	public function test_field_disabled(): void {
		$html = Block::render(
			'gated-media-access/field',
			array(
				'name'     => 'email',
				'disabled' => true,
			)
		);

		$this->assertStringContainsString( 'disabled', $html );
	}

	// -------------------------------------------------------------------------
	// §6.10 Empty state — never a dead end.
	// -------------------------------------------------------------------------

	/** @testdox An empty state renders its icon, headline and line. */
	public function test_empty_state_renders(): void {
		$html = Block::render(
			'gated-media-access/empty-state',
			array(
				'icon'    => 'i-files',
				'title'   => 'No files yet',
				'message' => 'Files you are given access to will appear here.',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-empty-state', $html );
		$this->assertStringContainsString( 'No files yet', $html );
		$this->assertStringContainsString( 'i-files', $html );
	}

	/**
	 * @testdox An empty state with no explanatory line renders nothing.
	 *
	 * §6.10: the line beneath always states what would put something here —
	 * the box is never a dead end.
	 */
	public function test_empty_state_requires_a_message(): void {
		$html = Block::render( 'gated-media-access/empty-state', array( 'title' => 'Nothing here' ) );

		$this->assertSame( '', trim( wp_strip_all_tags( $html ) ) );
	}

	// -------------------------------------------------------------------------
	// §6.3 Buttons.
	// -------------------------------------------------------------------------

	/** @testdox A button with a link renders as an anchor, and without one as a button. */
	public function test_button_element_follows_its_purpose(): void {
		$link = Block::render(
			'gated-media-access/button',
			array(
				'label' => 'Download',
				'href'  => 'https://example.org/file.pdf',
			)
		);

		$submit = Block::render(
			'gated-media-access/button',
			array(
				'label' => 'Save',
				'type'  => 'submit',
			)
		);

		$this->assertStringContainsString( '<a ', $link );
		$this->assertStringContainsString( '<button', $submit );
		$this->assertStringContainsString( 'type="submit"', $submit );
	}

	/** @testdox The text link is its own thing, not a third button variant. */
	public function test_text_link_variant(): void {
		$html = Block::render(
			'gated-media-access/button',
			array(
				'label'   => 'Cancel',
				'variant' => 'link',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-text-link', $html );
		$this->assertStringNotContainsString( 'gatedmedia-button', $html );
	}

	/** @testdox A button with no label renders nothing rather than an empty control. */
	public function test_button_needs_a_label(): void {
		$this->assertSame( '', trim( Block::render( 'gated-media-access/button', array() ) ) );
	}

	// -------------------------------------------------------------------------
	// §6.1 Account nav — both variants, from one list.
	// -------------------------------------------------------------------------

	/** @testdox The nav marks the current item, in either variant. */
	public function test_account_nav_marks_the_current_item(): void {
		$items = array(
			array(
				'label'  => 'My Access',
				'href'   => 'https://example.org/account/my-access/',
				'icon'   => 'i-access',
				'active' => true,
			),
			array(
				'label' => 'Files',
				'href'  => 'https://example.org/account/files/',
				'icon'  => 'i-files',
			),
		);

		foreach ( array( 'sidebar', 'tabs' ) as $variant ) {
			$html = Block::render(
				'gated-media-access/account-nav',
				array(
					'items'   => $items,
					'variant' => $variant,
				)
			);

			$this->assertStringContainsString( 'aria-current="page"', $html );
			$this->assertSame( 1, substr_count( $html, 'aria-current' ), 'More than one item marked current.' );
			$this->assertStringContainsString( 'is-active', $html );
		}
	}

	// -------------------------------------------------------------------------
	// §6.11 Filter — search at every width.
	// -------------------------------------------------------------------------

	/** @testdox The filter renders one type list into both the select and the chips. */
	public function test_filter_renders_both_controls(): void {
		$html = Block::render(
			'gated-media-access/filter',
			array(
				'types'  => array(
					array(
						'value' => 'all',
						'label' => 'All',
					),
					array(
						'value' => 'pdf',
						'label' => 'PDF',
					),
				),
				'active' => 'all',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-filter__search', $html );
		$this->assertStringContainsString( 'gatedmedia-filter__type', $html );
		$this->assertStringContainsString( 'gatedmedia-type-chips', $html );
		// One list, so each type appears in both controls.
		$this->assertSame( 2, substr_count( $html, '>PDF<' ) );
	}

	// -------------------------------------------------------------------------
	// §6.16 Summary — pluralised once, here.
	// -------------------------------------------------------------------------

	/** @testdox A summary phrases its counts, and drops the empty ones. */
	public function test_summary_phrases_counts(): void {
		$html = Block::render(
			'gated-media-access/summary',
			array(
				'counts' => array(
					array(
						'type'  => 'file',
						'count' => 12,
					),
					array(
						'type'  => 'post',
						'count' => 1,
					),
					array(
						'type'  => 'group',
						'count' => 0,
					),
				),
			)
		);

		$this->assertStringContainsString( '12 files', $html );
		$this->assertStringContainsString( '1 post', $html );
		$this->assertStringNotContainsString( 'group', $html );
	}

	// -------------------------------------------------------------------------
	// §6.14 Coupon — applied replaces, not annotates.
	// -------------------------------------------------------------------------

	/** @testdox An applied coupon replaces the input and its button entirely. */
	public function test_coupon_applied_replaces_the_pair(): void {
		$html = Block::render(
			'gated-media-access/coupon',
			array(
				'code'     => 'SAVE25',
				'applied'  => true,
				'discount' => '£12.25',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-coupon__applied', $html );
		$this->assertStringContainsString( 'SAVE25', $html );
		$this->assertStringContainsString( 'Remove', $html );
		$this->assertStringNotContainsString( 'gatedmedia-coupon__row', $html );
	}
}
