<?php
/**
 * The General tab: accounts, the store, Stripe, revoking, and what uninstalling takes.
 *
 * A section is a head and its fields, so the tab is a list of those and nothing else.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{sections: array<int, array{title: string, note: string, fields: array<int, array<string, mixed>>}>} $data
 */

use PinkCrab\Gated_Access\Support\View;

foreach ( $data['sections'] as $section ) {
	View::render(
		'components/section-head',
		array(
			'title' => $section['title'],
			'note'  => $section['note'],
		)
	);

	View::render( 'components/fields', array( 'fields' => $section['fields'] ) );
}
