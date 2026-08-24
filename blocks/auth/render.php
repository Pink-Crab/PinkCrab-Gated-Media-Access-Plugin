<?php
/**
 * §7.7 Sign in / sign up / reset — one view, four states.
 *
 * **No shell.** A centred column at the form cap in a bordered card, on the
 * page background — `.gatedmedia-centred` and `.gatedmedia-card`, which §7.5's
 * forced state already uses. The theme's own header and footer stay, as with
 * every other view.
 *
 * **The h1 is the theme's.** `Auth_Route` titles the virtual page from the
 * state, so the theme has already printed the heading; this draws the sub-line
 * beneath it and nothing more. `Account_Renderer::page_header()` handles the
 * account area the same way, per §2 conflict 4.
 *
 * **Reset link sent replaces the fields and the button entirely** with a
 * bordered confirmation box, and never confirms whether the address exists.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Auth\Auth_Action;
use PinkCrab\Gated_Access\Auth\Auth_Route;
use PinkCrab\Gated_Access\Support\Auth_Url;
use PinkCrab\Gated_Access\Support\Block;

defined( 'ABSPATH' ) || exit;

/**
 * Supplied by Auth_State; the defaults are what renders with nothing
 * answering, which is a bare sign-in form.
 *
 * @var array<string, mixed> $gatedmedia_data
 */
$gatedmedia_data = apply_filters(
	'gatedmedia_auth_data',
	array(
		'state'          => Auth_Url::STATE_SIGNIN,
		'error'          => '',
		'message'        => '',
		'invalid'        => array(),
		'email'          => '',
		'redirect'       => '',
		'signup_offered' => false,
		'minimum'        => 12,
		'action_url'     => admin_url( 'admin-post.php' ),
		'nonce'          => '',
	)
);

$gatedmedia_state    = (string) ( $gatedmedia_data['state'] ?? Auth_Url::STATE_SIGNIN );
$gatedmedia_redirect = (string) ( $gatedmedia_data['redirect'] ?? '' );
$gatedmedia_email    = (string) ( $gatedmedia_data['email'] ?? '' );
$gatedmedia_invalid  = is_array( $gatedmedia_data['invalid'] ?? null ) ? $gatedmedia_data['invalid'] : array();
$gatedmedia_signup   = true === ( $gatedmedia_data['signup_offered'] ?? false );
$gatedmedia_sent     = Auth_Url::STATE_SENT === $gatedmedia_state;

// -----------------------------------------------------------------------------
// Block 1's second half — the sub-line. Check your email has none.
// -----------------------------------------------------------------------------
$gatedmedia_sublines = array(
	Auth_Url::STATE_SIGNUP => __( "You'll use this to reach everything you've been given access to.", 'gated-media-access' ),
	Auth_Url::STATE_SIGNIN => __( 'Welcome back.', 'gated-media-access' ),
	Auth_Url::STATE_RESET  => __( "We'll email you a link.", 'gated-media-access' ),
);

$gatedmedia_subline = $gatedmedia_sublines[ $gatedmedia_state ] ?? '';

// The heading is drawn here rather than by the theme — the one view where that
// is true, and `Auth_Route::title_for()` says why. The virtual page carries no
// title, so this is still the page's only h1.
$gatedmedia_body = sprintf(
	'<header class="gatedmedia-page-intro"><h1 class="gatedmedia-heading gatedmedia-heading--page">%s</h1>%s</header>',
	esc_html( Auth_Route::title_for( $gatedmedia_state ) ),
	'' === $gatedmedia_subline
		? ''
		: sprintf( '<p class="gatedmedia-text gatedmedia-text--meta">%s</p>', esc_html( $gatedmedia_subline ) )
);

// -----------------------------------------------------------------------------
// Block 2 — the notice slot. A failure only, and never on the sent state.
// -----------------------------------------------------------------------------
if ( ! $gatedmedia_sent && '' !== (string) ( $gatedmedia_data['message'] ?? '' ) ) {
	$gatedmedia_body .= Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'error',
			'text' => (string) $gatedmedia_data['message'],
		)
	);
}

