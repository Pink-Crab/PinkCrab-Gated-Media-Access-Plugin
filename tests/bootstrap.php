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

// Activate the plugin during WP's load sequence so it boots and registers
// before any test method runs.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Core's activate_plugin() → validate_plugin_requirements() →
		// get_plugin_data( translate: true ) translates the plugin headers. At
		// muplugins_loaded that is before init, which trips WP 6.7's
		// _load_textdomain_just_in_time notice — core behaviour we cannot avoid
		// when activating during the test bootstrap. Silence ONLY that notice,
		// ONLY around this call; removed immediately after so any genuine
		// early-translation bug in plugin code still fails loudly.
		$jit_filter = static function ( $trigger, $function_name ) {
			return '_load_textdomain_just_in_time' === $function_name ? false : $trigger;
		};
		add_filter( 'doing_it_wrong_trigger_error', $jit_filter, 10, 2 );

		activate_plugin( 'gated-media-access/gated-media-access.php' );

		remove_filter( 'doing_it_wrong_trigger_error', $jit_filter );
	}
);

// Boot WordPress + the test framework.
require $_phpunit_dir . '/includes/bootstrap.php';
