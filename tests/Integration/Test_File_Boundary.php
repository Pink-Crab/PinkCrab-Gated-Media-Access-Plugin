<?php
/**
 * The file boundary.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The dependency's protect-file decision goes through the resolver, and its
 * before-serve action re-publishes as gatedmedia_file_downloaded.
 *
 * Everything runs through apply_filters / do_action against the hooks the
 * bootstrap attached at plugin load, so the lazy container resolution is on
 * trial too.
 *
 * @group integration
 */
class Test_File_Boundary extends WP_UnitTestCase {

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer  = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so the boot-time registration is
		// gone by the time any test here runs.
		$this->writer->register_meta();
	}

	/** @testdox The bootstrap attached both boundary hooks at plugin load. */
	public function test_the_boundary_hooks_are_attached(): void {
		$this->assertNotFalse( has_filter( 'restrict_media_file_access_protect_file' ) );
		$this->assertNotFalse( has_action( 'restrict_media_file_access_before_serve' ) );
	}

	/** @testdox A restricted file is refused to the signed out. */
	public function test_signed_out_refused(): void {
		[ , $hash ] = $this->make_protected_attachment();

		wp_set_current_user( 0 );

		$this->assertTrue( apply_filters( 'restrict_media_file_access_protect_file', ! is_user_logged_in(), $hash ) );
	}

	/** @testdox A holder of a direct file grant is allowed the file. */
	public function test_holder_allowed(): void {
		[ $attachment_id, $hash ] = $this->make_protected_attachment();

		$this->writer->grant( $this->user_id, 'file', (string) $attachment_id, null, 'admin' );
		wp_set_current_user( $this->user_id );

		$this->assertFalse( apply_filters( 'restrict_media_file_access_protect_file', ! is_user_logged_in(), $hash ) );
	}

	/** @testdox A signed-in non-holder is refused — the dependency's everyone-signed-in default no longer applies. */
	public function test_non_holder_refused(): void {
		[ , $hash ] = $this->make_protected_attachment();

		wp_set_current_user( $this->user_id );

		// The dependency's own default would serve this: signed in, incoming
		// decision false. The resolver turns it away.
		$this->assertTrue( apply_filters( 'restrict_media_file_access_protect_file', ! is_user_logged_in(), $hash ) );
	}

	/** @testdox A size-suffixed request resolves to the same attachment and the same decision. */
	public function test_sized_suffix_resolves_to_the_same_attachment(): void {
		[ $attachment_id, $hash ] = $this->make_protected_attachment();

		$this->writer->grant( $this->user_id, 'file', (string) $attachment_id, null, 'admin' );
		wp_set_current_user( $this->user_id );

		$this->assertFalse( apply_filters( 'restrict_media_file_access_protect_file', ! is_user_logged_in(), $hash . '-150x150' ) );
	}

	/** @testdox A hash that places no attachment leaves the incoming decision alone, either way. */
	public function test_unknown_hash_untouched(): void {
		wp_set_current_user( $this->user_id );

		$unknown = str_repeat( 'ab', 16 );

		$this->assertTrue( apply_filters( 'restrict_media_file_access_protect_file', true, $unknown ) );
		$this->assertFalse( apply_filters( 'restrict_media_file_access_protect_file', false, $unknown ) );
	}

	/** @testdox A segment that is not hash-shaped at all leaves the incoming decision alone. */
	public function test_malformed_segment_untouched(): void {
		$this->assertFalse( apply_filters( 'restrict_media_file_access_protect_file', false, 'not-a-hash!' ) );
		$this->assertTrue( apply_filters( 'restrict_media_file_access_protect_file', true, 'not-a-hash!' ) );
	}

	/** @testdox The before-serve action re-publishes as gatedmedia_file_downloaded with user, attachment and path. */
	public function test_download_action_republished(): void {
		[ $attachment_id ] = $this->make_protected_attachment();

		wp_set_current_user( $this->user_id );

		$seen = array();
		add_action(
			'gatedmedia_file_downloaded',
			static function ( int $user_id, int $file_id, string $path ) use ( &$seen ): void {
				$seen[] = array( $user_id, $file_id, $path );
			},
			10,
			3
		);

		do_action( 'restrict_media_file_access_before_serve', $attachment_id, '/uploads/protected/file.txt' );

		$this->assertSame( array( array( $this->user_id, $attachment_id, '/uploads/protected/file.txt' ) ), $seen );
	}

	/**
	 * An attachment with a real uploaded file, restricted through the
	 * dependency so it has a genuine protected-file hash.
	 *
	 * @return array{0: int, 1: string} Attachment ID and its hash.
	 */
	private function make_protected_attachment(): array {
		$upload = wp_upload_dir();
		$path   = $upload['basedir'] . '/boundary-' . wp_generate_password( 8, false ) . '.txt';

		file_put_contents( $path, 'protected file contents' );

		$attachment_id = self::factory()->attachment->create_object(
			$path,
			0,
			array( 'post_mime_type' => 'text/plain' )
		);

		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'  => basename( $path ),
				'sizes' => array(),
			)
		);

		$this->assertTrue( rmfa_set_file_as_protected( $attachment_id ) );

		$hash = rmfa_get_media_protected_file_hash( $attachment_id );
		$this->assertIsString( $hash );

		return array( $attachment_id, $hash );
	}
}
