<?php
/**
 * One Stripe key field.
 *
 * A publishable key is plain text. A secret renders empty whatever is stored, marked when there is one, because the stored value never travels back to the browser.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{option: string, row: array{type: string, label: string, name: string, value: string, has_value: bool}} $data
 */

$row = $data['row'];

?>
<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_<?php echo esc_attr( $row['name'] ); ?>"><?php echo esc_html( $row['label'] ); ?></label>

	<?php if ( 'secret' === $row['type'] ) : ?>
		<input type="password" class="large-text code" name="<?php echo esc_attr( $data['option'] ); ?>[<?php echo esc_attr( $row['name'] ); ?>]" id="gatedmedia_<?php echo esc_attr( $row['name'] ); ?>" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $row['has_value'] ? __( 'saved, leave empty to keep', 'gated-media-access' ) : '' ); ?>" />
		<p class="gatedmedia-admin-help">
			<?php
			echo esc_html(
				$row['has_value']
					? __( 'A value is saved. It is never shown; type to replace it.', 'gated-media-access' )
					: __( 'Nothing saved yet.', 'gated-media-access' )
			);
			?>
		</p>
	<?php else : ?>
		<input type="text" class="large-text code" name="<?php echo esc_attr( $data['option'] ); ?>[<?php echo esc_attr( $row['name'] ); ?>]" id="gatedmedia_<?php echo esc_attr( $row['name'] ); ?>" value="<?php echo esc_attr( $row['value'] ); ?>" autocomplete="off" />
	<?php endif; ?>
</div>
