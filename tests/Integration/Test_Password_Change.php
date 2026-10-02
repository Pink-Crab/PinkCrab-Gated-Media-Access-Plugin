<?php
/**
 * Changing a password from the profile.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_Error;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Password_Change;
use PinkCrab\Gated_Access\Account\Profile_Writer;

/**
 * The current password is required, the new one must be long enough and typed twice.
 *
 * @group integration
 */
class Test_Password_Change extends WP_UnitTestCase {

	/** The account being changed. */
	private int $user_id = 0;

	public function set_up(): void {
		parent::set_up();

		reset_phpmailer_instance();

		$this->user_id = self::factory()->user->create(
			array(
				'user_email' => 'someone@example.org',
				'user_pass'  => 'the-old-password',
			)
		);
	}

	public function tear_down(): void {
		reset_phpmailer_instance();

		parent::tear_down();
	}

	/** @testdox Nothing typed in the password fields refuses nothing. */
	public function test_nothing_typed(): void {
		$this->assertFalse( $this->check( array() )->has_errors() );
	}

	/** @testdox A current password on its own, as a browser fills it in, refuses nothing. */
	public function test_only_the_current_password(): void {
		$this->assertFalse( $this->check( array( 'current_password' => 'the-old-password' ) )->has_errors() );
	}

	/** @testdox A new password without the current one is refused on the current password field. */
	public function test_the_current_password_is_required(): void {
		$errors = $this->check(
			array(
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-brand-new-password',
			)
		);

		$this->assertRefused( $errors, 'password_current_missing', 'current_password' );
	}

	/** @testdox Only the confirm field typed is still a change, and needs the current password. */
	public function test_only_the_confirm_field(): void {
		$this->assertRefused(
			$this->check( array( 'confirm_password' => 'a-brand-new-password' ) ),
			'password_current_missing',
			'current_password'
		);
	}

	/** @testdox A wrong current password is refused on the current password field. */
	public function test_a_wrong_current_password(): void {
		$errors = $this->check(
			array(
				'current_password' => 'not-the-password',
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-brand-new-password',
			)
		);

		$this->assertRefused( $errors, 'password_current', 'current_password' );
	}

	/** @testdox A new password shorter than the minimum is refused on the new password field. */
	public function test_a_short_new_password(): void {
		$errors = $this->check(
			array(
				'current_password' => 'the-old-password',
				'new_password'     => str_repeat( 'a', 11 ),
				'confirm_password' => str_repeat( 'a', 11 ),
			)
		);

		$this->assertRefused( $errors, 'password_short', 'new_password' );
	}

	/** @testdox A new password of exactly the minimum length is accepted. */
	public function test_the_minimum_length_is_enough(): void {
		$errors = $this->check(
			array(
				'current_password' => 'the-old-password',
				'new_password'     => str_repeat( 'a', 12 ),
				'confirm_password' => str_repeat( 'a', 12 ),
			)
		);

		$this->assertFalse( $errors->has_errors() );
	}

	/** @testdox New passwords that do not match are refused on the confirm field. */
	public function test_a_mismatch(): void {
		$errors = $this->check(
			array(
				'current_password' => 'the-old-password',
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-different-password',
			)
		);

		$this->assertRefused( $errors, 'password_mismatch', 'confirm_password' );
	}

	/** @testdox The current password is checked before the new one, so a wrong one never says more. */
	public function test_the_current_password_is_checked_first(): void {
		$errors = $this->check(
			array(
				'current_password' => 'not-the-password',
				'new_password'     => 'short',
				'confirm_password' => 'different',
			)
		);

		$this->assertRefused( $errors, 'password_current', 'current_password' );
	}

	/** @testdox A correct change refuses nothing. */
	public function test_a_correct_change(): void {
		$this->assertFalse( $this->check( $this->correct() )->has_errors() );
	}

	/** @testdox A refusal carries the text the form shows. */
	public function test_a_refusal_carries_its_text(): void {
		$errors = $this->check( array( 'new_password' => 'a-brand-new-password' ) );

		$this->assertSame( Profile_Writer::message_for( 'password_current_missing' ), $errors->get_error_message() );
	}

	/** @testdox Refusals already made by others are kept. */
	public function test_other_refusals_are_kept(): void {
		$errors = new WP_Error( 'vat_invalid', 'No.' );

		$errors = ( new Password_Change() )->check( $errors, $this->user_id, array( 'new_password' => 'a-brand-new-password' ) );

		$this->assertSame( array( 'vat_invalid', 'password_current_missing' ), $errors->get_error_codes() );
	}

	/** @testdox An account that does not exist is refused, not changed. */
	public function test_an_unknown_account(): void {
		$errors = ( new Password_Change() )->check( new WP_Error(), 999999, $this->correct() );

		$this->assertRefused( $errors, 'password_current', 'current_password' );
	}

	/** @testdox Saving a correct change sets the new password. */
	public function test_save_sets_the_password(): void {
		( new Password_Change() )->save( $this->user_id, $this->correct() );

		$this->assertTrue( $this->password_is( 'a-brand-new-password' ) );
		$this->assertFalse( $this->password_is( 'the-old-password' ) );
	}

	/** @testdox Spaces and symbols inside a password are kept, and the ends trimmed, as core's sign-in trims them. */
	public function test_save_keeps_the_inside_and_trims_the_ends(): void {
		$password = '  <pass word> %41 &amp; ';

		( new Password_Change() )->save(
			$this->user_id,
			array(
				'current_password' => 'the-old-password',
				'new_password'     => $password,
				'confirm_password' => $password,
			)
		);

		$this->assertTrue( $this->password_is( '<pass word> %41 &amp;' ) );
		$this->assertTrue( $this->signs_in_with( $password ) );
	}

