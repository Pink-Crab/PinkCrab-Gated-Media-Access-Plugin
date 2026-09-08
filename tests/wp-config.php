<?php
/**
 * Test-suite wp-config.
 *
 * Loaded by wp-phpunit via the WP_PHPUNIT__TESTS_CONFIG env var (set in
 * phpunit.xml.dist). Defines the constants WordPress expects; never
 * `require wp-settings.php` here — wp-phpunit's bootstrap does that.
 *
 * DB credentials come from env vars (typically populated from tests/.env;
 * see tests/.env_sample for the template).
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

// WordPress lives at <plugin-root>/wordpress/ (installed by roots/wordpress
// via the roots/wordpress-core-installer composer plugin).
define( 'ABSPATH', dirname( __DIR__ ) . '/wordpress/' );

// The test install's own plugins directory — where the bootstrap unzips
// restrict-media-file-access, and where core looks when it includes active
// plugins. This plugin itself is not in here; the bootstrap requires it by
// path, so nothing depends on where the checkout lands.
define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) . '/wordpress/wp-content/plugins' );

// Database. CI vs local split: the CI workflows export `environment_github=true`
// and the branch below matches the MySQL service they spin up. Locally, a
// tests/.env supplies WP_DB_* via vlucas/phpdotenv (loaded in tests/bootstrap.php).
if ( getenv( 'environment_github' ) ) {
	define( 'DB_NAME',     'wordpress_test' );
	define( 'DB_USER',     'root' );
	define( 'DB_PASSWORD', 'root' );
	define( 'DB_HOST',     '127.0.0.1' );
} else {
	define( 'DB_NAME',     getenv( 'WP_DB_NAME' ) ?: 'wordpress_test' );
	define( 'DB_USER',     getenv( 'WP_DB_USER' ) ?: 'root' );
	define( 'DB_PASSWORD', getenv( 'WP_DB_PASS' ) ?: '' );
	define( 'DB_HOST',     getenv( 'WP_DB_HOST' ) ?: '127.0.0.1' );
}
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = getenv( 'WP_TESTS_TABLE_PREFIX' ) ?: 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL',  'admin@example.org' );
define( 'WP_TESTS_TITLE',  'Gated Media Access Tests' );

define( 'WP_PHP_BINARY', 'php' );

// Salts — irrelevant for tests, WP demands them.
define( 'AUTH_KEY',         'phpunit-key' );
define( 'SECURE_AUTH_KEY',  'phpunit-key' );
define( 'LOGGED_IN_KEY',    'phpunit-key' );
define( 'NONCE_KEY',        'phpunit-key' );
define( 'AUTH_SALT',        'phpunit-salt' );
define( 'SECURE_AUTH_SALT', 'phpunit-salt' );
define( 'LOGGED_IN_SALT',   'phpunit-salt' );
define( 'NONCE_SALT',       'phpunit-salt' );

define( 'WPLANG',   '' );
define( 'WP_DEBUG', true );

// Core calls wp_is_block_theme before the theme directory is registered, which
// is a notice PHPUnit turns into an error in a test running in its own process.
// @see https://core.trac.wordpress.org/ticket/63086
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test bootstrap.
	function ( $errno, $errstr ) {
		return E_USER_NOTICE === $errno && false !== strpos( $errstr, 'wp_is_block_theme' );
	},
	E_USER_NOTICE
);
