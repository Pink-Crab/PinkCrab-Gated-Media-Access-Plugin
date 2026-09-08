<?php
/**
 * Profile: one view, three states.
 *
 * Standard, forced completion, and saved. One page, not three.
 *
 * Three things here are easy to get wrong:
 *
 * - **The email field is read-only**, rendered disabled with a helper line beneath saying so, while every other field is editable.
 * - **Forced completion shows only the missing fields**, inside a bordered card on wide and the plain column on narrow, rather than the full form with a notice on top.
 * - **The forced-completion notice is not dismissible**, expressed by it having no dismiss button at all rather than by a flag.
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
use PinkCrab\Gated_Access\Account\Profile_Writer;

defined( 'ABSPATH' ) || exit;

$gatedmedia_user = wp_get_current_user();

if ( 0 === $gatedmedia_user->ID ) {
	return;
}

$gatedmedia_values  = Profile_Writer::values_for( $gatedmedia_user->ID );
$gatedmedia_fields  = Profile_Writer::fields();
$gatedmedia_missing = Profile_Writer::missing_for( $gatedmedia_user->ID );

// ?profile=saved comes back from the writer's redirect, and ?profile=complete is how the forced-completion state is reached.
$gatedmedia_state = isset( $_GET['profile'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display state only, changes nothing.
	? sanitize_key( wp_unslash( $_GET['profile'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';

$gatedmedia_is_forced = 'complete' === $gatedmedia_state && array() !== $gatedmedia_missing;
$gatedmedia_is_saved  = 'saved' === $gatedmedia_state;

// Forced completion narrows the form to what is actually missing.
$gatedmedia_shown = $gatedmedia_is_forced
	? array_intersect_key( $gatedmedia_fields, array_flip( $gatedmedia_missing ) )
	: $gatedmedia_fields;

// -----------------------------------------------------------------------------
// The notice slot. One of three, or none.
// -----------------------------------------------------------------------------
$gatedmedia_notice = '';

if ( $gatedmedia_is_saved ) {
	$gatedmedia_notice = Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'success',
			'text' => __( 'Your details have been saved.', 'gated-media-access' ),
		)
	);
} elseif ( $gatedmedia_is_forced ) {
	$gatedmedia_notice = Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'info',
			'text' => __( 'Please complete your details before continuing.', 'gated-media-access' ),
			// Deliberately not dismissible, which is what makes it forced.
		)
	);
} elseif ( array() !== $gatedmedia_missing ) {
	$gatedmedia_notice = Block::render(
		'gated-media-access/notice',
		array(
			'kind'        => 'info',
			'text'        => __( 'Your profile is incomplete.', 'gated-media-access' ),
			'dismissible' => true,
		)
	);
}

// -----------------------------------------------------------------------------
// The fields. Email first, read-only, then whatever this state shows.
// -----------------------------------------------------------------------------
$gatedmedia_inputs = Block::render(
	'gated-media-access/field',
	array(
		'name'     => 'email',
		'label'    => __( 'Email', 'gated-media-access' ),
		'type'     => 'email',
		'value'    => $gatedmedia_user->user_email,
		'disabled' => true,
		'message'  => __( 'Your email cannot be changed here.', 'gated-media-access' ),
	)
);

foreach ( $gatedmedia_shown as $gatedmedia_key => $gatedmedia_field ) {
	$gatedmedia_inputs .= Block::render(
		'gated-media-access/field',
		array(
			'name'         => $gatedmedia_key,
			'label'        => $gatedmedia_field['label'],
			'type'         => $gatedmedia_field['type'],
			'value'        => $gatedmedia_values[ $gatedmedia_key ] ?? '',
			'autocomplete' => $gatedmedia_field['autocomplete'],
			'required'     => $gatedmedia_field['required'],
		)
	);
}

$gatedmedia_actions = Block::render(
	'gated-media-access/button',
	array(
		'label' => __( 'Save', 'gated-media-access' ),
		'type'  => 'submit',
	)
) . Block::render(
	'gated-media-access/button',
	array(
		'label'   => __( 'Cancel', 'gated-media-access' ),
		'href'    => remove_query_arg( 'profile' ),
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

		<?php echo $gatedmedia_inputs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the field block. ?>

		<div class="gatedmedia-form-actions">
			<?php echo $gatedmedia_actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the button block. ?>
		</div>
	</form>
</div>
