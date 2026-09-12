<?php
/**
 * Every group: the create form, then one panel each.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{form_url: string, create_action: string, create_field: array<string, mixed>, groups: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<?php
View::render(
	'components/section-head',
	array(
		'title' => __( 'New group', 'gated-media-access' ),
		'note'  => __( 'A name is all it needs', 'gated-media-access' ),
	)
);
?>

<form method="post" action="<?php echo esc_url( $data['form_url'] ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( $data['create_action'] ); ?>" />
	<?php wp_nonce_field( $data['create_action'] ); ?>

	<?php View::render( 'components/fields', array( 'fields' => array( $data['create_field'] ) ) ); ?>
</form>

<?php
View::render(
	'components/section-head',
	array(
		'title' => __( 'Every group', 'gated-media-access' ),
		'note'  => __( 'What it holds, and who holds it', 'gated-media-access' ),
	)
);

if ( array() === $data['groups'] ) {
	View::render( 'components/empty', array( 'message' => __( 'No groups yet.', 'gated-media-access' ) ) );
}

foreach ( $data['groups'] as $group ) {
	View::render( 'admin/groups/panel', $group );
}
