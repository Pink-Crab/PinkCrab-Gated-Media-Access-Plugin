<?php
/**
 * The Notifications tab: delivery, then one template panel per notification.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{delivery: array<int, array<string, mixed>>, panels: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

View::render(
	'components/section-head',
	array(
		'title' => __( 'Delivery', 'gated-media-access' ),
		'note'  => __( 'Copies and timing', 'gated-media-access' ),
	)
);

View::render( 'components/fields', array( 'fields' => $data['delivery'] ) );

View::render(
	'components/section-head',
	array(
		'title' => __( 'Templates', 'gated-media-access' ),
		'note'  => __( 'Subject and body per email', 'gated-media-access' ),
	)
);

foreach ( $data['panels'] as $panel ) {
	View::render( 'admin/settings/notification-panel', $panel );
}
