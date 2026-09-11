<?php
/**
 * What a group holds, files and posts alike.
 *
 * The remove control is a posting form, not a link: taking something out is a write, and a write behind a GET is one prefetch away from happening by itself. It is drawn only where a group is named, so the list has none.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{items: array<int, array{title: string, edit_url: string, type_label: string, id: int}>, uuid?: string, form_url?: string, item_action?: string} $data
 */

$removable = isset( $data['uuid'] ) && '' !== $data['uuid'];

?>
<?php if ( array() === $data['items'] ) : ?>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'Nothing yet.', 'gated-media-access' ); ?></p>
<?php else : ?>
	<ul class="gatedmedia-admin-list">
		<?php foreach ( $data['items'] as $item ) : ?>
			<li>
				<a href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
				<span class="gatedmedia-admin-caps"><?php echo esc_html( $item['type_label'] ); ?></span>
				<?php if ( $removable ) : ?>
					<form method="post" action="<?php echo esc_url( (string) $data['form_url'] ); ?>" class="gatedmedia-admin-row-action">
						<input type="hidden" name="action" value="<?php echo esc_attr( (string) $data['item_action'] ); ?>" />
						<input type="hidden" name="group" value="<?php echo esc_attr( (string) $data['uuid'] ); ?>" />
						<input type="hidden" name="item" value="<?php echo esc_attr( (string) $item['id'] ); ?>" />
						<input type="hidden" name="op" value="remove" />
						<?php wp_nonce_field( (string) $data['item_action'] ); ?>
						<button type="submit" class="button-link"><?php esc_html_e( 'Remove', 'gated-media-access' ); ?></button>
					</form>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
