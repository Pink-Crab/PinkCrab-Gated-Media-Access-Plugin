<?php
/**
 * PHPUnit bootstrap.
 *
 * Boots wp-phpunit, activates this plugin, and exposes a tests/.env
 * (gitignored) for the DB credentials.
 *
 *   - `WP_PHPUNIT__DIR` (set by wp-phpunit's composer install) locates the
 *     framework. No hard-coded vendor path.
 *   - `tests/.env` (read via vlucas/phpdotenv) supplies DB credentials. A
 *     `tests/.env_sample` template ships in the repo; copy to .env and edit.
 *   - The plugin is activated through `activate_plugin()` inside
 *     `muplugins_loaded` — the same path WordPress would take in production.
 *
 * Note: restrict-media-file-access is a hard runtime dependency. Where it is
 * absent this plugin deliberately refuses to boot, so any integration test
 * covering the file boundary needs it installed alongside.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use Gin0115\WPUnit_Helpers\WP\WP_Dependencies;

// Load tests/.env if present (silently — CI overrides via env vars).
try {
	\Dotenv\Dotenv::createUnsafeImmutable( __DIR__ )->safeLoad();
} catch ( \Throwable $e ) {
	// .env optional — CI / containerised dev set env vars directly.
}

// Locate wp-phpunit. wp-phpunit's composer install sets WP_PHPUNIT__DIR for us;
// fall back to the conventional path so a clean checkout still works.
$_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! is_string( $_phpunit_dir ) || ! is_dir( $_phpunit_dir ) ) {
	$_phpunit_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}
if ( ! is_dir( $_phpunit_dir ) ) {
	fwrite(
		STDERR,
		"ERROR: wp-phpunit not found at {$_phpunit_dir}.\n" .
		"       Run `composer install` first.\n"
	);
	exit( 1 );
}

require_once $_phpunit_dir . '/includes/functions.php';

// restrict-media-file-access is a hard runtime dependency — without it this
// plugin refuses to boot, so the suite would only ever exercise the guard.
//
// It is installed from its public GitHub release rather than through composer,
// because the git tag ships no vendor/ directory: its main file bails at
// `if ( ! is_file( … . '/vendor/autoload.php' ) ) { … return; }` before
// requiring functions.php, so the rmfa_* functions never exist. The release
// zip is a built artifact and does include vendor/.
define( 'TEST_WP_ROOT', dirname( __DIR__ ) . '/wordpress' );
// No directory prefix: the release zip has no top-level folder, so it unpacks
// straight into wp-content/plugins/ and the main file sits at its root.
define( 'GATEDMEDIA_TEST_DEPENDENCY', 'restrict-media-file-access.php' );

// roots/wordpress-no-content ships without wp-content/plugins, and
// ZipArchive::extractTo() will not create it.
if ( ! is_dir( TEST_WP_ROOT . '/wp-content/plugins' ) ) {
	mkdir( TEST_WP_ROOT . '/wp-content/plugins', 0777, true );
}

if ( ! WP_Dependencies::plugin_installed( GATEDMEDIA_TEST_DEPENDENCY, TEST_WP_ROOT ) ) {
	try {
		WP_Dependencies::install_remote_plugin_from_zip(
			'https://github.com/a8cteam51/restrict-media-file-access/releases/download/v1.4.2/restrict-media-file-access.zip',
			TEST_WP_ROOT
		);
	} catch ( \Throwable $th ) {
		fwrite( STDERR, "ERROR: could not install restrict-media-file-access.\n" . $th->getMessage() . "\n" );
		exit( 1 );
	}
}

// Load the plugin during WP's load sequence so it boots and registers before
// any test method runs.
//
// Required directly rather than through activate_plugin(), because that takes
// a WP_PLUGIN_DIR-relative slug and so depends on the checkout sitting in a
// directory named after the plugin. It does locally; on a CI runner the
// checkout is a worktree with an arbitrary name, and the slug resolves to
// nothing — activate_plugin() then fails silently into a WP_Error and the
// plugin never loads.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// Activating here rather than requiring the file: wp-settings.php
		// includes active plugins immediately after this action, so core loads
		// the dependency itself, running its activation path as it would in
		// production.
		WP_Dependencies::activate_plugin( GATEDMEDIA_TEST_DEPENDENCY );

		// Ours is required by path — it lives outside WP_PLUGIN_DIR, and its
		// guard runs later on plugins_loaded, by which point core has included
		// the dependency above.
		require_once dirname( __DIR__ ) . '/gated-media-access.php';
	}
);

// Boot WordPress + the test framework.
require $_phpunit_dir . '/includes/bootstrap.php';
