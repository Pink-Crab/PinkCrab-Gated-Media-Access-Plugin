<?php
/**
 * The Groups screen, in one of its two modes.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{title: string, group_name: string, group_url: string, list_url: string, notice: array{message: string, type: string}|null, edit: array<string, mixed>|null, list: array<string, mixed>|null} $data
 */

use PinkCrab\Gated_Access\Support\View;

$editing = null !== $data['edit'];

$tabs = array(
	array(
		'label'  => __( 'All groups', 'gated-media-access' ),
		'url'    => $data['list_url'],
		'active' => ! $editing,
	),
);

if ( $editing ) {
	$tabs[] = array(
		'label'  => $data['group_name'],
		'url'    => $data['group_url'],
		'active' => true,
	);
}

$body = View::get( 'components/page-header', array( 'title' => $data['title'] ) )
	. View::get( 'components/tabs', array( 'tabs' => $tabs ) );

if ( null !== $data['notice'] ) {
	$body .= View::get( 'components/notice', $data['notice'] );
}

$body .= $editing
	? View::get( 'admin/groups/edit', (array) $data['edit'] )
	: View::get( 'admin/groups/list', (array) $data['list'] );

View::render( 'components/screen', array( 'body' => $body ) );
