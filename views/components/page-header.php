<?php
/**
 * A screen's header: the plugin's name over the screen's own, and the one action that applies to the whole screen.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{title: string, kicker?: string, action?: array{label: string, type?: string, form?: string, url?: string}} $data
 */

$button = $data['action'] ?? null;

?>
<header class="gatedmedia-admin-header">
	<div>
		<span class="gatedmedia-admin-caps"><?php echo esc_html( (string) ( $data['kicker'] ?? __( 'Gated Media Access', 'gated-media-access' ) ) ); ?></span>
		<h1><?php echo esc_html( (string) $data['title'] ); ?></h1>
	</div>

	<?php if ( null !== $button ) : ?>
		<?php if ( isset( $button['url'] ) ) : ?>
			<a class="gatedmedia-admin-button" href="<?php echo esc_url( (string) $button['url'] ); ?>"><?php echo esc_html( (string) $button['label'] ); ?></a>
		<?php else : ?>
			<button type="<?php echo esc_attr( (string) ( $button['type'] ?? 'submit' ) ); ?>" class="gatedmedia-admin-button" <?php echo isset( $button['form'] ) ? 'form="' . esc_attr( (string) $button['form'] ) . '"' : ''; ?>><?php echo esc_html( (string) $button['label'] ); ?></button>
		<?php endif; ?>
	<?php endif; ?>
</header>
