<?php
/**
 * The single-payment detail page.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Money;

/**
 * One payment, whole: the row as Stripe left it, and the access it granted.
 * Read-only like the list it hangs off — the record of what happened is not
 * editable. Reached from the Payments list's Reference column; registered
 * hidden (`add_submenu_page( '', … )`, the `Edit_Access_Page` pattern —
 * never `remove_submenu_page()`).
 */
class Payment_Detail_Page implements Hookable {

	/** The page's `page` query arg. */
	public const PAGE_SLUG = 'gatedmedia-payment';

	/**
	 * Reads only.
	 *
	 * @param Payment_Store   $store    The payments table's owner.
	 * @param Access_Lookup   $lookup   Finds what a reference granted.
	 * @param Access_Taxonomy $taxonomy Turns a group UUID back into its term.
	 */
	public function __construct( private Payment_Store $store, private Access_Lookup $lookup, private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * The hidden page.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Registers reachable-but-unlisted, behind the view capability.
	 */
	public function register_page(): void {
		add_submenu_page(
			'',
			__( 'Payment', 'gated-media-access' ),
			__( 'Payment', 'gated-media-access' ),
			Capabilities::view_payments(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The URL the Reference column points at.
	 *
	 * @param string $uuid The payment's public identifier.
	 */
	public static function url_for( string $uuid ): string {
		return add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'payment' => $uuid,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The payment and its grants, or where to go when there is none.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display keyed by uuid.
		$uuid    = isset( $_GET['payment'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['payment'] ) ) : '';
		$payment = '' === $uuid ? null : $this->store->find_by_uuid( $uuid );

		if ( null === $payment ) {
			printf(
				'<div class="wrap"><div class="gatedmedia-admin"><p>%s <a href="%s">%s</a></p></div></div>',
				esc_html__( 'No payment found for that reference.', 'gated-media-access' ),
				esc_url( admin_url( 'admin.php?page=' . Payments_Page::PAGE_SLUG ) ),
				esc_html__( 'Back to Payments', 'gated-media-access' )
			);

			return;
		}

		?>
		<div class="wrap">
			<div class="gatedmedia-admin">
				<header class="gatedmedia-admin-header">
					<div>
						<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Payment', 'gated-media-access' ); ?></span>
						<h1><?php echo esc_html( Money::format( $payment->amount_total, $payment->currency ) ); ?></h1>
					</div>
					<span class="gatedmedia-admin-caps"><?php echo esc_html( $this->status_label( $payment->status ) ); ?></span>
				</header>

				<?php $this->render_row( $payment ); ?>
				<?php $this->render_grants( $payment ); ?>

				<p><a class="gatedmedia-admin-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Payments_Page::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Back to Payments', 'gated-media-access' ); ?></a></p>
			</div>
		</div>
		<?php
	}

	/**
	 * The row's facts, labelled.
	 *
	 * @param Payment $payment The row.
	 */
	private function render_row( Payment $payment ): void {
		$user    = get_userdata( $payment->user_id );
		$product = get_post( $payment->product_id );

		$facts = array(
			__( 'Date', 'gated-media-access' )           => $payment->created_at,
			__( 'Reference', 'gated-media-access' )      => $payment->uuid,
			__( 'User', 'gated-media-access' )           => false === $user ? sprintf( /* translators: %d: user id. */ __( 'User %d (deleted)', 'gated-media-access' ), $payment->user_id ) : $user->display_name,
			__( 'Product', 'gated-media-access' )        => null === $product ? sprintf( /* translators: %d: product id. */ __( 'Product %d (deleted)', 'gated-media-access' ), $payment->product_id ) : ( '' === get_the_title( $product ) ? __( '(no title)', 'gated-media-access' ) : get_the_title( $product ) ),
			__( 'Coupon', 'gated-media-access' )         => 0 === $payment->coupon_id ? Money::not_applicable() : sprintf( '%s (−%s)', get_the_title( $payment->coupon_id ), Money::format( $payment->discount_amount, $payment->currency ) ),
			__( 'Stripe session', 'gated-media-access' ) => '' === $payment->stripe_session_id ? Money::not_applicable() : $payment->stripe_session_id,
			__( 'Stripe intent', 'gated-media-access' )  => '' === $payment->stripe_payment_intent_id ? Money::not_applicable() : $payment->stripe_payment_intent_id,
		);

		?>
		<div class="gatedmedia-admin-section-head">
			<h2><?php esc_html_e( 'The payment', 'gated-media-access' ); ?></h2>
			<span class="gatedmedia-admin-caps"><?php esc_html_e( 'As Stripe left it', 'gated-media-access' ); ?></span>
		</div>
		<?php

		foreach ( $facts as $label => $value ) {
			printf(
				'<div class="gatedmedia-admin-field"><span class="gatedmedia-admin-caps">%s</span><span>%s</span></div>',
				esc_html( $label ),
				esc_html( (string) $value )
			);
		}
	}

	/**
	 * What the payment granted — every record its reference wrote, or the
	 * frozen snapshot when nothing has (a pending or failed row).
	 *
	 * @param Payment $payment The row.
	 */
	private function render_grants( Payment $payment ): void {
		$records = $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid );

		?>
		<div class="gatedmedia-admin-section-head">
			<h2><?php esc_html_e( 'Access granted', 'gated-media-access' ); ?></h2>
			<span class="gatedmedia-admin-caps"><?php esc_html_e( 'From the frozen snapshot', 'gated-media-access' ); ?></span>
		</div>
		<?php

		if ( array() === $records ) {
			printf( '<p class="gatedmedia-admin-help">%s</p>', esc_html__( 'Nothing granted by this payment yet.', 'gated-media-access' ) );

			return;
		}

		foreach ( $records as $access_id ) {
			printf(
				'<div class="gatedmedia-admin-field"><span class="gatedmedia-admin-caps">%s</span><span>%s</span> — <a href="%s">%s</a></div>',
				esc_html( $this->record_status( $access_id ) ),
				esc_html( $this->item_label( $access_id ) ),
				esc_url( Edit_Access_Page::url_for( $access_id ) ),
				esc_html__( 'View record', 'gated-media-access' )
			);
		}
	}

	/**
	 * A record's status, spelled for a person.
	 *
	 * @param int $access_id The record.
	 */
	private function record_status( int $access_id ): string {
		$labels = array(
			Post_Types::STATUS_ACTIVE  => __( 'Active', 'gated-media-access' ),
			Post_Types::STATUS_EXPIRED => __( 'Expired', 'gated-media-access' ),
			Post_Types::STATUS_REVOKED => __( 'Revoked', 'gated-media-access' ),
		);

		return $labels[ get_post_status( $access_id ) ] ?? (string) get_post_status( $access_id );
	}

	/**
	 * What the record grants, named for a person.
	 *
	 * @param int $access_id The record.
	 */
	private function item_label( int $access_id ): string {
		$type       = (string) get_post_meta( $access_id, Access_Writer::META_ITEM_TYPE, true );
		$identifier = (string) get_post_meta( $access_id, Access_Writer::META_ITEM_ID, true );

		if ( 'group' === $type ) {
			$term = $this->taxonomy->find_group( $identifier );

			return null === $term ? $identifier : $term->name;
		}

		$title = get_the_title( (int) $identifier );

		return '' === $title ? "#{$identifier}" : $title;
	}

	/**
	 * The payment status, spelled for a person — the list's labels.
	 *
	 * @param string $status One of the four Payment STATUS_* values.
	 */
	private function status_label( string $status ): string {
		$labels = array(
			Payment::STATUS_PENDING  => __( 'Pending', 'gated-media-access' ),
			Payment::STATUS_COMPLETE => __( 'Complete', 'gated-media-access' ),
			Payment::STATUS_REFUNDED => __( 'Refunded', 'gated-media-access' ),
			Payment::STATUS_FAILED   => __( 'Failed', 'gated-media-access' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
