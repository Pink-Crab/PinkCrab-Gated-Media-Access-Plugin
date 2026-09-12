<?php
/**
 * One group on the list: what it holds, and who holds it.
 *
 * The first panel is open, because seeing the contents is the point of the screen.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{name: string, url: string, open: bool, summary: string, items: array<int, array<string, mixed>>, holders: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

$body = sprintf( '<p class="gatedmedia-admin-caps">%s</p>', esc_html__( 'Contains', 'gated-media-access' ) )
	. View::get(
		'components/list',
		array(
			'items' => $data['items'],
			'empty' => __( 'Nothing yet.', 'gated-media-access' ),
		)
	)
	. sprintf( '<p class="gatedmedia-admin-caps">%s</p>', esc_html__( 'Who has access', 'gated-media-access' ) )
	. View::get(
		'components/list',
		array(
			'items' => $data['holders'],
			'empty' => __( 'Nobody yet.', 'gated-media-access' ),
		)
	);

View::render(
	'components/panel',
	array(
		'title' => $data['name'],
		'url'   => $data['url'],
		'open'  => $data['open'],
		'aside' => sprintf( '<span class="gatedmedia-admin-caps">%s</span>', esc_html( $data['summary'] ) ),
		'body'  => $body,
	)
);
