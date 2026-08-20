<?php
/**
 * The settings screen.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * The plugin's top-level menu, and the Settings page under it — the Stripe
 * mode and keys, and the revoke behaviour whose option round 4 shipped a
 * reader for. The rest of spec §8 (account creation, profile prompt,
 * notifications, templates) arrives with the rounds that build those
 * features.
 *
 * Until round 5 the screen was unreachable: the ACCESS post type's
 * `show_in_menu` under this parent makes the top-level click land on the
 * Access list, and no Settings entry existed. Now it has its own submenu
 * item — registered under the parent, never `remove_submenu_page()`d
 * (`Edit_Access_Page` records how that breaks page access).
 *
 * Secrets are never echoed back: a stored secret renders as an empty
 * password input with a saved marker, and an empty submit keeps what is
 * stored — retyping is only needed to change one.
 */
class Settings_Page implements Hookable {

	/**
	 * The menu slug, and the page's `page` query arg.
	 */
	public const MENU_SLUG = 'gated-media-access';

	/** The Settings page's own slug. */
	public const SETTINGS_SLUG = 'gatedmedia-settings';

	/** The Settings API group. */
	public const GROUP = 'gatedmedia_settings_group';

	/**
	 * Reads back what the form displays.
	 *
	 * @param Settings $settings The one settings reader.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * The menu, the page, the option's registration and its capability.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'admin_menu', array( $this, 'register_menu' ) );
		$loader->action( 'init', array( $this, 'register_setting' ) );
		$loader->filter( 'option_page_capability_' . self::GROUP, array( $this, 'option_capability' ) );
	}

	/**
	 * Adds the top-level menu and the Settings entry under it.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Gated Media Access', 'gated-media-access' ),
			__( 'Gated Access', 'gated-media-access' ),
			Capabilities::manage_settings(),
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-lock'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'gated-media-access' ),
			__( 'Settings', 'gated-media-access' ),
			Capabilities::manage_settings(),
			self::SETTINGS_SLUG,
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Registers the one option with the Settings API, so options.php
	 * accepts and sanitizes the form's submit.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Core's options.php demands manage_options unless the option page
	 * names its own capability — ours is the filtered manage-settings one.
	 */
	public function option_capability(): string {
		return Capabilities::manage_settings();
	}

	/**
	 * The top-level page. The ACCESS post type's menu placement means the
	 * top-level click lands on the Access list; this render only exists for
	 * a direct visit to the slug.
	 */
	public function render(): void {
		printf(
			'<div class="wrap"><h1>%s</h1><p><a href="%s">%s</a></p></div>',
			esc_html__( 'Gated Media Access', 'gated-media-access' ),
			esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			esc_html__( 'Settings', 'gated-media-access' )
		);
	}

