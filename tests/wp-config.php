<?php
/**
 * Test-suite wp-config.
 *
 * Loaded by wp-phpunit through `WP_PHPUNIT__TESTS_CONFIG`. It defines the constants WordPress expects and never requires wp-settings.php, which wp-phpunit's own bootstrap does.
 *
 * DB credentials come from env vars, usually populated from tests/.env.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

// WordPress lives at <plugin-root>/wordpress/, installed by roots/wordpress.
define( 'ABSPATH', dirname( __DIR__ ) . '/wordpress/' );

// The test install's plugins directory, where the bootstrap unzips the dependency.
define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) . '/wordpress/wp-content/plugins' );

// CI exports `environment_github=true` and matches its own MySQL service; locally tests/.env supplies WP_DB_*.
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

// Salts. Irrelevant for tests, but WordPress demands them.
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

// Core notices wp_is_block_theme before the theme directory registers and PHPUnit turns that into an error in a process-isolated test, per core.trac ticket 63086.
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test bootstrap.
	function ( $errno, $errstr ) {
		return E_USER_NOTICE === $errno && false !== strpos( $errstr, 'wp_is_block_theme' );
	},
	E_USER_NOTICE
);
