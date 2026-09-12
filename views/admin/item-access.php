<?php
/**
 * The per-item Access metabox: who holds this item, a staged grant, and the groups it sits in.
 *
 * Fields, never a form: a metabox lives inside the editor's own form, and a form in a form posts the wrong one. The grant is applied when the item itself is saved.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{holders: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>, grant_fields: array<int, array<string, mixed>>, group_field: array<string, mixed>, nonce_action: string, nonce_name: string} $data
 */

use PinkCrab\Gated_Access\Support\View;

View::render(
	'components/list',
	array(
		'items' => $data['holders'],
		'empty' => __( 'Nobody holds direct access to this item.', 'gated-media-access' ),
	)
);

?>
<div class="gatedmedia-inline-grant">
	<?php
	View::render( 'components/fields', array( 'fields' => $data['grant_fields'] ) );
	wp_nonce_field( $data['nonce_action'], $data['nonce_name'] );
	?>
</div>

<hr />

<?php
View::render( 'components/section-head', array( 'title' => __( 'Groups', 'gated-media-access' ) ) );

View::render(
	'components/list',
	array(
		'items' => $data['groups'],
		'empty' => __( 'This item is in no group.', 'gated-media-access' ),
	)
);

View::render( 'components/fields', array( 'fields' => array( $data['group_field'] ) ) );
