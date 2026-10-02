<?php
/**
 * Profile: one view, four states.
 *
 * Standard, forced completion, saved and refused. One page, not four.
 *
 * Things here that are easy to get wrong:
 *
 * - **The email field is read-only**, rendered disabled with a helper line beneath saying so, while every other field is editable.
 * - **Forced completion shows only the missing fields**, inside a bordered card on wide and the plain column on narrow, rather than the full form with a notice on top.
 * - **The forced-completion notice is not dismissible**, expressed by it having no dismiss button at all rather than by a flag.
 * - **A password is never written back into the page**, whatever the values say.
 *
 * Each group of `Profile_Writer::groups()` is a plain div with no styles of its own, headed only when it has a label.
 *
 * Actions are Save beside a Cancel text link, left-aligned and not full width on wide, and on narrow Save goes full width and Cancel becomes a centred link.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Support\Block;
use PinkCrab\Gated_Access\Support\Labels;
use PinkCrab\Gated_Access\Account\Profile_Writer;

defined( 'ABSPATH' ) || exit;

$gatedmedia_user = wp_get_current_user();

if ( 0 === $gatedmedia_user->ID ) {
	return;
}

$gatedmedia_groups  = Profile_Writer::groups();
$gatedmedia_values  = Profile_Writer::form_values( $gatedmedia_user->ID );
$gatedmedia_missing = Profile_Writer::missing_for( $gatedmedia_user->ID );

// ?profile=saved and ?profile=error come back from the writer's redirect, and ?profile=complete is how the forced-completion state is reached.
$gatedmedia_state = isset( $_GET['profile'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display state only, changes nothing.
	? sanitize_key( wp_unslash( $_GET['profile'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';

$gatedmedia_is_forced = 'complete' === $gatedmedia_state && array() !== $gatedmedia_missing;
$gatedmedia_is_saved  = 'saved' === $gatedmedia_state;
$gatedmedia_is_error  = 'error' === $gatedmedia_state;

// The refusal and the field it is about.
$gatedmedia_error_code  = $gatedmedia_is_error ? sanitize_key( (string) wp_unslash( $_GET[ Profile_Writer::ARG_ERROR ] ?? '' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$gatedmedia_error_field = $gatedmedia_is_error ? sanitize_key( (string) wp_unslash( $_GET[ Profile_Writer::ARG_FIELD ] ?? '' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// -----------------------------------------------------------------------------
// The notice slot. One of four, or none.
// -----------------------------------------------------------------------------
$gatedmedia_notice = '';

if ( $gatedmedia_is_saved ) {
	$gatedmedia_notice = Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'success',
			'text' => Labels::text( 'account.profile.saved' ),
		)
	);
} elseif ( $gatedmedia_is_error ) {
	$gatedmedia_notice = Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'error',
			'text' => Labels::text( 'account.profile.not_saved' ),
		)
	);
} elseif ( $gatedmedia_is_forced ) {
	$gatedmedia_notice = Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'info',
			'text' => Labels::text( 'account.profile.required' ),
			// Deliberately not dismissible, which is what makes it forced.
		)
	);
} elseif ( array() !== $gatedmedia_missing ) {
	$gatedmedia_notice = Block::render(
		'gated-media-access/notice',
		array(
			'kind'        => 'info',
			'text'        => Labels::text( 'account.profile.incomplete' ),
			'dismissible' => true,
		)
	);
}

// -----------------------------------------------------------------------------
// The groups, in order. Forced completion keeps only the missing fields and the read-only ones.
// -----------------------------------------------------------------------------
$gatedmedia_body = '';

foreach ( $gatedmedia_groups as $gatedmedia_group_key => $gatedmedia_group ) {
	$gatedmedia_inputs = '';

	foreach ( $gatedmedia_group['fields'] as $gatedmedia_key => $gatedmedia_field ) {
		if ( $gatedmedia_is_forced && ! $gatedmedia_field['disabled'] && ! in_array( $gatedmedia_key, $gatedmedia_missing, true ) ) {
			continue;
		}

		$gatedmedia_inputs .= Block::render(
			'gated-media-access/field',
			array(
				'name'         => $gatedmedia_key,
				'label'        => $gatedmedia_field['label'],
				'type'         => $gatedmedia_field['type'],
				'value'        => 'password' === $gatedmedia_field['type'] ? '' : ( $gatedmedia_values[ $gatedmedia_key ] ?? '' ),
				'autocomplete' => $gatedmedia_field['autocomplete'],
				'required'     => $gatedmedia_field['required'],
				'disabled'     => $gatedmedia_field['disabled'],
				'message'      => $gatedmedia_field['message'],
				'error'        => $gatedmedia_key === $gatedmedia_error_field ? Profile_Writer::message_for( $gatedmedia_error_code ) : '',
			)
		);
	}

	if ( '' === $gatedmedia_inputs ) {
		continue;
	}

	$gatedmedia_heading = '' === $gatedmedia_group['label']
		? ''
		: Block::render( 'gated-media-access/section-heading', array( 'text' => $gatedmedia_group['label'] ) );

	$gatedmedia_body .= sprintf(
		'<div class="%s">%s%s</div>',
		esc_attr( 'gatedmedia-profile-group gatedmedia-profile-group--' . sanitize_html_class( $gatedmedia_group_key ) ),
		$gatedmedia_heading,
		$gatedmedia_inputs
	);
}

$gatedmedia_actions = Block::render(
	'gated-media-access/button',
	array(
		'label' => Labels::text( 'account.profile.save' ),
		'type'  => 'submit',
	)
) . Block::render(
	'gated-media-access/button',
	array(
		'label'   => Labels::text( 'account.profile.cancel' ),
		'href'    => remove_query_arg( array( 'profile', Profile_Writer::ARG_ERROR, Profile_Writer::ARG_FIELD ) ),
		'variant' => 'link',
	)
);
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia gatedmedia-view gatedmedia-view--profile' ) ) ); ?>>
	<?php echo $gatedmedia_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the notice block. ?>

	<form
		class="<?php echo $gatedmedia_is_forced ? 'gatedmedia-card' : ''; ?>"
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	>
		<input type="hidden" name="action" value="<?php echo esc_attr( Profile_Writer::ACTION ); ?>">
		<?php wp_nonce_field( Profile_Writer::ACTION ); ?>

		<?php echo $gatedmedia_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Field and heading blocks escape their own output; the wrapper class is escaped above. ?>

		<div class="gatedmedia-form-actions">
			<?php echo $gatedmedia_actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the button block. ?>
		</div>
	</form>
</div>
