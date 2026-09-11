<?php
/**
 * The Edit Access page: the record's summary, and its one editable field.
 *
 * A message stands in for the form when there is no record, or when it is revoked.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{message: string, access_id: int, action: string, form_url: string, expires: string, summary: array<string, string>} $data
 */

?>
<div class="wrap">
	<div class="gatedmedia-admin">
		<header class="gatedmedia-admin-header">
			<div>
				<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Gated Media Access', 'gated-media-access' ); ?></span>
				<h1><?php esc_html_e( 'Edit Access', 'gated-media-access' ); ?></h1>
			</div>
			<?php if ( '' === $data['message'] ) : ?>
				<button type="submit" form="gatedmedia-edit-access" class="gatedmedia-admin-button"><?php esc_html_e( 'Update Access', 'gated-media-access' ); ?></button>
			<?php endif; ?>
		</header>

		<?php if ( '' !== $data['message'] ) : ?>
			<p class="gatedmedia-admin-help"><?php echo esc_html( $data['message'] ); ?></p>
		<?php else : ?>
			<div class="gatedmedia-admin-section-head">
				<h2><?php esc_html_e( 'Record', 'gated-media-access' ); ?></h2>
				<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Holder, item and provenance', 'gated-media-access' ); ?></span>
			</div>

			<dl class="gatedmedia-admin-summary">
				<?php foreach ( $data['summary'] as $label => $value ) : ?>
					<dt class="gatedmedia-admin-caps"><?php echo esc_html( $label ); ?></dt>
					<dd><?php echo wp_kses_post( $value ); ?></dd>
				<?php endforeach; ?>
			</dl>

			<form method="post" id="gatedmedia-edit-access" action="<?php echo esc_url( $data['form_url'] ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $data['action'] ); ?>" />
				<input type="hidden" name="access" value="<?php echo esc_attr( (string) $data['access_id'] ); ?>" />
				<?php wp_nonce_field( $data['action'] . '_' . $data['access_id'] ); ?>

				<div class="gatedmedia-admin-section-head">
					<h2><?php esc_html_e( 'Expiry', 'gated-media-access' ); ?></h2>
					<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Site timezone', 'gated-media-access' ); ?></span>
				</div>

				<div class="gatedmedia-admin-field">
					<label class="gatedmedia-admin-caps" for="gatedmedia_expires"><?php esc_html_e( 'Expires', 'gated-media-access' ); ?></label>
					<input type="datetime-local" name="gatedmedia_expires" id="gatedmedia_expires" value="<?php echo esc_attr( $data['expires'] ); ?>" />
					<p class="gatedmedia-admin-help"><?php esc_html_e( 'Clear the field for lifetime access. A past date expires the record immediately.', 'gated-media-access' ); ?></p>
				</div>
			</form>
		<?php endif; ?>
	</div>
</div>
