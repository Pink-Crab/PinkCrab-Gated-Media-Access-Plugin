<?php
/**
 * The account area's frame: sidebar, tab strip, and the section's own block.
 *
 * The page title is the theme's. The virtual page is titled with the section, so printing our own here would put two h1s on the page, and the wordmark is deliberately not a heading.
 *
 * The sidebar is hidden below 782px, where the tab strip replaces it.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{nav: string, tabs: string, description: string, content: string} $data
 */

?>
<div class="gatedmedia gatedmedia-account alignwide">
	<div class="gatedmedia-account__sidebar">
		<div class="gatedmedia-account__brand">
			<span class="gatedmedia-heading gatedmedia-heading--page"><?php esc_html_e( 'Account', 'gated-media-access' ); ?></span>
			<p class="gatedmedia-text gatedmedia-text--meta"><?php esc_html_e( 'Manage your access', 'gated-media-access' ); ?></p>
		</div>

		<?php echo $data['nav']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the nav block. ?>
	</div>

	<div class="gatedmedia-account__body">
		<?php echo $data['tabs']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the nav block. ?>

		<div class="gatedmedia-account__main">
			<?php if ( '' !== $data['description'] ) : ?>
				<header class="gatedmedia-page-intro">
					<p class="gatedmedia-text gatedmedia-text--meta"><?php echo esc_html( $data['description'] ); ?></p>
				</header>
			<?php endif; ?>

			<?php echo $data['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the section's own block. ?>
		</div>
	</div>
</div>
