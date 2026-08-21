<?php
/**
 * What the Orders view draws.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Support\Expiry;
use PinkCrab\Gated_Access\Support\Item_Label;
use PinkCrab\Gated_Access\Support\Money;

/**
 * Turns payment rows into the shapes the orders block declares — §7.3 the
 * list, §7.4 one order.
 *
 * Separate from View_Data because that class turns the *resolver's* allowed
 * items into views; this one reads the payments table, and mixing the two
 * would put a payments dependency inside the access reader.
 *
 * The render file cannot reach container services, so the data arrives by
 * filter — `gatedmedia_orders_data` — exactly as My Access and Files do.
 *
 * Ownership is checked here and nowhere else: the list is queried by user id,
 * and one order is only returned when it belongs to the person asking. An
 * order that is not yours reads as an order that does not exist.
 */
class Orders_View_Data implements Hookable {

	/** The query arg Stripe returns under, naming the order just bought. */
	public const NEW_ORDER = 'new_order';

	/**
	 * Reads, never writes.
	 *
	 * @param Payment_Store $store  The payments table's owner.
	 * @param Access_Lookup $lookup Finds the access an order created.
	 * @param Item_Label    $labels Names the items an order contained.
	 */
	public function __construct(
		private Payment_Store $store,
		private Access_Lookup $lookup,
		private Item_Label $labels,
	) {
	}

	/**
	 * Supplies the view.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->filter( 'gatedmedia_orders_data', array( $this, 'orders' ), 2 );
	}

	/**
	 * The list, and the one order when a detail segment names it.
	 *
	 * @param array<string, mixed> $data   The view's defaults.
	 * @param string               $detail The second URL segment — a payment uuid, or ''.
	 * @return array<string, mixed>
	 */
	public function orders( array $data, string $detail = '' ): array {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return $data;
		}

		// Where "back to orders" goes, derived rather than chopped off an
		// order's own URL.
		$data['section_url'] = $this->section_url();

		if ( '' !== $detail ) {
			$data['detail'] = $this->detail( $detail, $user_id );

			return $data;
		}

		foreach ( $this->store->for_user( $user_id ) as $payment ) {
			$data['orders'][] = $this->row( $payment );
		}

