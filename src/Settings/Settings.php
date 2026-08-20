<?php
/**
 * Reading the plugin's settings.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

/**
 * The one settings option, `gatedmedia_settings`, an array (specification.md
 * §8). This class only reads it — the screen that writes it is the round 5
 * Settings build, so today every key answers with its default.
 */
class Settings {

	/** The option holding every setting. */
	public const OPTION = 'gatedmedia_settings';

	/** Revoke marks the record revoked — the default, history kept. */
	public const REVOKE_BEHAVIOUR_REVOKE = 'revoke';

	/** Expire pulls the record's date to now. */
	public const REVOKE_BEHAVIOUR_EXPIRE = 'expire';

	/** Delete removes the record outright. */
	public const REVOKE_BEHAVIOUR_DELETE = 'delete';

	/**
	 * What revoking a record does on this site: revoke, expire or delete.
	 *
	 * Defaults to revoke — records are kept as history (requirements.md) —
	 * and anything unrecognised lands back there.
	 */
	public function revoke_behaviour(): string {
		$settings  = get_option( self::OPTION );
		$behaviour = is_array( $settings ) && isset( $settings['revoke_behaviour'] ) ? (string) $settings['revoke_behaviour'] : self::REVOKE_BEHAVIOUR_REVOKE;

		/**
		 * Filters the revoke behaviour, over the stored setting.
		 *
		 * @param string $behaviour One of revoke, expire, delete.
		 */
		$behaviour = (string) apply_filters( 'gatedmedia_revoke_behaviour', $behaviour );

		$known = array( self::REVOKE_BEHAVIOUR_REVOKE, self::REVOKE_BEHAVIOUR_EXPIRE, self::REVOKE_BEHAVIOUR_DELETE );

		return in_array( $behaviour, $known, true ) ? $behaviour : self::REVOKE_BEHAVIOUR_REVOKE;
	}
}
