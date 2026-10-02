<?php
/**
 * What the profile block draws.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Profile_Writer;
use PinkCrab\Gated_Access\Support\Block;
use PinkCrab\Gated_Access\Support\Labels;

/**
 * Groups in order as plain divs, passwords never filled in, and each state's notice.
 *
 * @group integration
 */
class Test_Profile_Block extends WP_UnitTestCase {

	/** The signed-in user. */
	private int $user_id = 0;

	/** The request URI before the test, put back after. */
	private string $request_uri = '';

	public function set_up(): void {
		parent::set_up();

		$this->request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );

		$this->user_id = self::factory()->user->create( array( 'user_email' => 'someone@example.org' ) );
		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_profile_fields' );
		remove_all_filters( 'gatedmedia_profile_values' );
		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = $this->request_uri;

		parent::tear_down();
	}

	/** @testdox Signed out, the block draws nothing. */
	public function test_signed_out_draws_nothing(): void {
		wp_set_current_user( 0 );

		$this->assertSame( '', trim( $this->render() ) );
	}

	/** @testdox Each group is a div of its own, in order. */
	public function test_groups_are_divs_in_order(): void {
		$html = $this->render();

		$main     = strpos( $html, '<div class="gatedmedia-profile-group gatedmedia-profile-group--main">' );
		$password = strpos( $html, '<div class="gatedmedia-profile-group gatedmedia-profile-group--password">' );
		$contact  = strpos( $html, '<div class="gatedmedia-profile-group gatedmedia-profile-group--contact">' );

		$this->assertNotFalse( $main );
		$this->assertNotFalse( $password );
		$this->assertNotFalse( $contact );
		$this->assertLessThan( $password, $main );
		$this->assertLessThan( $contact, $password );
	}

	/** @testdox Each field sits inside its own group. */
	public function test_fields_sit_in_their_group(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#gatedmedia-profile-group--main">(?:(?!gatedmedia-profile-group--).)*id="gatedmedia-last_name"#s', $html );
		$this->assertMatchesRegularExpression( '#gatedmedia-profile-group--password">(?:(?!gatedmedia-profile-group--).)*id="gatedmedia-confirm_password"#s', $html );
		$this->assertMatchesRegularExpression( '#gatedmedia-profile-group--contact">(?:(?!gatedmedia-profile-group--).)*id="gatedmedia-country"#s', $html );
	}

	/** @testdox The email comes first, then first and last name. */
	public function test_main_field_order(): void {
		$html = $this->render();

		$email = strpos( $html, 'id="gatedmedia-email"' );
		$first = strpos( $html, 'id="gatedmedia-first_name"' );
		$last  = strpos( $html, 'id="gatedmedia-last_name"' );

		$this->assertLessThan( $first, $email );
		$this->assertLessThan( $last, $first );
	}

	/** @testdox With no labels, no group is headed. */
	public function test_no_headings_by_default(): void {
		$this->assertStringNotContainsString( 'gatedmedia-section-heading', $this->render() );
	}

	/** @testdox A group a site labels is headed with that label. */
	public function test_a_labelled_group_is_headed(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['contact']['label'] = 'Contact details';

				return $groups;
			}
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#gatedmedia-profile-group--contact">\s*<h2[^>]*gatedmedia-section-heading[^>]*>\s*Contact details\s*</h2>#', $html );
		$this->assertSame( 1, substr_count( $html, 'gatedmedia-section-heading' ) );
	}

	/** @testdox The password inputs are password inputs with the hints password managers read. */
	public function test_password_inputs(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#id="gatedmedia-current_password"[^>]*autocomplete="current-password"[^>]*type="password"#s', $html );
		$this->assertMatchesRegularExpression( '#id="gatedmedia-new_password"[^>]*autocomplete="new-password"[^>]*type="password"#s', $html );
		$this->assertMatchesRegularExpression( '#id="gatedmedia-confirm_password"[^>]*autocomplete="new-password"[^>]*type="password"#s', $html );
	}

	/** @testdox A password is never written into the page, whatever the values say. */
	public function test_passwords_are_never_filled(): void {
		add_filter(
			'gatedmedia_profile_values',
			static function ( array $values ): array {
				$values['current_password'] = 'leaked-secret';
				$values['new_password']     = 'leaked-secret';

				return $values;
			}
		);

		$html = $this->render();

		$this->assertStringNotContainsString( 'leaked-secret', $html );
		$this->assertMatchesRegularExpression( '#id="gatedmedia-current_password"[^>]*value=""#s', $html );
	}

	/** @testdox The new password's helper line names the minimum. */
	public function test_new_password_note(): void {
		$this->assertMatchesRegularExpression( '#id="gatedmedia-new_password-message"[^>]*>\s*At least 12 characters#', $this->render() );
	}

	/** @testdox The email is drawn disabled, with the account's address and its note. */
	public function test_email_is_disabled(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#id="gatedmedia-email"[^>]*type="email"[^>]*value="someone@example.org"[^>]*disabled#s', $html );
		$this->assertStringContainsString( esc_html( Labels::text( 'account.profile.email_note' ) ), $html );
	}

	/** @testdox Stored values are drawn into their inputs. */
	public function test_stored_values_are_drawn(): void {
		update_user_meta( $this->user_id, 'first_name', 'Glynn' );
		update_user_meta( $this->user_id, 'gatedmedia_company', 'Pink Crab' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#id="gatedmedia-first_name"[^>]*value="Glynn"#s', $html );
		$this->assertMatchesRegularExpression( '#id="gatedmedia-company"[^>]*value="Pink Crab"#s', $html );
	}

	/** @testdox The first and last name are marked required. */
	public function test_required_fields_are_marked(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#id="gatedmedia-first_name"[^>]*required#s', $html );
		$this->assertDoesNotMatchRegularExpression( '#id="gatedmedia-company"[^>]*required[^>]*>#s', $html );
	}

	/** @testdox A site's field is drawn in its group, with the value it supplies. */
	public function test_a_site_field_is_drawn(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['contact']['fields']['vat'] = array( 'label' => 'VAT number' );

				return $groups;
			}
		);
		add_filter(
			'gatedmedia_profile_values',
			static function ( array $values ): array {
				$values['vat'] = 'GB123456789';

				return $values;
			}
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#gatedmedia-profile-group--contact">(?:(?!gatedmedia-profile-group--).)*id="gatedmedia-vat"[^>]*value="GB123456789"#s', $html );
		$this->assertStringContainsString( 'VAT number', $html );
	}

	/** @testdox A group a site removes is not drawn. */
	public function test_a_removed_group_is_not_drawn(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				unset( $groups['password'] );

				return $groups;
			}
		);

		$html = $this->render();

		$this->assertStringNotContainsString( 'gatedmedia-profile-group--password', $html );
		$this->assertStringNotContainsString( 'type="password"', $html );
	}

	/** @testdox A group left with no fields is not drawn. */
	public function test_an_empty_group_is_not_drawn(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['contact']['fields'] = array();

				return $groups;
			}
		);

		$this->assertStringNotContainsString( 'gatedmedia-profile-group--contact', $this->render() );
	}

	/** @testdox The form posts to admin-post with the save action and a nonce, and has one submit. */
	public function test_the_form_posts_the_save(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'name="action" value="' . Profile_Writer::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertSame( 1, substr_count( $html, 'type="submit"' ) );
	}

	/** @testdox A profile missing its name shows the dismissible incomplete notice. */
	public function test_the_incomplete_notice(): void {
		$html = $this->render();

		$this->assertStringContainsString( Labels::text( 'account.profile.incomplete' ), $html );
		$this->assertStringContainsString( 'gatedmedia-notice__dismiss', $html );
	}

	/** @testdox A saved profile shows the success notice. */
	public function test_the_saved_notice(): void {
		$this->complete_the_name();
		$_GET['profile'] = 'saved';

		$html = $this->render();

		$this->assertStringContainsString( 'gatedmedia-notice--success', $html );
		$this->assertStringContainsString( Labels::text( 'account.profile.saved' ), $html );
	}

	/** @testdox A refusal shows the error notice and its text under the field it names. */
	public function test_a_refusal_marks_its_field(): void {
		$this->refused( 'password_current', 'current_password' );

		$html = $this->render();

		$this->assertStringContainsString( 'gatedmedia-notice--error', $html );
		$this->assertStringContainsString( esc_html( Labels::text( 'account.profile.not_saved' ) ), $html );
		$this->assertMatchesRegularExpression( '#id="gatedmedia-current_password"[^>]*aria-invalid="true"#s', $html );
		$this->assertMatchesRegularExpression(
			'#id="gatedmedia-current_password-message"[^>]*>\s*' . preg_quote( esc_html( Profile_Writer::message_for( 'password_current' ) ), '#' ) . '#',
			$html
		);
	}

	/** @testdox Only the named field is marked invalid. */
	public function test_only_the_named_field_is_marked(): void {
		$this->refused( 'password_mismatch', 'confirm_password' );

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'aria-invalid="true"' ) );
		$this->assertMatchesRegularExpression( '#id="gatedmedia-confirm_password"[^>]*aria-invalid="true"#s', $html );
	}

	/** @testdox The short-password refusal names the minimum under the new password. */
	public function test_the_short_refusal_names_the_minimum(): void {
		$this->refused( 'password_short', 'new_password' );

		$this->assertMatchesRegularExpression( '#id="gatedmedia-new_password-message"[^>]*>\s*Passwords need at least 12 characters#', $this->render() );
	}

	/** @testdox A refusal with no field shows only the notice. */
	public function test_a_refusal_without_a_field(): void {
		$this->refused( 'vat_invalid', '' );

		$html = $this->render();

		$this->assertStringContainsString( 'gatedmedia-notice--error', $html );
		$this->assertStringNotContainsString( 'aria-invalid', $html );
	}

	/** @testdox A refusal naming a field not on the form marks nothing. */
	public function test_a_refusal_for_a_missing_field(): void {
		$this->refused( 'vat_invalid', 'not_a_field' );

		$this->assertStringNotContainsString( 'aria-invalid', $this->render() );
	}

	/** @testdox A refusal code with no text of its own shows the not-saved line under the field. */
	public function test_an_unknown_refusal_code(): void {
		$this->refused( 'something_else', 'company' );

		$this->assertMatchesRegularExpression(
			'#id="gatedmedia-company-message"[^>]*>\s*' . preg_quote( esc_html( Labels::text( 'account.profile.not_saved' ) ), '#' ) . '#',
			$this->render()
		);
	}

	/** @testdox The error state needs the error flag: a code alone in the URL is ignored. */
	public function test_a_code_without_the_error_state(): void {
		$this->complete_the_name();
		$_GET[ Profile_Writer::ARG_ERROR ] = 'password_current';
		$_GET[ Profile_Writer::ARG_FIELD ] = 'current_password';

		$html = $this->render();

		$this->assertStringNotContainsString( 'gatedmedia-notice--error', $html );
		$this->assertStringNotContainsString( 'aria-invalid', $html );
	}

	/** @testdox Cancel drops the last attempt's state from the address. */
	public function test_cancel_drops_the_state(): void {
		$this->refused( 'password_current', 'current_password' );
		$_SERVER['REQUEST_URI'] = '/account/profile/?profile=error&' . Profile_Writer::ARG_ERROR . '=password_current&' . Profile_Writer::ARG_FIELD . '=current_password';

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<a[^>]*href="/account/profile/"#', $html );
	}

	/** @testdox Forced completion shows only the missing fields and the email, in a card. */
	public function test_forced_completion(): void {
		$_GET['profile'] = 'complete';

		$html = $this->render();

		$this->assertStringContainsString( 'id="gatedmedia-first_name"', $html );
		$this->assertStringContainsString( 'id="gatedmedia-last_name"', $html );
		$this->assertStringContainsString( 'id="gatedmedia-email"', $html );
		$this->assertStringNotContainsString( 'gatedmedia-profile-group--password', $html );
		$this->assertStringNotContainsString( 'gatedmedia-profile-group--contact', $html );
		$this->assertMatchesRegularExpression( '#<form\s+class="gatedmedia-card"#', $html );
		$this->assertStringNotContainsString( 'gatedmedia-notice__dismiss', $html );
	}

	/** @testdox Forced completion with only one field missing shows only that one. */
	public function test_forced_completion_one_missing(): void {
		update_user_meta( $this->user_id, 'first_name', 'Glynn' );
		$_GET['profile'] = 'complete';

		$html = $this->render();

		$this->assertStringNotContainsString( 'id="gatedmedia-first_name"', $html );
		$this->assertStringContainsString( 'id="gatedmedia-last_name"', $html );
	}

	/** @testdox Forced completion with nothing missing shows the whole form. */
	public function test_forced_completion_nothing_missing(): void {
		$this->complete_the_name();
		$_GET['profile'] = 'complete';

		$html = $this->render();

		$this->assertStringContainsString( 'gatedmedia-profile-group--password', $html );
		$this->assertStringContainsString( 'gatedmedia-profile-group--contact', $html );
	}

	/**
	 * The profile block, as the account page draws it.
	 */
	private function render(): string {
		return Block::render( 'gated-media-access/profile' );
	}

	/**
	 * Fills the required name, so no incomplete notice shows.
	 */
	private function complete_the_name(): void {
		update_user_meta( $this->user_id, 'first_name', 'Glynn' );
		update_user_meta( $this->user_id, 'last_name', 'Quelch' );
	}

	/**
	 * The address a refusal comes back to.
	 *
	 * @param string $code  The refusal code.
	 * @param string $field The field it names, '' for none.
	 */
	private function refused( string $code, string $field ): void {
		$_GET['profile']                   = 'error';
		$_GET[ Profile_Writer::ARG_ERROR ] = $code;

		if ( '' !== $field ) {
			$_GET[ Profile_Writer::ARG_FIELD ] = $field;
		}
	}
}
