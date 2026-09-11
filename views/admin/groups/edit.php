<?php
/**
 * One group: its name, what it holds, and who holds it.
 *
 * Three forms, not one: the details save, and one add form per kind of thing a group can hold. Search fields rather than selects, because the list is every post and every file on the site.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{uuid: string, name: string, form_url: string, save_action: string, item_action: string, details: array<int, array<string, mixed>>, adders: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>, holders: array<int, array<string, mixed>>, items_note: string, holders_note: string} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<?php
View::render(
	'components/section-head',
	array(
		'title' => __( 'Details', 'gated-media-access' ),
		'note'  => __( 'What this group is called', 'gated-media-access' ),
	)
);
?>

<form method="post" action="<?php echo esc_url( $data['form_url'] ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( $data['save_action'] ); ?>" />
	<input type="hidden" name="group" value="<?php echo esc_attr( $data['uuid'] ); ?>" />
	<?php wp_nonce_field( $data['save_action'] ); ?>

	<?php View::render( 'components/fields', array( 'fields' => $data['details'] ) ); ?>

	<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Save group', 'gated-media-access' ); ?></button>
</form>

<?php
View::render(
	'components/section-head',
	array(
		'title' => __( 'Contents', 'gated-media-access' ),
		'note'  => $data['items_note'],
	)
);

View::render(
	'components/list',
	array(
		'items' => $data['items'],
		'empty' => __( 'Nothing yet.', 'gated-media-access' ),
	)
);
?>

<?php foreach ( $data['adders'] as $adder ) : ?>
	<form method="post" action="<?php echo esc_url( $data['form_url'] ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $data['item_action'] ); ?>" />
		<input type="hidden" name="group" value="<?php echo esc_attr( $data['uuid'] ); ?>" />
		<input type="hidden" name="op" value="add" />
		<?php wp_nonce_field( $data['item_action'] ); ?>

		<?php View::render( 'components/fields', array( 'fields' => array( $adder ) ) ); ?>
	</form>
<?php endforeach; ?>

<?php
View::render(
	'components/section-head',
	array(
		'title' => __( 'Who has access', 'gated-media-access' ),
		'note'  => $data['holders_note'],
	)
);

View::render(
	'components/list',
	array(
		'items' => $data['holders'],
		'empty' => __( 'Nobody yet.', 'gated-media-access' ),
	)
);
