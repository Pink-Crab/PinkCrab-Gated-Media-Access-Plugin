<?php
/**
 * One user's access, resolved.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

/**
 * The per-user picture the resolver builds once and everything reads: the live
 * direct records, and the flattened maps they expand to.
 *
 * Expiries are UTC timestamps, or null for lifetime. Where an item is reachable
 * more than one way — held directly and inside a held group — the map carries
 * the most generous expiry: lifetime beats any date, later beats sooner.
 *
 * Immutable; revoked and expired records were dropped during the build, so
 * membership here *is* the answer.
 */
final class Access_Picture {

	/**
	 * Built by the resolver, nothing else.
	 *
	 * @param array<int, array<string, mixed>> $records The live direct records.
	 * @param array<int, int|null>             $files   Attachment ID → effective expiry.
	 * @param array<int, int|null>             $posts   Post ID → effective expiry.
	 * @param array<string, int|null>          $groups  Group UUID → expiry.
	 *
	 * @phpstan-param array<int, array{access_id: int, item_type: string, item_id: string, expires_at: int|null}> $records
	 */
	public function __construct(
		private array $records,
		private array $files,
		private array $posts,
		private array $groups,
	) {
	}

	/**
	 * The live direct records, as granted — what My Access lists.
	 *
	 * @return array<int, array{access_id: int, item_type: string, item_id: string, expires_at: int|null}>
	 */
	public function records(): array {
		return $this->records;
	}

	/**
	 * Every reachable attachment — direct grants and group contents together.
	 *
	 * @return array<int, int|null>
	 */
	public function files(): array {
		return $this->files;
	}

	/**
	 * Every reachable post, the same way.
	 *
	 * @return array<int, int|null>
	 */
	public function posts(): array {
		return $this->posts;
	}

	/**
	 * The groups held.
	 *
	 * @return array<string, int|null>
	 */
	public function groups(): array {
		return $this->groups;
	}

	/**
	 * Whether this attachment is reachable.
	 *
	 * @param int $file_id The attachment.
	 */
	public function has_file( int $file_id ): bool {
		return array_key_exists( $file_id, $this->files );
	}

	/**
	 * Whether this post is reachable.
	 *
	 * @param int $post_id The post.
	 */
	public function has_post( int $post_id ): bool {
		return array_key_exists( $post_id, $this->posts );
	}

	/**
	 * Whether this group is held.
	 *
	 * @param string $uuid The group's identity.
	 */
	public function has_group( string $uuid ): bool {
		return array_key_exists( $uuid, $this->groups );
	}
}
