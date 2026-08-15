<?php
/**
 * The settings screen.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;

/**
 * The plugin's top-level menu and its settings screen.
 *
 * Empty for now — it registers the menu and renders a heading. The Settings
 * API fields (Stripe keys and mode, email templates, account route, revoke
 * behaviour, profile prompt) come later.
 */
class Settings_Page implements Hookable {

	/**
	 * The menu slug, and the page's `page` query arg.
	 */
	public const MENU_SLUG = 'gated-media-access';

	/**
	 * Registers the menu.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Adds the top-level menu and its first page.
	 *
	 * Gated on `manage_options` until `gatedmedia_manage_settings` is granted
	 * on activation; swap it then.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Gated Media Access', 'gated-media-access' ),
			__( 'Gated Access', 'gated-media-access' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-lock'
		);
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		printf(
			'<div class="wrap"><h1>%s</h1><p>%s</p></div>',
			esc_html__( 'Gated Media Access', 'gated-media-access' ),
			esc_html__( 'No settings yet.', 'gated-media-access' )
		);
	}
}
