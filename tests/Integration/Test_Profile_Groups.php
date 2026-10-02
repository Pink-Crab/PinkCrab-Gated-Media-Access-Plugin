<?php
/**
 * The profile's groups, and the filters over them.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Profile_Fields;
use PinkCrab\Gated_Access\Account\Profile_Writer;
use PinkCrab\Gated_Access\Support\Labels;

/**
 * Groups and fields are drawn in order, and the same filtered list decides what is saved.
 *
 * @group integration
 */
class Test_Profile_Groups extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_profile_fields' );
		remove_all_filters( 'gatedmedia_profile_values' );
		remove_all_filters( 'gatedmedia_labels' );
		Labels::forget();

		parent::tear_down();
	}

	/** @testdox The default groups are main, password and contact, in that order. */
	public function test_default_group_order(): void {
		$this->assertSame( array( 'main', 'password', 'contact' ), array_keys( Profile_Writer::groups() ) );
	}

	/** @testdox Main holds the email, first name and last name, in that order. */
	public function test_main_group_fields(): void {
		$this->assertSame(
			array( 'email', 'first_name', 'last_name' ),
			array_keys( Profile_Writer::groups()['main']['fields'] )
		);
	}

	/** @testdox Password holds current, new and confirm, all password inputs not stored here. */
	public function test_password_group_fields(): void {
		$fields = Profile_Writer::groups()['password']['fields'];

		$this->assertSame( array( 'current_password', 'new_password', 'confirm_password' ), array_keys( $fields ) );

		foreach ( $fields as $key => $field ) {
			$this->assertSame( 'password', $field['type'], $key );
			$this->assertSame( Profile_Fields::STORE_NONE, $field['store'], $key );
			$this->assertFalse( $field['required'], $key );
		}
	}

	/** @testdox The password inputs carry the autocomplete hints password managers read. */
	public function test_password_autocomplete(): void {
		$fields = Profile_Writer::groups()['password']['fields'];

		$this->assertSame( 'current-password', $fields['current_password']['autocomplete'] );
		$this->assertSame( 'new-password', $fields['new_password']['autocomplete'] );
		$this->assertSame( 'new-password', $fields['confirm_password']['autocomplete'] );
	}

	/** @testdox The new password's helper line names the minimum length. */
	public function test_new_password_note_names_the_minimum(): void {
		$this->assertStringContainsString( '12', Profile_Writer::groups()['password']['fields']['new_password']['message'] );
	}

	/** @testdox Contact holds company, phone, address, town or city, postcode and country, in that order. */
	public function test_contact_group_fields(): void {
		$this->assertSame(
			array( 'company', 'phone', 'address_line', 'city', 'postcode', 'country' ),
			array_keys( Profile_Writer::groups()['contact']['fields'] )
		);
	}

	/** @testdox No group carries a label by default, so none is headed. */
	public function test_groups_are_unlabelled_by_default(): void {
		foreach ( Profile_Writer::groups() as $key => $group ) {
			$this->assertSame( '', $group['label'], $key );
		}
	}

	/** @testdox The email is shown read-only and never stored here. */
	public function test_email_is_read_only(): void {
		$email = Profile_Writer::groups()['main']['fields']['email'];

		$this->assertTrue( $email['disabled'] );
		$this->assertSame( Profile_Fields::STORE_NONE, $email['store'] );
		$this->assertSame( 'email', $email['type'] );
		$this->assertSame( Labels::text( 'account.profile.email_note' ), $email['message'] );
	}

	/** @testdox Only first and last name are required. */
	public function test_only_the_name_is_required(): void {
		$required = array();

		foreach ( Profile_Writer::groups() as $group ) {
			foreach ( $group['fields'] as $key => $field ) {
				if ( $field['required'] ) {
					$required[] = $key;
				}
			}
		}

		$this->assertSame( array( 'first_name', 'last_name' ), $required );
	}

	/** @testdox First and last name are core user meta, the rest of the stored fields are ours. */
	public function test_default_stores(): void {
		$fields = Profile_Writer::fields();

		$this->assertSame( Profile_Fields::STORE_CORE, $fields['first_name']['store'] );
		$this->assertSame( Profile_Fields::STORE_CORE, $fields['last_name']['store'] );

		foreach ( array( 'company', 'phone', 'address_line', 'city', 'postcode', 'country' ) as $key ) {
			$this->assertSame( Profile_Fields::STORE_META, $fields[ $key ]['store'], $key );
		}
	}

	/** @testdox The stored fields leave out the email and the passwords, in render order. */
	public function test_stored_fields_leave_out_email_and_passwords(): void {
		$this->assertSame(
			array( 'first_name', 'last_name', 'company', 'phone', 'address_line', 'city', 'postcode', 'country' ),
			array_keys( Profile_Writer::fields() )
		);
	}

	/** @testdox A site can reorder the groups. */
	public function test_groups_can_be_reordered(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static fn ( array $groups ): array => array(
				'contact'  => $groups['contact'],
				'main'     => $groups['main'],
				'password' => $groups['password'],
			)
		);

		$this->assertSame( array( 'contact', 'main', 'password' ), array_keys( Profile_Writer::groups() ) );
	}

	/** @testdox A site can reorder the fields inside a group. */
	public function test_fields_can_be_reordered(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$fields                   = $groups['main']['fields'];
				$groups['main']['fields'] = array(
					'last_name'  => $fields['last_name'],
					'first_name' => $fields['first_name'],
					'email'      => $fields['email'],
				);

				return $groups;
			}
		);

		$this->assertSame( array( 'last_name', 'first_name', 'email' ), array_keys( Profile_Writer::groups()['main']['fields'] ) );
	}

	/** @testdox A site can move a field from one group to another. */
	public function test_a_field_can_move_group(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['main']['fields']['phone'] = $groups['contact']['fields']['phone'];
				unset( $groups['contact']['fields']['phone'] );

				return $groups;
			}
		);

		$groups = Profile_Writer::groups();

		$this->assertArrayHasKey( 'phone', $groups['main']['fields'] );
		$this->assertArrayNotHasKey( 'phone', $groups['contact']['fields'] );
		$this->assertArrayHasKey( 'phone', Profile_Writer::fields() );
	}

	/** @testdox A site can remove a whole group. */
	public function test_a_group_can_be_removed(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				unset( $groups['password'] );

				return $groups;
			}
		);

		$this->assertSame( array( 'main', 'contact' ), array_keys( Profile_Writer::groups() ) );
	}

	/** @testdox A removed field is no longer one of the stored fields. */
	public function test_a_removed_field_is_not_stored(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				unset( $groups['contact']['fields']['country'] );

				return $groups;
			}
		);

		$this->assertArrayNotHasKey( 'country', Profile_Writer::fields() );
		$this->assertArrayNotHasKey( 'country', Profile_Writer::values_for( self::factory()->user->create() ) );
	}

	/** @testdox A site can add a group of its own. */
	public function test_a_group_can_be_added(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['billing'] = array(
					'label'  => 'Billing',
					'fields' => array(
						'vat' => array(
							'label' => 'VAT number',
							'store' => 'meta',
						),
					),
				);

				return $groups;
			}
		);

		$groups = Profile_Writer::groups();

		$this->assertSame( array( 'main', 'password', 'contact', 'billing' ), array_keys( $groups ) );
		$this->assertSame( 'Billing', $groups['billing']['label'] );
		$this->assertArrayHasKey( 'vat', Profile_Writer::fields() );
	}

	/** @testdox A site's field only has to say what differs from the defaults. */
	public function test_a_field_is_filled_with_defaults(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['contact']['fields']['vat'] = array( 'label' => 'VAT number' );

				return $groups;
			}
		);

		$this->assertSame(
			array(
				'label'        => 'VAT number',
				'type'         => 'text',
				'store'        => Profile_Fields::STORE_NONE,
				'required'     => false,
				'autocomplete' => '',
				'disabled'     => false,
				'message'      => '',
			),
			Profile_Writer::groups()['contact']['fields']['vat']
		);
	}

	/** @testdox A site's meta field reads from its prefixed key. */
	public function test_a_meta_field_reads_its_prefixed_key(): void {
		$this->add_vat( 'meta' );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'gatedmedia_vat', 'GB123456789' );

		$this->assertSame( 'GB123456789', Profile_Writer::values_for( $user_id )['vat'] );
	}

	/** @testdox A site's core field reads from the bare key. */
	public function test_a_core_field_reads_its_bare_key(): void {
		$this->add_vat( 'core' );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'vat', 'GB123456789' );

		$this->assertSame( 'GB123456789', Profile_Writer::values_for( $user_id )['vat'] );
	}

	/** @testdox A site's field stored elsewhere is not one of the stored fields. */
	public function test_a_none_field_is_not_stored(): void {
		$this->add_vat( 'none' );

		$this->assertArrayNotHasKey( 'vat', Profile_Writer::fields() );
	}

	/** @testdox An unknown store is treated as not stored here. */
	public function test_an_unknown_store_is_none(): void {
		$this->add_vat( 'database' );

		$this->assertSame( Profile_Fields::STORE_NONE, Profile_Writer::groups()['contact']['fields']['vat']['store'] );
		$this->assertArrayNotHasKey( 'vat', Profile_Writer::fields() );
	}

	/** @testdox A required field stored elsewhere does not make the profile incomplete. */
	public function test_a_required_none_field_is_not_missing(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['contact']['fields']['vat'] = array(
					'label'    => 'VAT number',
					'required' => true,
				);

				return $groups;
			}
		);

		$this->assertNotContains( 'vat', Profile_Writer::missing_for( self::factory()->user->create() ) );
	}

	/** @testdox A required field a site stores here does make the profile incomplete. */
	public function test_a_required_meta_field_is_missing(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['contact']['fields']['vat'] = array(
					'label'    => 'VAT number',
					'store'    => 'meta',
					'required' => true,
				);

				return $groups;
			}
		);

		$this->assertContains( 'vat', Profile_Writer::missing_for( self::factory()->user->create() ) );
	}

	/** @testdox A required flag that is not exactly true is not required. */
	public function test_required_must_be_true(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['contact']['fields']['vat'] = array(
					'store'    => 'meta',
					'required' => 'yes',
				);

				return $groups;
			}
		);

		$this->assertFalse( Profile_Writer::fields()['vat']['required'] );
	}

	/** @testdox A filter that returns something other than an array leaves the defaults in place. */
	public function test_a_broken_filter_keeps_the_defaults(): void {
		add_filter( 'gatedmedia_profile_fields', static fn (): string => 'nope' );

		$this->assertSame( array( 'main', 'password', 'contact' ), array_keys( Profile_Writer::groups() ) );
	}

	/** @testdox Malformed groups and fields are dropped rather than drawn. */
	public function test_malformed_entries_are_dropped(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['not_an_array']        = 'x';
				$groups['no_fields']           = array( 'label' => 'Empty' );
				$groups[0]                     = array( 'fields' => array() );
				$groups['main']['fields']['x'] = 'not a field';
				$groups['main']['fields'][7]   = array( 'label' => 'Numbered' );

				return $groups;
			}
		);

		$groups = Profile_Writer::groups();

		$this->assertSame( array( 'main', 'password', 'contact' ), array_keys( $groups ) );
		$this->assertSame( array( 'email', 'first_name', 'last_name' ), array_keys( $groups['main']['fields'] ) );
	}

	/** @testdox A label that is not text is dropped to empty rather than drawn. */
	public function test_a_non_string_label_is_empty(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				$groups['contact']['label'] = array( 'Contact' );

				return $groups;
			}
		);

		$this->assertSame( '', Profile_Writer::groups()['contact']['label'] );
	}

	/** @testdox The form values carry the account's email. */
	public function test_form_values_carry_the_email(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'someone@example.org' ) );

		$this->assertSame( 'someone@example.org', Profile_Writer::form_values( $user_id )['email'] );
	}

	/** @testdox The form values carry the stored values. */
	public function test_form_values_carry_stored_values(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'first_name', 'Glynn' );
		update_user_meta( $user_id, 'gatedmedia_company', 'Pink Crab' );

		$values = Profile_Writer::form_values( $user_id );

		$this->assertSame( 'Glynn', $values['first_name'] );
		$this->assertSame( 'Pink Crab', $values['company'] );
	}

	/** @testdox The form values give every field a string, and the passwords nothing. */
	public function test_form_values_cover_every_field(): void {
		$values = Profile_Writer::form_values( self::factory()->user->create() );

		foreach ( Profile_Writer::groups() as $group ) {
			foreach ( array_keys( $group['fields'] ) as $key ) {
				$this->assertArrayHasKey( $key, $values );
				$this->assertIsString( $values[ $key ] );
			}
		}

		$this->assertSame( '', $values['current_password'] );
		$this->assertSame( '', $values['new_password'] );
		$this->assertSame( '', $values['confirm_password'] );
	}

	/** @testdox With the email field removed, the form values do not carry it. */
	public function test_form_values_follow_a_removed_email(): void {
		add_filter(
			'gatedmedia_profile_fields',
			static function ( array $groups ): array {
				unset( $groups['main']['fields']['email'] );

				return $groups;
			}
		);

		$this->assertArrayNotHasKey( 'email', Profile_Writer::form_values( self::factory()->user->create() ) );
	}

	/** @testdox A site fills the fields it stores itself through gatedmedia_profile_values. */
	public function test_values_filter_fills_a_none_field(): void {
		$this->add_vat( 'none' );

		$user_id = self::factory()->user->create();
		$seen    = 0;

		add_filter(
			'gatedmedia_profile_values',
			static function ( array $values, int $id ) use ( &$seen ): array {
				$seen          = $id;
				$values['vat'] = 'GB123456789';

				return $values;
			},
			10,
			2
		);

		$this->assertSame( 'GB123456789', Profile_Writer::form_values( $user_id )['vat'] );
		$this->assertSame( $user_id, $seen );
	}

	/** @testdox Values the filter gives that are not text are drawn as empty. */
	public function test_values_filter_non_scalars_are_empty(): void {
		add_filter(
			'gatedmedia_profile_values',
			static function ( array $values ): array {
				$values['company'] = array( 'x' );
				$values['phone']   = 12345;

				return $values;
			}
		);

		$values = Profile_Writer::form_values( self::factory()->user->create() );

		$this->assertSame( '', $values['company'] );
		$this->assertSame( '12345', $values['phone'] );
	}

	/** @testdox A values filter that returns something other than an array is ignored. */
	public function test_a_broken_values_filter_is_ignored(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'someone@example.org' ) );

		add_filter( 'gatedmedia_profile_values', static fn (): string => 'nope' );

		$this->assertSame( 'someone@example.org', Profile_Writer::form_values( $user_id )['email'] );
	}

	/** @testdox A refusal code with a label of its own reads that label. */
	public function test_message_for_a_known_code(): void {
		$this->assertSame(
			Labels::text( 'account.profile.error.password_current' ),
			Profile_Writer::message_for( 'password_current' )
		);
	}

	/** @testdox The short-password text names the minimum length. */
	public function test_message_for_short_names_the_minimum(): void {
		$this->assertStringContainsString( '12', Profile_Writer::message_for( 'password_short' ) );
		$this->assertStringNotContainsString( '%d', Profile_Writer::message_for( 'password_short' ) );
	}

	/** @testdox A code with no label of its own falls back to the not-saved line. */
	public function test_message_for_an_unknown_code(): void {
		$this->assertSame( Labels::text( 'account.profile.not_saved' ), Profile_Writer::message_for( 'something_else' ) );
	}

	/** @testdox A site adds the text for its own refusal code through gatedmedia_labels. */
	public function test_message_for_a_site_code(): void {
		add_filter(
			'gatedmedia_labels',
			static function ( array $labels ): array {
				$labels['account.profile.error.vat_invalid'] = 'That is not a VAT number.';

				return $labels;
			}
		);
		Labels::forget();

		$this->assertSame( 'That is not a VAT number.', Profile_Writer::message_for( 'vat_invalid' ) );
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
}
