<?php
/**
 * The expiry warning job.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Account\Account_Route;
use PinkCrab\Gated_Access\Account\Account_Renderer;
use PinkCrab\Gated_Access\Account\Section_Registry;
use PinkCrab\Gated_Access\Account\Sections\My_Access_Section;
use PinkCrab\Gated_Access\Assets\Asset_Loader;
use PinkCrab\Gated_Access\Blocks\Sprite;
use PinkCrab\Gated_Access\Notifications\Expiry_Warning;
use PinkCrab\Gated_Access\Notifications\Notification_Sender;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Records inside the lead window are warned once; lifetime, far-future and
 * already-warned records are left alone; a reschedule earns a new warning.
 *
 * @group integration
 */
class Test_Expiry_Warning extends WP_UnitTestCase {

	private Access_Writer $writer;

	private Expiry_Warning $job;

	private int $user_id;

	private int $post_id;

	/**
	 * What `wp_mail()` was asked to send.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $outbox = array();

	public function set_up(): void {
		parent::set_up();

		$this->writer = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->writer->register_meta();

		$this->job = new Expiry_Warning(
			new Notification_Sender( new Settings() ),
			new Settings(),
			new Access_Taxonomy(),
			new My_Access_Section()
		);
		$this->job->register_meta();

		$this->user_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'holder@example.test',
			)
		);
		$this->post_id = self::factory()->post->create( array( 'post_title' => 'Fading Post' ) );

		$this->outbox = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		remove_all_filters( 'gatedmedia_account_route' );
		wp_clear_scheduled_hook( Expiry_Warning::HOOK );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Built from the route's slug by hand rather than through `Account_Url`,
	 * so it survived the setting that turned the route off.
	 *
	 * @testdox With the account route off, the warning's link does not point at a page that no longer answers.
	 */
	public function test_the_link_follows_the_account_route_setting(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );

		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, 3, 'admin' );

		$this->assertSame( 1, $this->job->run() );
		$this->assertStringNotContainsString( '/account/', (string) $this->outbox[0]['message'] );
	}

	/**
	 * Short-circuits `wp_mail()`, keeping what it was given.
	 *
	 * @param bool|null            $short Null to send normally.
	 * @param array<string, mixed> $atts  The mail as compiled.
	 */
	public function capture_mail( ?bool $short, array $atts ): bool {
		$this->outbox[] = $atts;

		return true;
	}

	/** @testdox A record expiring inside the lead window warns its holder, names the item and the date, and is marked warned. */
	public function test_warns_a_record_inside_the_window(): void {
		$access_id = (int) $this->writer->grant( $this->user_id, 'post', (string) $this->post_id, 3, 'admin' );

		$this->assertSame( 1, $this->job->run() );
		$this->assertCount( 1, $this->outbox );
		$this->assertSame( array( 'holder@example.test' ), $this->outbox[0]['to'] );
		$this->assertSame( 'Your access to Fading Post expires soon', $this->outbox[0]['subject'] );
		$this->assertStringContainsString( date_i18n( (string) get_option( 'date_format' ), time() + 3 * DAY_IN_SECONDS ), (string) $this->outbox[0]['message'] );
		$this->assertNotSame( '', (string) get_post_meta( $access_id, Expiry_Warning::META_WARNED_AT, true ) );
	}

	/** @testdox Lifetime and far-future records are never warned. */
	public function test_leaves_lifetime_and_far_future_records(): void {
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );
		$second = self::factory()->post->create();
		$this->writer->grant( $this->user_id, 'post', (string) $second, 60, 'admin' );

		$this->assertSame( 0, $this->job->run() );
		$this->assertCount( 0, $this->outbox );
	}

	/** @testdox A warned record is not warned twice. */
	public function test_warns_once(): void {
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, 3, 'admin' );

		$this->assertSame( 1, $this->job->run() );
		$this->assertSame( 0, $this->job->run() );
		$this->assertCount( 1, $this->outbox );
	}

	/** @testdox Rescheduling clears the warned flag, so the new date warns again when it draws near. */
	public function test_reschedule_earns_a_new_warning(): void {
		$access_id = (int) $this->writer->grant( $this->user_id, 'post', (string) $this->post_id, 3, 'admin' );

		$this->assertSame( 1, $this->job->run() );

		$this->writer->set_expiry( $access_id, gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) );
		$this->job->forget_warning( $access_id );

		$this->assertSame( 1, $this->job->run() );
		$this->assertCount( 2, $this->outbox );
	}

	/** @testdox The lead window follows the expiry_warning_days setting. */
	public function test_window_follows_the_setting(): void {
		update_option( Settings::OPTION, array( 'expiry_warning_days' => '30' ) );
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, 14, 'admin' );

		$this->assertSame( 1, $this->job->run() );
	}

	/** @testdox Scheduling twice leaves exactly one daily event. */
	public function test_schedules_once(): void {
		$this->job->schedule();
		$this->job->schedule();

		$this->assertNotFalse( wp_next_scheduled( Expiry_Warning::HOOK ) );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn ( array $hooks ): bool => isset( $hooks[ Expiry_Warning::HOOK ] ) ) );
	}
}
