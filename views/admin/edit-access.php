<?php
/**
 * Edit Access: the record's fixed facts, and the one thing that can change.
 *
 * A message stands in for the form when there is no record, or when it is revoked.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{message: string, access_id: int, action: string, form_url: string, fields: array<int, array<string, mixed>>, summary: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

$editable = '' === $data['message'];

$body = View::get(
	'components/page-header',
	array(
		'title'  => __( 'Edit Access', 'gated-media-access' ),
		'action' => $editable ? array( 'label' => __( 'Update Access', 'gated-media-access' ) ) : null,
	)
);

if ( ! $editable ) {
	$body .= View::get( 'components/empty', array( 'message' => $data['message'] ) );
} else {
	$body .= View::get(
		'components/section-head',
		array(
			'title' => __( 'Record', 'gated-media-access' ),
			'note'  => __( 'Holder, item and provenance', 'gated-media-access' ),
		)
	);
	$body .= View::get( 'components/fields', array( 'fields' => $data['summary'] ) );
	$body .= View::get(
		'components/section-head',
		array(
			'title' => __( 'Expiry', 'gated-media-access' ),
			'note'  => __( 'Site timezone', 'gated-media-access' ),
		)
	);
	$body .= View::get( 'components/fields', array( 'fields' => $data['fields'] ) );
}

View::render(
	'components/screen',
	array(
		'form' => $editable
			? array(
				'url'    => $data['form_url'],
				'fields' => sprintf(
					'<input type="hidden" name="action" value="%s" /><input type="hidden" name="access" value="%s" />%s',
					esc_attr( $data['action'] ),
					esc_attr( (string) $data['access_id'] ),
					wp_nonce_field( $data['action'] . '_' . $data['access_id'], '_wpnonce', true, false )
				),
			)
			: null,
		'body' => $body,
	)
);
