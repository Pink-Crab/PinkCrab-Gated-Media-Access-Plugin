<?php
/**
 * The shared view components.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PinkCrab\Gated_Access\Support\View;

/**
 * Every admin screen is assembled from these, so what they emit is the contract: one field markup, one section head, one notice, everywhere.
 *
 * A screen that writes its own version of any of these is the thing this set exists to stop.
 *
 * @group unit
 */
class Test_View_Components extends TestCase {

	/** The page header: kicker, title, and an optional action. */
	public function test_page_header(): void {
		$html = View::get(
			'components/page-header',
			array(
				'kicker' => 'Gated Media Access',
				'title'  => 'Add Access',
				'action' => array(
					'label' => 'Add Access',
					'type'  => 'submit',
				),
			)
		);

		$this->assertStringContainsString( 'gatedmedia-admin-header', $html );
		$this->assertStringContainsString( 'Gated Media Access', $html );
		$this->assertStringContainsString( '<h1>Add Access</h1>', $html );
		$this->assertStringContainsString( 'type="submit"', $html );
		$this->assertStringContainsString( 'gatedmedia-admin-button', $html );
	}

	/** No action, no button. */
	public function test_page_header_without_an_action(): void {
		$html = View::get( 'components/page-header', array( 'title' => 'Groups' ) );

		$this->assertStringNotContainsString( '<button', $html );
	}

	/**
	 * The screen shell carries the marker core looks for before it moves the notices.
	 *
	 * Without it, `common.js` puts every admin notice after the first `.wrap h1`, which is inside our own header, so a plugin's notice lands between our title and our save button.
	 */
	public function test_screen_shell_keeps_notices_out_of_the_sheet(): void {
		$html = View::get( 'components/screen', array( 'body' => '<p>Body</p>' ) );

		$this->assertStringContainsString( 'wp-header-end', $html );
		$this->assertStringContainsString( 'gatedmedia-admin', $html );
		$this->assertStringContainsString( '<p>Body</p>', $html );
		$this->assertLessThan(
			strpos( $html, '<div class="gatedmedia-admin' ),
			strpos( $html, 'wp-header-end' ),
			'The marker has to come before our own header.'
		);
	}

