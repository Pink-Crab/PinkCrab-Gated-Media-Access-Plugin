<?php
/**
 * The profile's default groups, and the shape every field is held to.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use PinkCrab\Gated_Access\Auth\Auth_State;
use PinkCrab\Gated_Access\Support\Labels;

/**
 * What `Profile_Writer::groups()` starts from and cleans `gatedmedia_profile_fields` with. Split from the writer to keep it under phpmd's class-complexity ceiling.
 *
 * @phpstan-type Profile_Field array{label: string, type: string, store: string, required: bool, autocomplete: string, disabled: bool, message: string}
 * @phpstan-type Profile_Group array{label: string, fields: array<string, Profile_Field>}
 */
class Profile_Fields {

	/** Core user meta, read and written under the field's own key. */
	public const STORE_CORE = 'core';

	/** Our user meta, under the `gatedmedia_` prefix. */
	public const STORE_META = 'meta';

	/** Not stored by the plugin. */
	public const STORE_NONE = 'none';

	/**
	 * The plugin's own three groups: email and name, password, then contact details.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		return array(
			'main'     => array(
				'label'  => '',
				'fields' => array(
					// Identifies the account, so it is shown and never written.
					'email'      => array(
						'label'    => Labels::text( 'account.profile.email' ),
						'type'     => 'email',
						'disabled' => true,
						'message'  => Labels::text( 'account.profile.email_note' ),
					),
					'first_name' => array(
						'label'        => __( 'First name', 'gated-media-access' ),
						'store'        => self::STORE_CORE,
						'required'     => true,
						'autocomplete' => 'given-name',
					),
					'last_name'  => array(
						'label'        => __( 'Last name', 'gated-media-access' ),
						'store'        => self::STORE_CORE,
						'required'     => true,
						'autocomplete' => 'family-name',
					),
				),
			),
			'password' => array(
				'label'  => '',
				'fields' => array(
					'current_password' => array(
						'label'        => __( 'Current password', 'gated-media-access' ),
						'type'         => 'password',
						'autocomplete' => 'current-password',
					),
					'new_password'     => array(
						'label'        => __( 'New password', 'gated-media-access' ),
						'type'         => 'password',
						'autocomplete' => 'new-password',
						'message'      => sprintf( Labels::text( 'account.profile.password_note' ), Auth_State::PASSWORD_MINIMUM ),
					),
					'confirm_password' => array(
						'label'        => __( 'Confirm new password', 'gated-media-access' ),
						'type'         => 'password',
						'autocomplete' => 'new-password',
					),
				),
			),
			'contact'  => array(
				'label'  => '',
				'fields' => array(
					'company'      => array(
						'label'        => __( 'Company', 'gated-media-access' ),
						'store'        => self::STORE_META,
						'autocomplete' => 'organization',
					),
					'phone'        => array(
						'label'        => __( 'Phone', 'gated-media-access' ),
						'type'         => 'tel',
						'store'        => self::STORE_META,
						'autocomplete' => 'tel',
					),
					'address_line' => array(
						'label'        => __( 'Address', 'gated-media-access' ),
						'store'        => self::STORE_META,
						'autocomplete' => 'address-line1',
					),
					'city'         => array(
						'label'        => __( 'Town or city', 'gated-media-access' ),
						'store'        => self::STORE_META,
						'autocomplete' => 'address-level2',
					),
					'postcode'     => array(
						'label'        => __( 'Postcode', 'gated-media-access' ),
						'store'        => self::STORE_META,
						'autocomplete' => 'postal-code',
					),
					'country'      => array(
						'label'        => __( 'Country', 'gated-media-access' ),
						'store'        => self::STORE_META,
						'autocomplete' => 'country-name',
					),
				),
			),
		);
	}

	/**
	 * Drops anything malformed and fills each field's missing keys, so a filtered field only has to say what differs.
	 *
	 * @param array<mixed> $groups What the filter returned.
	 * @return array<string, Profile_Group>
	 */
	public static function normalise( array $groups ): array {
		$clean = array();

		foreach ( $groups as $key => $group ) {
			if ( ! is_string( $key ) || ! is_array( $group ) || ! is_array( $group['fields'] ?? null ) ) {
				continue;
			}

			$fields = array();

			foreach ( $group['fields'] as $name => $field ) {
				if ( is_string( $name ) && is_array( $field ) ) {
					$fields[ $name ] = self::field( $field );
				}
			}

			$clean[ $key ] = array(
				'label'  => is_string( $group['label'] ?? null ) ? $group['label'] : '',
				'fields' => $fields,
			);
		}

		return $clean;
	}

	/**
	 * One field with every key set.
	 *
	 * @param array<mixed> $field The field as given.
	 * @return Profile_Field
	 */
	private static function field( array $field ): array {
		$store = $field['store'] ?? self::STORE_NONE;

		return array(
			'label'        => is_string( $field['label'] ?? null ) ? $field['label'] : '',
			'type'         => is_string( $field['type'] ?? null ) ? $field['type'] : 'text',
			'store'        => in_array( $store, array( self::STORE_CORE, self::STORE_META ), true ) ? $store : self::STORE_NONE,
			'required'     => true === ( $field['required'] ?? false ),
			'autocomplete' => is_string( $field['autocomplete'] ?? null ) ? $field['autocomplete'] : '',
			'disabled'     => true === ( $field['disabled'] ?? false ),
			'message'      => is_string( $field['message'] ?? null ) ? $field['message'] : '',
		);
	}
}
