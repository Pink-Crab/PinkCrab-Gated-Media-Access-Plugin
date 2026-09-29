<?php
/**
 * Every word the front end says.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

/**
 * One keyed dictionary for every front-end label, heading, button, notice and message.
 *
 * Written inline in the render files, a site could reword one line only by re-rendering the whole block through `render_block_gated-media-access/{name}` or by translating the text domain, which changes it everywhere at once and needs a .mo file. Through here a single key can be replaced and the rest left exactly as it was.
 *
 * Keys are `area.thing.variant` and do not change, because a site's override is written against the key. Defaults stay translated, so a site that does nothing gets the same words in the same language it always did.
 *
 * Static because the templates reach it the same way they reach `Block::render()` and `Account_Url::section()`: they are plain PHP files with nothing injected into them.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 */
class Labels {

	/**
	 * The dictionary once the filter has had it, null until first asked.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $labels = null;

	/**
	 * One label, by key.
	 *
	 * An unknown key answers with itself: a blank button says nothing about where it came from, and the key is at least searchable.
	 *
	 * @param string $key The label's key.
	 */
	public static function text( string $key ): string {
		$labels = self::all();

		return $labels[ $key ] ?? $key;
	}

	/**
	 * The whole dictionary, filtered once per request.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		if ( null !== self::$labels ) {
			return self::$labels;
		}

		$defaults = self::defaults();

		/**
		 * Filters every front-end label, keyed.
		 *
		 * Replace a value to reword one line. Keys are stable; a label carrying `%s` or `%d` is a pattern the caller fills, so dropping the token loses whatever it stood for.
		 *
		 * @param array<string, string> $defaults The translated defaults.
		 */
		$filtered = apply_filters( 'gatedmedia_labels', $defaults );

		if ( ! is_array( $filtered ) ) {
			self::$labels = $defaults;

			return self::$labels;
		}

