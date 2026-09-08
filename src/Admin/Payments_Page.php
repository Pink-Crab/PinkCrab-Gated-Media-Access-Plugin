<?php
/**
 * The payments screen.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Settings\Settings_Page;

/**
 * The Payments submenu entry and its read-only list, behind `gatedmedia_view_payments`.
 */
class Payments_Page implements Hookable {

	/** The page's `page` query arg. */
	public const PAGE_SLUG = 'gatedmedia-payments';

	/**
	 * Reads only.
	 *
	 * @param Payment_Store $store The payments table's owner.
	 */
	public function __construct( private Payment_Store $store ) {
	}

	/**
	 * The menu entry.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Adds the page under the plugin menu, behind the view capability.
	 */
	public function register_page(): void {
		add_submenu_page(
			Settings_Page::MENU_SLUG,
			__( 'Payments', 'gated-media-access' ),
			__( 'Payments', 'gated-media-access' ),
			Capabilities::view_payments(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The list, and nothing else: no form, no actions, no buttons.
	 */
	public function render(): void {
		$table = $this->build_table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payments', 'gated-media-access' ); ?></h1>
			<?php $table->display(); ?>
		</div>
		<?php
	}

	/**
	 * The table, with its parent class loaded first: WP_List_Table exists only once an admin screen has included it, and the integration suite has no admin screen.
	 */
	public function build_table(): Payments_List_Table {
		if ( ! class_exists( \WP_List_Table::class ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		return new Payments_List_Table( $this->store );
	}
}
