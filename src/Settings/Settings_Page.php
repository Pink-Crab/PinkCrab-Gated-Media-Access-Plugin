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
use PinkCrab\Gated_Access\Support\View;

/**
 * The plugin's top-level menu, and the Settings page under it: the shop currency, the product path, the Stripe mode and keys, the revoke behaviour, the account fields and the notification templates.
 *
 * The screen has to exist for the menu to work, because the ACCESS post type's `show_in_menu` under this parent makes the top-level click land on the Access list and no Settings entry existed.
 *
 * It now has its own submenu item, registered under the parent and never `remove_submenu_page()`d, which `Edit_Access_Page` records as breaking page access.
 *
 * Secrets are never echoed back: a stored secret renders as an empty password input with a saved marker, and an empty submit keeps what is stored, so retyping is needed only to change one.
 *
 * The markup is in `views/admin/settings/`.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") A settings screen is a list of fields and every field is one more branch, so splitting it further buys nothing a reader wants.
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
	 * Registers the one option with the Settings API, so options.php accepts and sanitizes the form's submit.
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
	 * Core's options.php demands manage_options unless the option page names its own capability, and ours is the filtered manage-settings one.
	 */
	public function option_capability(): string {
		return Capabilities::manage_settings();
	}

	/**
	 * The top-level page: the ACCESS post type's menu placement means the top-level click lands on the Access list, so this render exists only for a direct visit to the slug.
	 */
	public function render(): void {
		View::render(
			'admin/settings/landing',
			array( 'settings_url' => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) )
		);
	}

	/**
	 * The Settings form: one form, two tabs, one save.
	 */
	public function render_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses which tab renders; the write handler carries the nonce.
		$section = isset( $_GET['section'] ) ? sanitize_key( (string) $_GET['section'] ) : '';

		View::render(
			'admin/settings/index',
			array(
				'group'             => self::GROUP,
				'form_url'          => admin_url( 'options.php' ),
				'general_url'       => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
				'notifications_url' => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&section=notifications' ),
				'on_notifications'  => 'notifications' === $section,
				'notifications'     => $this->notifications,
				'general'           => $this->general_data(),
			)
		);
	}

	/**
	 * What the General tab draws: the accounts section, the store, Stripe, revoking, and the uninstall choice.
	 *
	 * @return array<string, mixed>
	 */
	private function general_data(): array {
		return array(
			'option'             => Settings::OPTION,
			'accounts'           => $this->accounts,
			'currencies'         => \Symfony\Component\Intl\Currencies::getNames(),
			'currency'           => $this->settings->currency(),
			'product_path'       => $this->settings->product_path(),
			'home_url'           => home_url( '/' ),
			'stripe_mode'        => $this->settings->stripe_mode(),
			'mode_test'          => Settings::MODE_TEST,
			'mode_live'          => Settings::MODE_LIVE,
			'key_rows'           => $this->key_rows(),
			'revoke_behaviour'   => $this->settings->revoke_behaviour(),
			'revoke_options'     => array(
				Settings::REVOKE_BEHAVIOUR_REVOKE => __( 'Mark revoked, keeping the record as history', 'gated-media-access' ),
				Settings::REVOKE_BEHAVIOUR_EXPIRE => __( 'Expire, pulling the record’s date to now', 'gated-media-access' ),
				Settings::REVOKE_BEHAVIOUR_DELETE => __( 'Delete, removing the record outright', 'gated-media-access' ),
			),
			'purge_on_uninstall' => $this->settings->purge_on_uninstall(),
		);
	}

	/**
	 * The six Stripe key fields: a publishable key in the clear, the two secrets marked but never carried back.
	 *
	 * @return array<int, array{type: string, label: string, name: string, value: string, has_value: bool}>
	 */
	private function key_rows(): array {
		$stored = get_option( Settings::OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		$modes = array(
			Settings::MODE_TEST => __( 'Test', 'gated-media-access' ),
			Settings::MODE_LIVE => __( 'Live', 'gated-media-access' ),
		);

		$rows = array();

		foreach ( $modes as $mode => $label ) {
			$rows[] = array(
				'type'      => 'text',
				/* translators: %s: test or live. */
				'label'     => sprintf( __( '%s publishable key', 'gated-media-access' ), $label ),
				'name'      => "stripe_{$mode}_key",
				'value'     => (string) ( $stored[ "stripe_{$mode}_key" ] ?? '' ),
				'has_value' => false,
			);

			$secrets = array(
				/* translators: %s: test or live. */
				"stripe_{$mode}_secret"         => sprintf( __( '%s secret key', 'gated-media-access' ), $label ),
				/* translators: %s: test or live. */
				"stripe_{$mode}_webhook_secret" => sprintf( __( '%s webhook secret', 'gated-media-access' ), $label ),
			);

			foreach ( $secrets as $name => $secret_label ) {
				$rows[] = array(
					'type'      => 'secret',
					'label'     => $secret_label,
					'name'      => $name,
					'value'     => '',
					'has_value' => '' !== (string) ( $stored[ $name ] ?? '' ),
				);
			}
		}

		return $rows;
	}

	/**
	 * The submit, cleaned: mode and behaviour to known values, keys to plain text, and an empty secret keeps what is stored.
	 *
	 * @param mixed $input What options.php hands over.
	 * @return array<string, string>
	 */
	public function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = get_option( Settings::OPTION );
		$clean    = is_array( $existing ) ? array_map( 'strval', $existing ) : array();

		// Absent means "this form did not carry the field", never "reset it": the screen is two tabs posting one form to one callback, so a Notifications save arrives with no General key on it, and writing a default for each of those flipped a live shop to test mode, blanked its publishable keys and moved every product URL.
		if ( isset( $input['stripe_mode'] ) ) {
			$clean['stripe_mode'] = Settings::MODE_LIVE === $input['stripe_mode'] ? Settings::MODE_LIVE : Settings::MODE_TEST;
		}

		$clean = $this->sanitize_store( $input, $clean );

		if ( isset( $input['revoke_behaviour'] ) ) {
			$behaviour                 = (string) $input['revoke_behaviour'];
			$known                     = array( Settings::REVOKE_BEHAVIOUR_REVOKE, Settings::REVOKE_BEHAVIOUR_EXPIRE, Settings::REVOKE_BEHAVIOUR_DELETE );
			$clean['revoke_behaviour'] = in_array( $behaviour, $known, true ) ? $behaviour : Settings::REVOKE_BEHAVIOUR_REVOKE;
		}

		if ( isset( $input['purge_on_uninstall'] ) ) {
			$clean['purge_on_uninstall'] = '1' === (string) $input['purge_on_uninstall'] ? '1' : '0';
		}

		$clean = $this->sanitize_keys( $input, $clean );
		$clean = $this->accounts->sanitize( $input, $clean );

		return $this->sanitize_notifications( $input, $clean );
	}

	/**
	 * The notification keys: a switch and a subject and body override per type, the admin-copy pair, and the warning lead time.
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
	 * The store pair: a real ISO currency, and the product path with its rewrite flush when it moves.
	 *
	 * @param array<string, mixed>  $input What options.php handed over.
	 * @param array<string, string> $clean The cleaned settings so far.
	 * @return array<string, string>
	 */
	private function sanitize_store( array $input, array $clean ): array {
		if ( isset( $input['currency'] ) ) {
			$currency          = strtoupper( sanitize_text_field( (string) $input['currency'] ) );
			$clean['currency'] = \Symfony\Component\Intl\Currencies::exists( $currency ) ? $currency : 'GBP';
		}

		if ( ! isset( $input['product_path'] ) ) {
			return $clean;
		}

		$previous_path         = (string) ( $clean['product_path'] ?? '' );
		$path                  = sanitize_title( (string) $input['product_path'] );
		$clean['product_path'] = '' === $path ? 'access' : $path;

		// The product rewrite rule is built from the path and a change only takes with a flush, guarded on the path itself, or a save from a form that never carried it scheduled a flush for a change that had not happened.
		if ( $clean['product_path'] !== $previous_path ) {
			add_action( 'shutdown', 'flush_rewrite_rules' );
		}

		return $clean;
	}

	/**
	 * The six Stripe keys: publishable in the clear, and an empty secret keeps what is stored.
	 *
	 * @param array<string, mixed>  $input What options.php handed over.
	 * @param array<string, string> $clean The cleaned settings so far.
	 * @return array<string, string>
	 */
	private function sanitize_keys( array $input, array $clean ): array {
		// A publishable key is not a secret, so an empty submit does clear it, but only when the form carried the field, since absent is still "not submitted" here as everywhere else.
		foreach ( array( 'stripe_test_key', 'stripe_live_key' ) as $name ) {
			if ( isset( $input[ $name ] ) ) {
				$clean[ $name ] = sanitize_text_field( (string) $input[ $name ] );
			}
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
