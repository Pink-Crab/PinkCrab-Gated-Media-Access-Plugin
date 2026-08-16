<?php
/**
 * The boot loop.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access;

use Dice\Dice;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Settings\Settings_Page;
use PinkCrab\Gated_Access\Assets\Asset_Loader;
use PinkCrab\Gated_Access\Blocks\Block_Registrar;
use PinkCrab\Gated_Access\Blocks\Sprite;
use PinkCrab\Gated_Access\Account\Account_Route;
use PinkCrab\Gated_Access\Account\Profile_Writer;

/**
 * Builds every service through the container and attaches their hooks in one
 * pass.
 *
 * The list is fixed. Third-party code does not register services into it — it
 * extends through the plugin's own hooks.
 */
class Plugin {

	/**
	 * The service classes, in boot order.
	 *
	 * Note the file access filter will not live here. It has to be attached
	 * before `init` finishes — files are served on `parse_request`, before the
	 * main query — so it is attached at plugin load and resolves its service
	 * lazily on first call. That is the one exception to this list.
	 *
	 * @var array<class-string>
	 */
	private const SERVICES = array(
		Asset_Loader::class,
		Block_Registrar::class,
		Sprite::class,
		Account_Route::class,
		Profile_Writer::class,
		Settings_Page::class,
	);

	/**
	 * Builds each service, lets the hookable ones register, then attaches
	 * everything to WordPress in one pass.
	 */
	public function boot(): void {
		// Shared by default, which for a list of services is the only sane
		// reading: without it Dice hands out a fresh instance per resolution,
		// so a service holding state — the sprite knowing it has been asked
		// for, the registry memoising the section list — would be answering
		// about an object nobody else has.
		$container = ( new Dice() )->addRule( '*', array( 'shared' => true ) );
		$loader    = new Hook_Loader();

		foreach ( self::SERVICES as $service ) {
			$instance = $container->create( $service );

			if ( $instance instanceof Hookable ) {
				$instance->register_hooks( $loader );
			}
		}

		$loader->register_hooks();
	}

	/**
	 * The notice shown when restrict-media-file-access is missing or inactive.
	 *
	 * Nothing else of ours runs in that state.
	 */
	public static function render_missing_dependency_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p><p><a href="%s">%s</a></p></div>',
			esc_html__(
				'Gated Media Access needs the Restrict Media File Access plugin, which is not active. Until it is, no files are protected and nothing else in this plugin runs.',
				'gated-media-access'
			),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Go to Plugins', 'gated-media-access' )
		);
	}
}