	/** @testdox Spaces around a new password do not count towards its length. */
	public function test_surrounding_spaces_do_not_count(): void {
		$padded = '   ' . str_repeat( 'a', 11 ) . '   ';

		$errors = $this->check(
			array(
				'current_password' => 'the-old-password',
				'new_password'     => $padded,
				'confirm_password' => $padded,
			)
		);

		$this->assertRefused( $errors, 'password_short', 'new_password' );
	}

	/** @testdox Only spaces in the new password fields is no change. */
	public function test_only_spaces_is_no_change(): void {
		$errors = $this->check(
			array(
				'current_password' => 'the-old-password',
				'new_password'     => '   ',
				'confirm_password' => '   ',
			)
		);

		$this->assertFalse( $errors->has_errors() );
	}

	/** @testdox A current password typed with spaces around it is still accepted, as sign-in accepts it. */
	public function test_the_current_password_is_trimmed(): void {
		$errors = $this->check(
			array(
				'current_password' => '  the-old-password  ',
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-brand-new-password',
			)
		);

		$this->assertFalse( $errors->has_errors() );
	}

	/** @testdox After a change, signing in works with the new password and not the old. */
	public function test_signing_in_after_a_change(): void {
		( new Password_Change() )->save( $this->user_id, $this->correct() );

		$this->assertTrue( $this->signs_in_with( 'a-brand-new-password' ) );
		$this->assertFalse( $this->signs_in_with( 'the-old-password' ) );
	}

	/** @testdox Saving with no new password leaves the password alone. */
	public function test_save_with_nothing_changes_nothing(): void {
		( new Password_Change() )->save( $this->user_id, array( 'current_password' => 'the-old-password' ) );

		$this->assertTrue( $this->password_is( 'the-old-password' ) );
	}

	/** @testdox Saving with no submission at all leaves the password alone. */
	public function test_save_with_no_submission(): void {
		( new Password_Change() )->save( $this->user_id );

		$this->assertTrue( $this->password_is( 'the-old-password' ) );
	}

	/** @testdox Saving checks again, so firing the action with a wrong current password changes nothing. */
	public function test_save_checks_again(): void {
		( new Password_Change() )->save(
			$this->user_id,
			array(
				'current_password' => 'not-the-password',
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-brand-new-password',
			)
		);

		$this->assertTrue( $this->password_is( 'the-old-password' ) );
	}

	/** @testdox Saving a mismatch changes nothing. */
	public function test_save_refuses_a_mismatch(): void {
		( new Password_Change() )->save(
			$this->user_id,
			array(
				'current_password' => 'the-old-password',
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-different-password',
			)
		);

		$this->assertTrue( $this->password_is( 'the-old-password' ) );
	}

	/** @testdox A changed password sends core's password-changed email to the account. */
	public function test_save_sends_the_changed_email(): void {
		( new Password_Change() )->save( $this->user_id, $this->correct() );

		$sent = tests_retrieve_phpmailer_instance()->get_sent( 0 );

		$this->assertNotFalse( $sent );
		$this->assertSame( 'someone@example.org', $sent->to[0][0] );
	}

	/** @testdox The plugin checks passwords on gatedmedia_profile_errors. */
	public function test_the_check_is_hooked(): void {
		$errors = apply_filters( 'gatedmedia_profile_errors', new WP_Error(), $this->user_id, array( 'new_password' => 'a-brand-new-password' ) );

		$this->assertContains( 'password_current_missing', $errors->get_error_codes() );
	}

	/** @testdox The plugin saves passwords on gatedmedia_profile_updated. */
	public function test_the_save_is_hooked(): void {
		do_action( 'gatedmedia_profile_updated', $this->user_id, $this->correct() );

		$this->assertTrue( $this->password_is( 'a-brand-new-password' ) );
	}

	/**
	 * Runs the check for the account.
	 *
	 * @param array<string, string> $submitted The posted fields.
	 */
	private function check( array $submitted ): WP_Error {
		return ( new Password_Change() )->check( new WP_Error(), $this->user_id, $submitted );
	}

	/**
	 * A submission that should pass.
	 *
	 * @return array<string, string>
	 */
	private function correct(): array {
		return array(
			'current_password' => 'the-old-password',
			'new_password'     => 'a-brand-new-password',
			'confirm_password' => 'a-brand-new-password',
		);
	}

	/**
	 * One refusal, with this code, marking this field.
	 *
	 * @param WP_Error $errors The refusals.
	 * @param string   $code   The expected code.
	 * @param string   $field  The expected field.
	 */
	private function assertRefused( WP_Error $errors, string $code, string $field ): void {
		$this->assertSame( array( $code ), $errors->get_error_codes() );
		$this->assertSame( array( 'field' => $field ), $errors->get_error_data( $code ) );
	}

	/**
	 * Whether the account's password is now this.
	 *
	 * @param string $password The password to try.
	 */
	private function password_is( string $password ): bool {
		clean_user_cache( $this->user_id );

		return wp_check_password( $password, get_userdata( $this->user_id )->user_pass, $this->user_id );
	}

	/**
	 * Whether core's own sign-in accepts this password, trimming and all.
	 *
	 * @param string $password The password as typed at sign-in.
	 */
	private function signs_in_with( string $password ): bool {
		clean_user_cache( $this->user_id );

		return wp_authenticate( get_userdata( $this->user_id )->user_login, $password ) instanceof \WP_User;
	}
}
