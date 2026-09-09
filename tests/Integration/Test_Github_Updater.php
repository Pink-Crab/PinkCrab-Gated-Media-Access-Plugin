<?php
/**
 * Updates from GitHub releases.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Updates\Github_Updater;

/**
 * Only a plain X.Y.Z release is offered, and it is offered whether or not it is newer than what is installed, because core is the one that compares them.
 *
 * @group integration
 */
class Test_Github_Updater extends WP_UnitTestCase {

	/** The headers core passes the filter. */
	private const HEADERS = array(
		'Version'     => '0.1.0',
		'RequiresWP'  => '6.4',
		'RequiresPHP' => '8.3',
		'Name'        => 'Gated Media Access',
	);

	/** The URL the faked transport was asked for. */
	private string $requested = '';

	/** How many requests the faked transport answered. */
	private int $requests = 0;

	public function set_up(): void {
		parent::set_up();

		delete_site_transient( 'gatedmedia_latest_release' );

		$this->requested = '';
		$this->requests  = 0;
	}

	public function tear_down(): void {
		delete_site_transient( 'gatedmedia_latest_release' );

		parent::tear_down();
	}

	/** @testdox A stable release is offered, with the built zip as the package. */
	public function test_stable_release_is_offered(): void {
		$this->fake_release( $this->release( '1.2.0' ) );

		$offer = ( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );

		$this->assertIsArray( $offer );
		$this->assertSame( '1.2.0', $offer['version'] );
		$this->assertSame( 'gated-media-access', $offer['slug'] );
		$this->assertSame( 'https://github.com/Pink-Crab/gated-media-access/releases/download/1.2.0/gated-media-access.zip', $offer['package'] );
		$this->assertSame( '6.4', $offer['requires'] );
		$this->assertSame( '8.3', $offer['requires_php'] );
	}

	/** @testdox A stable release installs itself, unless a filter says otherwise. */
	public function test_stable_release_auto_updates(): void {
		$this->fake_release( $this->release( '1.2.0' ) );

		$offer = ( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );

		$this->assertIsArray( $offer );
		$this->assertTrue( $offer['autoupdate'] );

		add_filter( 'gatedmedia_auto_update', '__return_false' );
		delete_site_transient( 'gatedmedia_latest_release' );

		$refused = ( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );

		$this->assertIsArray( $refused );
		$this->assertFalse( $refused['autoupdate'] );
	}

	/** @testdox A release marked as a prerelease is refused. */
	public function test_prerelease_is_refused(): void {
		$release               = $this->release( '1.2.0' );
		$release['prerelease'] = true;

		$this->fake_release( $release );

		$this->assertFalse( ( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME ) );
	}

	/** @testdox A release candidate is refused on its tag alone, however it is marked. */
	public function test_release_candidate_tag_is_refused(): void {
		$this->fake_release( $this->release( '1.2.0-rc.1' ) );

		$this->assertFalse( ( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME ) );
	}

	/** @testdox A leading v on the tag is not part of the version. */
	public function test_tag_prefix_is_dropped(): void {
		$this->fake_release( $this->release( 'v1.2.0' ) );

		$offer = ( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );

		$this->assertIsArray( $offer );
		$this->assertSame( '1.2.0', $offer['version'] );
	}

	/** @testdox A release whose build failed is announced without anything to install. */
	public function test_release_without_the_asset_offers_no_package(): void {
		$release           = $this->release( '1.2.0' );
		$release['assets'] = array(
			array(
				'name'                 => 'source.zip',
				'browser_download_url' => 'https://example.com/source.zip',
			),
		);

		$this->fake_release( $release );

		$offer = ( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );

		$this->assertIsArray( $offer );
		$this->assertSame( '1.2.0', $offer['version'] );
		$this->assertArrayNotHasKey( 'package', $offer );
	}

	/** @testdox A release no newer than the installed version is still returned, so core can file it under no_update. */
	public function test_same_version_is_still_returned(): void {
		$this->fake_release( $this->release( '0.1.0' ) );

		$offer = ( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );

		$this->assertIsArray( $offer );
		$this->assertSame( '0.1.0', $offer['version'] );
	}

