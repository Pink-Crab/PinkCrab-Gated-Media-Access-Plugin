<?php
/**
 * One notification's panel: its switch in the summary, its subject and body inside.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{title: string, open: bool, enabled: bool, switch_name: string, fields: array<int, array<string, mixed>>, tokens: array<int, string>} $data
 */

use PinkCrab\Gated_Access\Support\View;

$aside = sprintf(
	'<label class="gatedmedia-admin-caps"><input type="hidden" name="%1$s" value="0" /><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
	esc_attr( $data['switch_name'] ),
	checked( true, $data['enabled'], false ),
	esc_html__( 'Enabled', 'gated-media-access' )
);

$tokens = '';

foreach ( $data['tokens'] as $token ) {
	$tokens .= sprintf( '<code>%s</code>', esc_html( $token ) );
}

$body = View::get( 'components/fields', array( 'fields' => $data['fields'] ) )
	. sprintf( '<div class="gatedmedia-admin-tokens">%s</div>', $tokens )
	. View::get( 'components/help', array( 'help' => __( 'Tokens are replaced when the email is sent.', 'gated-media-access' ) ) );

View::render(
	'components/panel',
	array(
		'title' => $data['title'],
		'open'  => $data['open'],
		'aside' => $aside,
		'body'  => $body,
	)
);
