<?php
/**
 * Summary: a count line, in meta type.
 *
 * "12 files, 45 posts". The one-line form of the contents list, used where the count is incidental to something else rather than the point of it.
 *
 * Takes either a ready-made string or a set of counts to phrase, and the counts form exists so the pluralisation happens once here rather than at each call site, which is where "1 files" comes from.
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

// A phrased string wins; otherwise build one from the counts.
if ( '' === $gatedmedia_text && array() !== ( $attributes['counts'] ?? array() ) && is_array( $attributes['counts'] ) ) {
	$gatedmedia_parts = array();

	foreach ( $attributes['counts'] as $gatedmedia_entry ) {
		if ( ! is_array( $gatedmedia_entry ) || ! isset( $gatedmedia_entry['count'], $gatedmedia_entry['type'] ) ) {
			continue;
		}

		$gatedmedia_count = (int) $gatedmedia_entry['count'];

		if ( 0 === $gatedmedia_count ) {
			continue;
		}

		switch ( (string) $gatedmedia_entry['type'] ) {
			case 'file':
				/* translators: %s: number of files. */
				$gatedmedia_parts[] = sprintf( _n( '%s file', '%s files', $gatedmedia_count, 'gated-media-access' ), number_format_i18n( $gatedmedia_count ) );
				break;
			case 'post':
				/* translators: %s: number of posts. */
				$gatedmedia_parts[] = sprintf( _n( '%s post', '%s posts', $gatedmedia_count, 'gated-media-access' ), number_format_i18n( $gatedmedia_count ) );
				break;
			case 'group':
				/* translators: %s: number of groups. */
				$gatedmedia_parts[] = sprintf( _n( '%s group', '%s groups', $gatedmedia_count, 'gated-media-access' ), number_format_i18n( $gatedmedia_count ) );
				break;
		}
	}

	$gatedmedia_text = implode( ', ', $gatedmedia_parts );
}

if ( '' === $gatedmedia_text ) {
	return;
}
?>
<p <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-summary' ) ) ); ?>>
<?php
	echo esc_html( $gatedmedia_text );
?>
</p>