	/** @testdox Another plugin's update check is passed through untouched. */
	public function test_another_plugin_is_left_alone(): void {
		$this->fake_release( $this->release( '1.2.0' ) );

		$this->assertFalse( ( new Github_Updater() )->offer( false, self::HEADERS, 'other/other.php' ) );
		$this->assertSame( 0, $this->requests, 'Nothing should have been asked of GitHub.' );
	}

	/** @testdox A failed lookup offers nothing and is remembered rather than retried. */
	public function test_a_failed_lookup_is_remembered(): void {
		$this->fake_release( array(), 404 );

		$updater = new Github_Updater();

		$this->assertFalse( $updater->offer( false, self::HEADERS, GATEDMEDIA_BASENAME ) );
		$this->assertFalse( $updater->offer( false, self::HEADERS, GATEDMEDIA_BASENAME ) );
		$this->assertSame( 1, $this->requests );
	}

	/** @testdox The release is cached, and installing anything drops the cache. */
	public function test_installing_drops_the_cache(): void {
		$this->fake_release( $this->release( '1.2.0' ) );

		$updater = new Github_Updater();
		$updater->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );
		$updater->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );

		$this->assertSame( 1, $this->requests );

		$updater->forget();
		$updater->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );

		$this->assertSame( 2, $this->requests );
	}

	/** @testdox The repository asked for is filterable. */
	public function test_the_repository_is_filterable(): void {
		$this->fake_release( $this->release( '1.2.0' ) );

		add_filter( 'gatedmedia_update_repository', static fn (): string => 'Someone/their-fork' );

		( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME );

		$this->assertSame( 'https://api.github.com/repos/Someone/their-fork/releases/latest', $this->requested );
	}

	/** @testdox A filter can refuse the release outright. */
	public function test_the_release_can_be_refused(): void {
		$this->fake_release( $this->release( '1.2.0' ) );

		add_filter( 'gatedmedia_update_release', '__return_null' );

		$this->assertFalse( ( new Github_Updater() )->offer( false, self::HEADERS, GATEDMEDIA_BASENAME ) );
	}

	/** @testdox The details screen answers for this plugin and for nothing else. */
	public function test_the_details_screen_is_filled(): void {
		$this->fake_release( $this->release( '1.2.0' ) );

		$updater = new Github_Updater();

		$information = $updater->information( false, 'plugin_information', (object) array( 'slug' => 'gated-media-access' ) );

		$this->assertIsObject( $information );
		$this->assertSame( '1.2.0', $information->version );
		$this->assertStringContainsString( 'What changed', $information->sections['changelog'] );

		$this->assertFalse( $updater->information( false, 'plugin_information', (object) array( 'slug' => 'another-plugin' ) ) );
		$this->assertFalse( $updater->information( false, 'query_plugins', (object) array( 'slug' => 'gated-media-access' ) ) );
	}

	/**
	 * A GitHub release payload, cut down to the parts that are read.
	 *
	 * @param string $tag The tag name.
	 *
	 * @return array<string, mixed>
	 */
	private function release( string $tag ): array {
		$version = ltrim( $tag, 'v' );

		return array(
			'tag_name'     => $tag,
			'draft'        => false,
			'prerelease'   => false,
			'body'         => 'What changed in ' . $version,
			'html_url'     => 'https://github.com/Pink-Crab/gated-media-access/releases/tag/' . $tag,
			'published_at' => '2026-09-09T09:00:00Z',
			'assets'       => array(
				array(
					'name'                 => 'gated-media-access.zip',
					'browser_download_url' => 'https://github.com/Pink-Crab/gated-media-access/releases/download/' . $version . '/gated-media-access.zip',
				),
			),
		);
	}

	/**
	 * Answers the next GitHub request with this payload, counting the calls and keeping the URL.
	 *
	 * @param array<string, mixed> $release The payload.
	 * @param int                  $status  The status code to answer with.
	 */
	private function fake_release( array $release, int $status = 200 ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $release, $status ) {
				if ( ! str_contains( (string) $url, 'api.github.com' ) ) {
					return $preempt;
				}

				++$this->requests;
				$this->requested = (string) $url;

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $release ),
					'response' => array(
						'code'    => $status,
						'message' => 200 === $status ? 'OK' : 'Not Found',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}
}
