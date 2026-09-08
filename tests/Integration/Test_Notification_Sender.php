<?php
/**
 * The one sender of notification email.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Notifications\Notification_Sender;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * The send contract at the `pre_wp_mail` boundary: templates, placeholders, the switches, both filters and the take-over action.
 *
 * @group integration
 */
class Test_Notification_Sender extends WP_UnitTestCase {

	private Notification_Sender $sender;

	private int $user_id;

	/**
	 * What `wp_mail()` was asked to send, captured before SMTP is involved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $outbox = array();

	public function set_up(): void {
		parent::set_up();

		$this->sender  = new Notification_Sender( new Settings() );
		$this->user_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'user_email'   => 'holder@example.test',
				'display_name' => 'Holder Person',
			)
		);

		$this->outbox = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		delete_option( Settings::OPTION );
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

	/** @testdox The default template mails the holder with every placeholder replaced. */
	public function test_sends_default_template_to_holder(): void {
		$sent = $this->sender->send(
			Notification_Sender::TYPE_ACCESS_CREATED,
			$this->user_id,
			array(
				'item' => 'The Archive',
				'link' => 'https://example.test/account/my-access/',
			)
		);

		$this->assertTrue( $sent );
		$this->assertCount( 1, $this->outbox );
		$this->assertSame( array( 'holder@example.test' ), $this->outbox[0]['to'] );
		$this->assertSame( 'Your access to The Archive is ready', $this->outbox[0]['subject'] );
		$this->assertStringContainsString( 'Hi Holder Person,', (string) $this->outbox[0]['message'] );
		$this->assertStringContainsString( 'https://example.test/account/my-access/', (string) $this->outbox[0]['message'] );
		$this->assertStringContainsString( 'Access expires: never', (string) $this->outbox[0]['message'] );
	}

	/** @testdox A switched-off type sends nothing. */
	public function test_disabled_type_sends_nothing(): void {
		update_option( Settings::OPTION, array( 'notify_access_created' => '0' ) );

		$sent = $this->sender->send( Notification_Sender::TYPE_ACCESS_CREATED, $this->user_id, array( 'item' => 'X' ) );

		$this->assertFalse( $sent );
		$this->assertCount( 0, $this->outbox );
	}

	/** @testdox An unknown type sends nothing. */
	public function test_unknown_type_sends_nothing(): void {
		$this->assertFalse( $this->sender->send( 'no_such_type', $this->user_id ) );
		$this->assertCount( 0, $this->outbox );
	}

	/** @testdox A stored template override wins over the shipped default, tokens still replaced. */
	public function test_stored_override_wins(): void {
		update_option(
			Settings::OPTION,
			array(
				'template_access_created_subject' => 'Welcome to {item}',
				'template_access_created_body'    => 'Go: {link}',
			)
		);

		$this->sender->send(
			Notification_Sender::TYPE_ACCESS_CREATED,
			$this->user_id,
			array(
				'item' => 'The Vault',
				'link' => 'https://example.test/v',
			)
		);

		$this->assertSame( 'Welcome to The Vault', $this->outbox[0]['subject'] );
		$this->assertSame( 'Go: https://example.test/v', $this->outbox[0]['message'] );
	}

	/** @testdox Emptied recipients stop the send, but the take-over action still fires. */
	public function test_empty_recipients_stop_send_action_still_fires(): void {
		$heard = array();

		add_filter( 'gatedmedia_notification_recipients', '__return_empty_array' );
		add_action(
			'gatedmedia_notification_sending',
			static function ( string $type ) use ( &$heard ): void {
				$heard[] = $type;
			}
		);

		$sent = $this->sender->send( Notification_Sender::TYPE_ACCESS_CREATED, $this->user_id, array( 'item' => 'X' ) );

		remove_filter( 'gatedmedia_notification_recipients', '__return_empty_array' );

		$this->assertFalse( $sent );
		$this->assertCount( 0, $this->outbox );
		$this->assertSame( array( Notification_Sender::TYPE_ACCESS_CREATED ), $heard );
	}

	/** @testdox The admin copy rides along when its switch is on. */
	public function test_admin_copy_appended(): void {
		update_option(
			Settings::OPTION,
			array(
				'admin_copy'         => '1',
				'admin_copy_address' => 'copies@example.test',
			)
		);

		$this->sender->send( Notification_Sender::TYPE_ACCESS_CREATED, $this->user_id, array( 'item' => 'X' ) );

		$this->assertSame( array( 'holder@example.test', 'copies@example.test' ), $this->outbox[0]['to'] );
	}

	/** @testdox An explicit address replaces the holder lookup, because guest invites have no account. */
	public function test_explicit_address_wins(): void {
		$this->sender->send(
			Notification_Sender::TYPE_INVITE_GUEST_FREE,
			0,
			array( 'item' => 'X' ),
			'guest@example.test'
		);

		$this->assertSame( array( 'guest@example.test' ), $this->outbox[0]['to'] );
	}

	/** @testdox The content filter has the last word over subject and body. */
	public function test_content_filter_wins(): void {
		add_filter(
			'gatedmedia_notification_content',
			static fn (): array => array(
				'subject' => 'Overridden',
				'body'    => 'Entirely.',
			)
		);

		$this->sender->send( Notification_Sender::TYPE_ACCESS_CREATED, $this->user_id, array( 'item' => 'X' ) );

		remove_all_filters( 'gatedmedia_notification_content' );

		$this->assertSame( 'Overridden', $this->outbox[0]['subject'] );
		$this->assertSame( 'Entirely.', $this->outbox[0]['message'] );
	}
}
