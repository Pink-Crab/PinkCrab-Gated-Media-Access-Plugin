<?php
/**
 * Add Access: pick a person, pick an item, give it a duration.
 *
 * Three rows of the item picker exist at once and the admin bundle shows whichever the type select names, so the prefill from an item's own Access panel stands without the script.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{action: string, form_url: string, fields: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

View::render(
	'components/screen',
	array(
		'form' => array(
			'url'    => $data['form_url'],
			'fields' => sprintf(
				'<input type="hidden" name="action" value="%s" />%s',
				esc_attr( $data['action'] ),
				wp_nonce_field( $data['action'], '_wpnonce', true, false )
			),
		),
		'body' => View::get(
			'components/page-header',
			array(
				'title'  => __( 'Add Access', 'gated-media-access' ),
				'action' => array( 'label' => __( 'Add Access', 'gated-media-access' ) ),
			)
		) . View::get( 'components/fields', array( 'fields' => $data['fields'] ) ),
	)
);
