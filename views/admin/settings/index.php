<?php
/**
 * The Settings screen: one form, two tabs, one save.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{group: string, form_url: string, general_url: string, notifications_url: string, on_notifications: bool, general: array<string, mixed>, notifications: array<string, mixed>} $data
 */

use PinkCrab\Gated_Access\Support\View;

$body = View::get(
	'components/page-header',
	array(
		'title'  => __( 'Settings', 'gated-media-access' ),
		'action' => array( 'label' => __( 'Save Changes', 'gated-media-access' ) ),
	)
) . View::get(
	'components/tabs',
	array(
		'tabs' => array(
			array(
				'label'  => __( 'General', 'gated-media-access' ),
				'url'    => $data['general_url'],
				'active' => ! $data['on_notifications'],
			),
			array(
				'label'  => __( 'Notifications', 'gated-media-access' ),
				'url'    => $data['notifications_url'],
				'active' => $data['on_notifications'],
			),
		),
	)
);

$body .= $data['on_notifications']
	? View::get( 'admin/settings/notifications', $data['notifications'] )
	: View::get( 'admin/settings/general', $data['general'] );

ob_start();
settings_fields( $data['group'] );

View::render(
	'components/screen',
	array(
		'form' => array(
			'url'    => $data['form_url'],
			'fields' => (string) ob_get_clean(),
		),
		'body' => $body,
	)
);
