<?php
/**
 * Sign in, sign up and reset: one view, four states.
 *
 * No shell. A centred column in a bordered card on the page background, using `.gatedmedia-centred` and `.gatedmedia-card`. The theme's own header and footer stay.
 *
 * The h1 is this block's, not the theme's: the virtual page carries no title, so this draws the heading inside the card. `Auth_Route::title_for()` says why this view is the exception, and `Account_Renderer::page_header()` still leaves the account area's h1 to the theme.
 *
 * Reset link sent replaces the fields and the button with a confirmation box, and never confirms whether the address exists.
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
use PinkCrab\Gated_Access\Support\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * Supplied by `Auth_State`, and the defaults are what renders with nothing answering, which is a bare sign-in form.
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

// The sub-line. The sent state has none.
$gatedmedia_sublines = array(
	Auth_Url::STATE_SIGNUP => Labels::text( 'auth.note.signup' ),
	Auth_Url::STATE_SIGNIN => Labels::text( 'auth.welcome' ),
	Auth_Url::STATE_RESET  => Labels::text( 'auth.note.reset' ),
);

$gatedmedia_subline = $gatedmedia_sublines[ $gatedmedia_state ] ?? '';

// The heading is drawn here, not by the theme, and is still the page's only h1.
$gatedmedia_body = sprintf(
	'<header class="gatedmedia-page-intro"><h1 class="gatedmedia-heading gatedmedia-heading--page">%s</h1>%s</header>',
	esc_html( Auth_Route::title_for( $gatedmedia_state ) ),
	'' === $gatedmedia_subline
		? ''
		: sprintf( '<p class="gatedmedia-text gatedmedia-text--meta">%s</p>', esc_html( $gatedmedia_subline ) )
);

// The notice slot. A failure only, and never on the sent state.
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
	// Worded so it is true whether or not an account matched.
	$gatedmedia_body .= Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'info',
			'icon' => 'i-clock',
			'text' => Labels::text( 'auth.reset.sent' ),
		)
	);
} else {
	// The fields and the full-width button, in one form.
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
			'label'        => Labels::text( 'auth.field.email' ),
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
				'label'        => Labels::text( 'auth.field.password' ),
				'type'         => 'password',
				'autocomplete' => $gatedmedia_signing_up ? 'new-password' : 'current-password',
				'required'     => true,
				'invalid'      => in_array( 'password', $gatedmedia_invalid, true ),
				'message'      => $gatedmedia_signing_up
					? sprintf( Labels::text( 'auth.field.password_hint' ), (int) ( $gatedmedia_data['minimum'] ?? 12 ) )
					: '',
			)
		);
	}

	$gatedmedia_buttons = array(
		Auth_Url::STATE_SIGNUP => Labels::text( 'auth.button.signup' ),
		Auth_Url::STATE_RESET  => Labels::text( 'auth.button.reset' ),
	);

	$gatedmedia_fields .= Block::render(
		'gated-media-access/button',
		array(
			'label' => $gatedmedia_buttons[ $gatedmedia_state ] ?? Labels::text( 'auth.button.signin' ),
			'type'  => 'submit',
			'full'  => true,
		)
	);

	/**
	 * Filters the fields inside the auth form.
	 *
	 * Where a site re-publishes core's `login_form` and `register_form`, so a captcha or two-factor plugin that only knows core still draws its field here.
	 *
	 * @param string $fields The form's fields, as HTML.
	 * @param string $state  Which state is being drawn: signin, signup or reset.
	 */
	$gatedmedia_fields = (string) apply_filters( 'gatedmedia_auth_fields', $gatedmedia_fields, $gatedmedia_state );

	$gatedmedia_body .= sprintf(
		'<form class="gatedmedia-auth" method="post" action="%s">%s</form>',
		esc_url( (string) ( $gatedmedia_data['action_url'] ?? '' ) ),
		$gatedmedia_fields
	);
}

// The alternate routes, one per line beneath the button. Sign up is offered only where this site creates accounts that way; an unavailable route is not linked at all.
$gatedmedia_links = array();

if ( Auth_Url::STATE_SIGNIN === $gatedmedia_state ) {
	$gatedmedia_links[] = array( Auth_Url::reset( $gatedmedia_redirect ), Labels::text( 'auth.link.forgotten' ) );

	if ( $gatedmedia_signup ) {
		$gatedmedia_links[] = array( Auth_Url::signup( $gatedmedia_redirect ), Labels::text( 'auth.link.signup' ) );
	}
} else {
	$gatedmedia_links[] = array(
		Auth_Url::signin( $gatedmedia_redirect ),
		Auth_Url::STATE_SIGNUP === $gatedmedia_state
			? Labels::text( 'auth.link.signin' )
			: Labels::text( 'auth.link.back' ),
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
