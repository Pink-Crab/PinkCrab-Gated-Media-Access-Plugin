<?php
/**
 * The payments table's migration.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Plugin;
use PinkCrab\Gated_Access\Payments\Payments_Schema;

/**
 * A failed `CREATE TABLE` must not be recorded as a success, or the migration
 * never runs again and every payment fails against a table that is not there.
 *
 * The harness rewrites CREATE and DROP to their TEMPORARY forms, which
 * `SHOW TABLES` cannot see, so these work on the real table and put it back.
 *
 * @group integration
 */
class Test_Payments_Schema extends WP_UnitTestCase {

	public function tear_down(): void {
		$this->restore_payments_table();

		parent::tear_down();
	}

	/**
	 * Rebuilds the real table, whatever the test did to it.
	 */
	private function restore_payments_table(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/** @testdox A CREATE TABLE that failed is not stamped as installed, so the next request tries again. */
	public function test_a_failed_create_is_not_stamped(): void {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$table = Payments_Schema::table_name();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		delete_option( Payments_Schema::OPTION_DB_VERSION );

		$break      = $this->break_creates();
		$suppressed = $wpdb->suppress_errors( true );

		( new Payments_Schema() )->migrate();

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', $break );

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'The table should not exist.' );
		$this->assertFalse( get_option( Payments_Schema::OPTION_DB_VERSION ), 'A failed create must leave the version unstamped.' );
	}

	/** @testdox The table is reported missing when it is not there, and present once it is. */
	public function test_table_exists_answers_for_the_real_table(): void {
		$this->drop_payments_table();

		$this->assertFalse( Payments_Schema::table_exists() );

		( new Payments_Schema() )->migrate();

		$this->assertTrue( Payments_Schema::table_exists() );
	}

	/** @testdox A table that cannot be created stops the full boot and says so in the admin. */
	public function test_a_missing_table_stops_the_full_boot(): void {
		global $wpdb;

		$this->drop_payments_table();

		$break      = $this->break_creates();
		$suppressed = $wpdb->suppress_errors( true );

		$booted = ( new Plugin() )->boot();

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', $break );

		$this->assertFalse( $booted, 'Boot should report that it did not run in full.' );
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'render_missing_table_notice' ) ) );
	}

	/** @testdox The notice names the table and tells the administrator to reactivate. */
	public function test_the_notice_offers_a_fix(): void {
		ob_start();
		Plugin::render_missing_table_notice();
		$notice = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'reactivate', $notice );
		$this->assertStringContainsString( admin_url( 'plugins.php' ), $notice );
	}

	/**
	 * Makes every CREATE TABLE fail, standing in for the real refusals: no
	 * CREATE privilege, or an index over the key length limit.
	 *
	 * @return callable(string):string The filter, for the caller to remove.
	 */
	private function break_creates(): callable {
		$table = Payments_Schema::table_name();

		$break = static fn( string $query ): string => str_starts_with( $query, 'CREATE TABLE ' )
			? "CREATE TABLE {$table} (id bigint(20) NOT NULL, KEY nope (no_such_column))"
			: $query;

		add_filter( 'query', $break );

		return $break;
	}

	/**
	 * Drops the real table, leaving the harness filters off for the test body.
	 */
	private function drop_payments_table(): void {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$table = Payments_Schema::table_name();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		delete_option( Payments_Schema::OPTION_DB_VERSION );
	}
}
