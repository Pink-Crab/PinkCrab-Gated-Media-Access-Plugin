<?php
/**
 * §7.5 Profile — one view, three states.
 *
 * Standard, forced completion, and saved. The mockup labels them Panel 1/2/3;
 * those are specimen labels for the sheet, not three pages.
 *
 * Two things here are easy to get wrong and are called out in the spec:
 *
 * - **The email field is read-only.** Rendered disabled with a helper line
 *   beneath saying so. Every other field is editable.
 * - **Forced completion shows only the missing fields**, inside a bordered card
 *   on wide and the plain column on narrow. It is not the full form with a
 *   notice on top.
 *
 * Actions are Save beside a Cancel text link, left-aligned and not full width
 * on wide; on narrow Save goes full width and Cancel becomes a centred link
 * beneath it.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Account\Profile_Writer;

defined( 'ABSPATH' ) || exit;

$gatedmedia_user = wp_get_current_user();

if ( 0 === $gatedmedia_user->ID ) {
	return;
}

$gatedmedia_values  = Profile_Writer::values_for( $gatedmedia_user->ID );
$gatedmedia_fields  = Profile_Writer::fields();
$gatedmedia_missing = Profile_Writer::missing_for( $gatedmedia_user->ID );

// ?profile=saved comes back from the writer's redirect. ?profile=complete is
// how the forced-completion state is reached.
$gatedmedia_state = isset( $_GET['profile'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display state only, changes nothing.
	? sanitize_key( wp_unslash( $_GET['profile'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';

$gatedmedia_is_forced = 'complete' === $gatedmedia_state && array() !== $gatedmedia_missing;
$gatedmedia_is_saved  = 'saved' === $gatedmedia_state;

// Forced completion narrows the form to what is actually missing.
$gatedmedia_shown = $gatedmedia_is_forced
	? array_intersect_key( $gatedmedia_fields, array_flip( $gatedmedia_missing ) )
	: $gatedmedia_fields;
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-view gatedmedia-view--profile' ) ) ); ?>>

	<?php if ( $gatedmedia_is_saved ) : ?>
	<div class="gatedmedia-notice gatedmedia-notice--success">
		<svg class="gatedmedia-icon gatedmedia-notice__icon" aria-hidden="true" focusable="false"><use href="#i-success"></use></svg>
		<div class="gatedmedia-notice__body"><?php esc_html_e( 'Your details have been saved.', 'gated-media-access' ); ?></div>
	</div>
	<?php elseif ( $gatedmedia_is_forced ) : ?>
	<div class="gatedmedia-notice">
		<svg class="gatedmedia-icon gatedmedia-notice__icon" aria-hidden="true" focusable="false"><use href="#i-info"></use></svg>
		<div class="gatedmedia-notice__body">
			<?php esc_html_e( 'Please complete your details before continuing.', 'gated-media-access' ); ?>
		</div>
	</div>
	<?php elseif ( array() !== $gatedmedia_missing ) : ?>
	<div class="gatedmedia-notice">
		<svg class="gatedmedia-icon gatedmedia-notice__icon" aria-hidden="true" focusable="false"><use href="#i-info"></use></svg>
		<div class="gatedmedia-notice__body">
			<?php esc_html_e( 'Your profile is incomplete.', 'gated-media-access' ); ?>
		</div>
		<button
			type="button"
			class="gatedmedia-notice__dismiss"
			aria-label="<?php esc_attr_e( 'Dismiss', 'gated-media-access' ); ?>"
		>&times;</button>
	</div>
	<?php endif; ?>

	<form
		class="<?php echo $gatedmedia_is_forced ? 'gatedmedia-card' : ''; ?>"
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	>
		<input type="hidden" name="action" value="<?php echo esc_attr( Profile_Writer::ACTION ); ?>">
		<?php wp_nonce_field( Profile_Writer::ACTION ); ?>

		<div class="gatedmedia-field">
			<label class="gatedmedia-field__label" for="gatedmedia-email">
				<?php esc_html_e( 'Email', 'gated-media-access' ); ?>
			</label>
			<input
				class="gatedmedia-field__input"
				type="email"
				id="gatedmedia-email"
				value="<?php echo esc_attr( $gatedmedia_user->user_email ); ?>"
				disabled
			>
			<p class="gatedmedia-field__message">
				<?php esc_html_e( 'Your email cannot be changed here.', 'gated-media-access' ); ?>
			</p>
		</div>

		<?php foreach ( $gatedmedia_shown as $gatedmedia_key => $gatedmedia_field ) : ?>
		<div class="gatedmedia-field">
			<label class="gatedmedia-field__label" for="gatedmedia-<?php echo esc_attr( $gatedmedia_key ); ?>">
				<?php echo esc_html( $gatedmedia_field['label'] ); ?>
			</label>
			<input
				class="gatedmedia-field__input"
				type="<?php echo esc_attr( $gatedmedia_field['type'] ); ?>"
				id="gatedmedia-<?php echo esc_attr( $gatedmedia_key ); ?>"
				name="<?php echo esc_attr( $gatedmedia_key ); ?>"
				value="<?php echo esc_attr( $gatedmedia_values[ $gatedmedia_key ] ?? '' ); ?>"
				<?php echo '' !== $gatedmedia_field['autocomplete'] ? 'autocomplete="' . esc_attr( $gatedmedia_field['autocomplete'] ) . '"' : ''; ?>
			>
		</div>
		<?php endforeach; ?>

		<div class="gatedmedia-form-actions">
			<button type="submit" class="gatedmedia-button gatedmedia-button--primary">
				<?php esc_html_e( 'Save', 'gated-media-access' ); ?>
			</button>
			<a class="gatedmedia-text-link" href="<?php echo esc_url( remove_query_arg( 'profile' ) ); ?>">
				<?php esc_html_e( 'Cancel', 'gated-media-access' ); ?>
			</a>
		</div>
	</form>

</div>
