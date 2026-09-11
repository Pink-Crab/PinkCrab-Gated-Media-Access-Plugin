<?php
/**
 * Who holds the group.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{holders: array<int, array{name: string, email: string, edit_url: string}>} $data
 */

?>
<?php if ( array() === $data['holders'] ) : ?>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'Nobody yet.', 'gated-media-access' ); ?></p>
<?php else : ?>
	<ul class="gatedmedia-admin-list">
		<?php foreach ( $data['holders'] as $holder ) : ?>
			<li>
				<a href="<?php echo esc_url( $holder['edit_url'] ); ?>"><?php echo esc_html( $holder['name'] ); ?></a>
				<span class="gatedmedia-admin-caps"><?php echo esc_html( $holder['email'] ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
