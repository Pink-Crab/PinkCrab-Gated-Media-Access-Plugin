<?php
/**
 * One group on the list: what it holds, and who holds it.
 *
 * The first panel is open, as the notification panels are, because seeing the contents is the point of the screen.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{name: string, url: string, open: bool, items: array<int, array{title: string, edit_url: string, type_label: string, id: int}>, holders: array<int, array{name: string, email: string, edit_url: string}>} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<details class="gatedmedia-admin-panel" <?php echo $data['open'] ? 'open' : ''; ?>>
	<summary>
		<span class="gatedmedia-admin-panel-title"><a href="<?php echo esc_url( $data['url'] ); ?>"><?php echo esc_html( $data['name'] ); ?></a></span>
		<span class="gatedmedia-admin-caps">
			<?php
			printf(
				'%s · %s',
				esc_html(
					sprintf(
						/* translators: %d: number of items. */
						_n( '%d item', '%d items', count( $data['items'] ), 'gated-media-access' ),
						count( $data['items'] )
					)
				),
				esc_html(
					sprintf(
						/* translators: %d: number of people with access. */
						_n( '%d with access', '%d with access', count( $data['holders'] ), 'gated-media-access' ),
						count( $data['holders'] )
					)
				)
			);
			?>
		</span>
	</summary>

	<div class="gatedmedia-admin-panel-body">
		<p class="gatedmedia-admin-caps"><?php esc_html_e( 'Contains', 'gated-media-access' ); ?></p>
		<?php View::render( 'admin/groups/items', array( 'items' => $data['items'] ) ); ?>

		<p class="gatedmedia-admin-caps"><?php esc_html_e( 'Who has access', 'gated-media-access' ); ?></p>
		<?php View::render( 'admin/groups/holders', array( 'holders' => $data['holders'] ) ); ?>
	</div>
</details>
