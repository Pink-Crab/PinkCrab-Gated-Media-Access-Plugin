<?php
/**
 * The Gated Media Access bootstrap file.
 *
 * @package PinkCrab\Gated_Access
 *
 * @wordpress-plugin
 * Plugin Name:             Gated Media Access
 * Plugin URI:              https://github.com/Pink-Crab/gated-media-access
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
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Plugin;

defined( 'ABSPATH' ) || exit;

define( 'GATEDMEDIA_VERSION', '0.1.0' );
define( 'GATEDMEDIA_BASENAME', plugin_basename( __FILE__ ) );
define( 'GATEDMEDIA_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'GATEDMEDIA_DIR_URL', plugin_dir_url( __FILE__ ) );

require_once GATEDMEDIA_DIR_PATH . 'vendor/autoload.php';

/**
 * Boots the plugin, once restrict-media-file-access is confirmed present.
 *
 * That plugin owns the files — it moves them, serves them and refuses them.
 * Without it we would look alive while files sat unprotected, so we do not
 * half-run: we show a notice and boot nothing.
 *
 * The check is a runtime one rather than the `Requires Plugins` header,
 * because the dependency is not distributed through wordpress.org.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'RESTRICT_MEDIA_FILE_ACCESS_BASENAME' ) ) {
			add_action( 'admin_notices', array( Plugin::class, 'render_missing_dependency_notice' ) );
			return;
		}

		( new Plugin() )->boot();
	}
);
