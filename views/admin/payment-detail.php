<?php
/**
 * One payment: what Stripe left, and what it granted.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{found: bool, payments_url: string, amount: string, status: string, facts: array<int, array<string, mixed>>, overuse: string, grant_error: string, grants: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

if ( ! $data['found'] ) {
	View::render(
		'components/screen',
		array(
			'body' => View::get(
				'components/page-header',
				array(
					'kicker' => __( 'Payment', 'gated-media-access' ),
					'title'  => __( 'Not found', 'gated-media-access' ),
					'action' => array(
						'label' => __( 'Back to Payments', 'gated-media-access' ),
						'url'   => $data['payments_url'],
					),
				)
			) . View::get( 'components/empty', array( 'message' => __( 'No payment found for that reference.', 'gated-media-access' ) ) ),
		)
	);

	return;
}

$body = View::get(
	'components/page-header',
	array(
		'kicker' => __( 'Payment', 'gated-media-access' ),
		'title'  => $data['amount'],
		'action' => array(
			'label' => __( 'Back to Payments', 'gated-media-access' ),
			'url'   => $data['payments_url'],
		),
	)
) . View::get(
	'components/section-head',
	array(
		'title' => __( 'The payment', 'gated-media-access' ),
		'note'  => $data['status'],
	)
) . View::get( 'components/fields', array( 'fields' => $data['facts'] ) );

if ( '' !== $data['overuse'] ) {
	$body .= View::get( 'components/help', array( 'help' => $data['overuse'] ) );
}

$body .= View::get(
	'components/section-head',
	array(
		'title' => __( 'Access granted', 'gated-media-access' ),
		'note'  => __( 'From the frozen snapshot', 'gated-media-access' ),
	)
);

// Stripe's retries are finite, so once it gives up this is the only place the failure shows.
if ( '' !== $data['grant_error'] ) {
	$body .= View::get( 'components/help', array( 'help' => __( 'The last attempt to grant this payment failed:', 'gated-media-access' ) ) );
	$body .= View::get( 'components/help', array( 'help' => $data['grant_error'] ) );
}

if ( array() !== $data['grants'] || '' === $data['grant_error'] ) {
	$body .= View::get(
		'components/list',
		array(
			'items' => $data['grants'],
			'empty' => __( 'Nothing granted by this payment yet.', 'gated-media-access' ),
		)
	);
}

View::render( 'components/screen', array( 'body' => $body ) );