		// A value that is not a string would be drawn as one, so the default stands instead.
		foreach ( $filtered as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$defaults[ $key ] = $value;
			}
		}

		self::$labels = $defaults;

		return self::$labels;
	}

	/**
	 * Drops the memoised dictionary, so a filter added later is read.
	 */
	public static function forget(): void {
		self::$labels = null;
	}

	/**
	 * The translated defaults, one per key.
	 *
	 * @return array<string, string>
	 */
	private static function defaults(): array {
		return array_merge(
			self::product_labels(),
			self::auth_labels(),
			self::account_labels(),
			self::files_labels(),
			self::orders_labels(),
			self::status_labels()
		);
	}

	/**
	 * The product page.
	 *
	 * @return array<string, string>
	 */
	private static function product_labels(): array {
		return array(
			'product.contents.heading'    => __( 'What you get', 'gated-media-access' ),
			'product.term.lifetime'       => __( 'Lifetime access', 'gated-media-access' ),
			'product.held.notice'         => __( 'You already have this.', 'gated-media-access' ),
			'product.held.button'         => __( 'View your access', 'gated-media-access' ),
			'product.ineligible.notice'   => __( 'This is only available to invited email addresses. If you were sent an invitation, sign in with that address.', 'gated-media-access' ),
			'product.lapsed.notice'       => __( 'Your access to this has ended.', 'gated-media-access' ),
			'product.button.free'         => __( 'Join', 'gated-media-access' ),
			'product.button.paid'         => __( 'Get access', 'gated-media-access' ),
			/* translators: %s: the price. */
			'product.button.priced'       => __( 'Get access, %s', 'gated-media-access' ),
			'product.button.signup'       => __( 'Create an account to continue', 'gated-media-access' ),
			'product.button.signin'       => __( 'Sign in to continue', 'gated-media-access' ),
			'product.button.have_account' => __( 'Already have an account? Sign in', 'gated-media-access' ),
			'product.error.not_for_sale'  => __( 'That product is not for sale.', 'gated-media-access' ),
			'product.error.not_eligible'  => __( 'This product is not available to you.', 'gated-media-access' ),
			'product.error.bad_coupon'    => __( 'That coupon cannot be used.', 'gated-media-access' ),
			'product.error.no_row'        => __( 'The payment could not be started. Nothing has been charged.', 'gated-media-access' ),
			'product.error.no_session'    => __( 'We could not start that purchase. Nothing has been charged.', 'gated-media-access' ),
			'product.error.needs_account' => __( 'Sign in to continue.', 'gated-media-access' ),
			'coupon.field.label'          => __( 'Coupon code', 'gated-media-access' ),
			'coupon.button.apply'         => __( 'Apply', 'gated-media-access' ),
			'coupon.button.remove'        => __( 'Remove', 'gated-media-access' ),
			/* translators: 1: the coupon code. 2: the amount it takes off. */
			'coupon.applied.with_amount'  => __( '%1$s applied, %2$s off', 'gated-media-access' ),
			/* translators: %s: the coupon code. */
			'coupon.applied.plain'        => __( '%s applied', 'gated-media-access' ),
		);
	}

	/**
	 * Signing in, signing up and resetting.
	 *
	 * @return array<string, string>
	 */
	private static function auth_labels(): array {
		return array(
			'auth.title.signin'         => __( 'Sign in', 'gated-media-access' ),
			'auth.title.signup'         => __( 'Create your account', 'gated-media-access' ),
			'auth.title.reset'          => __( 'Reset your password', 'gated-media-access' ),
			'auth.title.sent'           => __( 'Check your email', 'gated-media-access' ),
			'auth.welcome'              => __( 'Welcome back.', 'gated-media-access' ),
			'auth.note.signup'          => __( "You'll use this to reach everything you've been given access to.", 'gated-media-access' ),
			'auth.note.reset'           => __( "We'll email you a link.", 'gated-media-access' ),
			'auth.error.credentials'    => __( "That email and password don't match.", 'gated-media-access' ),
			'auth.reset.sent'           => __( 'If that email has an account, a reset link is on its way.', 'gated-media-access' ),
			'auth.field.email'          => __( 'Email', 'gated-media-access' ),
			'auth.field.password'       => __( 'Password', 'gated-media-access' ),
			/* translators: %d: the minimum number of characters. */
			'auth.field.password_hint'  => __( 'At least %d characters.', 'gated-media-access' ),
			'auth.button.signin'        => __( 'Sign in', 'gated-media-access' ),
			'auth.button.signup'        => __( 'Create account', 'gated-media-access' ),
			'auth.button.reset'         => __( 'Send reset link', 'gated-media-access' ),
			'auth.link.forgotten'       => __( 'Forgotten your password?', 'gated-media-access' ),
			'auth.link.signup'          => __( 'Create an account', 'gated-media-access' ),
			'auth.link.signin'          => __( 'Already have an account? Sign in', 'gated-media-access' ),
			'auth.link.back'            => __( 'Back to sign in', 'gated-media-access' ),
			'auth.error.bad_email'      => __( 'That does not look like an email address.', 'gated-media-access' ),
			'auth.error.email_taken'    => __( 'That email already has an account. Sign in instead.', 'gated-media-access' ),
			/* translators: %d: the minimum number of characters. */
			'auth.error.password_short' => __( 'Passwords need at least %d characters.', 'gated-media-access' ),
			'auth.error.closed'         => __( 'Accounts are not created here. Ask the site owner for one.', 'gated-media-access' ),
			'auth.error.not_created'    => __( 'That account could not be created. Please try again.', 'gated-media-access' ),
			'auth.error.expired_link'   => __( 'That link has expired. Ask for another.', 'gated-media-access' ),
		);
	}

	/**
	 * The account area's shell, its sections and its profile form.
	 *
	 * @return array<string, string>
	 */
	private static function account_labels(): array {
		return array(
			'account.brand.title'        => __( 'Account', 'gated-media-access' ),
			'account.nav.label'          => __( 'Account sections', 'gated-media-access' ),
			'account.section.my_access'  => __( 'My Access', 'gated-media-access' ),
			'account.section.files'      => __( 'Files', 'gated-media-access' ),
			'account.section.orders'     => __( 'Orders', 'gated-media-access' ),
			'account.section.profile'    => __( 'Profile', 'gated-media-access' ),
			'account.files.note'         => __( 'Everything you can download.', 'gated-media-access' ),
			'account.orders.note'        => __( 'What you have taken, and when.', 'gated-media-access' ),
			'account.profile.note'       => __( 'Your details.', 'gated-media-access' ),
			'account.profile.saved'      => __( 'Your details have been saved.', 'gated-media-access' ),
			'account.profile.required'   => __( 'Please complete your details before continuing.', 'gated-media-access' ),
			'account.profile.incomplete' => __( 'Your profile is incomplete.', 'gated-media-access' ),
			'account.profile.email'      => __( 'Email', 'gated-media-access' ),
			'account.profile.email_note' => __( 'Your email cannot be changed here.', 'gated-media-access' ),
			'account.profile.save'       => __( 'Save', 'gated-media-access' ),
			'account.profile.cancel'     => __( 'Cancel', 'gated-media-access' ),
			/* translators: %s: the block's name. */
			'account.missing_block'      => __( 'The block "%s" is not registered, so this section cannot render.', 'gated-media-access' ),
			'access.group.groups'        => __( 'Groups', 'gated-media-access' ),
			'access.group.posts'         => __( 'Posts', 'gated-media-access' ),
			'access.group.files'         => __( 'Files', 'gated-media-access' ),
			'access.group.missing'       => __( 'Group not found', 'gated-media-access' ),
			'access.group.missing_note'  => __( 'We could not find that group on your account.', 'gated-media-access' ),
			'access.group.empty'         => __( 'This group is empty', 'gated-media-access' ),
			'access.group.empty_note'    => __( 'Nothing has been put in it yet. Anything added will appear here.', 'gated-media-access' ),
			'access.group.back'          => __( 'Back to my access', 'gated-media-access' ),
			'access.empty'               => __( 'Nothing here yet', 'gated-media-access' ),
			'access.empty_note'          => __( 'Anything you are given access to will appear here, with the date it runs out.', 'gated-media-access' ),
		);
	}

	/**
	 * The files list and its filters.
	 *
	 * @return array<string, string>
	 */
	private static function files_labels(): array {
		return array(
			'files.filter.all'          => __( 'All', 'gated-media-access' ),
			'files.filter.pdf'          => __( 'PDF', 'gated-media-access' ),
			'files.filter.video'        => __( 'Video', 'gated-media-access' ),
			'files.filter.zip'          => __( 'ZIP', 'gated-media-access' ),
			'files.filter.audio'        => __( 'Audio', 'gated-media-access' ),
			'files.search.placeholder'  => __( 'Search files', 'gated-media-access' ),
			'files.filter.label'        => __( 'Filter by type', 'gated-media-access' ),
			'files.button.download'     => __( 'Download', 'gated-media-access' ),
			'files.button.downloading'  => __( 'Downloading…', 'gated-media-access' ),
			'files.state.available'     => __( 'Available', 'gated-media-access' ),
			'files.state.downloading'   => __( 'Downloading', 'gated-media-access' ),
			'files.state.past'          => __( 'Past access', 'gated-media-access' ),
			'files.state.gone'          => __( 'No longer available', 'gated-media-access' ),
			'files.empty'               => __( 'No files yet', 'gated-media-access' ),
			'files.empty_note'          => __( 'Files you are given access to will appear here, ready to download.', 'gated-media-access' ),
			'filter.search.placeholder' => __( 'Search', 'gated-media-access' ),
			'filter.type.label'         => __( 'Filter by type', 'gated-media-access' ),
		);
	}

	/**
	 * Orders and the payment confirmation.
	 *
	 * @return array<string, string>
	 */
	private static function orders_labels(): array {
		return array(
			'orders.missing'          => __( 'Order not found', 'gated-media-access' ),
			'orders.missing_note'     => __( 'We could not find that order on your account.', 'gated-media-access' ),
			'orders.back'             => __( 'Back to orders', 'gated-media-access' ),
			'orders.to_access'        => __( 'Go to my access', 'gated-media-access' ),
			'orders.snapshot.heading' => __( 'What this included at the time', 'gated-media-access' ),
			/* translators: %s: the date the order was placed. */
			'orders.placed'           => __( 'Frozen as it was on %s.', 'gated-media-access' ),
			'orders.granted.heading'  => __( 'Access this created', 'gated-media-access' ),
			'orders.empty'            => __( 'No orders yet', 'gated-media-access' ),
			'orders.empty_note'       => __( 'Anything you pay for will be listed here, with what it included.', 'gated-media-access' ),
			'payment.confirming'      => __( 'Confirming your payment', 'gated-media-access' ),
			'payment.confirming_note' => __( 'This usually takes a few seconds. Reload the page to check again.', 'gated-media-access' ),
			'payment.ready'           => __( "You're in", 'gated-media-access' ),
			'payment.ready_note'      => __( 'Your access is ready.', 'gated-media-access' ),
			'payment.refunded'        => __( 'This order was refunded', 'gated-media-access' ),
			'payment.refunded_note'   => __( 'The access it created has been withdrawn.', 'gated-media-access' ),
			'payment.failed'          => __( 'Payment not completed', 'gated-media-access' ),
			'payment.failed_note'     => __( "We couldn't take your payment, and you have not been charged.", 'gated-media-access' ),
			'payment.slow_note'       => __( 'This is taking longer than usual. Your payment is safe and your access will appear here shortly, so you can close this page.', 'gated-media-access' ),
			/* translators: %s: the payment's reference. */
			'payment.reference'       => __( 'Reference: %s', 'gated-media-access' ),
		);
	}

	/**
	 * The status pill, one per state it draws.
	 *
	 * @return array<string, string>
	 */
	private static function status_labels(): array {
		return array(
			'status.complete' => __( 'Complete', 'gated-media-access' ),
			'status.refunded' => __( 'Refunded', 'gated-media-access' ),
			'status.active'   => __( 'Active', 'gated-media-access' ),
			'status.expired'  => __( 'Expired', 'gated-media-access' ),
			'status.revoked'  => __( 'Revoked', 'gated-media-access' ),
			'status.pending'  => __( 'Pending', 'gated-media-access' ),
			'status.failed'   => __( 'Failed', 'gated-media-access' ),
			'row.gone'        => __( 'No longer available', 'gated-media-access' ),
		);
	}
}
