<?php
/**
 * What deleting the plugin removes.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Registration\Lifecycle;

// Core defines this only when it is genuinely uninstalling us.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

Lifecycle::uninstall();
