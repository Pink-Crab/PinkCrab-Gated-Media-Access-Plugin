<?php
/**
 * The head of one section: what it is, and what it covers, opposite each other over a hairline.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{title: string, note?: string} $data
 */

?>
<div class="gatedmedia-admin-section-head">
	<h2><?php echo esc_html( (string) $data['title'] ); ?></h2>
	<?php if ( '' !== (string) ( $data['note'] ?? '' ) ) : ?>
		<span class="gatedmedia-admin-caps"><?php echo esc_html( (string) $data['note'] ); ?></span>
	<?php endif; ?>
</div>