	/**
	 * The Settings form: mode, the six keys, and the revoke behaviour.
	 */
	public function render_settings(): void {
		$mode      = $this->settings->stripe_mode();
		$behaviour = $this->settings->revoke_behaviour();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Gated Media Access — Settings', 'gated-media-access' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( self::GROUP ); ?>
				<h2><?php esc_html_e( 'Stripe', 'gated-media-access' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gatedmedia_stripe_mode"><?php esc_html_e( 'Mode', 'gated-media-access' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( Settings::OPTION ); ?>[stripe_mode]" id="gatedmedia_stripe_mode">
								<option value="test" <?php selected( Settings::MODE_TEST, $mode ); ?>><?php esc_html_e( 'Test', 'gated-media-access' ); ?></option>
								<option value="live" <?php selected( Settings::MODE_LIVE, $mode ); ?>><?php esc_html_e( 'Live', 'gated-media-access' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Which set of keys checkout and the webhook use.', 'gated-media-access' ); ?></p>
						</td>
					</tr>
					<?php $this->render_key_rows( Settings::MODE_TEST, __( 'Test', 'gated-media-access' ) ); ?>
					<?php $this->render_key_rows( Settings::MODE_LIVE, __( 'Live', 'gated-media-access' ) ); ?>
				</table>
				<h2><?php esc_html_e( 'Access', 'gated-media-access' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gatedmedia_revoke_behaviour"><?php esc_html_e( 'Revoking access', 'gated-media-access' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( Settings::OPTION ); ?>[revoke_behaviour]" id="gatedmedia_revoke_behaviour">
								<option value="revoke" <?php selected( Settings::REVOKE_BEHAVIOUR_REVOKE, $behaviour ); ?>><?php esc_html_e( 'Mark revoked — the record is kept as history', 'gated-media-access' ); ?></option>
								<option value="expire" <?php selected( Settings::REVOKE_BEHAVIOUR_EXPIRE, $behaviour ); ?>><?php esc_html_e( 'Expire — the record’s date is pulled to now', 'gated-media-access' ); ?></option>
								<option value="delete" <?php selected( Settings::REVOKE_BEHAVIOUR_DELETE, $behaviour ); ?>><?php esc_html_e( 'Delete — the record is removed outright', 'gated-media-access' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'What the Revoke action on the Access list does.', 'gated-media-access' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One mode's three key rows. Secrets render empty with a saved marker —
	 * the stored value never travels back to the browser.
	 *
	 * @param string $mode  test or live.
	 * @param string $label The mode as a heading word.
	 */
	private function render_key_rows( string $mode, string $label ): void {
		$stored = get_option( Settings::OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		$publishable = (string) ( $stored[ "stripe_{$mode}_key" ] ?? '' );

		/* translators: %s: test or live. */
		$this->text_row( sprintf( __( '%s publishable key', 'gated-media-access' ), $label ), "stripe_{$mode}_key", $publishable );
		/* translators: %s: test or live. */
		$this->secret_row( sprintf( __( '%s secret key', 'gated-media-access' ), $label ), "stripe_{$mode}_secret", '' !== (string) ( $stored[ "stripe_{$mode}_secret" ] ?? '' ) );
		/* translators: %s: test or live. */
		$this->secret_row( sprintf( __( '%s webhook secret', 'gated-media-access' ), $label ), "stripe_{$mode}_webhook_secret", '' !== (string) ( $stored[ "stripe_{$mode}_webhook_secret" ] ?? '' ) );
	}

	/**
	 * A plain text row — publishable keys are not secrets.
	 *
	 * @param string $label Its label.
	 * @param string $name  The option array key.
	 * @param string $value The stored value.
	 */
	private function text_row( string $label, string $name, string $value ): void {
		printf(
			'<tr><th scope="row"><label for="gatedmedia_%2$s">%1$s</label></th><td><input type="text" class="regular-text code" name="%3$s[%2$s]" id="gatedmedia_%2$s" value="%4$s" autocomplete="off" /></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( Settings::OPTION ),
			esc_attr( $value )
		);
	}

	/**
	 * A secret row: always empty, marked when one is saved, kept when
	 * submitted empty.
	 *
	 * @param string $label     Its label.
	 * @param string $name      The option array key.
	 * @param bool   $has_value Whether one is stored.
	 */
	private function secret_row( string $label, string $name, bool $has_value ): void {
		printf(
			'<tr><th scope="row"><label for="gatedmedia_%2$s">%1$s</label></th><td><input type="password" class="regular-text code" name="%3$s[%2$s]" id="gatedmedia_%2$s" value="" autocomplete="new-password" placeholder="%4$s" /><p class="description">%5$s</p></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( Settings::OPTION ),
			esc_attr( $has_value ? __( 'saved — leave empty to keep', 'gated-media-access' ) : '' ),
			esc_html( $has_value ? __( 'A value is saved. It is never shown; type to replace it.', 'gated-media-access' ) : __( 'Nothing saved yet.', 'gated-media-access' ) )
		);
	}

	/**
	 * The submit, cleaned: mode and behaviour to known values, keys to
	 * plain text, and an empty secret keeps what is stored.
	 *
	 * @param mixed $input What options.php hands over.
	 * @return array<string, string>
	 */
	public function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = get_option( Settings::OPTION );
		$clean    = is_array( $existing ) ? array_map( 'strval', $existing ) : array();

		$clean['stripe_mode'] = Settings::MODE_LIVE === ( $input['stripe_mode'] ?? '' ) ? Settings::MODE_LIVE : Settings::MODE_TEST;

		$behaviour                 = (string) ( $input['revoke_behaviour'] ?? '' );
		$known                     = array( Settings::REVOKE_BEHAVIOUR_REVOKE, Settings::REVOKE_BEHAVIOUR_EXPIRE, Settings::REVOKE_BEHAVIOUR_DELETE );
		$clean['revoke_behaviour'] = in_array( $behaviour, $known, true ) ? $behaviour : Settings::REVOKE_BEHAVIOUR_REVOKE;

		foreach ( array( 'stripe_test_key', 'stripe_live_key' ) as $name ) {
			$clean[ $name ] = sanitize_text_field( (string) ( $input[ $name ] ?? '' ) );
		}

		foreach ( array( 'stripe_test_secret', 'stripe_test_webhook_secret', 'stripe_live_secret', 'stripe_live_webhook_secret' ) as $name ) {
			$submitted = sanitize_text_field( (string) ( $input[ $name ] ?? '' ) );

			if ( '' !== $submitted ) {
				$clean[ $name ] = $submitted;
			}
		}

		return $clean;
	}
}
