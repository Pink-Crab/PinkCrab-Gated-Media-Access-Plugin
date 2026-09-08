<?php
/**
 * §7.1 My Access — the landing view for the account area.
 *
 * Three sections, each a section heading (§6.9) over rows (§6.2): groups,
 * posts, files. **A section with nothing in it is not rendered**, and the
 * empty state (§6.10) appears only when all three are empty — one box at page
 * level, never one per section.
 *
 * The rows differ by kind, per §7.1:
 *
 * - **Groups** carry a count summary as their meta, and an expiry on the right.
 * - **Posts** carry an expiry on the right and nothing else.
 * - **Files** fold their expiry into the meta line and carry a **Download text
 *   link** rather than a button — Files (§7.2) is the view where downloading
 *   is the point, so it gets the heavier control and this one does not.
 *
 * Everything here comes from the resolver (architecture.md §4), reaching the
 * view through Held_Access on `gatedmedia_my_access_data`. A user holding
 * nothing renders the wholly-empty case.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Support\Block;

defined( 'ABSPATH' ) || exit;

$gatedmedia_user_id = get_current_user_id();

if ( 0 === $gatedmedia_user_id ) {
	return;
}

/**
 * Supplied by Held_Access, and by Group_Contents when a group is open; the
 * empty defaults
 * are what a user holding nothing renders.
 *
 * @var array{groups: array<int, array<string, mixed>>, posts: array<int, array<string, mixed>>, files: array<int, array<string, mixed>>} $gatedmedia_data
 */
// The second URL segment: a group's uuid, opening it rather than the list.
$gatedmedia_group = isset( $attributes['detail'] ) && is_string( $attributes['detail'] )
	? $attributes['detail']
	: '';

$gatedmedia_data = apply_filters(
	'gatedmedia_my_access_data',
	array(
		'groups' => array(),
		'posts'  => array(),
		'files'  => array(),
		'detail' => null,
	),
	$gatedmedia_group
);

$gatedmedia_groups = $gatedmedia_data['groups'];
$gatedmedia_posts  = $gatedmedia_data['posts'];
$gatedmedia_files  = $gatedmedia_data['files'];

/**
 * One section: a heading over its rows, or nothing at all.
 *
 * @param string                           $heading The section label.
 * @param array<int, array<string, mixed>> $items   Rows to draw.
 * @param callable                         $row     Turns one item into a rendered row.
 */
$gatedmedia_section = static function ( string $heading, array $items, callable $row ): string {
	if ( array() === $items ) {
		return '';
	}

	$rows = '';

	foreach ( $items as $item ) {
		$rows .= $row( $item );
	}

	return '<section class="gatedmedia-section">'
		. Block::render( 'gated-media-access/section-heading', array( 'text' => $heading ) )
		. $rows
		. '</section>';
};

/**
 * Groups and posts: an expiry on the right, no action on wide.
 *
 * @param array<string, mixed> $item One held item.
 */
$gatedmedia_held = static function ( array $item ): string {
	return Block::render(
		'gated-media-access/row',
		array(
			'title'       => (string) ( $item['title'] ?? '' ),
			'meta'        => (string) ( $item['meta'] ?? '' ),
			'href'        => (string) ( $item['href'] ?? '' ),
			'state'       => (string) ( $item['state'] ?? 'normal' ),
			'actionLabel' => (string) ( $item['action_label'] ?? '' ),
			'actionHref'  => (string) ( $item['href'] ?? '' ),
		),
		Block::render(
			'gated-media-access/expiry',
			array(
				'state' => (string) ( $item['expiry_state'] ?? 'lifetime' ),
				'label' => (string) ( $item['expiry_label'] ?? '' ),
			)
		)
	);
};

/**
 * Files: the expiry is already in the meta line, and the action is a text link.
 *
 * @param array<string, mixed> $item One held file.
 */
