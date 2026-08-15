<?php
/**
 * §7.2 Files — everything the person can download.
 *
 * The filter (§6.11) sits directly under the page header and carries a search
 * field **and** a type filter at every width: wide draws a select beside the
 * search, narrow swaps the select for chips and keeps the search. Dropping
 * search on the device most likely to have a long list is backwards, which is
 * why §8 conflict 8 settled it that way.
 *
 * Then three sections — available, downloading, past access — each a section
 * heading (§6.9) over rows (§6.2). This view uses a Download **button** and
 * keeps the expiry on the right, where My Access uses a text link and folds the
 * expiry into the meta line: Files is the view where downloading is the point,
 * so it gets the heavier control.
 *
 * **The rows are not built yet** — same reason as §7.1. The resolver is step 2
 * of architecture.md §12. The filter renders because it is part of the view's
 * structure; it has nothing to filter until then.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_user_id = get_current_user_id();

if ( 0 === $gatedmedia_user_id ) {
	return;
}

// Placeholders for the resolver's answer — annotated with the shape it will
// return, so static analysis does not narrow them to the empty array and call
// every branch below unreachable.
/** @var array<int, array<string, mixed>> $gatedmedia_available */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type annotation, not a description.
$gatedmedia_available = array();
/** @var array<int, array<string, mixed>> $gatedmedia_downloading */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type annotation, not a description.
$gatedmedia_downloading = array();
/** @var array<int, array<string, mixed>> $gatedmedia_past */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type annotation, not a description.
$gatedmedia_past = array();

$gatedmedia_is_empty = array() === $gatedmedia_available
	&& array() === $gatedmedia_downloading
	&& array() === $gatedmedia_past;

// §6.11 — the type filter. Chips on narrow, a select on wide; one list either
// way so the two cannot drift.
$gatedmedia_types = array(
	'all'   => __( 'All', 'gated-media-access' ),
	'pdf'   => __( 'PDF', 'gated-media-access' ),
	'video' => __( 'Video', 'gated-media-access' ),
	'zip'   => __( 'ZIP', 'gated-media-access' ),
	'audio' => __( 'Audio', 'gated-media-access' ),
);
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-view gatedmedia-view--files' ) ) ); ?>>

	<div class="gatedmedia-filter">
		<div class="gatedmedia-filter__search gatedmedia-field">
			<label class="gatedmedia-visually-hidden" for="gatedmedia-file-search">
				<?php esc_html_e( 'Search files', 'gated-media-access' ); ?>
			</label>
			<input
				class="gatedmedia-field__input"
				type="search"
				id="gatedmedia-file-search"
				data-gatedmedia-filter="search"
				placeholder="<?php esc_attr_e( 'Search files', 'gated-media-access' ); ?>"
			>
		</div>

		<label class="gatedmedia-visually-hidden" for="gatedmedia-file-type">
			<?php esc_html_e( 'Filter by type', 'gated-media-access' ); ?>
		</label>
		<select class="gatedmedia-filter__type" id="gatedmedia-file-type" data-gatedmedia-filter="type">
			<?php foreach ( $gatedmedia_types as $gatedmedia_value => $gatedmedia_label ) : ?>
			<option value="<?php echo esc_attr( $gatedmedia_value ); ?>"><?php echo esc_html( $gatedmedia_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="gatedmedia-type-chips" role="group" aria-label="<?php esc_attr_e( 'Filter by type', 'gated-media-access' ); ?>">
		<?php foreach ( $gatedmedia_types as $gatedmedia_value => $gatedmedia_label ) : ?>
		<button
			type="button"
			class="gatedmedia-type-chips__chip<?php echo 'all' === $gatedmedia_value ? ' is-active' : ''; ?>"
			data-gatedmedia-type-chips__chip="<?php echo esc_attr( $gatedmedia_value ); ?>"
			aria-pressed="<?php echo 'all' === $gatedmedia_value ? 'true' : 'false'; ?>"
		><?php echo esc_html( $gatedmedia_label ); ?></button>
		<?php endforeach; ?>
	</div>

	<?php if ( $gatedmedia_is_empty ) : ?>

		<div class="gatedmedia-empty-state">
			<svg class="gatedmedia-empty-state__icon" aria-hidden="true" focusable="false"><use href="#i-files"></use></svg>
			<p class="gatedmedia-empty-state__title"><?php esc_html_e( 'No files yet', 'gated-media-access' ); ?></p>
			<p class="gatedmedia-text gatedmedia-text--meta">
				<?php esc_html_e( 'Files you are given access to will appear here, ready to download.', 'gated-media-access' ); ?>
			</p>
		</div>

	<?php endif; ?>

</div>
