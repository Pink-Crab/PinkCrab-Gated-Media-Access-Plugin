<?php
/**
 * A stand-in for a real two-factor or captcha plugin.
 *
 * @package PinkCrab\Gated_Access\Tests
 *
 * @wordpress-plugin
 * Plugin Name: Fake Two Factor (e2e)
 * Description: Draws a token field on core's login and registration forms and refuses without it. Knows nothing about Gated Media Access.
 * Version:     1.0.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * The field it draws and demands, and the only value it accepts.
 *
 * Every hook below is one the survey found in real plugins: `login_form` (10 of 14), `register_form` (6), `authenticate` (11), `registration_errors` (7).
 */
const FAKE_2FA_FIELD = 'fake_2fa_token';
const FAKE_2FA_TOKEN = 'correct-horse';
const FAKE_2FA_OPTION = 'fake_2fa_active';

/**
 * Whether the e2e run has switched this on. Off, the whole plugin is inert.
 */
function fake_2fa_active(): bool {
	return '1' === (string) get_option( FAKE_2FA_OPTION, '' );
}

/**
 * What was posted, if anything.
 */
function fake_2fa_submitted(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- A test fixture reading its own field.
	return isset( $_POST[ FAKE_2FA_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ FAKE_2FA_FIELD ] ) ) : '';
}

/**
 * The field itself, pre-filled the way a real widget fills itself in with JavaScript.
 */
function fake_2fa_field(): void {
	if ( ! fake_2fa_active() ) {
		return;
	}

	printf(
		'<input type="hidden" name="%s" id="%s" value="%s" />',
		esc_attr( FAKE_2FA_FIELD ),
		esc_attr( FAKE_2FA_FIELD ),
		esc_attr( FAKE_2FA_TOKEN )
	);
}

add_action( 'login_form', 'fake_2fa_field' );
add_action( 'register_form', 'fake_2fa_field' );

/**
 * Refuses a sign-in without the token, at the priority the real ones use.
 *
 * @param mixed $user The user or error so far.
 * @return mixed
 */
function fake_2fa_authenticate( $user ) {
	if ( ! fake_2fa_active() || is_wp_error( $user ) ) {
		return $user;
	}

	return FAKE_2FA_TOKEN === fake_2fa_submitted()
		? $user
		: new WP_Error( 'fake_2fa', __( 'Two factor check failed.', 'default' ) );
}

add_filter( 'authenticate', 'fake_2fa_authenticate', 21 );

/**
 * Refuses a registration without the token.
 *
 * @param WP_Error $errors The errors so far.
 * @return WP_Error
 */
function fake_2fa_registration_errors( $errors ) {
	if ( fake_2fa_active() && FAKE_2FA_TOKEN !== fake_2fa_submitted() && $errors instanceof WP_Error ) {
		$errors->add( 'fake_2fa', __( 'Two factor check failed.', 'default' ) );
	}

	return $errors;
}

add_filter( 'registration_errors', 'fake_2fa_registration_errors' );
