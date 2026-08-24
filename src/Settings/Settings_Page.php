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
use PinkCrab\Gated_Access\Notifications\Notification_Sender;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Support\Account_Url;

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
	 * @param Settings            $settings      The one settings reader.
	 * @param Notification_Fields $notifications The Notifications tab's fields.
	 * @param Account_Fields      $accounts      The Accounts section's fields.
	 */
	public function __construct( private Settings $settings, private Notification_Fields $notifications, private Account_Fields $accounts ) {
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses which tab renders; the write handler carries the nonce.
		$section = isset( $_GET['section'] ) ? sanitize_key( (string) $_GET['section'] ) : '';
		?>
		<div class="wrap">
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( self::GROUP ); ?>
				<div class="gatedmedia-admin">
					<header class="gatedmedia-admin-header">
						<div>
							<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Gated Media Access', 'gated-media-access' ); ?></span>
							<h1><?php esc_html_e( 'Settings', 'gated-media-access' ); ?></h1>
						</div>
						<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Save Changes', 'gated-media-access' ); ?></button>
					</header>

					<nav class="gatedmedia-admin-tabs">
						<a class="<?php echo 'notifications' === $section ? '' : 'is-active'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'General', 'gated-media-access' ); ?></a>
						<a class="<?php echo 'notifications' === $section ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&section=notifications' ) ); ?>"><?php esc_html_e( 'Notifications', 'gated-media-access' ); ?></a>
					</nav>

					<?php if ( 'notifications' === $section ) : ?>
						<?php $this->notifications->render(); ?>
					<?php else : ?>
						<?php $this->accounts->render(); ?>

					<div class="gatedmedia-admin-section-head">
						<h2><?php esc_html_e( 'Store', 'gated-media-access' ); ?></h2>
						<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Currency and address', 'gated-media-access' ); ?></span>
					</div>

					<div class="gatedmedia-admin-field">
						<label class="gatedmedia-admin-caps" for="gatedmedia_currency"><?php esc_html_e( 'Currency', 'gated-media-access' ); ?></label>
						<select name="<?php echo esc_attr( Settings::OPTION ); ?>[currency]" id="gatedmedia_currency">
							<?php foreach ( \Symfony\Component\Intl\Currencies::getNames() as $code => $name ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $this->settings->currency() ); ?>><?php echo esc_html( "{$code} — {$name}" ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="gatedmedia-admin-help"><?php esc_html_e( 'Every product is priced and sold in this currency.', 'gated-media-access' ); ?></p>
					</div>

					<div class="gatedmedia-admin-field">
						<label class="gatedmedia-admin-caps" for="gatedmedia_product_path"><?php esc_html_e( 'Product URL path', 'gated-media-access' ); ?></label>
						<span class="gatedmedia-admin-inline">
							<code><?php echo esc_html( home_url( '/' ) ); ?></code>
							<input type="text" class="regular-text code" name="<?php echo esc_attr( Settings::OPTION ); ?>[product_path]" id="gatedmedia_product_path" value="<?php echo esc_attr( $this->settings->product_path() ); ?>" />
							<code>/&lt;uuid&gt;</code>
						</span>
						<p class="gatedmedia-admin-help"><?php esc_html_e( 'The only public way to a product is this path plus its UUID — never a slug or an ID.', 'gated-media-access' ); ?></p>
					</div>

					<div class="gatedmedia-admin-section-head">
						<h2><?php esc_html_e( 'Stripe', 'gated-media-access' ); ?></h2>
						<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Mode and keys', 'gated-media-access' ); ?></span>
					</div>

					<div class="gatedmedia-admin-field">
						<label class="gatedmedia-admin-caps" for="gatedmedia_stripe_mode"><?php esc_html_e( 'Mode', 'gated-media-access' ); ?></label>
						<select name="<?php echo esc_attr( Settings::OPTION ); ?>[stripe_mode]" id="gatedmedia_stripe_mode">
							<option value="test" <?php selected( Settings::MODE_TEST, $mode ); ?>><?php esc_html_e( 'Test', 'gated-media-access' ); ?></option>
							<option value="live" <?php selected( Settings::MODE_LIVE, $mode ); ?>><?php esc_html_e( 'Live', 'gated-media-access' ); ?></option>
						</select>
						<p class="gatedmedia-admin-help"><?php esc_html_e( 'Which set of keys checkout and the webhook use.', 'gated-media-access' ); ?></p>
					</div>

						<?php $this->render_key_rows( Settings::MODE_TEST, __( 'Test', 'gated-media-access' ) ); ?>
						<?php $this->render_key_rows( Settings::MODE_LIVE, __( 'Live', 'gated-media-access' ) ); ?>

					<div class="gatedmedia-admin-section-head">
						<h2><?php esc_html_e( 'Access', 'gated-media-access' ); ?></h2>
						<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Revoke behaviour', 'gated-media-access' ); ?></span>
					</div>

					<div class="gatedmedia-admin-field">
						<label class="gatedmedia-admin-caps" for="gatedmedia_revoke_behaviour"><?php esc_html_e( 'Revoking access', 'gated-media-access' ); ?></label>
						<select name="<?php echo esc_attr( Settings::OPTION ); ?>[revoke_behaviour]" id="gatedmedia_revoke_behaviour">
							<option value="revoke" <?php selected( Settings::REVOKE_BEHAVIOUR_REVOKE, $behaviour ); ?>><?php esc_html_e( 'Mark revoked — the record is kept as history', 'gated-media-access' ); ?></option>
							<option value="expire" <?php selected( Settings::REVOKE_BEHAVIOUR_EXPIRE, $behaviour ); ?>><?php esc_html_e( 'Expire — the record’s date is pulled to now', 'gated-media-access' ); ?></option>
							<option value="delete" <?php selected( Settings::REVOKE_BEHAVIOUR_DELETE, $behaviour ); ?>><?php esc_html_e( 'Delete — the record is removed outright', 'gated-media-access' ); ?></option>
						</select>
						<p class="gatedmedia-admin-help"><?php esc_html_e( 'What the Revoke action on the Access list does.', 'gated-media-access' ); ?></p>
					</div>
					<?php endif; ?>
				</div>
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
			'<div class="gatedmedia-admin-field"><label class="gatedmedia-admin-caps" for="gatedmedia_%2$s">%1$s</label><input type="text" class="large-text code" name="%3$s[%2$s]" id="gatedmedia_%2$s" value="%4$s" autocomplete="off" /></div>',
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
			'<div class="gatedmedia-admin-field"><label class="gatedmedia-admin-caps" for="gatedmedia_%2$s">%1$s</label><input type="password" class="large-text code" name="%3$s[%2$s]" id="gatedmedia_%2$s" value="" autocomplete="new-password" placeholder="%4$s" /><p class="gatedmedia-admin-help">%5$s</p></div>',
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

		$clean = $this->sanitize_store( $input, $clean );

		$behaviour                 = (string) ( $input['revoke_behaviour'] ?? '' );
		$known                     = array( Settings::REVOKE_BEHAVIOUR_REVOKE, Settings::REVOKE_BEHAVIOUR_EXPIRE, Settings::REVOKE_BEHAVIOUR_DELETE );
		$clean['revoke_behaviour'] = in_array( $behaviour, $known, true ) ? $behaviour : Settings::REVOKE_BEHAVIOUR_REVOKE;

		$clean = $this->sanitize_keys( $input, $clean );
		$clean = $this->accounts->sanitize( $input, $clean );

		return $this->sanitize_notifications( $input, $clean );
	}

	/**
	 * The notification keys: a switch and a subject/body override per type,
	 * the admin-copy pair, and the warning lead time.
	 *
	 * @param array<string, mixed>  $input What options.php handed over.
	 * @param array<string, string> $clean The cleaned settings so far.
	 * @return array<string, string>
	 */
	private function sanitize_notifications( array $input, array $clean ): array {
		$clean = $this->sanitize_templates( $input, $clean );

		if ( isset( $input['admin_copy'] ) ) {
			$clean['admin_copy'] = '1' === (string) $input['admin_copy'] ? '1' : '0';
		}

		if ( isset( $input['admin_copy_address'] ) ) {
			$clean['admin_copy_address'] = sanitize_email( (string) $input['admin_copy_address'] );
		}

		if ( isset( $input['expiry_warning_days'] ) ) {
			$clean['expiry_warning_days'] = (string) max( 1, absint( $input['expiry_warning_days'] ) );
		}

		return $clean;
	}

	/**
	 * Each notification type's switch and subject/body override.
	 *
	 * @param array<string, mixed>  $input What options.php handed over.
	 * @param array<string, string> $clean The cleaned settings so far.
	 * @return array<string, string>
	 */
	private function sanitize_templates( array $input, array $clean ): array {
		foreach ( array_keys( Notification_Sender::types() ) as $type ) {
			if ( isset( $input[ "notify_{$type}" ] ) ) {
				$clean[ "notify_{$type}" ] = '0' === (string) $input[ "notify_{$type}" ] ? '0' : '1';
			}

			if ( isset( $input[ "template_{$type}_subject" ] ) ) {
				$clean[ "template_{$type}_subject" ] = sanitize_text_field( (string) $input[ "template_{$type}_subject" ] );
			}

			if ( isset( $input[ "template_{$type}_body" ] ) ) {
				$clean[ "template_{$type}_body" ] = sanitize_textarea_field( (string) $input[ "template_{$type}_body" ] );
			}
		}

		return $clean;
	}

	/**
	 * The store pair: a real ISO currency, and the product path with its
	 * rewrite flush when it moves.
	 *
	 * @param array<string, mixed>  $input What options.php handed over.
	 * @param array<string, string> $clean The cleaned settings so far.
	 * @return array<string, string>
	 */
	private function sanitize_store( array $input, array $clean ): array {
		$currency          = strtoupper( sanitize_text_field( (string) ( $input['currency'] ?? '' ) ) );
		$clean['currency'] = \Symfony\Component\Intl\Currencies::exists( $currency ) ? $currency : 'GBP';

		$previous_path         = (string) ( $clean['product_path'] ?? '' );
		$path                  = sanitize_title( (string) ( $input['product_path'] ?? '' ) );
		$clean['product_path'] = '' === $path ? 'access' : $path;

		// The product rewrite rule is built from the path; a change only
		// takes with a flush.
		if ( $clean['product_path'] !== $previous_path ) {
			add_action( 'shutdown', 'flush_rewrite_rules' );
		}

		return $clean;
	}

	/**
	 * The six Stripe keys: publishable in the clear, an empty secret keeps
	 * what is stored.
	 *
	 * @param array<string, mixed>  $input What options.php handed over.
	 * @param array<string, string> $clean The cleaned settings so far.
	 * @return array<string, string>
	 */
	private function sanitize_keys( array $input, array $clean ): array {
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
