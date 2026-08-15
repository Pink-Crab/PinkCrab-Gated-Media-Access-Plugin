<?php
/**
 * §7.1 My Access — the landing view for the account area.
 *
 * Three sections, each a section heading (§6.9) over rows (§6.2): groups,
 * posts, files. A section with nothing in it is not rendered. The empty state
 * (§6.10) appears only when all three are empty — one box at page level, never
 * three stacked down the page.
 *
 * **The rows are not built yet.** Everything shown here comes from the resolver
 * (architecture.md §4), and the resolver is step 2 of §12 — it does not exist.
 * Until it does this renders the wholly-empty case, which is the truthful
 * answer for a site with no access records in it.
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

// Placeholder for the resolver's per-user picture. Once §4 lands these become
// its three buckets and nothing else on this page changes. The annotations are
// the shape the resolver will return — without them static analysis narrows
// each to the empty array and calls every check below unreachable.
/** @var array<int, array<string, mixed>> $gatedmedia_groups */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type annotation, not a description.
$gatedmedia_groups = array();
/** @var array<int, array<string, mixed>> $gatedmedia_posts */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type annotation, not a description.
$gatedmedia_posts = array();
/** @var array<int, array<string, mixed>> $gatedmedia_files */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type annotation, not a description.
$gatedmedia_files = array();

$gatedmedia_is_empty = array() === $gatedmedia_groups
	&& array() === $gatedmedia_posts
	&& array() === $gatedmedia_files;
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-view gatedmedia-view--my-access' ) ) ); ?>>

	<?php if ( $gatedmedia_is_empty ) : ?>

		<div class="gatedmedia-empty-state">
			<svg class="gatedmedia-empty-state__icon" aria-hidden="true" focusable="false"><use href="#i-empty"></use></svg>
			<p class="gatedmedia-empty-state__title"><?php esc_html_e( 'Nothing here yet', 'gated-media-access' ); ?></p>
			<p class="gatedmedia-text gatedmedia-text--meta">
				<?php esc_html_e( 'Anything you are given access to will appear here, with the date it runs out.', 'gated-media-access' ); ?>
			</p>
		</div>

	<?php else : ?>

		<?php if ( array() !== $gatedmedia_groups ) : ?>
		<section class="gatedmedia-section">
			<h2 class="gatedmedia-section-heading"><?php esc_html_e( 'Groups', 'gated-media-access' ); ?></h2>
		</section>
		<?php endif; ?>

		<?php if ( array() !== $gatedmedia_posts ) : ?>
		<section class="gatedmedia-section">
			<h2 class="gatedmedia-section-heading"><?php esc_html_e( 'Posts', 'gated-media-access' ); ?></h2>
		</section>
		<?php endif; ?>

		<?php if ( array() !== $gatedmedia_files ) : ?>
		<section class="gatedmedia-section">
			<h2 class="gatedmedia-section-heading"><?php esc_html_e( 'Files', 'gated-media-access' ); ?></h2>
		</section>
		<?php endif; ?>

	<?php endif; ?>

</div>
