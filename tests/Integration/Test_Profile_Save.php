<?php
/**
 * Saving the profile from the front end.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use Exception;
use WPDieException;
use WP_Error;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Profile_Writer;

/**
 * What `Profile_Writer::handle()` writes, refuses and hands on.
 *
 * @group integration
 */
class Test_Profile_Save extends WP_UnitTestCase {

	/** Where the last redirect was aimed. */
	private string $captured = '';

	/** The signed-in user. */
	private int $user_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->captured = '';

		// The handler exits after redirecting, which would take the runner with it, so throw from the filter first.
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->captured = (string) $location;

				throw new Exception( 'redirected' );
			}
		);

		$this->user_id = self::factory()->user->create(
			array(
				'user_email' => 'someone@example.org',
				'user_pass'  => 'the-old-password',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'gatedmedia_profile_fields' );
		remove_all_filters( 'gatedmedia_profile_errors' );
		remove_all_actions( 'gatedmedia_profile_updated' );
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/** @testdox Posted name and contact fields are saved where each is stored. */
	public function test_saves_core_and_meta_fields(): void {
		$this->save(
			array(
				'first_name' => 'Glynn',
				'last_name'  => 'Quelch',
				'company'    => 'Pink Crab',
				'postcode'   => 'NG1 1AA',
			)
		);

		$this->assertSame( 'Glynn', get_user_meta( $this->user_id, 'first_name', true ) );
		$this->assertSame( 'Quelch', get_user_meta( $this->user_id, 'last_name', true ) );
		$this->assertSame( 'Pink Crab', get_user_meta( $this->user_id, 'gatedmedia_company', true ) );
		$this->assertSame( 'NG1 1AA', get_user_meta( $this->user_id, 'gatedmedia_postcode', true ) );
	}

	/** @testdox A field that was not posted is left as it was, never blanked. */
	public function test_an_unposted_field_is_left_alone(): void {
		update_user_meta( $this->user_id, 'gatedmedia_phone', '01234' );

		$this->save( array( 'company' => 'Pink Crab' ) );

		$this->assertSame( '01234', get_user_meta( $this->user_id, 'gatedmedia_phone', true ) );
	}

	/** @testdox A posted field that is empty is saved as empty. */
	public function test_an_empty_field_is_saved_empty(): void {
		update_user_meta( $this->user_id, 'gatedmedia_phone', '01234' );

		$this->save( array( 'phone' => '' ) );

		$this->assertSame( '', get_user_meta( $this->user_id, 'gatedmedia_phone', true ) );
	}

	/** @testdox Values are sanitised before they are saved. */
	public function test_values_are_sanitised(): void {
		$this->save( array( 'company' => "<b>Pink</b>\n Crab  " ) );

		$this->assertSame( 'Pink Crab', get_user_meta( $this->user_id, 'gatedmedia_company', true ) );
	}

	/** @testdox A posted key that is not a field is ignored. */
	public function test_unknown_keys_are_ignored(): void {
		$this->save(
			array(
				'role'            => 'administrator',
				'gatedmedia_role' => 'administrator',
				'user_email'      => 'attacker@example.org',
			)
		);

		$this->assertSame( '', get_user_meta( $this->user_id, 'role', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'gatedmedia_role', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'gatedmedia_user_email', true ) );
		$this->assertSame( 'someone@example.org', get_userdata( $this->user_id )->user_email );
		$this->assertFalse( user_can( $this->user_id, 'manage_options' ) );
	}

	/** @testdox A posted email is neither saved nor changes the account. */
	public function test_the_email_is_not_written(): void {
		$this->save( array( 'email' => 'attacker@example.org' ) );

		$this->assertSame( 'someone@example.org', get_userdata( $this->user_id )->user_email );
		$this->assertSame( '', get_user_meta( $this->user_id, 'email', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'gatedmedia_email', true ) );
	}

	/** @testdox A posted value that is not text is ignored. */
	public function test_an_array_value_is_ignored(): void {
		update_user_meta( $this->user_id, 'gatedmedia_company', 'Pink Crab' );

		$this->save( array( 'company' => array( 'x' ) ) );

		$this->assertSame( 'Pink Crab', get_user_meta( $this->user_id, 'gatedmedia_company', true ) );
	}

	/** @testdox A field the filter removed is not saved, even when posted. */
	public function test_a_removed_field_is_not_saved(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				unset( $groups['contact']['fields']['country'] );

				return $groups;
			}
		);

		$this->save( array( 'country' => 'England' ) );

		$this->assertSame( '', get_user_meta( $this->user_id, 'gatedmedia_country', true ) );
	}

	/** @testdox A field a site adds as meta is saved under its prefixed key. */
	public function test_a_site_meta_field_is_saved(): void {
		$this->add_vat( 'meta' );

		$this->save( array( 'vat' => 'GB123456789' ) );

		$this->assertSame( 'GB123456789', get_user_meta( $this->user_id, 'gatedmedia_vat', true ) );
	}

	/** @testdox A field a site stores itself is not written by the plugin. */
	public function test_a_none_field_is_not_written(): void {
		$this->add_vat( 'none' );

		$this->save( array( 'vat' => 'GB123456789' ) );

		$this->assertSame( '', get_user_meta( $this->user_id, 'vat', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'gatedmedia_vat', true ) );
	}

	/** @testdox gatedmedia_profile_updated carries the user and every posted field, so a site saves its own. */
	public function test_the_action_carries_the_submission(): void {
		$this->add_vat( 'none' );

		$seen = array();

		add_action(
			'gatedmedia_profile_updated',
			static function ( int $user_id, array $submitted ) use ( &$seen ): void {
				$seen = array( $user_id, $submitted );
			},
			10,
			2
		);

		$this->save(
			array(
				'company' => 'Pink Crab',
				'vat'     => 'GB123456789',
			)
		);

		$this->assertSame( $this->user_id, $seen[0] );
		$this->assertSame(
			array(
				'company' => 'Pink Crab',
				'vat'     => 'GB123456789',
			),
			$seen[1]
		);
	}

	/** @testdox The action fires after the stored fields are written, so a handler reads the new values. */
	public function test_the_action_fires_after_the_write(): void {
		$read = '';

		add_action(
			'gatedmedia_profile_updated',
			function () use ( &$read ): void {
				$read = (string) get_user_meta( $this->user_id, 'gatedmedia_company', true );
			}
		);

		$this->save( array( 'company' => 'Pink Crab' ) );

		$this->assertSame( 'Pink Crab', $read );
	}

	/** @testdox Passwords reach the action exactly as typed, since sanitising would change them. */
	public function test_passwords_are_passed_raw(): void {
		$seen = array();

		add_filter(
			'gatedmedia_profile_errors',
			static function ( WP_Error $errors, int $user_id, array $submitted ) use ( &$seen ): WP_Error {
				$seen = $submitted;

				return $errors;
			},
			1,
			3
		);

		$this->save( array( 'current_password' => '<pass  word> %41' ) );

		$this->assertSame( '<pass  word> %41', $seen['current_password'] );
	}

	/** @testdox A read-only field is left out of what the hooks see, even when posted. */
	public function test_read_only_fields_are_not_submitted(): void {
		$seen = array();

		add_action(
			'gatedmedia_profile_updated',
			static function ( int $user_id, array $submitted ) use ( &$seen ): void {
				$seen = $submitted;
			},
			10,
			2
		);

		$this->save( array( 'email' => 'attacker@example.org' ) );

		$this->assertArrayNotHasKey( 'email', $seen );
	}

	/** @testdox A save goes back with the saved state. */
	public function test_a_save_redirects_saved(): void {
		$url = $this->save( array( 'company' => 'Pink Crab' ) );

		$this->assertStringContainsString( 'profile=saved', $url );
	}

	/** @testdox A save goes back to where it came from, without the last attempt's state. */
	public function test_a_save_returns_to_the_referer(): void {
		$_REQUEST['_wp_http_referer'] = '/account/profile/?profile=error&' . Profile_Writer::ARG_ERROR . '=password_current&' . Profile_Writer::ARG_FIELD . '=current_password';

		$url = $this->save( array( 'company' => 'Pink Crab' ) );

		$this->assertStringContainsString( '/account/profile/', $url );
		$this->assertStringContainsString( 'profile=saved', $url );
		$this->assertStringNotContainsString( Profile_Writer::ARG_ERROR, $url );
		$this->assertStringNotContainsString( Profile_Writer::ARG_FIELD, $url );
	}

	/** @testdox gatedmedia_profile_errors sees the user and the posted fields. */
	public function test_the_errors_filter_sees_the_submission(): void {
		$seen = array();

		add_filter(
			'gatedmedia_profile_errors',
			static function ( WP_Error $errors, int $user_id, array $submitted ) use ( &$seen ): WP_Error {
				$seen = array( $user_id, $submitted );

				return $errors;
			},
			10,
			3
		);

		$this->save( array( 'company' => 'Pink Crab' ) );

		$this->assertSame( $this->user_id, $seen[0] );
		$this->assertSame( array( 'company' => 'Pink Crab' ), $seen[1] );
	}

	/** @testdox A refusal saves nothing and does not fire the action. */
	public function test_a_refusal_saves_nothing(): void {
		$this->refuse_with( 'vat_invalid', array( 'field' => 'company' ) );

		$fired = false;
		add_action(
			'gatedmedia_profile_updated',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$this->save(
			array(
				'first_name' => 'Glynn',
				'company'    => 'Pink Crab',
			)
		);

		$this->assertSame( '', get_user_meta( $this->user_id, 'first_name', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'gatedmedia_company', true ) );
		$this->assertFalse( $fired );
	}

	/** @testdox A refusal goes back with the error state, its code and its field. */
	public function test_a_refusal_carries_code_and_field(): void {
		$this->refuse_with( 'vat_invalid', array( 'field' => 'company' ) );

		$url = $this->save( array( 'company' => 'Pink Crab' ) );

		$this->assertStringContainsString( 'profile=error', $url );
		$this->assertStringContainsString( Profile_Writer::ARG_ERROR . '=vat_invalid', $url );
		$this->assertStringContainsString( Profile_Writer::ARG_FIELD . '=company', $url );
		$this->assertStringNotContainsString( 'profile=saved', $url );
	}

	/** @testdox A refusal with no field carries only its code. */
	public function test_a_refusal_without_a_field(): void {
		$this->refuse_with( 'vat_invalid', null );

		$url = $this->save( array( 'company' => 'Pink Crab' ) );

		$this->assertStringContainsString( Profile_Writer::ARG_ERROR . '=vat_invalid', $url );
		$this->assertStringNotContainsString( Profile_Writer::ARG_FIELD, $url );
	}

	/** @testdox Only the first refusal is carried back. */
	public function test_only_the_first_refusal_is_carried(): void {
		add_filter(
			'gatedmedia_profile_errors',
			static function ( WP_Error $errors ): WP_Error {
				$errors->add( 'first_problem', 'One.', array( 'field' => 'company' ) );
				$errors->add( 'second_problem', 'Two.', array( 'field' => 'phone' ) );

				return $errors;
			}
		);

		$url = $this->save( array( 'company' => 'Pink Crab' ) );

		$this->assertStringContainsString( Profile_Writer::ARG_ERROR . '=first_problem', $url );
		$this->assertStringNotContainsString( 'second_problem', $url );
	}

	/** @testdox An errors filter that returns something other than a WP_Error does not stop the save. */
	public function test_a_broken_errors_filter_does_not_refuse(): void {
		add_filter( 'gatedmedia_profile_errors', static fn (): string => 'nope' );

		$url = $this->save( array( 'company' => 'Pink Crab' ) );

		$this->assertSame( 'Pink Crab', get_user_meta( $this->user_id, 'gatedmedia_company', true ) );
		$this->assertStringContainsString( 'profile=saved', $url );
	}

	/** @testdox A save without a valid nonce writes nothing. */
	public function test_a_bad_nonce_saves_nothing(): void {
		$_POST['company']     = 'Pink Crab';
		$_POST['_wpnonce']    = 'not-a-nonce';
		$_REQUEST['_wpnonce'] = 'not-a-nonce';

		try {
			( new Profile_Writer() )->handle();
			$this->fail( 'Expected the nonce check to stop the save.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '', get_user_meta( $this->user_id, 'gatedmedia_company', true ) );
		}
	}

	/** @testdox The right current password and a matching new one change the password. */
	public function test_a_password_change_through_the_form(): void {
		$url = $this->save(
			array(
				'current_password' => 'the-old-password',
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-brand-new-password',
			)
		);

		$this->assertTrue( $this->password_is( 'a-brand-new-password' ) );
		$this->assertStringContainsString( 'profile=saved', $url );
	}

	/** @testdox A wrong current password changes nothing, saves nothing else, and marks the current password. */
	public function test_a_wrong_current_password_through_the_form(): void {
		$url = $this->save(
			array(
				'company'          => 'Pink Crab',
				'current_password' => 'not-the-password',
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-brand-new-password',
			)
		);

		$this->assertTrue( $this->password_is( 'the-old-password' ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'gatedmedia_company', true ) );
		$this->assertStringContainsString( Profile_Writer::ARG_ERROR . '=password_current', $url );
		$this->assertStringContainsString( Profile_Writer::ARG_FIELD . '=current_password', $url );
	}

	/** @testdox A current password filled in by the browser, with no new one, still lets the rest save. */
	public function test_an_autofilled_current_password_does_not_block_a_save(): void {
		$url = $this->save(
			array(
				'company'          => 'Pink Crab',
				'current_password' => 'the-old-password',
				'new_password'     => '',
				'confirm_password' => '',
			)
		);

		$this->assertSame( 'Pink Crab', get_user_meta( $this->user_id, 'gatedmedia_company', true ) );
		$this->assertTrue( $this->password_is( 'the-old-password' ) );
		$this->assertStringContainsString( 'profile=saved', $url );
	}

	/** @testdox With the password group removed, posted passwords are ignored. */
	public function test_no_password_group_no_change(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				unset( $groups['password'] );

				return $groups;
			}
		);

		$url = $this->save(
			array(
				'current_password' => 'the-old-password',
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-brand-new-password',
			)
		);

		$this->assertTrue( $this->password_is( 'the-old-password' ) );
		$this->assertStringContainsString( 'profile=saved', $url );
	}

	/** @testdox A password change keeps the person on the same account and leaves their name alone. */
	public function test_a_password_change_leaves_the_profile(): void {
		update_user_meta( $this->user_id, 'first_name', 'Glynn' );

		$this->save(
			array(
				'current_password' => 'the-old-password',
				'new_password'     => 'a-brand-new-password',
				'confirm_password' => 'a-brand-new-password',
			)
		);

		$this->assertSame( 'Glynn', get_user_meta( $this->user_id, 'first_name', true ) );
		$this->assertSame( 'someone@example.org', get_userdata( $this->user_id )->user_email );
	}

	/**
	 * Posts the fields with a valid nonce and returns where the save redirected.
	 *
	 * @param array<string, mixed> $fields The posted fields.
	 */
	private function save( array $fields ): string {
		$_POST                = $fields;
		$_POST['_wpnonce']    = wp_create_nonce( Profile_Writer::ACTION );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		try {
			( new Profile_Writer() )->handle();
		} catch ( WPDieException $e ) {
			$this->fail( 'The save died: ' . $e->getMessage() );
		} catch ( Exception $e ) {
			return $this->captured;
		}

		$this->fail( 'Expected a redirect and got none.' );
	}

	/**
	 * Refuses every save with this code.
	 *
	 * @param string                    $code The refusal code.
	 * @param array<string, mixed>|null $data The error data.
	 */
	private function refuse_with( string $code, ?array $data ): void {
		add_filter(
			'gatedmedia_profile_errors',
			static function ( WP_Error $errors ) use ( $code, $data ): WP_Error {
				$errors->add( $code, 'No.', $data );

				return $errors;
			}
		);
	}

	/**
	 * Adds a VAT field to the contact group, stored as given.
	 *
	 * @param string $store Where it is stored.
	 */
	private function add_vat( string $store ): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ) use ( $store ): array {
				$groups['contact']['fields']['vat'] = array(
					'label' => 'VAT number',
					'store' => $store,
				);

				return $groups;
			}
		);
	}

	/**
	 * Whether the user's password is now this.
	 *
	 * @param string $password The password to try.
	 */
	private function password_is( string $password ): bool {
		clean_user_cache( $this->user_id );

		return wp_check_password( $password, get_userdata( $this->user_id )->user_pass, $this->user_id );
	}
}
