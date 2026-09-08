<?php
/**
 * The daily expiry sweep.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * Moves active records past their expiry to the expired status, daily.
 *
 * Housekeeping, not enforcement: `Resolver` compares dates against now on every read, so nothing would leak if this never ran, and it exists so the admin list and the account area read sensibly.
 *
 * Every move goes through `Access_Writer::expire()`, and the sweep changes no record itself.
 */
class Sweep implements Hookable {

	/** The cron hook, daily. */
	public const HOOK = 'gatedmedia_sweep_expired';

	/**
	 * Every status move goes through the writer.
	 *
	 * @param Access_Writer $writer The one writer of access records.
	 */
	public function __construct( private Access_Writer $writer ) {
	}

	/**
	 * Schedules on init, runs on the cron hook.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'schedule' ) );
		$loader->action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedules the daily event, exactly once.
	 */
	public function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Expires every active record whose date has passed.
	 *
	 * @return int How many records were moved.
	 */
	public function run(): int {
		$swept = 0;

		foreach ( $this->past_expiry_ids() as $access_id ) {
			if ( $this->writer->expire( $access_id ) ) {
				++$swept;
			}
		}

		return $swept;
	}

	/**
	 * Active records whose expiry is set and behind now.
	 *
	 * The status is named, never 'any', which would skip all three of ours. Lifetime records hold '' in the meta, which the first clause drops.
	 *
	 * @return array<int, int>
	 */
	private function past_expiry_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => Post_Types::STATUS_ACTIVE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Daily cron; the pair of clauses is the sweep's whole condition.
				'meta_query'     => array(
					array(
						'key'     => Access_Writer::META_EXPIRES_AT,
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'key'     => Access_Writer::META_EXPIRES_AT,
						'value'   => gmdate( 'Y-m-d H:i:s' ),
						'compare' => '<=',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}
}
