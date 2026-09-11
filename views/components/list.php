<?php
/**
 * A list of things: what each is, what kind it is, and its own action.
 *
 * A list, not a table: two facts per row, and the row's action sits at its end.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{items: array<int, array{title: string, url?: string, meta?: string, action?: string}>, empty: string} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<?php if ( array() === $data['items'] ) : ?>
	<?php View::render( 'components/empty', array( 'message' => $data['empty'] ) ); ?>
<?php else : ?>
	<ul class="gatedmedia-admin-list">
		<?php foreach ( $data['items'] as $item ) : ?>
			<li>
				<?php if ( isset( $item['url'] ) ) : ?>
					<a href="<?php echo esc_url( (string) $item['url'] ); ?>"><?php echo esc_html( (string) $item['title'] ); ?></a>
				<?php else : ?>
					<span><?php echo esc_html( (string) $item['title'] ); ?></span>
				<?php endif; ?>

				<?php if ( '' !== (string) ( $item['meta'] ?? '' ) ) : ?>
					<span class="gatedmedia-admin-caps"><?php echo esc_html( (string) $item['meta'] ); ?></span>
				<?php endif; ?>

				<?php echo (string) ( $item['action'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The row's own action, composed by the screen and escaped there. ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
