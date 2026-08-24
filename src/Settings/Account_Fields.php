<?php
/**
 * The Accounts section of the settings screen.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

use PinkCrab\Gated_Access\Support\Account_Url;

/**
 * The three settings specification.md §8 tabled and nothing ever read:
 * `account_creation`, `account_route` and `profile_prompt`.
 *
 * Its own class rather than three more methods on `Settings_Page`, which is at
 * phpmd's class-complexity ceiling — the same call round 8 made splitting
 * `Coupon_Pricing` out of `Checkout` rather than suppressing the rule.
 * `Notification_Fields` is the pattern: render the section, sanitize its own
 * keys, and let the screen own only the frame.
 *
 * **Core's `users_can_register` is not here, deliberately.** `account_creation`
 * governs this plugin's own sign-up; wp-login.php keeps whatever policy the
 * site already gave it, and the two run alongside each other.
 */
class Account_Fields {

	/**
	 * The settings reader, for the current values.
	 *
	 * @param Settings $settings The one settings reader.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * The section: how someone gets an account, whether the plugin's own
	 * account pages are on, and whether a thin profile is prompted.
	 */
	public function render(): void {
		$option   = Settings::OPTION;
		$creation = $this->settings->account_creation();
		?>
		<div class="gatedmedia-admin-section-head">
			<h2><?php esc_html_e( 'Accounts', 'gated-media-access' ); ?></h2>
			<span class="gatedmedia-admin-caps"><?php esc_html_e( 'How people get one, and where it lives', 'gated-media-access' ); ?></span>
		</div>

		<div class="gatedmedia-admin-field">
			<label class="gatedmedia-admin-caps" for="gatedmedia_account_creation"><?php esc_html_e( 'How a user gets an account', 'gated-media-access' ); ?></label>
			<select name="<?php echo esc_attr( $option ); ?>[account_creation]" id="gatedmedia_account_creation">
				<option value="<?php echo esc_attr( Settings::ACCOUNT_CREATION_REGISTRATION ); ?>" <?php selected( Settings::ACCOUNT_CREATION_REGISTRATION, $creation ); ?>><?php esc_html_e( 'They sign themselves up', 'gated-media-access' ); ?></option>
				<option value="<?php echo esc_attr( Settings::ACCOUNT_CREATION_ADMIN ); ?>" <?php selected( Settings::ACCOUNT_CREATION_ADMIN, $creation ); ?>><?php esc_html_e( 'An administrator creates them', 'gated-media-access' ); ?></option>
				<option value="<?php echo esc_attr( Settings::ACCOUNT_CREATION_PURCHASE ); ?>" <?php selected( Settings::ACCOUNT_CREATION_PURCHASE, $creation ); ?>><?php esc_html_e( 'One is made when they buy', 'gated-media-access' ); ?></option>
			</select>
			<p class="gatedmedia-admin-help"><?php esc_html_e( 'Only the first draws a sign-up form. This governs this plugin alone — WordPress’ own registration setting is left exactly as you set it.', 'gated-media-access' ); ?></p>
		</div>

		<div class="gatedmedia-admin-field">
			<label class="gatedmedia-admin-caps" for="gatedmedia_account_route"><?php esc_html_e( 'Account pages', 'gated-media-access' ); ?></label>
			<select name="<?php echo esc_attr( $option ); ?>[account_route]" id="gatedmedia_account_route">
				<option value="1" <?php selected( true, $this->settings->account_route() ); ?>><?php esc_html_e( 'Use the plugin’s own pages', 'gated-media-access' ); ?></option>
				<option value="0" <?php selected( false, $this->settings->account_route() ); ?>><?php esc_html_e( 'I will place the blocks on my own pages', 'gated-media-access' ); ?></option>
			</select>
			<p class="gatedmedia-admin-help">
				<?php
				printf(
					/* translators: %s: the account area's URL. */
					esc_html__( 'Switched on, the account area answers at %s. Switched off it does not, and the same blocks can be placed on pages of your own.', 'gated-media-access' ),
					'<code>' . esc_html( Account_Url::section( '' ) ) . '</code>'
				);
				?>
			</p>
		</div>

		<div class="gatedmedia-admin-field">
			<label class="gatedmedia-admin-caps" for="gatedmedia_profile_prompt"><?php esc_html_e( 'Ask for missing details', 'gated-media-access' ); ?></label>
			<select name="<?php echo esc_attr( $option ); ?>[profile_prompt]" id="gatedmedia_profile_prompt">
				<option value="0" <?php selected( false, $this->settings->profile_prompt() ); ?>><?php esc_html_e( 'No', 'gated-media-access' ); ?></option>
				<option value="1" <?php selected( true, $this->settings->profile_prompt() ); ?>><?php esc_html_e( 'On their first sign-in', 'gated-media-access' ); ?></option>
			</select>
			<p class="gatedmedia-admin-help"><?php esc_html_e( 'Someone whose profile is missing a required field is asked to complete it. Never when they were part-way through buying something.', 'gated-media-access' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The three keys, cleaned.
	 *
	 * `Settings_Page::sanitize()` starts from what is already stored and copies
	 * forward only the keys it knows, so a key with no clause anywhere is
	 * dropped on every save — which is precisely what happened to these three
	 * before round 9 read them.
	 *
	 * The route and the prompt are read with `isset()` rather than defaulted,
	 * so a form that does not carry them leaves them as they were instead of
	 * switching them off.
	 *
	 * @param array<string, mixed>  $input What options.php handed over.
	 * @param array<string, string> $clean The cleaned settings so far.
	 * @return array<string, string>
	 */
	public function sanitize( array $input, array $clean ): array {
		if ( isset( $input['account_creation'] ) ) {
			$route = (string) $input['account_creation'];
			$known = array( Settings::ACCOUNT_CREATION_REGISTRATION, Settings::ACCOUNT_CREATION_ADMIN, Settings::ACCOUNT_CREATION_PURCHASE );

			$clean['account_creation'] = in_array( $route, $known, true ) ? $route : Settings::ACCOUNT_CREATION_REGISTRATION;
		}

		// $clean still carries what was stored, so this is the previous value.
		$previous = (string) ( $clean['account_route'] ?? '1' );

		foreach ( array( 'account_route', 'profile_prompt' ) as $name ) {
			if ( isset( $input[ $name ] ) ) {
				$clean[ $name ] = '1' === (string) $input[ $name ] ? '1' : '0';
			}
		}

		// The account area's rewrite rules only exist while the route is on, so
		// switching it either way changes which URLs the site answers.
		if ( (string) ( $clean['account_route'] ?? '1' ) !== $previous ) {
			add_action( 'shutdown', 'flush_rewrite_rules' );
		}

		return $clean;
	}
}
