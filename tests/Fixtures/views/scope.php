<?php
/**
 * Fixture: reports what the template can see of the calling scope.
 *
 * @package PinkCrab\Gated_Access\Tests
 *
 * @var array<string, mixed> $data
 */

echo isset( $this ) ? 'has-this' : 'no-this';
echo isset( $data ) ? ':has-data' : ':no-data';
echo isset( $leaked ) ? ':leaked' : ':clean';
