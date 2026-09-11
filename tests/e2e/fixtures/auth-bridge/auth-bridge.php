<?php
/**
 * Site glue: makes a core-targeting login plugin work on this plugin's own auth pages.
 *
 * @package PinkCrab\Gated_Access\Tests
 *
 * @wordpress-plugin
 * Plugin Name: Gated Media Auth Bridge (e2e)
 * Description: Fires core's login and registration hooks inside the plugin's own auth form, so a plugin that only knows core still works there.
 * Version:     1.0.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const GATEDMEDIA_BRIDGE_OPTION = 'gatedmedia_bridge_active';

/**
 * Whether the e2e run has switched the bridge on.
 */
function gatedmedia_bridge_active(): bool {
	return '1' === (string) get_option( GATEDMEDIA_BRIDGE_OPTION, '' );
}

/**
 * Draws whatever core's forms would have drawn, inside ours.
 *
 * This is the whole point of the exercise: nothing here is specific to the other plugin, it just re-publishes core's two rendering hooks.
 *
 * @param string $fields The form's fields so far.
 * @param string $state  Which of the auth states is being drawn.
 * @return string
 */
function gatedmedia_bridge_fields( string $fields, string $state ): string {
	if ( ! gatedmedia_bridge_active() ) {
		return $fields;
	}

	$hook = 'signup' === $state ? 'register_form' : 'login_form';

	ob_start();
	do_action( $hook );
	$theirs = (string) ob_get_clean();

	if ( 'signin' === $state ) {
		$theirs .= (string) apply_filters( 'login_form_middle', '', array() );
	}

	return $fields . $theirs;
}

add_filter( 'gatedmedia_auth_fields', 'gatedmedia_bridge_fields', 10, 2 );

/**
 * Asks core's registration hook whether this sign-up may proceed.
 *
 * @param WP_Error $errors The refusals so far.
 * @param string   $email  The address being registered.
 * @return WP_Error
 */
function gatedmedia_bridge_signup_errors( $errors, string $email ) {
	if ( ! gatedmedia_bridge_active() || ! $errors instanceof WP_Error ) {
		return $errors;
	}

	$theirs = apply_filters( 'registration_errors', new WP_Error(), $email, $email );

	if ( $theirs instanceof WP_Error && $theirs->has_errors() ) {
		foreach ( $theirs->get_error_codes() as $code ) {
			$errors->add( $code, (string) $theirs->get_error_message( $code ) );
		}
	}

	return $errors;
}

add_filter( 'gatedmedia_auth_signup_errors', 'gatedmedia_bridge_signup_errors', 10, 2 );