		return $data;
	}

	/**
	 * One payment as a list row.
	 *
	 * @param Payment $payment The payment.
	 * @return array<string, mixed>
	 */
	private function row( Payment $payment ): array {
		return array(
			'uuid'     => $payment->uuid,
			'title'    => $this->title( $payment ),
			'date'     => $this->date( $payment->created_at ),
			'href'     => $this->url( $payment->uuid ),
			'amount'   => $payment->amount_total,
			'original' => $payment->amount_total + $payment->discount_amount,
			'currency' => $payment->currency,
			'status'   => $payment->status,
		);
	}

	/**
	 * One order in full — §7.4 — for its owner only.
	 *
	 * @param string $uuid    The payment being asked for.
	 * @param int    $user_id Who is asking.
	 * @return array<string, mixed>|null
	 */
	private function detail( string $uuid, int $user_id ): ?array {
		$payment = $this->store->find_by_uuid( $uuid );

		// Someone else's order and an order that never existed answer the
		// same way, so the page confirms nothing either.
		if ( null === $payment || $payment->user_id !== $user_id ) {
			return null;
		}

		$detail                 = $this->row( $payment );
		$detail['amount_label'] = Money::format( $payment->amount_total, $payment->currency );
		$detail['contents']     = $this->contents( $payment );
		$detail['access']       = $this->access( $payment );
		$detail['is_new']       = $this->is_new_order( $payment );

		return $detail;
	}

	/**
	 * The access this order actually created, as it stands now.
	 *
	 * Read back by the reference the grant was written under — the same pair
	 * `Stripe_Webhook` revokes by on a refund — so it reflects what the order
	 * produced rather than what it promised. A refunded order's records are
	 * still listed, carrying whatever state the revoke left them in.
	 *
	 * @param Payment $payment The order.
	 * @return array<int, array<string, string>>
	 */
	private function access( Payment $payment ): array {
		$rows = array();

		foreach ( $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) as $access_id ) {
			$type       = sanitize_key( (string) get_post_meta( $access_id, Access_Writer::META_ITEM_TYPE, true ) );
			$identifier = sanitize_text_field( (string) get_post_meta( $access_id, Access_Writer::META_ITEM_ID, true ) );

			$title = $this->labels->text( $type, $identifier );

			if ( '' === $title ) {
				continue;
			}

			$expiry = $this->expiry( $access_id );

			$rows[] = array(
				'title'        => $title,
				'expiry_state' => $expiry['state'],
				'expiry_label' => $expiry['label'],
			);
		}

		return $rows;
	}

	/**
	 * When one access record runs out.
	 *
	 * `META_EXPIRES_AT` holds a UTC MySQL datetime, not a timestamp — it is
	 * written with `gmdate( 'Y-m-d H:i:s' )` (`Access_Writer::reschedule()`),
	 * so it is read back the way every other reader reads it. Casting the
	 * string to an integer would yield the year and date everything a day out.
	 *
	 * An empty value is lifetime, which is what the writer stores for access
	 * that never ends.
	 *
	 * @param int $access_id The access record.
	 * @return array{state: string, label: string}
	 */
	private function expiry( int $access_id ): array {
		$stored = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );

		if ( '' === $stored ) {
			return Expiry::describe( null );
		}

		$stamp = strtotime( $stored . ' +0000' );

		return Expiry::describe( false === $stamp ? null : $stamp );
	}

	/**
	 * Whether this is the order the buyer has just come back to from Stripe.
	 *
	 * The query arg has to name this very payment, and `detail()` has already
	 * established that the payment is theirs — so a pasted uuid belonging to
	 * someone else never reaches here.
	 *
	 * @param Payment $payment The order being viewed.
	 */
	private function is_new_order( Payment $payment ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads nothing and changes nothing; the uuid is matched against the owner's own payment.
		$claimed = isset( $_GET[ self::NEW_ORDER ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::NEW_ORDER ] ) ) : '';

		return '' !== $claimed && $claimed === $payment->uuid;
	}

	/**
	 * What the order was for, frozen as it was on the day.
	 *
	 * Groups are live (architecture.md §1), so the snapshot is what the order
	 * included rather than what those groups hold now — which is why §6.12's
	 * note exists.
	 *
	 * @param Payment $payment The order.
	 * @return array<int, array<string, string>>
	 */
	private function contents( Payment $payment ): array {
		$items = array();

		// The snapshot is what `Checkout::begin_payment()` stored: the
		// product's raw `gatedmedia_items` meta, one `type:identifier` string
		// per entry.
		foreach ( $payment->contents_snapshot as $entry ) {
			list( $type, $identifier ) = Item_Label::split( (string) $entry );

			$text = $this->labels->text( $type, $identifier );

			if ( '' === $text ) {
				continue;
			}

			$items[] = array(
				'text' => $text,
				'icon' => $this->labels->icon( $type ),
			);
		}

		return $items;
	}

	/**
	 * The product's title, as it is now.
	 *
	 * A deleted product still has orders against it, so the row keeps a name
	 * rather than rendering blank.
	 *
	 * @param Payment $payment The order.
	 */
	private function title( Payment $payment ): string {
		$product = get_post( $payment->product_id );

		if ( $product instanceof WP_Post && '' !== $product->post_title ) {
			return $product->post_title;
		}

		return __( 'Access', 'gated-media-access' );
	}

	/**
	 * A stored timestamp in the site's own date format.
	 *
	 * @param string $created_at The row's MySQL datetime.
	 */
	private function date( string $created_at ): string {
		$stamp = strtotime( $created_at );

		return false === $stamp
			? ''
			: (string) wp_date( (string) get_option( 'date_format' ), $stamp );
	}

	/**
	 * Where one order lives.
	 *
	 * The account segment is a filter rather than a setting
	 * (`Account_Route::slug()`), so it is resolved the same way here — a site
	 * that renames the account area renames its order links with it.
	 *
	 * @param string $uuid The payment.
	 */
	private function url( string $uuid ): string {
		return $this->section_url() . $uuid . '/';
	}

	/**
	 * The Orders section itself.
	 */
	private function section_url(): string {
		$slug = apply_filters( 'gatedmedia_account_slug', 'account' );
		$slug = is_string( $slug ) && '' !== $slug ? $slug : 'account';

		return home_url( sprintf( '/%s/orders/', $slug ) );
	}
}
