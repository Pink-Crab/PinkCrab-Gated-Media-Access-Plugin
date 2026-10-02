<?php
/**
 * The account the profile e2e specs edit, reset on every run.
 *
 * Its own account rather than the admin, because the specs change its password and every other spec signs in as the admin.
 *
 * Run by tests/e2e/global-setup.js before the suite.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

$gatedmedia_profile_user = get_user_by( 'login', 'e2e-profile' );

if ( ! $gatedmedia_profile_user instanceof WP_User ) {
	$gatedmedia_profile_id = wp_insert_user(
		array(
			'user_login' => 'e2e-profile',
			'user_pass'  => 'e2e-profile-password',
			'user_email' => 'e2e-profile@example.test',
			'role'       => 'subscriber',
		)
	);
} else {
	$gatedmedia_profile_id = $gatedmedia_profile_user->ID;
	wp_set_password( 'e2e-profile-password', $gatedmedia_profile_id );
}

if ( is_wp_error( $gatedmedia_profile_id ) ) {
	echo 'Profile fixture failed: ' . esc_html( $gatedmedia_profile_id->get_error_message() ) . "\n";
	return;
}

// A complete name, so no incomplete notice sits above the form, and nothing else.
update_user_meta( $gatedmedia_profile_id, 'first_name', 'Profile' );
update_user_meta( $gatedmedia_profile_id, 'last_name', 'Tester' );

foreach ( array( 'company', 'phone', 'address_line', 'city', 'postcode', 'country' ) as $gatedmedia_key ) {
	delete_user_meta( $gatedmedia_profile_id, 'gatedmedia_' . $gatedmedia_key );
}

echo "Fixture ready: e2e-profile\n";
