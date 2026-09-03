<?php
/**
 * The access-created email.
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
use PinkCrab\Gated_Access\Notifications\Access_Created_Mail;
use PinkCrab\Gated_Access\Notifications\Notification_Sender;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * One email per holder per request, whatever granted — and none at all for
 * invite-sourced grants, whose invite email is the announcement.
 *
 * @group integration
 */
class Test_Access_Created_Mail extends WP_UnitTestCase {

	private Access_Writer $writer;

	private Access_Created_Mail $mail;

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

		$this->mail = new Access_Created_Mail(
			new Notification_Sender( new Settings() ),
			new Access_Taxonomy(),
			new My_Access_Section()
		);

		add_action( 'gatedmedia_access_granted', array( $this->mail, 'queue' ), 10, 2 );

		$this->user_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'holder@example.test',
			)
		);
		$this->post_id = self::factory()->post->create( array( 'post_title' => 'Secret Post' ) );

		$this->outbox = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		remove_action( 'gatedmedia_access_granted', array( $this->mail, 'queue' ) );
		remove_all_filters( 'gatedmedia_account_route' );
		remove_all_filters( 'gatedmedia_account_url' );
		parent::tear_down();
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

	/** @testdox An admin grant mails the holder: item named, lifetime reads never, the account link rides along. */
	public function test_admin_grant_mails_holder(): void {
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );
		$this->mail->flush();

		$this->assertCount( 1, $this->outbox );
		$this->assertSame( array( 'holder@example.test' ), $this->outbox[0]['to'] );
		$this->assertSame( 'Your access to Secret Post is ready', $this->outbox[0]['subject'] );
		$this->assertStringContainsString( 'Access expires: never', (string) $this->outbox[0]['message'] );
		$this->assertStringContainsString( home_url( '/account/my-access/' ), (string) $this->outbox[0]['message'] );
	}

	/**
	 * The link was built from the route's slug by hand rather than through
	 * `Account_Url`, so it kept pointing at `/account/` after the setting had
	 * turned that route off — an email inviting the holder to a 404.
	 *
	 * @testdox With the account route off, the email link does not point at a page that no longer answers.
	 */
	public function test_the_link_follows_the_account_route_setting(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );

		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );
		$this->mail->flush();

		$this->assertCount( 1, $this->outbox );
		$this->assertStringNotContainsString( '/account/', (string) $this->outbox[0]['message'] );
	}

	/** @testdox A site with its own pages gets its own link in the email. */
	public function test_the_link_follows_the_url_filter(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );
		add_filter( 'gatedmedia_account_url', static fn(): string => home_url( '/members-area/' ) );

		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );
		$this->mail->flush();

		$this->assertStringContainsString( home_url( '/members-area/' ), (string) $this->outbox[0]['message'] );
	}

	/** @testdox Several grants in one request mean one email, items joined. */
	public function test_several_grants_one_email(): void {
		$second = self::factory()->post->create( array( 'post_title' => 'Second Post' ) );

		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'stripe', 'pay-1' );
		$this->writer->grant( $this->user_id, 'post', (string) $second, null, 'stripe', 'pay-1' );
		$this->mail->flush();

		$this->assertCount( 1, $this->outbox );
		$this->assertStringContainsString( 'Secret Post, Second Post', (string) $this->outbox[0]['message'] );
	}

	/** @testdox A timed grant names its soonest expiry date. */
	public function test_timed_grant_names_expiry(): void {
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, 30, 'admin' );
		$this->mail->flush();

		$expected = date_i18n( (string) get_option( 'date_format' ), time() + 30 * DAY_IN_SECONDS );

		$this->assertCount( 1, $this->outbox );
		$this->assertStringContainsString( "Access expires: {$expected}", (string) $this->outbox[0]['message'] );
	}

	/** @testdox Invite-sourced grants are the invite email's to announce — nothing sends here. */
	public function test_invite_sourced_grant_is_skipped(): void {
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'invite' );
		$this->mail->flush();

		$this->assertCount( 0, $this->outbox );
	}

	/** @testdox Flushing with nothing queued sends nothing. */
	public function test_empty_flush_sends_nothing(): void {
		$this->mail->flush();

		$this->assertCount( 0, $this->outbox );
	}
}
