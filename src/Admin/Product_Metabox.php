<?php
/**
 * The product's one metabox.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Admin\Pickers\File_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\Group_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\Post_Picker;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Money;

/**
 * Price, duration, visibility, the items the product grants, and the email
 * allow-list (spec §1a, §7) — one box on the product editor, behind
 * `gatedmedia_manage_products`.
 *
 * Owns its meta keys, per the round 1 decision: the class that writes a key
 * registers it. Items are chosen through the same pickers as everywhere;
 * each choice becomes a removable row over a `gatedmedia_items[]` hidden
 * input, so the editor's own save carries the list — no second form, no
 * admin-post round trip.
 *
 * Price is typed as a decimal in the product's currency and stored in minor
 * units; `Money` converts both ways with ICU's per-currency digits.
 */
class Product_Metabox implements Hookable {

	public const META_PRICE      = 'gatedmedia_price_amount';
	public const META_CURRENCY   = 'gatedmedia_price_currency';
	public const META_DURATION   = 'gatedmedia_duration_days';
	public const META_VISIBILITY = 'gatedmedia_visibility';
	public const META_ITEMS      = 'gatedmedia_items';
	public const META_EMAILS     = 'gatedmedia_allowed_email';

	/** The nonce field inside the editor form. */
	public const NONCE_FIELD = 'gatedmedia_product_nonce';

