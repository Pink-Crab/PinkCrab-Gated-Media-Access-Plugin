<?php
/**
 * Section heading: uppercase 12px, wide-tracked, muted.
 *
 * The small form at both widths. It is a label rather than a title, because the page already has its heading and keeping this small stops it competing.
 *
 * The tag is still a real heading and its level is settable, because the visual size says nothing about document structure, and inside the account shell the page title is the h1, so these are h2 by default.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_text = isset( $attributes['text'] ) ? (string) $attributes['text'] : '';

if ( '' === $gatedmedia_text ) {
	return;
}

$gatedmedia_level = isset( $attributes['level'] ) ? (int) $attributes['level'] : 2;
$gatedmedia_tag   = 'h' . (string) min( 6, max( 2, $gatedmedia_level ) );
?>
<<?php echo esc_html( $gatedmedia_tag ); ?> <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-section-heading' ) ) ); ?>>
<?php
	echo esc_html( $gatedmedia_text );
?>
</<?php echo esc_html( $gatedmedia_tag ); ?>>
