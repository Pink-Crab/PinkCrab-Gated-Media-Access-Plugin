<?php
/**
 * What a person holds.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Support\Access_Row;

/**
 * Answers the `my-access` block: the groups, posts and files this person has been given, each in its own list.
 *
 * The block renders and cannot reach the container, so it raises `gatedmedia_my_access_data` and this answers it.
 */
class Held_Access implements Hookable {

	/**
	 * Reads, never writes.
	 *
	 * @param Resolver   $resolver What this person is allowed to see.
	 * @param Access_Row $rows     One held thing as a row.
	 */
	public function __construct(
		private Resolver $resolver,
		private Access_Row $rows,
	) {
	}

	/**
	 * Answers the block.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->filter( 'gatedmedia_my_access_data', array( $this, 'held' ) );
	}

	/**
	 * Everything held, sorted into the three lists the block draws.
	 *
	 * @param array<string, mixed> $data The view's defaults.
	 * @return array<string, mixed>
	 */
	public function held( array $data ): array {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return $data;
		}

		foreach ( $this->resolver->allowed_for( $user_id )->records() as $record ) {
			$item = $this->row( $record );

			if ( null === $item ) {
				continue;
			}

			if ( 'group' === $record['item_type'] ) {
				$data['groups'][] = $item;
			} elseif ( 'post' === $record['item_type'] ) {
				$data['posts'][] = $item;
			} else {
				$data['files'][] = $item;
			}
		}

		return $data;
	}

	/**
	 * One record as its row.
	 *
	 * @param array{access_id: int, item_type: string, item_id: string, expires_at: int|null} $record The record.
	 * @return array<string, mixed>|null Null when the target no longer resolves.
	 */
	private function row( array $record ): ?array {
		if ( 'group' === $record['item_type'] ) {
			return $this->rows->group( $record['item_id'], $record['expires_at'] );
		}

		if ( 'post' === $record['item_type'] ) {
			return $this->rows->post( (int) $record['item_id'], $record['expires_at'] );
		}

		if ( 'file' !== $record['item_type'] ) {
			return null;
		}

		$item = $this->rows->file( (int) $record['item_id'], $record['expires_at'] );

		if ( null === $item ) {
			return null;
		}

		// My Access folds a file's expiry into its meta line, where Files gives it a chip.
		$item['meta'] = Access_Row::joined(
			array( (string) $item['meta'], (string) $item['expiry_label'] )
		);

		return $item;
	}
}
