<?php
/**
 * The Gated Media Access bootstrap file.
 *
 * @package PinkCrab\Gated_Access
 *
 * @wordpress-plugin
 * Plugin Name:             Gated Media Access
 * Plugin URI:              https://github.com/Pink-Crab/PinkCrab-Gated-Media-Access-Plugin
 * Description:             Gated access to documents, media and posts. Access is granted by on-site payment, by an administrator, or by webhook.
 * Version:                 0.1.0
 * Requires at least:       6.4
 * Requires PHP:            8.3
 * Author:                  Glynn Quelch
 * Author URI:              https://github.com/Pink-Crab
 * License:                 GPL v3 or later
 * License URI:             https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:             gated-media-access
 * Domain Path:             /languages
 * Update URI:              https://github.com/Pink-Crab/PinkCrab-Gated-Media-Access-Plugin
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Plugin;
use PinkCrab\Gated_Access\Registration\Lifecycle;
use PinkCrab\Gated_Access\Updates\Github_Updater;

defined( 'ABSPATH' ) || exit;

define( 'GATEDMEDIA_VERSION', '0.1.0' );
define( 'GATEDMEDIA_BASENAME', plugin_basename( __FILE__ ) );
define( 'GATEDMEDIA_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'GATEDMEDIA_DIR_URL', plugin_dir_url( __FILE__ ) );

require_once GATEDMEDIA_DIR_PATH . 'vendor/autoload.php';

// Outside the boot below on purpose: the two daily events must be cleared even where restrict-media-file-access has gone and nothing booted.
register_deactivation_hook( __FILE__, array( Lifecycle::class, 'deactivate' ) );

// Outside it for the same reason: an update is how a broken install is repaired, so it must be offered to a site where nothing else of ours is running.
( new Github_Updater() )->attach();

/**
 * Boots the plugin, once restrict-media-file-access is confirmed present.
 *
 * That plugin owns the files, moving them, serving them and refusing them, so without it this would look alive while files sat unprotected, and rather than half-run it shows a notice and boots nothing.
 *
 * The check is a runtime one rather than the `Requires Plugins` header, because the dependency is not distributed through wordpress.org.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'RESTRICT_MEDIA_FILE_ACCESS_BASENAME' ) ) {
			add_action( 'admin_notices', array( Plugin::class, 'render_missing_dependency_notice' ) );
			return;
		}

		$plugin = new Plugin();
		$plugin->boot();

		// The file boundary attaches here rather than in Plugin::SERVICES, because files are served on parse_request before the main query, and the class behind both hooks is built on the first call rather than now.
		add_filter(
			'restrict_media_file_access_protect_file',
			static fn ( $refuse, $protected_file ): bool => $plugin->file_boundary()->protect_file( (bool) $refuse, (string) $protected_file ),
			10,
			2
		);

		add_action(
			'restrict_media_file_access_before_serve',
			static function ( $attachment_id, $file_path ) use ( $plugin ): void {
				$plugin->file_boundary()->announce_download( (int) $attachment_id, (string) $file_path );
			},
			10,
			2
		);
	}
);
