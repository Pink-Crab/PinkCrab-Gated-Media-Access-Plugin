<?php
/**
 * §7.2 Files — everything the person can download.
 *
 * The filter (§6.11) sits directly under the page header and carries a search
 * field **and** a type filter at every width. Then three sections — available,
 * downloading, past access — each a section heading (§6.9) over rows (§6.2).
 *
 * This view uses a Download **button** and keeps the expiry on the right,
 * where My Access (§7.1) uses a text link and folds the expiry into the meta
 * line. Files is the view where downloading is the point, so it gets the
 * heavier control. That difference is deliberate, and is why the two views
 * compose the same Row differently rather than sharing a wrapper.
 *
 * Per-section treatment, per §7.2:
 *
 * - **Available** — expiry, then a Download secondary button.
 * - **Downloading** — the button is replaced by a progress indication.
 * - **Past access** — dimmed, no action, the right side states it has gone.
 *
 * The rows come from the resolver (architecture.md §4), which is step 2 of §12
 * and does not exist. The filter renders regardless because it is part of the
 * view's structure; it has nothing to filter until then.
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
 * Supplied by View_Data from the resolver's allowed items; the empty defaults
 * are what a user holding nothing renders. Downloading is a client-side state
 * and stays empty server-side.
 *
 * @var array{available: array<int, array<string, mixed>>, downloading: array<int, array<string, mixed>>, past: array<int, array<string, mixed>>} $gatedmedia_data
 */
$gatedmedia_data = apply_filters(
	'gatedmedia_files_data',
	array(
		'available'   => array(),
		'downloading' => array(),
		'past'        => array(),
	)
);

$gatedmedia_available   = $gatedmedia_data['available'];
$gatedmedia_downloading = $gatedmedia_data['downloading'];
$gatedmedia_past        = $gatedmedia_data['past'];

// One list of types feeds both the select and the chips, so they cannot drift.
$gatedmedia_types = array(
	array(
		'value' => 'all',
		'label' => __( 'All', 'gated-media-access' ),
	),
	array(
		'value' => 'pdf',
		'label' => __( 'PDF', 'gated-media-access' ),
	),
	array(
		'value' => 'video',
		'label' => __( 'Video', 'gated-media-access' ),
	),
	array(
		'value' => 'zip',
		'label' => __( 'ZIP', 'gated-media-access' ),
	),
	array(
		'value' => 'audio',
		'label' => __( 'Audio', 'gated-media-access' ),
	),
);

$gatedmedia_filter = Block::render(
	'gated-media-access/filter',
	array(
		'searchLabel' => __( 'Search files', 'gated-media-access' ),
		'typeLabel'   => __( 'Filter by type', 'gated-media-access' ),
		'types'       => $gatedmedia_types,
		'active'      => 'all',
	)
);

/**
 * One file row. The aside varies by which section it sits in.
 *
 * @param array<string, mixed> $item    One file.
 * @param string               $section available|downloading|past.
 */
$gatedmedia_row = static function ( array $item, string $section ): string {
	// Past access is dimmed and actionless — the Row's own unavailable state.
	if ( 'past' === $section ) {
		return Block::render(
			'gated-media-access/row',
			array(
				'title'            => (string) ( $item['title'] ?? '' ),
				'meta'             => (string) ( $item['meta'] ?? '' ),
				'state'            => 'unavailable',
				'unavailableLabel' => __( 'No longer available', 'gated-media-access' ),
			)
		);
	}

	$aside = Block::render(
		'gated-media-access/expiry',
		array(
			'state' => (string) ( $item['expiry_state'] ?? 'lifetime' ),
			'label' => (string) ( $item['expiry_label'] ?? '' ),
		)
	);

	// Downloading replaces the button with a progress statement; the rest of
	// the row is unchanged.
	$aside .= 'downloading' === $section
		? sprintf(
			'<span class="gatedmedia-text gatedmedia-text--meta" role="status">%s</span>',
			esc_html__( 'Downloading…', 'gated-media-access' )
		)
		: Block::render(
			'gated-media-access/button',
			array(
				'label'   => __( 'Download', 'gated-media-access' ),
				'href'    => (string) ( $item['href'] ?? '' ),
				'variant' => 'secondary',
				'icon'    => 'i-download',
			)
		);

	return Block::render(
		'gated-media-access/row',
		array(
			'title'       => (string) ( $item['title'] ?? '' ),
			'meta'        => (string) ( $item['meta'] ?? '' ),
			'actionLabel' => 'available' === $section ? __( 'Download', 'gated-media-access' ) : '',
			'actionHref'  => (string) ( $item['href'] ?? '' ),
			'actionIcon'  => 'i-download',
		),
		$aside
	);
};

/**
 * A heading over its rows, or nothing.
 *
 * @param string                           $heading The section label.
 * @param array<int, array<string, mixed>> $items   Rows to draw.
 * @param string                           $section Which section it is.
 * @param callable                         $row     Renders one row.
 */
$gatedmedia_section = static function ( string $heading, array $items, string $section, callable $row ): string {
	if ( array() === $items ) {
		return '';
	}

	$rows = '';

	foreach ( $items as $item ) {
		$rows .= $row( $item, $section );
	}

	return '<section class="gatedmedia-section">'
		. Block::render( 'gated-media-access/section-heading', array( 'text' => $heading ) )
		. $rows
		. '</section>';
};

$gatedmedia_body = $gatedmedia_section( __( 'Available', 'gated-media-access' ), $gatedmedia_available, 'available', $gatedmedia_row )
	. $gatedmedia_section( __( 'Downloading', 'gated-media-access' ), $gatedmedia_downloading, 'downloading', $gatedmedia_row )
	. $gatedmedia_section( __( 'Past access', 'gated-media-access' ), $gatedmedia_past, 'past', $gatedmedia_row );

if ( '' === $gatedmedia_body ) {
	$gatedmedia_body = Block::render(
		'gated-media-access/empty-state',
		array(
			'icon'    => 'i-files',
			'title'   => __( 'No files yet', 'gated-media-access' ),
			'message' => __( 'Files you are given access to will appear here, ready to download.', 'gated-media-access' ),
		)
	);
}
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-view gatedmedia-view--files' ) ) ); ?>>
	<?php
	echo $gatedmedia_filter; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the filter block.
	echo $gatedmedia_body;   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the blocks that produced it.
	?>
</div>