	/** The section head: title and the note that sits opposite it. */
	public function test_section_head(): void {
		$html = View::get(
			'components/section-head',
			array(
				'title' => 'Store',
				'note'  => 'Currency and address',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-admin-section-head', $html );
		$this->assertStringContainsString( '<h2>Store</h2>', $html );
		$this->assertStringContainsString( 'Currency and address', $html );
	}

	/** A text field: label bound to the control, value, help. */
	public function test_text_field(): void {
		$html = $this->fields(
			array(
				array(
					'type'  => 'text',
					'name'  => 'product_path',
					'id'    => 'gatedmedia_product_path',
					'label' => 'Product URL path',
					'value' => 'access',
					'help'  => 'Where products answer.',
				),
			)
		);

		$this->assertStringContainsString( 'gatedmedia-admin-field', $html );
		$this->assertStringContainsString( '<label class="gatedmedia-admin-caps" for="gatedmedia_product_path">Product URL path</label>', $html );
		$this->assertStringContainsString( 'name="product_path"', $html );
		$this->assertStringContainsString( 'value="access"', $html );
		$this->assertStringContainsString( 'gatedmedia-admin-help', $html );
		$this->assertStringContainsString( 'Where products answer.', $html );
	}

	/** No help text, no help paragraph. */
	public function test_a_field_without_help(): void {
		$html = $this->fields( array( array( 'type' => 'text', 'name' => 'a', 'id' => 'a', 'label' => 'A' ) ) );

		$this->assertStringNotContainsString( 'gatedmedia-admin-help', $html );
	}

	/** A select marks the chosen option and nothing else. */
	public function test_select_field(): void {
		$html = $this->fields(
			array(
				array(
					'type'    => 'select',
					'name'    => 'mode',
					'id'      => 'gatedmedia_mode',
					'label'   => 'Mode',
					'value'   => 'live',
					'options' => array(
						'test' => 'Test',
						'live' => 'Live',
					),
				),
			)
		);

		$this->assertMatchesRegularExpression( '/<option value="live"[^>]*selected/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<option value="test"[^>]*selected/', $html );
	}

	/** A checkbox carries the hidden zero, so unticking really stores the off. */
	public function test_checkbox_field(): void {
		$html = $this->fields(
			array(
				array(
					'type'    => 'checkbox',
					'name'    => 'admin_copy',
					'id'      => 'gatedmedia_admin_copy',
					'label'   => 'Send admin copies',
					'checked' => true,
				),
			)
		);

		$this->assertStringContainsString( 'type="hidden" name="admin_copy" value="0"', $html );
		$this->assertStringContainsString( 'checked', $html );
		$this->assertStringContainsString( 'gatedmedia-admin-check', $html );
	}

	/** A number field can carry a unit beside it. */
	public function test_number_field_with_a_suffix(): void {
		$html = $this->fields(
			array(
				array(
					'type'   => 'number',
					'name'   => 'expiry_warning_days',
					'id'     => 'gatedmedia_expiry_warning_days',
					'label'  => 'Lead time',
					'value'  => '7',
					'suffix' => 'days',
					'min'    => 1,
				),
			)
		);

		$this->assertStringContainsString( 'type="number"', $html );
		$this->assertStringContainsString( 'min="1"', $html );
		$this->assertStringContainsString( 'days', $html );
	}

	/** A secret renders empty whatever is stored, and says one is saved. */
	public function test_secret_field_never_carries_its_value_back(): void {
		$html = $this->fields(
			array(
				array(
					'type'      => 'secret',
					'name'      => 'stripe_live_secret',
					'id'        => 'gatedmedia_stripe_live_secret',
					'label'     => 'Live secret key',
					'value'     => 'sk_live_never_show_me',
					'has_value' => true,
				),
			)
		);

		$this->assertStringNotContainsString( 'sk_live_never_show_me', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'value=""', $html );
	}

	/** A read-only pair states a fact rather than offering a control. */
	public function test_static_field(): void {
		$html = $this->fields(
			array(
				array(
					'type'  => 'static',
					'label' => 'Holder',
					'value' => 'Jane Whitfield',
				),
			)
		);

		$this->assertStringContainsString( 'Jane Whitfield', $html );
		$this->assertStringNotContainsString( '<input', $html );
	}

	/** The three notice kinds, each with its core class. */
	public function test_notice(): void {
		$this->assertStringContainsString( 'notice-success', View::get( 'components/notice', array( 'message' => 'Saved.', 'type' => 'success' ) ) );
		$this->assertStringContainsString( 'notice-error', View::get( 'components/notice', array( 'message' => 'No.', 'type' => 'error' ) ) );
		$this->assertStringContainsString( 'Saved.', View::get( 'components/notice', array( 'message' => 'Saved.' ) ) );
	}

	/** The empty state says what would fill it, never just "nothing". */
	public function test_empty_state(): void {
		$html = View::get( 'components/empty', array( 'message' => 'No groups yet.' ) );

		$this->assertStringContainsString( 'gatedmedia-admin-help', $html );
		$this->assertStringContainsString( 'No groups yet.', $html );
	}

	/** A list row: what it is, what kind, and its own action. */
	public function test_list(): void {
		$html = View::get(
			'components/list',
			array(
				'items' => array(
					array(
						'title'  => 'Q3 Market Report',
						'url'    => 'https://example.test/edit',
						'meta'   => 'file',
						'action' => '<button>Remove</button>',
					),
				),
				'empty' => 'Nothing yet.',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-admin-list', $html );
		$this->assertStringContainsString( 'Q3 Market Report', $html );
		$this->assertStringContainsString( 'file', $html );
		$this->assertStringContainsString( '<button>Remove</button>', $html );
	}

	/** An empty list draws its empty state instead of an empty box. */
	public function test_an_empty_list(): void {
		$html = View::get( 'components/list', array( 'items' => array(), 'empty' => 'Nothing yet.' ) );

		$this->assertStringContainsString( 'Nothing yet.', $html );
		$this->assertStringNotContainsString( '<ul', $html );
	}

	/** The tab strip marks exactly one tab current. */
	public function test_tabs(): void {
		$html = View::get(
			'components/tabs',
			array(
				'tabs' => array(
					array(
						'label'  => 'General',
						'url'    => 'https://example.test/general',
						'active' => true,
					),
					array(
						'label'  => 'Notifications',
						'url'    => 'https://example.test/notifications',
						'active' => false,
					),
				),
			)
		);

		$this->assertSame( 1, substr_count( $html, 'is-active' ) );
		$this->assertStringContainsString( 'gatedmedia-admin-tabs', $html );
	}

	/** A panel: title, whatever sits in its summary, and a body. */
	public function test_panel(): void {
		$html = View::get(
			'components/panel',
			array(
				'title'  => 'Access created',
				'aside'  => '<span>Enabled</span>',
				'body'   => '<p>Body</p>',
				'open'   => true,
			)
		);

		$this->assertStringContainsString( 'gatedmedia-admin-panel', $html );
		$this->assertStringContainsString( 'Access created', $html );
		$this->assertStringContainsString( '<span>Enabled</span>', $html );
		$this->assertStringContainsString( '<p>Body</p>', $html );
		$this->assertStringContainsString( 'open', $html );
	}

	/** A closed panel is not open. */
	public function test_a_closed_panel(): void {
		$html = View::get( 'components/panel', array( 'title' => 'Expiry warning', 'open' => false ) );

		$this->assertStringNotContainsString( ' open', $html );
	}

	/**
	 * Renders a set of fields.
	 *
	 * @param array<int, array<string, mixed>> $fields The field definitions.
	 */
	private function fields( array $fields ): string {
		return View::get( 'components/fields', array( 'fields' => $fields ) );
	}
}
