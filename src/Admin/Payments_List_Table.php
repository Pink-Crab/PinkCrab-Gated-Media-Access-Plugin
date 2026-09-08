<?php
/**
 * The payments list.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_List_Table;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Support\Money;

/**
 * The one list we write from nothing: payments live in their own table, outside WP_Query, so core's list screens cannot render them.
 *
 * Read-only, with no row actions, no bulk actions and nothing to click, because the rows are the record of what Stripe did and that record is not editable.
 */
class Payments_List_Table extends WP_List_Table {

	/** Rows per page. */
	private const PER_PAGE = 20;

	/**
	 * Reads only. The store's status movers belong to checkout and the webhook.
	 *
	 * @var Payment_Store
	 */
	private Payment_Store $store;

	/**
	 * Builds over the one store.
	 *
	 * @param Payment_Store $store The payments table's owner.
	 */
	public function __construct( Payment_Store $store ) {
		$this->store = $store;

		parent::__construct(
			array(
				'singular' => 'payment',
				'plural'   => 'payments',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The columns. No checkbox, because nothing here takes a bulk action.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'created'   => __( 'Date', 'gated-media-access' ),
			'holder'    => __( 'User', 'gated-media-access' ),
			'product'   => __( 'Product', 'gated-media-access' ),
			'amount'    => __( 'Amount', 'gated-media-access' ),
			'coupon'    => __( 'Coupon', 'gated-media-access' ),
			'status'    => __( 'Status', 'gated-media-access' ),
			'reference' => __( 'Reference', 'gated-media-access' ),
		);
	}

	/**
	 * One page of rows, newest first, plus the pagination maths.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$page  = max( 1, $this->get_pagenum() );
		$total = $this->store->total();

		$this->items = $this->store->paged( $page, self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * When the payment was created, UTC as stored.
	 *
	 * @param Payment $item The row.
	 */
	public function column_created( Payment $item ): string {
		return esc_html( $item->created_at );
	}

	/**
	 * Who paid, linked to their profile.
	 *
	 * @param Payment $item The row.
	 */
	public function column_holder( Payment $item ): string {
		$user = get_userdata( $item->user_id );

		if ( false === $user ) {
			/* translators: %d: user id. */
			return esc_html( sprintf( __( 'User %d (deleted)', 'gated-media-access' ), $item->user_id ) );
		}

		$link = get_edit_user_link( $item->user_id );

		if ( '' === $link ) {
			return esc_html( $user->display_name );
		}

		return sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $user->display_name ) );
	}

	/**
	 * What they bought, linked to the product's editor.
	 *
	 * @param Payment $item The row.
	 */
	public function column_product( Payment $item ): string {
		if ( null === get_post( $item->product_id ) ) {
			/* translators: %d: product id. */
			return esc_html( sprintf( __( 'Product %d (deleted)', 'gated-media-access' ), $item->product_id ) );
		}

		$title = get_the_title( $item->product_id );
		$title = '' === $title ? __( '(no title)', 'gated-media-access' ) : $title;
		$link  = get_edit_post_link( $item->product_id );

		// No edit link is a permissions fact about the viewer, not the product.
		if ( null === $link ) {
			return esc_html( $title );
		}

		return sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ) );
	}

	/**
	 * What was paid, in that payment's own currency.
	 *
	 * @param Payment $item The row.
	 */
	public function column_amount( Payment $item ): string {
		return esc_html( Money::format( $item->amount_total, $item->currency ) );
	}

	/**
	 * The coupon and what it took off, or an em dash when none applied.
	 *
	 * @param Payment $item The row.
	 */
	public function column_coupon( Payment $item ): string {
		if ( 0 === $item->coupon_id ) {
			return esc_html( Money::not_applicable() );
		}

		$code = get_the_title( $item->coupon_id );

		return esc_html(
			sprintf(
				/* translators: 1: coupon code, 2: discount amount. */
				__( '%1$s (−%2$s)', 'gated-media-access' ),
				'' === $code ? (string) $item->coupon_id : $code,
				Money::format( $item->discount_amount, $item->currency )
			)
		);
	}

	/**
	 * The status, spelled for a person.
	 *
	 * @param Payment $item The row.
	 */
	public function column_status( Payment $item ): string {
		$labels = array(
			Payment::STATUS_PENDING  => __( 'Pending', 'gated-media-access' ),
			Payment::STATUS_COMPLETE => __( 'Complete', 'gated-media-access' ),
			Payment::STATUS_REFUNDED => __( 'Refunded', 'gated-media-access' ),
			Payment::STATUS_FAILED   => __( 'Failed', 'gated-media-access' ),
		);

		return esc_html( $labels[ $item->status ] ?? $item->status );
	}

	/**
	 * The public identifier, what support asks for and Stripe echoes back, linked to the payment's own page.
	 *
	 * @param Payment $item The row.
	 */
	public function column_reference( Payment $item ): string {
		return sprintf(
			'<a href="%s"><code>%s</code></a>',
			esc_url( Payment_Detail_Page::url_for( $item->uuid ) ),
			esc_html( $item->uuid )
		);
	}

	/**
	 * What an empty table says.
	 */
	public function no_items(): void {
		esc_html_e( 'No payments yet.', 'gated-media-access' );
	}
}
