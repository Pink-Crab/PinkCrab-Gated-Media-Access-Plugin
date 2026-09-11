<?php
/**
 * One group: its name, what it holds, and who holds it.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{uuid: string, name: string, description: string, form_url: string, save_action: string, item_action: string, items: array<int, array{title: string, edit_url: string, type_label: string, id: int}>, holders: array<int, array{name: string, email: string, edit_url: string}>, pickers: array<int, array{picker: \PinkCrab\Gated_Access\Admin\Pickers\Picker, label: string}>} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Details', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'What this group is called', 'gated-media-access' ); ?></span>
</div>

<form method="post" action="<?php echo esc_url( $data['form_url'] ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( $data['save_action'] ); ?>" />
	<input type="hidden" name="group" value="<?php echo esc_attr( $data['uuid'] ); ?>" />
	<?php wp_nonce_field( $data['save_action'] ); ?>

	<div class="gatedmedia-admin-field">
		<label class="gatedmedia-admin-caps" for="gatedmedia_group_edit_name"><?php esc_html_e( 'Name', 'gated-media-access' ); ?></label>
		<input type="text" class="regular-text" id="gatedmedia_group_edit_name" name="group_name" value="<?php echo esc_attr( $data['name'] ); ?>" required />
	</div>

	<div class="gatedmedia-admin-field">
		<label class="gatedmedia-admin-caps" for="gatedmedia_group_edit_description"><?php esc_html_e( 'Description', 'gated-media-access' ); ?></label>
		<textarea rows="3" class="large-text" id="gatedmedia_group_edit_description" name="group_description"><?php echo esc_textarea( $data['description'] ); ?></textarea>
		<p class="gatedmedia-admin-help"><?php esc_html_e( 'For your own reference. Nobody outside the admin sees it.', 'gated-media-access' ); ?></p>
	</div>

	<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Save group', 'gated-media-access' ); ?></button>
</form>

<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Contents', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps">
		<?php
		printf(
			/* translators: %d: number of items. */
			esc_html( _n( '%d item', '%d items', count( $data['items'] ), 'gated-media-access' ) ),
			count( $data['items'] )
		);
		?>
	</span>
</div>

<?php
View::render(
	'admin/groups/items',
	array(
		'items'       => $data['items'],
		'uuid'        => $data['uuid'],
		'form_url'    => $data['form_url'],
		'item_action' => $data['item_action'],
	)
);
?>

<?php foreach ( $data['pickers'] as $field ) : ?>
	<form method="post" action="<?php echo esc_url( $data['form_url'] ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $data['item_action'] ); ?>" />
		<input type="hidden" name="group" value="<?php echo esc_attr( $data['uuid'] ); ?>" />
		<input type="hidden" name="op" value="add" />
		<?php wp_nonce_field( $data['item_action'] ); ?>

		<div class="gatedmedia-admin-field">
			<label class="gatedmedia-admin-caps"><?php echo esc_html( $field['label'] ); ?></label>
			<span class="gatedmedia-admin-inline">
				<?php $field['picker']->render(); ?>
				<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Add', 'gated-media-access' ); ?></button>
			</span>
		</div>
	</form>
<?php endforeach; ?>

<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Who has access', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps">
		<?php
		printf(
			/* translators: %d: number of people. */
			esc_html( _n( '%d person', '%d people', count( $data['holders'] ), 'gated-media-access' ) ),
			count( $data['holders'] )
		);
		?>
	</span>
</div>

<?php View::render( 'admin/groups/holders', array( 'holders' => $data['holders'] ) ); ?>
