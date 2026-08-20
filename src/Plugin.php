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
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\File_Boundary;
use PinkCrab\Gated_Access\Access\Post_Boundary;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Restriction;
use PinkCrab\Gated_Access\Access\Sweep;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Account\View_Data;
use PinkCrab\Gated_Access\Admin\Access_Filters;
use PinkCrab\Gated_Access\Admin\Access_List;
use PinkCrab\Gated_Access\Admin\Add_Access_Page;
use PinkCrab\Gated_Access\Admin\Edit_Access_Page;
use PinkCrab\Gated_Access\Admin\Item_Access_Metabox;
use PinkCrab\Gated_Access\Admin\Picker_Search;
use PinkCrab\Gated_Access\Admin\Coupon_Metabox;
use PinkCrab\Gated_Access\Admin\Payments_Page;
use PinkCrab\Gated_Access\Admin\Product_Metabox;
use PinkCrab\Gated_Access\Admin\Profile_Access_List;
use PinkCrab\Gated_Access\Admin\Quick_Edit_Grant;
use PinkCrab\Gated_Access\Admin\Revoke_Action;

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
		// First on purpose: same-priority init callbacks fire in registration
		// order, and Account_Route flushes rewrites on init — the post types'
		// rules must exist by then.
		Post_Types::class,
		Access_Taxonomy::class,
		Capabilities::class,
		Access_Writer::class,
		// For its memo-honesty hooks only — everything else calls it.
		Resolver::class,
		Restriction::class,
		Post_Boundary::class,
		Sweep::class,
		Payments_Schema::class,
		Asset_Loader::class,
		Block_Registrar::class,
		Sprite::class,
		Account_Route::class,
		Profile_Writer::class,
		View_Data::class,
		Settings_Page::class,
		Access_Filters::class,
		Access_List::class,
		Add_Access_Page::class,
		Edit_Access_Page::class,
		Item_Access_Metabox::class,
		Product_Metabox::class,
		Coupon_Metabox::class,
		Picker_Search::class,
		Quick_Edit_Grant::class,
		Payments_Page::class,
		Profile_Access_List::class,
		Revoke_Action::class,
	);

	/**
	 * The built container, kept so the file boundary can resolve lazily.
	 *
	 * @var Dice|null
	 */
	private ?Dice $container = null;

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
		$container       = ( new Dice() )->addRule( '*', array( 'shared' => true ) );
		$this->container = $container;
		$loader          = new Hook_Loader();

		foreach ( self::SERVICES as $service ) {
			$instance = $container->create( $service );

			if ( $instance instanceof Hookable ) {
				$instance->register_hooks( $loader );
			}
		}

		$loader->register_hooks();
	}

	/**
	 * The file boundary, resolved through the shared container on first ask.
	 *
	 * The bootstrap attaches its two hooks at plugin load; nothing is built
	 * until the first protected-file request actually arrives.
	 */
	public function file_boundary(): File_Boundary {
		if ( null === $this->container ) {
			$this->container = ( new Dice() )->addRule( '*', array( 'shared' => true ) );
		}

		/**
		 * Dice builds the class it is named.
		 *
		 * @var File_Boundary $boundary
		 */
		$boundary = $this->container->create( File_Boundary::class );

		return $boundary;
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