if ( $gatedmedia_sent ) {
	// The confirmation box, in place of the fields and the button. Worded so it
	// is true whether or not an account matched — §7.7's one hard copy rule
	// here, and the reason this state exists rather than a success notice.
	$gatedmedia_body .= Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'info',
			'icon' => 'i-clock',
			'text' => __( 'If that email has an account, a reset link is on its way.', 'gated-media-access' ),
		)
	);
} else {
	// ---------------------------------------------------------------------
	// Blocks 3 and 4 — the fields and the full-width button, in one form.
	// ---------------------------------------------------------------------
	$gatedmedia_fields = sprintf(
		'<input type="hidden" name="action" value="%s" /><input type="hidden" name="_wpnonce" value="%s" /><input type="hidden" name="%s" value="%s" />',
		esc_attr( Auth_Action::ACTION ),
		esc_attr( (string) ( $gatedmedia_data['nonce'] ?? '' ) ),
		esc_attr( Auth_Action::INTENT_FIELD ),
		esc_attr( $gatedmedia_state )
	);

	// Carried so signing in lands them back on the product they were buying.
	if ( '' !== $gatedmedia_redirect ) {
		$gatedmedia_fields .= sprintf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( Auth_Url::ARG_REDIRECT ),
			esc_attr( $gatedmedia_redirect )
		);
	}

	$gatedmedia_fields .= Block::render(
		'gated-media-access/field',
		array(
			'name'         => 'email',
			'label'        => __( 'Email', 'gated-media-access' ),
			'type'         => 'email',
			'value'        => $gatedmedia_email,
			'placeholder'  => 'jane@example.com',
			'autocomplete' => 'email',
			'required'     => true,
			'invalid'      => in_array( 'email', $gatedmedia_invalid, true ),
		)
	);

	// Reset asks for the address and nothing else.
	if ( Auth_Url::STATE_RESET !== $gatedmedia_state ) {
		$gatedmedia_signing_up = Auth_Url::STATE_SIGNUP === $gatedmedia_state;

		$gatedmedia_fields .= Block::render(
			'gated-media-access/field',
			array(
				'name'         => 'password',
				'label'        => __( 'Password', 'gated-media-access' ),
				'type'         => 'password',
				'autocomplete' => $gatedmedia_signing_up ? 'new-password' : 'current-password',
				'required'     => true,
				'invalid'      => in_array( 'password', $gatedmedia_invalid, true ),
				'message'      => $gatedmedia_signing_up
					? sprintf(
						/* translators: %d: the minimum number of characters. */
						__( 'At least %d characters.', 'gated-media-access' ),
						(int) ( $gatedmedia_data['minimum'] ?? 12 )
					)
					: '',
			)
		);
	}

	$gatedmedia_labels = array(
		Auth_Url::STATE_SIGNUP => __( 'Create account', 'gated-media-access' ),
		Auth_Url::STATE_RESET  => __( 'Send reset link', 'gated-media-access' ),
	);

	$gatedmedia_fields .= Block::render(
		'gated-media-access/button',
		array(
			'label' => $gatedmedia_labels[ $gatedmedia_state ] ?? __( 'Sign in', 'gated-media-access' ),
			'type'  => 'submit',
			'full'  => true,
		)
	);

	$gatedmedia_body .= sprintf(
		'<form class="gatedmedia-auth" method="post" action="%s">%s</form>',
		esc_url( (string) ( $gatedmedia_data['action_url'] ?? '' ) ),
		$gatedmedia_fields
	);
}

// -----------------------------------------------------------------------------
// Block 5 — the alternate routes, one per line beneath the button.
//
// Sign up is only ever offered when this site creates accounts that way. A
// control that says something it cannot do is the fault this round exists to
// fix, so an unavailable route is not linked to at all.
// -----------------------------------------------------------------------------
$gatedmedia_links = array();

if ( Auth_Url::STATE_SIGNIN === $gatedmedia_state ) {
	$gatedmedia_links[] = array( Auth_Url::reset( $gatedmedia_redirect ), __( 'Forgotten your password?', 'gated-media-access' ) );

	if ( $gatedmedia_signup ) {
		$gatedmedia_links[] = array( Auth_Url::signup( $gatedmedia_redirect ), __( 'Create an account', 'gated-media-access' ) );
	}
} else {
	$gatedmedia_links[] = array(
		Auth_Url::signin( $gatedmedia_redirect ),
		Auth_Url::STATE_SIGNUP === $gatedmedia_state
			? __( 'Already have an account? Sign in', 'gated-media-access' )
			: __( 'Back to sign in', 'gated-media-access' ),
	);
}

$gatedmedia_routes = '';

foreach ( $gatedmedia_links as $gatedmedia_link ) {
	$gatedmedia_routes .= sprintf(
		'<p class="gatedmedia-auth__route">%s</p>',
		Block::render(
			'gated-media-access/button',
			array(
				'label'   => $gatedmedia_link[1],
				'href'    => $gatedmedia_link[0],
				'variant' => 'link',
			)
		)
	);
}

$gatedmedia_body .= $gatedmedia_routes;
?>
<?php // The `gatedmedia` root class is what scopes border-box and the type roles (_base.scss); without it the fields overflow the card's padding. ?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia gatedmedia-centred' ) ) ); ?>>
	<div class="gatedmedia-card gatedmedia-auth-card">
		<?php echo $gatedmedia_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed from component blocks, each escaping its own output. ?>
	</div>
</div>
