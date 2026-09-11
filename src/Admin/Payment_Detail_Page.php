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
use PinkCrab\Gated_Access\Support\View;

/**
 * One payment, whole: the row as Stripe left it, and the access it granted.
 *
 * Read-only like the list it hangs off, because the record of what happened is not editable. Reached from the Payments list's Reference column, and registered hidden with an empty parent rather than `remove_submenu_page()`.
 *
 * The markup is `views/admin/payment-detail.php`.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Moving the markup out added View as a thirteenth name. One payment is read from the store, the lookup, the taxonomy and the records it wrote, and each is a real dependency of the answer.
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

		$payments_url = admin_url( 'admin.php?page=' . Payments_Page::PAGE_SLUG );

		if ( null === $payment ) {
			View::render(
				'admin/payment-detail',
				array(
					'found'        => false,
					'payments_url' => $payments_url,
					'amount'       => '',
					'status'       => '',
					'facts'        => array(),
					'overuse'      => '',
					'grant_error'  => '',
					'grants'       => array(),
				)
			);

			return;
		}

		View::render(
			'admin/payment-detail',
			array(
				'found'        => true,
				'payments_url' => $payments_url,
				'amount'       => Money::format( $payment->amount_total, $payment->currency ),
				'status'       => $this->status_label( $payment->status ),
				'facts'        => $this->facts( $payment ),
				'overuse'      => $this->overuse_message( $payment ),
				'grant_error'  => $payment->grant_error,
				'grants'       => $this->grant_rows( $payment ),
			)
		);
	}

	/**
	 * The row's facts, labelled.
	 *
	 * @param Payment $payment The row.
	 * @return array<string, string>
	 */
	private function facts( Payment $payment ): array {
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

		return array_map( 'strval', $facts );
	}

	/**
	 * Says so when this payment took its coupon past a limit, and '' otherwise.
	 *
	 * A checkout reserves a limited coupon only briefly, so two that overlap for longer can both complete, and nothing can be refused once Stripe has the money, so this reports rather than prevents.
	 *
	 * @param Payment $payment The row.
	 */
	private function overuse_message( Payment $payment ): string {
		$overuse = $this->store->coupon_overuse( $payment );

		if ( null === $overuse ) {
			return '';
		}

		return $overuse['per_user']
			? sprintf(
				/* translators: 1: which of this buyer's uses this was, 2: the coupon's per-buyer limit. */
				__( 'This payment is this buyer’s use %1$d of a coupon limited to %2$d each. Two of their checkouts overlapped, and nothing was refused after the money was taken.', 'gated-media-access' ),
				$overuse['used'],
				$overuse['limit']
			)
			: sprintf(
				/* translators: 1: which use this was, 2: the coupon's limit. */
				__( 'This payment is use %1$d of a coupon limited to %2$d. Two checkouts overlapped, and nothing was refused after the money was taken.', 'gated-media-access' ),
				$overuse['used'],
				$overuse['limit']
			);
	}

	/**
	 * What the payment granted: every record its reference wrote, as rows.
	 *
	 * @param Payment $payment The row.
	 * @return array<int, array{status: string, label: string, url: string}>
	 */
	private function grant_rows( Payment $payment ): array {
		$rows = array();

		foreach ( $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) as $access_id ) {
			$rows[] = array(
				'status' => $this->record_status( $access_id ),
				'label'  => $this->item_label( $access_id ),
				'url'    => Edit_Access_Page::url_for( $access_id ),
			);
		}

		return $rows;
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
	 * The payment status, spelled for a person, using the list's labels.
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
