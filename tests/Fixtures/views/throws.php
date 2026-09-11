<?php
/**
 * Fixture: prints, then throws part way through.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

echo 'started';

throw new \RuntimeException( 'template blew up' );
