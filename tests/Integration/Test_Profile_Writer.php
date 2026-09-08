<?php
/**
 * The profile shape and its writer.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Profile_Writer;

/**
 * All three creation routes fill the same fields, which only holds while there is one definition of what those fields are.
 *
 * @group integration
 */
class Test_Profile_Writer extends WP_UnitTestCase {

	/** @testdox The profile carries the four fields every creation route fills. */
	public function test_defines_the_fields_from_the_brief(): void {
		$fields = array_keys( Profile_Writer::fields() );

		foreach ( array( 'first_name', 'last_name', 'company', 'phone' ) as $expected ) {
			$this->assertContains( $expected, $fields );
		}
	}

	/** @testdox Email is not a profile field, because it identifies the account. */
	public function test_email_is_not_writable(): void {
		$this->assertArrayNotHasKey( 'email', Profile_Writer::fields() );
	}

	/** @testdox An untouched profile reports empty values rather than nulls. */
	public function test_values_default_to_empty_strings(): void {
		$user_id = self::factory()->user->create();

		foreach ( Profile_Writer::values_for( $user_id ) as $key => $value ) {
			$this->assertIsString( $value, sprintf( 'Field "%s" was not a string.', $key ) );
		}
	}

	/** @testdox Core fields are read from core user meta, ours from the prefixed keys. */
	public function test_reads_core_and_our_own_meta(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'first_name', 'Glynn' );
		update_user_meta( $user_id, 'gatedmedia_company', 'Pink Crab' );

		$values = Profile_Writer::values_for( $user_id );

		$this->assertSame( 'Glynn', $values['first_name'] );
		$this->assertSame( 'Pink Crab', $values['company'] );
	}

	/** @testdox Our meta keys carry the gatedmedia prefix, never a bare name. */
	public function test_our_meta_keys_are_prefixed(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'gatedmedia_phone', '01234' );

		$this->assertSame( '01234', Profile_Writer::values_for( $user_id )['phone'] );
		$this->assertSame( '', (string) get_user_meta( $user_id, 'phone', true ) );
	}

	/** @testdox A brand new account is missing the fields marked required. */
	public function test_a_new_account_is_incomplete(): void {
		$user_id = self::factory()->user->create();

		$this->assertContains( 'first_name', Profile_Writer::missing_for( $user_id ) );
		$this->assertContains( 'last_name', Profile_Writer::missing_for( $user_id ) );
	}

	/** @testdox Filling the required fields completes the profile. */
	public function test_filling_the_required_fields_completes_it(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'first_name', 'Glynn' );
		update_user_meta( $user_id, 'last_name', 'Quelch' );

		$this->assertSame( array(), Profile_Writer::missing_for( $user_id ) );
	}

	/** @testdox Whitespace does not count as a filled field. */
	public function test_whitespace_does_not_complete_a_field(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'first_name', '   ' );

		$this->assertContains( 'first_name', Profile_Writer::missing_for( $user_id ) );
	}

	/** @testdox Optional fields never make a profile incomplete. */
	public function test_optional_fields_are_not_required(): void {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'first_name', 'Glynn' );
		update_user_meta( $user_id, 'last_name', 'Quelch' );

		$this->assertNotContains( 'company', Profile_Writer::missing_for( $user_id ) );
		$this->assertNotContains( 'phone', Profile_Writer::missing_for( $user_id ) );
	}
}
