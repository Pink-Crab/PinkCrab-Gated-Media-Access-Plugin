<?php
/**
 * The payment path's outbound call and its table.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Two costs the buy request carries: an outbound call with no ceiling on how long it may hold the PHP worker, and a coupon count that scans the payments table on columns nothing indexes.
 *
 * @group integration
 */
class Test_Payments_Performance extends WP_UnitTestCase {

	/** @testdox The Stripe client is pinned to a timeout and a retry count. */
	public function test_the_stripe_client_is_pinned(): void {
		$config = ( new Stripe_Gateway( new Settings() ) )->client_config();

		$this->assertArrayHasKey( 'timeout', $config );
		$this->assertArrayHasKey( 'connect_timeout', $config );
		$this->assertArrayHasKey( 'max_network_retries', $config );

		$this->assertLessThanOrEqual( 15, $config['timeout'], 'a Stripe stall must not hold a worker for long' );
		$this->assertLessThanOrEqual( 5, $config['connect_timeout'] );
	}

	/** @testdox The coupon count has an index to read rather than the whole table. */
	public function test_the_payments_table_indexes_the_coupon_count(): void {
		global $wpdb;

		// The suite rewrites CREATE TABLE to its TEMPORARY form, which SHOW INDEX cannot see, so this works on the real table.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		$table = Payments_Schema::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading our own table's keys.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );

		$first_columns = array();

		foreach ( (array) $rows as $row ) {
			if ( 1 === (int) $row['Seq_in_index'] ) {
				$first_columns[] = (string) $row['Column_name'];
			}
		}

		$this->assertContains( 'coupon_id', $first_columns, 'coupon_completions() scans the whole table' );
	}
}
