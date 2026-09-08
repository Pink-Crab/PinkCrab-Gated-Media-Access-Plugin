<?php
/**
 * PHPUnit bootstrap.
 *
 * Boots wp-phpunit, activates this plugin, and reads a gitignored tests/.env for the DB credentials.
 *
 * `WP_PHPUNIT__DIR` locates the framework, so no vendor path is hard-coded. The plugin is activated inside `muplugins_loaded`, the same path WordPress takes in production.
 *
 * restrict-media-file-access is a hard runtime dependency, so any test covering the file boundary needs it installed alongside.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use Gin0115\WPUnit_Helpers\WP\WP_Dependencies;

// Load tests/.env if present. CI overrides via env vars.
try {
	\Dotenv\Dotenv::createUnsafeImmutable( __DIR__ )->safeLoad();
} catch ( \Throwable $e ) {
	// .env is optional: CI and containerised dev set env vars directly.
}

// Composer sets WP_PHPUNIT__DIR; fall back so a clean checkout still works.
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

// The dependency is installed from its GitHub release rather than composer, because the git tag ships no vendor/ and its main file bails before the rmfa_* functions exist.
define( 'TEST_WP_ROOT', dirname( __DIR__ ) . '/wordpress' );
// No directory prefix: the release zip has no top-level folder.
define( 'GATEDMEDIA_TEST_DEPENDENCY', 'restrict-media-file-access.php' );

// roots/wordpress-no-content has no wp-content/plugins, and ZipArchive will not make one.
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

// Loaded during WP's own sequence so it boots before any test method runs.
//
// By path rather than activate_plugin(), which takes a WP_PLUGIN_DIR-relative slug that resolves to nothing in a CI worktree and fails silently into a WP_Error.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// Activated rather than required, so core loads it and runs its activation path.
		WP_Dependencies::activate_plugin( GATEDMEDIA_TEST_DEPENDENCY );

		// Ours is required by path: it lives outside WP_PLUGIN_DIR.
		require_once dirname( __DIR__ ) . '/gated-media-access.php';
	}
);

// Boot WordPress + the test framework.
require $_phpunit_dir . '/includes/bootstrap.php';