	/**
	 * Groups display through the taxonomy's UUID identity.
	 *
	 * @param Access_Taxonomy $taxonomy Turns a UUID back into its term.
	 */
	public function __construct( private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * The keys, their protection, the box and its save.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register_meta' ) );
		$loader->filter( 'is_protected_meta', array( $this, 'protect_meta' ), 3 );
		$loader->admin_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		$loader->action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'save' ) );
	}

	/**
	 * Declares every key this class writes. Nothing is exposed over REST —
	 * the box renders and saves them itself.
	 */
	public function register_meta(): void {
		foreach ( $this->meta_definitions() as $key => $args ) {
			register_post_meta( Post_Types::PRODUCT, $key, $args );
		}
	}

	/**
	 * Marks our keys protected, so nothing treats them as user-editable.
	 *
	 * @param bool   $is_protected Whether the key is already protected.
	 * @param string $meta_key     The key being asked about.
	 * @param string $meta_type    The object type the key is on.
	 */
	public function protect_meta( bool $is_protected, string $meta_key, string $meta_type ): bool {
		if ( 'post' === $meta_type && array_key_exists( $meta_key, $this->meta_definitions() ) ) {
			return true;
		}

		return $is_protected;
	}

	/**
	 * One box, for those who may manage products.
	 */
	public function register_metabox(): void {
		if ( ! current_user_can( Capabilities::manage_products() ) ) {
			return;
		}

		add_meta_box(
			'gatedmedia_product',
			__( 'Product', 'gated-media-access' ),
			array( $this, 'render' ),
			Post_Types::PRODUCT,
			'normal'
		);
	}

	/**
	 * The whole box: pricing, the items list, and the allow-list.
	 *
	 * @param WP_Post $post The product being edited.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_FIELD, self::NONCE_FIELD );

		$this->render_pricing( $post );
		$this->render_items( $post );
		$this->render_emails( $post );
	}

	/**
	 * Price, currency, duration and visibility.
	 *
	 * @param WP_Post $post The product being edited.
	 */
	private function render_pricing( WP_Post $post ): void {
		$currency = (string) get_post_meta( $post->ID, self::META_CURRENCY, true );
		$currency = '' === $currency ? 'GBP' : $currency;
		$price    = (string) get_post_meta( $post->ID, self::META_PRICE, true );
		$duration = (string) get_post_meta( $post->ID, self::META_DURATION, true );
		$listed   = 'unlisted' !== (string) get_post_meta( $post->ID, self::META_VISIBILITY, true );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="gatedmedia_price"><?php esc_html_e( 'Price', 'gated-media-access' ); ?></label></th>
				<td>
					<input type="number" step="any" min="0" class="small-text" name="gatedmedia_price" id="gatedmedia_price" value="<?php echo esc_attr( '' === $price ? '' : Money::to_decimal( (int) $price, $currency ) ); ?>" />
					<input type="text" maxlength="3" class="small-text" name="gatedmedia_currency" id="gatedmedia_currency" value="<?php echo esc_attr( $currency ); ?>" />
					<p class="description"><?php esc_html_e( 'In the currency named beside it (ISO code). 0 is a free product.', 'gated-media-access' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gatedmedia_duration"><?php esc_html_e( 'Duration', 'gated-media-access' ); ?></label></th>
				<td>
					<input type="number" min="1" step="1" class="small-text" name="gatedmedia_duration" id="gatedmedia_duration" value="<?php echo esc_attr( $duration ); ?>" />
					<p class="description"><?php esc_html_e( 'Days of access a purchase grants. Empty for lifetime.', 'gated-media-access' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gatedmedia_visibility"><?php esc_html_e( 'Visibility', 'gated-media-access' ); ?></label></th>
				<td>
					<select name="gatedmedia_visibility" id="gatedmedia_visibility">
						<option value="listed" <?php selected( $listed ); ?>><?php esc_html_e( 'Listed', 'gated-media-access' ); ?></option>
						<option value="unlisted" <?php selected( ! $listed ); ?>><?php esc_html_e( 'Unlisted — direct link only', 'gated-media-access' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * The items the product grants: existing rows with remove, and the
	 * picker controls the admin bundle turns into an add.
	 *
	 * @param WP_Post $post The product being edited.
	 */
	private function render_items( WP_Post $post ): void {
		$items = array_map( 'strval', (array) get_post_meta( $post->ID, self::META_ITEMS, false ) );
		?>
		<h4><?php esc_html_e( 'Items', 'gated-media-access' ); ?></h4>
		<p class="description"><?php esc_html_e( 'What buying this product gives access to. A group covers whatever it contains, now and later.', 'gated-media-access' ); ?></p>
		<ul class="gatedmedia-product-items" id="gatedmedia_product_items">
			<?php foreach ( $items as $item ) : ?>
				<li>
					<?php echo esc_html( $this->item_label( (string) $item ) ); ?>
					<input type="hidden" name="gatedmedia_items[]" value="<?php echo esc_attr( (string) $item ); ?>" />
					<button type="button" class="button-link gatedmedia-remove-item" aria-label="<?php esc_attr_e( 'Remove item', 'gated-media-access' ); ?>">&times;</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="gatedmedia-product-add-item">
			<select id="gatedmedia_item_type">
				<option value="group"><?php esc_html_e( 'Group', 'gated-media-access' ); ?></option>
				<option value="post"><?php esc_html_e( 'Post', 'gated-media-access' ); ?></option>
				<option value="file"><?php esc_html_e( 'File', 'gated-media-access' ); ?></option>
			</select>
			<span data-gatedmedia-row="group"><?php ( new Group_Picker( 'gatedmedia_add_group', 'gatedmedia_product_group' ) )->render(); ?></span>
			<span data-gatedmedia-row="post"><?php ( new Post_Picker( 'gatedmedia_add_post', 'gatedmedia_product_post' ) )->render(); ?></span>
			<span data-gatedmedia-row="file"><?php ( new File_Picker( 'gatedmedia_add_file', 'gatedmedia_product_file' ) )->render(); ?></span>
			<button type="button" class="button" id="gatedmedia_add_item"><?php esc_html_e( 'Add item', 'gated-media-access' ); ?></button>
		</div>
		<?php
	}

	/**
	 * The allow-list, one address per line. Empty means anyone may buy.
	 *
	 * @param WP_Post $post The product being edited.
	 */
	private function render_emails( WP_Post $post ): void {
		$emails = array_map( 'strval', (array) get_post_meta( $post->ID, self::META_EMAILS, false ) );
		?>
		<h4><label for="gatedmedia_allowed_emails"><?php esc_html_e( 'Email allow-list', 'gated-media-access' ); ?></label></h4>
		<p class="description"><?php esc_html_e( 'One address per line. Leave empty to let anyone buy. Who may buy and how it is found (visibility) are separate questions.', 'gated-media-access' ); ?></p>
		<textarea class="large-text" rows="4" name="gatedmedia_allowed_emails" id="gatedmedia_allowed_emails"><?php echo esc_textarea( implode( "\n", $emails ) ); ?></textarea>
		<?php
	}

	/**
	 * Persists the box on the editor's own save.
	 *
	 * @param int $post_id The product being saved.
	 */
	public function save( int $post_id ): void {
		if ( ! $this->may_save( $post_id ) ) {
			return;
		}

		$this->save_pricing( $post_id );
		$this->save_repeated( $post_id, self::META_ITEMS, $this->posted_items() );
		$this->save_repeated( $post_id, self::META_EMAILS, $this->posted_emails() );
	}

	/**
	 * The save guards: a verified nonce, a real save, a permitted user.
	 *
	 * @param int $post_id The product being saved.
	 */
	private function may_save( int $post_id ): bool {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( false === wp_verify_nonce( $nonce, self::NONCE_FIELD ) ) {
			return false;
		}

		if ( false !== wp_is_post_autosave( $post_id ) || false !== wp_is_post_revision( $post_id ) ) {
			return false;
		}

		return current_user_can( Capabilities::manage_products() );
	}

	/**
	 * Price, currency, duration, visibility — currency first, since the
	 * price's minor units depend on it.
	 *
	 * @param int $post_id The product being saved.
	 */
	private function save_pricing( int $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in save().
		$currency = isset( $_POST['gatedmedia_currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['gatedmedia_currency'] ) ) ) : '';
		$currency = 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'GBP';

		$price    = isset( $_POST['gatedmedia_price'] ) ? sanitize_text_field( wp_unslash( $_POST['gatedmedia_price'] ) ) : '';
		$duration = isset( $_POST['gatedmedia_duration'] ) ? absint( wp_unslash( $_POST['gatedmedia_duration'] ) ) : 0;
		$listed   = ! isset( $_POST['gatedmedia_visibility'] ) || 'unlisted' !== sanitize_key( wp_unslash( $_POST['gatedmedia_visibility'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_post_meta( $post_id, self::META_CURRENCY, $currency );
		update_post_meta( $post_id, self::META_PRICE, Money::to_minor( $price, $currency ) );
		update_post_meta( $post_id, self::META_DURATION, 0 === $duration ? '' : $duration );
		update_post_meta( $post_id, self::META_VISIBILITY, $listed ? 'listed' : 'unlisted' );
	}

	/**
	 * The submitted items, validated to `type:id` with a type we grant.
	 *
	 * @return array<int, string>
	 */
	private function posted_items(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in save().
		$posted = isset( $_POST['gatedmedia_items'] ) && is_array( $_POST['gatedmedia_items'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['gatedmedia_items'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array_values(
			array_filter(
				$posted,
				static fn ( string $item ): bool => 1 === preg_match( '/^(file|post|group):.+$/', $item )
			)
		);
	}

	/**
	 * The submitted allow-list, one valid address per line.
	 *
	 * @return array<int, string>
	 */
	private function posted_emails(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in save().
		$raw = isset( $_POST['gatedmedia_allowed_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['gatedmedia_allowed_emails'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$lines  = preg_split( '/\R+/', $raw );
		$emails = array_map( 'sanitize_email', is_array( $lines ) ? $lines : array() );

		return array_values( array_filter( $emails, static fn ( string $email ): bool => '' !== $email ) );
	}

	/**
	 * Replaces a repeated key's rows with the submitted set.
	 *
	 * @param int                $post_id The product being saved.
	 * @param string             $key     The repeated key.
	 * @param array<int, string> $rows    Its new rows.
	 */
	private function save_repeated( int $post_id, string $key, array $rows ): void {
		delete_post_meta( $post_id, $key );

		foreach ( $rows as $row ) {
			add_post_meta( $post_id, $key, $row );
		}
	}

	/**
	 * A stored `type:id` as a person reads it.
	 *
	 * @param string $item One stored row.
	 */
	private function item_label( string $item ): string {
		list( $type, $identifier ) = array_pad( explode( ':', $item, 2 ), 2, '' );

		if ( 'group' === $type ) {
			$term = $this->taxonomy->find_group( $identifier );

			/* translators: %s: group name. */
			return sprintf( __( 'Group: %s', 'gated-media-access' ), null === $term ? $identifier : $term->name );
		}

		$title = get_the_title( (int) $identifier );

		/* translators: 1: item type, 2: item title. */
		return sprintf( __( '%1$s: %2$s', 'gated-media-access' ), ucfirst( $type ), '' === $title ? "#{$identifier}" : $title );
	}

	/**
	 * One definition per key (spec §1a). The repeated pair are multi-row;
	 * nothing is exposed over REST.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function meta_definitions(): array {
		$single_text = array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => '__return_false',
		);

		$repeated_text = array(
			'type'              => 'string',
			'single'            => false,
			'show_in_rest'      => false,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => '__return_false',
		);

		return array(
			self::META_PRICE      => array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => '__return_false',
			),
			self::META_CURRENCY   => $single_text,
			self::META_DURATION   => $single_text,
			self::META_VISIBILITY => $single_text,
			self::META_ITEMS      => $repeated_text,
			self::META_EMAILS     => $repeated_text,
		);
	}
}
