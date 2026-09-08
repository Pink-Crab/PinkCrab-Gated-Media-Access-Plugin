<?php
/**
 * Registering and enqueuing the built assets.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Assets;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Admin\Picker_Search;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * Owns the four built bundles and decides where they load.
 *
 * **Registered early, enqueued late.** Registration happens on `init` because the account route renders on `template_redirect`, before `wp_enqueue_scripts`, and a handle enqueued there has to already exist.
 *
 * Registering enqueues nothing, so it costs a few array writes on requests that never use it.
 *
 * **Conditional, everywhere.** The front bundle reaches a page in exactly two ways: the account route asks for it, or a block declares it as its style in block.json and core loads it because that block is on the page.
 *
 * There is no `wp_enqueue_scripts` hook here that fires on every front-end request.
 *
 * The handles are public API: a third-party section renders inside our shell and needs our components, so it declares `gatedmedia-front` as a dependency and renaming it breaks their plugin as well as ours.
 */
class Asset_Loader implements Hookable {

	public const FRONT_STYLE  = 'gatedmedia-front';
	public const FRONT_SCRIPT = 'gatedmedia-front';
	public const ADMIN_STYLE  = 'gatedmedia-admin';
	public const ADMIN_SCRIPT = 'gatedmedia-admin';

	/**
	 * The block editor bundle.
	 *
	 * Its own handle rather than more code in the admin one: this loads only in the editor, through `enqueue_block_editor_assets`, and depends on `wp-editor` and `wp-plugins`, which the admin bundle has no use for.
	 */
	public const EDITOR_SCRIPT = 'gatedmedia-editor';

	/**
	 * Registers the bundles, and the admin enqueue.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register' ) );
		$loader->admin_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		$loader->admin_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
	}

	/**
	 * Registers all four. Enqueues none.
	 */
	public function register(): void {
		$front_style = $this->asset( 'css/front' );
		wp_register_style(
			self::FRONT_STYLE,
			GATEDMEDIA_DIR_URL . 'build/css/front.css',
			array(),
			$front_style['version']
		);

		$front_script = $this->asset( 'js/front' );
		wp_register_script(
			self::FRONT_SCRIPT,
			GATEDMEDIA_DIR_URL . 'build/js/front.js',
			$front_script['dependencies'],
			$front_script['version'],
			true
		);

		$admin_style = $this->asset( 'css/admin' );
		wp_register_style(
			self::ADMIN_STYLE,
			GATEDMEDIA_DIR_URL . 'build/css/admin.css',
			array(),
			$admin_style['version']
		);

		$admin_script = $this->asset( 'js/admin' );
		wp_register_script(
			self::ADMIN_SCRIPT,
			GATEDMEDIA_DIR_URL . 'build/js/admin.js',
			// The pickers ride jQuery UI's autocomplete, and wp-scripts detects only @wordpress imports, so the handle is added here.
			array_merge( $admin_script['dependencies'], array( 'jquery', 'jquery-ui-autocomplete' ) ),
			$admin_script['version'],
			true
		);

		$editor_script = $this->asset( 'js/editor' );
		wp_register_script(
			self::EDITOR_SCRIPT,
			GATEDMEDIA_DIR_URL . 'build/js/editor.js',
			$editor_script['dependencies'],
			$editor_script['version'],
			true
		);
	}

	/**
	 * The editor bundle, on the editors of types that can be restricted.
	 *
	 * `enqueue_block_editor_assets` rather than `admin_enqueue_scripts`: the status control is a slot fill, and the slot exists only once the editor has booted.
	 */
	public function enqueue_editor(): void {
		$screen = get_current_screen();

		if ( null === $screen || ! in_array( (string) $screen->post_type, Access_Taxonomy::object_types(), true ) ) {
			return;
		}

		wp_enqueue_script( self::EDITOR_SCRIPT );
	}

	/**
	 * Puts the front bundle on this request.
	 *
	 * Called by the account route. A block placed on someone else's page names the handle in its block.json instead, and core enqueues it only when that block is on the page.
	 */
	public function enqueue_front(): void {
		wp_enqueue_style( self::FRONT_STYLE );
		wp_enqueue_script( self::FRONT_SCRIPT );
	}

	/**
	 * Admin assets, on the screens that use them: our own pages, the list tables whose quick edit carries the grant picker, and the restrictable types' editors, where the item metabox's group button lives.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_admin( string $hook_suffix ): void {
		$screen_type = (string) ( get_current_screen()->post_type ?? '' );

		$our_page    = str_contains( $hook_suffix, 'gated-media-access' ) || str_contains( $hook_suffix, 'gatedmedia' );
		$access_list = 'edit.php' === $hook_suffix && Post_Types::ACCESS === $screen_type;
		$quick_edit  = 'edit.php' === $hook_suffix
			&& in_array( $screen_type, array_diff( Access_Taxonomy::object_types(), array( 'attachment' ) ), true );
		$editor      = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
			&& in_array( $screen_type, Access_Taxonomy::object_types(), true );

		if ( ! $our_page && ! $access_list && ! $quick_edit && ! $editor ) {
			return;
		}

		wp_enqueue_style( self::ADMIN_STYLE );
		wp_enqueue_script( self::ADMIN_SCRIPT );

		wp_add_inline_script(
			self::ADMIN_SCRIPT,
			'window.gatedmediaPicker = ' . (string) wp_json_encode( array( 'nonce' => wp_create_nonce( Picker_Search::NONCE ) ) ) . ';',
			'before'
		);
	}

	/**
	 * The dependencies and version wp-scripts worked out at build time.
	 *
	 * Falls back to the plugin version when the build has not been run, so a checkout without `npm run build` degrades to an unstyled page rather than a fatal error.
	 *
	 * @param string $name Entry name, e.g. `css/front`.
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	private function asset( string $name ): array {
		$path = GATEDMEDIA_DIR_PATH . 'build/' . $name . '.asset.php';

		if ( ! is_readable( $path ) ) {
			return array(
				'dependencies' => array(),
				'version'      => GATEDMEDIA_VERSION,
			);
		}

		$asset = require $path;

		return array(
			'dependencies' => is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			'version'      => is_string( $asset['version'] ?? null ) ? $asset['version'] : GATEDMEDIA_VERSION,
		);
	}
}