$gatedmedia_file = static function ( array $item ): string {
	return Block::render(
		'gated-media-access/row',
		array(
			'title'       => (string) ( $item['title'] ?? '' ),
			'meta'        => (string) ( $item['meta'] ?? '' ),
			'state'       => (string) ( $item['state'] ?? 'normal' ),
			'actionLabel' => __( 'Download', 'gated-media-access' ),
			'actionHref'  => (string) ( $item['href'] ?? '' ),
			'actionIcon'  => 'i-download',
		),
		Block::render(
			'gated-media-access/button',
			array(
				'label'   => __( 'Download', 'gated-media-access' ),
				'href'    => (string) ( $item['href'] ?? '' ),
				'variant' => 'link',
				'icon'    => 'i-download',
			)
		)
	);
};

if ( '' !== $gatedmedia_group ) {
	// One group, opened. A group nobody gave you reads exactly like one that
	// does not exist — the detail is null either way.
	$gatedmedia_detail = is_array( $gatedmedia_data['detail'] ?? null ) ? $gatedmedia_data['detail'] : null;

	if ( null === $gatedmedia_detail ) {
		$gatedmedia_body = Block::render(
			'gated-media-access/empty-state',
			array(
				'icon'    => 'i-empty',
				'title'   => __( 'Group not found', 'gated-media-access' ),
				'message' => __( 'We could not find that group on your account.', 'gated-media-access' ),
			)
		);
	} else {
		$gatedmedia_rows = '';

		foreach ( (array) ( $gatedmedia_detail['items'] ?? array() ) as $gatedmedia_item ) {
			$gatedmedia_rows .= Block::render(
				'gated-media-access/row',
				array(
					'title' => (string) ( $gatedmedia_item['title'] ?? '' ),
					'meta'  => (string) ( $gatedmedia_item['meta'] ?? '' ),
					'href'  => (string) ( $gatedmedia_item['href'] ?? '' ),
				)
			);
		}

		if ( '' === $gatedmedia_rows ) {
			$gatedmedia_rows = Block::render(
				'gated-media-access/empty-state',
				array(
					'icon'    => 'i-empty',
					'title'   => __( 'This group is empty', 'gated-media-access' ),
					'message' => __( 'Nothing has been put in it yet. Anything added will appear here.', 'gated-media-access' ),
				)
			);
		}

		$gatedmedia_body = Block::render(
			'gated-media-access/button',
			array(
				'label'   => __( 'Back to my access', 'gated-media-access' ),
				'href'    => (string) ( $gatedmedia_data['section_url'] ?? '' ),
				'variant' => 'link',
				'icon'    => 'i-back',
			)
		)
			. '<h2 class="gatedmedia-heading gatedmedia-heading--page">' . esc_html( (string) ( $gatedmedia_detail['title'] ?? '' ) ) . '</h2>'
			. '<hr class="gatedmedia-rule" />'
			. $gatedmedia_rows;
	}
} else {
	$gatedmedia_body = $gatedmedia_section( __( 'Groups', 'gated-media-access' ), $gatedmedia_groups, $gatedmedia_held )
		. $gatedmedia_section( __( 'Posts', 'gated-media-access' ), $gatedmedia_posts, $gatedmedia_held )
		. $gatedmedia_section( __( 'Files', 'gated-media-access' ), $gatedmedia_files, $gatedmedia_file );

	if ( '' === $gatedmedia_body ) {
		$gatedmedia_body = Block::render(
			'gated-media-access/empty-state',
			array(
				'icon'    => 'i-empty',
				'title'   => __( 'Nothing here yet', 'gated-media-access' ),
				'message' => __( 'Anything you are given access to will appear here, with the date it runs out.', 'gated-media-access' ),
			)
		);
	}
}
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia gatedmedia-view gatedmedia-view--my-access' ) ) ); ?>>
	<?php echo $gatedmedia_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the blocks that produced it. ?>
</div>
