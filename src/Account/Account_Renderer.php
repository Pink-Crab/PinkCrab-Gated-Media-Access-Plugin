<?php
/**
 * The account shell.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

/**
 * Draws ui-spec.md §7.0 — the frame every account view sits in — and fills its
 * main column with the current section's block.
 *
 * **It returns markup, not a document.** The account area is a virtual page:
 * the theme renders its own header, navigation and footer, and this is the
 * content inside them. A plugin distributed for other people's sites does not
 * get to replace their theme, and a person on the account page still expects
 * the site around it.
 *
 * That is a deliberate departure from the corpus on one point. §7.0 narrow
 * specifies a 56px top bar carrying the site name; inside a theme the theme's
 * own header already is that, so ours would be a second one. The tab strip
 * beneath it is kept — it is section navigation, which the theme does not
 * provide. Everything else in §7.0 is drawn as specified.
 *
 * Blocks placed by an administrator on their own page are the other route, and
 * there the theme supplies the frame in exactly the same way — which is why the
 * shell lives here rather than inside the blocks.
 */
class Account_Renderer {

	/**
	 * The whole shell, as a string.
	 *
	 * Returned rather than echoed because it becomes a virtual post's content,
	 * and has to exist before the theme starts rendering.
	 *
	 * @param Account_Section    $current  The section being viewed.
	 * @param Section_Collection $sections Everything in the nav — already filtered to this user.
	 * @param string             $detail   The optional second URL segment.
	 */
	public function markup( Account_Section $current, Section_Collection $sections, string $detail = '' ): string {
		ob_start();
		?>
<div class="gatedmedia gatedmedia-account alignwide">
		<?php $this->sidebar( $current, $sections ); ?>

	<div class="gatedmedia-account__body">
		<?php $this->tabs( $current, $sections ); ?>

		<div class="gatedmedia-account__main">
			<?php $this->page_header( $current ); ?>
			<?php $this->section_content( $current, $detail ); ?>
		</div>
	</div>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The icon sprite.
	 *
	 * Inlined rather than referenced as an external file because `<use>` across
	 * documents is not reliably supported, and a nav whose icons silently fail
	 * in one browser is worse than a kilobyte of markup. Printed in the footer
	 * so it is out of the way of the theme's own markup.
	 */
	public function sprite(): void {
		$path = GATEDMEDIA_DIR_PATH . 'assets/icons.svg';

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, not a remote request.
		$sprite = file_get_contents( $path );

		if ( false === $sprite ) {
			return;
		}

		echo wp_kses(
			$sprite,
			array(
				'svg'    => array(
					'xmlns'       => true,
					'width'       => true,
					'height'      => true,
					'style'       => true,
					'aria-hidden' => true,
					'focusable'   => true,
				),
				'defs'   => array(),
				'symbol' => array(
					'id'      => true,
					'viewbox' => true,
				),
				'path'   => array(
					'fill' => true,
					'd'    => true,
				),
			)
		);
	}

	/**
	 * §7.0 wide — the sidebar. Hidden below 782px, where the tab strip replaces
	 * it entirely.
	 *
	 * The "Account" wordmark is not a heading (§2 conflict 4): the page's one
	 * h1 is the section title in the main column.
	 *
	 * @param Account_Section    $current  The section being viewed.
	 * @param Section_Collection $sections Everything in the nav.
	 */
	private function sidebar( Account_Section $current, Section_Collection $sections ): void {
		?>
	<div class="gatedmedia-account__sidebar">
		<div class="gatedmedia-account__brand">
			<span class="gatedmedia-heading gatedmedia-heading--page"><?php esc_html_e( 'Account', 'gated-media-access' ); ?></span>
			<p class="gatedmedia-text gatedmedia-text--meta"><?php esc_html_e( 'Manage your access', 'gated-media-access' ); ?></p>
		</div>

		<nav class="gatedmedia-account-nav" aria-label="<?php esc_attr_e( 'Account', 'gated-media-access' ); ?>">
			<?php foreach ( $sections as $section ) : ?>
				<?php $is_current = $section->slug() === $current->slug(); ?>
			<a
				class="gatedmedia-account-nav__item<?php echo $is_current ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( $this->url( $section ) ); ?>"
				<?php echo $is_current ? 'aria-current="page"' : ''; ?>
			>
				<?php $this->icon( $section->icon() ); ?>
				<span><?php echo esc_html( $section->menu_label() ); ?></span>
			</a>
			<?php endforeach; ?>
		</nav>
	</div>
		<?php
	}

	/**
	 * §7.0 narrow — the scrolling tab strip. Same sections as the sidebar, so
	 * the two cannot disagree.
	 *
	 * @param Account_Section    $current  The section being viewed.
	 * @param Section_Collection $sections Everything in the nav.
	 */
	private function tabs( Account_Section $current, Section_Collection $sections ): void {
		?>
		<nav class="gatedmedia-tab-strip" aria-label="<?php esc_attr_e( 'Account sections', 'gated-media-access' ); ?>">
			<?php foreach ( $sections as $section ) : ?>
				<?php $is_current = $section->slug() === $current->slug(); ?>
			<a
				class="gatedmedia-tab-strip__item<?php echo $is_current ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( $this->url( $section ) ); ?>"
				<?php echo $is_current ? 'aria-current="page"' : ''; ?>
			>
				<?php $this->icon( $section->icon(), 'gatedmedia-icon--small' ); ?>
				<span><?php echo esc_html( $section->menu_label() ); ?></span>
			</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * The line beneath the page title saying what the page is for.
	 *
	 * **The title itself is the theme's.** The virtual page is titled with the
	 * section, so the theme renders it as the page heading — rendering our own
	 * as well would print it twice and put two h1s on the page, which §2
	 * conflict 4 settled against.
	 *
	 * My Access is drawn with no sub-line, so an empty description renders
	 * nothing rather than an empty paragraph, and nothing here renders at all.
	 *
	 * @param Account_Section $current The section being viewed.
	 */
	private function page_header( Account_Section $current ): void {
		if ( '' === $current->description() ) {
			return;
		}
		?>
			<header class="gatedmedia-page-intro">
				<p class="gatedmedia-text gatedmedia-text--meta"><?php echo esc_html( $current->description() ); ?></p>
			</header>
		<?php
	}

	/**
	 * The section's block, rendered through the block itself.
	 *
	 * `do_blocks()` on a block comment rather than calling a render callback
	 * directly, so this route and an editor-placed block go through exactly the
	 * same code and produce exactly the same markup.
	 *
	 * @param Account_Section $current The section being viewed.
	 * @param string          $detail  The optional second URL segment.
	 */
	private function section_content( Account_Section $current, string $detail ): void {
		$block = $current->block();

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $block ) ) {
			$this->missing_block( $block );
			return;
		}

		$attributes = '' === $detail
			? ''
			: ' ' . (string) wp_json_encode( array( 'detail' => $detail ) );

		echo do_blocks( sprintf( '<!-- wp:%s%s /-->', $block, $attributes ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output is escaped by the block.
	}

	/**
	 * A section naming a block that is not registered.
	 *
	 * Only reachable through a third party's own mistake, so it says which
	 * block rather than failing silently — and it stays inside the shell so the
	 * rest of the account area still works.
	 *
	 * @param string $block The block name that is missing.
	 */
	private function missing_block( string $block ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		printf(
			'<div class="gatedmedia-notice gatedmedia-notice--error"><div class="gatedmedia-notice__body">%s</div></div>',
			esc_html(
				sprintf(
					/* translators: %s: block name, e.g. my-plugin/subscriptions */
					__( 'The block "%s" is not registered, so this section cannot render.', 'gated-media-access' ),
					$block
				)
			)
		);
	}

	/**
	 * A sprite icon. Nothing renders when the section named a symbol we do not
	 * ship — a missing icon is not worth a broken page.
	 *
	 * @param string $symbol   Symbol id, e.g. `i-files`.
	 * @param string $modifier Extra class — `gatedmedia-icon--small` for the 16px size.
	 */
	private function icon( string $symbol, string $modifier = '' ): void {
		if ( '' === $symbol ) {
			return;
		}

		printf(
			'<svg class="%s" aria-hidden="true" focusable="false"><use href="#%s"></use></svg>',
			esc_attr( trim( 'gatedmedia-icon ' . $modifier ) ),
			esc_attr( $symbol )
		);
	}

	/**
	 * The URL for a section.
	 *
	 * The first section is reachable at the bare route as well as at its own
	 * slug; it is linked at its slug so the nav's URLs and the address bar
	 * agree once you have clicked something.
	 *
	 * @param Account_Section $section The section to link to.
	 */
	private function url( Account_Section $section ): string {
		$slug = apply_filters( 'gatedmedia_account_slug', 'account' );
		$slug = is_string( $slug ) && '' !== $slug ? $slug : 'account';

		return home_url( sprintf( '/%s/%s/', $slug, $section->slug() ) );
	}
}
