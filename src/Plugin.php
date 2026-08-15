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
		Settings_Page::class,
	);

	/**
	 * Builds each service, lets the hookable ones register, then attaches
	 * everything to WordPress in one pass.
	 */
	public function boot(): void {
		$container = new Dice();
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
