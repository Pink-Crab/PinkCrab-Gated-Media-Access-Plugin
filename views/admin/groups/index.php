<?php
/**
 * The Groups screen, in one of its two modes.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{title: string, group_name: string, group_url: string, list_url: string, notice: array{message: string, type: string}|null, edit: array<string, mixed>|null, list: array<string, mixed>|null} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<div class="wrap">
	<div class="gatedmedia-admin">
		<header class="gatedmedia-admin-header">
			<div>
				<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Gated Media Access', 'gated-media-access' ); ?></span>
				<h1><?php echo esc_html( $data['title'] ); ?></h1>
			</div>
		</header>

		<nav class="gatedmedia-admin-tabs">
			<a class="<?php echo null === $data['edit'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( $data['list_url'] ); ?>"><?php esc_html_e( 'All groups', 'gated-media-access' ); ?></a>
			<?php if ( null !== $data['edit'] ) : ?>
				<a class="is-active" href="<?php echo esc_url( $data['group_url'] ); ?>"><?php echo esc_html( $data['group_name'] ); ?></a>
			<?php endif; ?>
		</nav>

		<?php if ( null !== $data['notice'] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $data['notice']['type'] ); ?>"><p><?php echo esc_html( $data['notice']['message'] ); ?></p></div>
		<?php endif; ?>

		<?php
		if ( null !== $data['edit'] ) {
			View::render( 'admin/groups/edit', $data['edit'] );
		} else {
			View::render( 'admin/groups/list', (array) $data['list'] );
		}
		?>
	</div>
</div>
