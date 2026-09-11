<?php
/**
 * One payment: what Stripe left, and what it granted.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{found: bool, payments_url: string, amount: string, status: string, facts: array<string, string>, overuse: string, grant_error: string, grants: array<int, array{status: string, label: string, url: string}>} $data
 */

?>
<div class="wrap">
	<div class="gatedmedia-admin">
		<?php if ( ! $data['found'] ) : ?>
			<p>
				<?php esc_html_e( 'No payment found for that reference.', 'gated-media-access' ); ?>
				<a href="<?php echo esc_url( $data['payments_url'] ); ?>"><?php esc_html_e( 'Back to Payments', 'gated-media-access' ); ?></a>
			</p>
		<?php else : ?>
			<header class="gatedmedia-admin-header">
				<div>
					<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Payment', 'gated-media-access' ); ?></span>
					<h1><?php echo esc_html( $data['amount'] ); ?></h1>
				</div>
				<span class="gatedmedia-admin-caps"><?php echo esc_html( $data['status'] ); ?></span>
			</header>

			<div class="gatedmedia-admin-section-head">
				<h2><?php esc_html_e( 'The payment', 'gated-media-access' ); ?></h2>
				<span class="gatedmedia-admin-caps"><?php esc_html_e( 'As Stripe left it', 'gated-media-access' ); ?></span>
			</div>

			<?php foreach ( $data['facts'] as $label => $value ) : ?>
				<div class="gatedmedia-admin-field">
					<span class="gatedmedia-admin-caps"><?php echo esc_html( $label ); ?></span>
					<span><?php echo esc_html( $value ); ?></span>
				</div>
			<?php endforeach; ?>

			<?php if ( '' !== $data['overuse'] ) : ?>
				<p class="gatedmedia-admin-help"><?php echo esc_html( $data['overuse'] ); ?></p>
			<?php endif; ?>

			<div class="gatedmedia-admin-section-head">
				<h2><?php esc_html_e( 'Access granted', 'gated-media-access' ); ?></h2>
				<span class="gatedmedia-admin-caps"><?php esc_html_e( 'From the frozen snapshot', 'gated-media-access' ); ?></span>
			</div>

			<?php if ( '' !== $data['grant_error'] ) : ?>
				<p class="gatedmedia-admin-help"><?php esc_html_e( 'The last attempt to grant this payment failed:', 'gated-media-access' ); ?></p>
				<p class="gatedmedia-admin-help"><?php echo esc_html( $data['grant_error'] ); ?></p>
			<?php endif; ?>

			<?php if ( array() === $data['grants'] && '' === $data['grant_error'] ) : ?>
				<p class="gatedmedia-admin-help"><?php esc_html_e( 'Nothing granted by this payment yet.', 'gated-media-access' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $data['grants'] as $grant ) : ?>
				<div class="gatedmedia-admin-field">
					<span class="gatedmedia-admin-caps"><?php echo esc_html( $grant['status'] ); ?></span>
					<span><?php echo esc_html( $grant['label'] ); ?></span>
					<a href="<?php echo esc_url( $grant['url'] ); ?>"><?php esc_html_e( 'View record', 'gated-media-access' ); ?></a>
				</div>
			<?php endforeach; ?>

			<p><a class="gatedmedia-admin-button" href="<?php echo esc_url( $data['payments_url'] ); ?>"><?php esc_html_e( 'Back to Payments', 'gated-media-access' ); ?></a></p>
		<?php endif; ?>
	</div>
</div>
