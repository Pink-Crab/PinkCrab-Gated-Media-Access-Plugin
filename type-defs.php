<?php
/**
 * Type definitions for static analysis.
 *
 * Plugin-defined constants are declared here (with empty values) so PHPStan,
 * Psalm, IDE intellisense, etc. can resolve them without executing the main
 * plugin file. Referenced by .phpstan.neon via `parameters.bootstrapFiles`.
 *
 * Do NOT require this file at runtime — the real values are set by the main
 * plugin file's `define()` calls.
 *
 * @since   0.1.0
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

const GATEDMEDIA_VERSION  = '';
const GATEDMEDIA_BASENAME = '';
const GATEDMEDIA_DIR_PATH = '';
const GATEDMEDIA_DIR_URL  = '';
