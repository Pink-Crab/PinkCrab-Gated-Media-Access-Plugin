<?php
/**
 * §6.11 Filter — a search field and a type filter.
 *
 * §8 conflict 8 is the settled part and the part worth not undoing: the corpus
 * drew search on wide and **no search at all** on narrow. Dropping search on
 * the device most likely to have a long list is backwards, so the search field
 * stays at every width; only the type control changes, from a select to chips.
 *
 * One list of types feeds both controls, so they cannot drift apart.
 *
 * The search input is composed from the field block rather than written again
 * here — it is the same §6.8 field, with its label visually hidden because the
 * placeholder and context already say what it is.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_types = isset( $attributes['types'] ) && is_array( $attributes['types'] )
	? $attributes['types']
	: array();

$gatedmedia_search_label = isset( $attributes['searchLabel'] ) && '' !== $attributes['searchLabel']
	? (string) $attributes['searchLabel']
	: __( 'Search', 'gated-media-access' );

$gatedmedia_type_label = isset( $attributes['typeLabel'] ) && '' !== $attributes['typeLabel']
	? (string) $attributes['typeLabel']
	: __( 'Filter by type', 'gated-media-access' );

$gatedmedia_active = isset( $attributes['active'] ) ? (string) $attributes['active'] : 'all';

$gatedmedia_search = do_blocks(
	sprintf(
		'<!-- wp:gated-media-access/field %s /-->',
		(string) wp_json_encode(
			array(
				'name'        => 'gatedmedia-search',
				'label'       => $gatedmedia_search_label,
				'labelHidden' => true,
				'type'        => 'search',
				'placeholder' => $gatedmedia_search_label,
			)
		)
	)
);
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-filter' ) ) ); ?>>
	<div class="gatedmedia-filter__search" data-gatedmedia-filter="search">
		<?php echo $gatedmedia_search; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the field block. ?>
	</div>

	<?php if ( array() !== $gatedmedia_types ) : ?>
	<label class="gatedmedia-visually-hidden" for="gatedmedia-filter-type"><?php echo esc_html( $gatedmedia_type_label ); ?></label>
	<select class="gatedmedia-filter__type" id="gatedmedia-filter-type" data-gatedmedia-filter="type">
		<?php foreach ( $gatedmedia_types as $gatedmedia_type ) : ?>
			<?php if ( ! is_array( $gatedmedia_type ) || ! isset( $gatedmedia_type['value'], $gatedmedia_type['label'] ) ) : ?>
				<?php continue; ?>
			<?php endif; ?>
		<option
			value="<?php echo esc_attr( (string) $gatedmedia_type['value'] ); ?>"
			<?php selected( (string) $gatedmedia_type['value'], $gatedmedia_active ); ?>
		><?php echo esc_html( (string) $gatedmedia_type['label'] ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>
</div>

<?php if ( array() !== $gatedmedia_types ) : ?>
<div class="gatedmedia-type-chips" role="group" aria-label="<?php echo esc_attr( $gatedmedia_type_label ); ?>">
	<?php foreach ( $gatedmedia_types as $gatedmedia_type ) : ?>
		<?php if ( ! is_array( $gatedmedia_type ) || ! isset( $gatedmedia_type['value'], $gatedmedia_type['label'] ) ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<?php $gatedmedia_is_active = (string) $gatedmedia_type['value'] === $gatedmedia_active; ?>
	<button
		type="button"
		class="gatedmedia-type-chips__chip<?php echo $gatedmedia_is_active ? ' is-active' : ''; ?>"
		data-gatedmedia-chip="<?php echo esc_attr( (string) $gatedmedia_type['value'] ); ?>"
		aria-pressed="<?php echo $gatedmedia_is_active ? 'true' : 'false'; ?>"
	><?php echo esc_html( (string) $gatedmedia_type['label'] ); ?></button>
	<?php endforeach; ?>
</div>
<?php endif; ?>
