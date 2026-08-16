<?php
/**
 * §6.1 Account nav — the same four items, drawn two ways.
 *
 * Wide is a 256px column with the active item marked down its left edge;
 * narrow is a horizontally scrolling strip with the active one underlined.
 * §3 is explicit that the sidebar is **replaced, not collapsed** — there is no
 * hamburger anywhere in the corpus.
 *
 * Both variants render and CSS shows one, so switching width needs no JS and
 * no server-side guess about the device. The hidden one is `display: none`, so
 * it is out of the accessibility tree and its `aria-current` is not announced
 * twice.
 *
 * Items are data rather than inner blocks: they come from the section list,
 * which is filtered, so nobody authors them and there is nothing for an editor
 * to compose.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_items = isset( $attributes['items'] ) && is_array( $attributes['items'] )
	? $attributes['items']
	: array();

if ( array() === $gatedmedia_items ) {
	return;
}

$gatedmedia_tabs = 'tabs' === ( isset( $attributes['variant'] ) ? (string) $attributes['variant'] : 'sidebar' );

$gatedmedia_base = $gatedmedia_tabs ? 'gatedmedia-tab-strip' : 'gatedmedia-account-nav';

$gatedmedia_label = isset( $attributes['label'] ) && '' !== $attributes['label']
	? (string) $attributes['label']
	: __( 'Account', 'gated-media-access' );
?>
<nav
	<?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => $gatedmedia_base ) ) ); ?>
	aria-label="<?php echo esc_attr( $gatedmedia_label ); ?>"
>
	<?php foreach ( $gatedmedia_items as $gatedmedia_item ) : ?>
		<?php
		if ( ! is_array( $gatedmedia_item ) || '' === (string) ( $gatedmedia_item['label'] ?? '' ) || '' === (string) ( $gatedmedia_item['href'] ?? '' ) ) {
			continue;
		}

		$gatedmedia_active = true === ( $gatedmedia_item['active'] ?? false );
		$gatedmedia_icon   = isset( $gatedmedia_item['icon'] ) ? (string) $gatedmedia_item['icon'] : '';
		?>
	<a
		class="<?php echo esc_attr( $gatedmedia_base . '__item' . ( $gatedmedia_active ? ' is-active' : '' ) ); ?>"
		href="<?php echo esc_url( (string) $gatedmedia_item['href'] ); ?>"
		<?php echo $gatedmedia_active ? 'aria-current="page"' : ''; ?>
	>
		<?php if ( '' !== $gatedmedia_icon ) : ?>
		<svg
			class="gatedmedia-icon<?php echo $gatedmedia_tabs ? ' gatedmedia-icon--small' : ''; ?>"
			aria-hidden="true"
			focusable="false"
		><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>
		<?php endif; ?>
		<span><?php echo esc_html( (string) $gatedmedia_item['label'] ); ?></span>
	</a>
	<?php endforeach; ?>
</nav>
