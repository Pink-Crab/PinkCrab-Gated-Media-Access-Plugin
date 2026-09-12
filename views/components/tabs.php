<?php
/**
 * Section navigation within one screen.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{tabs: array<int, array{label: string, url: string, active: bool}>} $data
 */

?>
<nav class="gatedmedia-admin-tabs">
	<?php foreach ( $data['tabs'] as $entry ) : ?>
		<a class="<?php echo $entry['active'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( (string) $entry['url'] ); ?>"><?php echo esc_html( (string) $entry['label'] ); ?></a>
	<?php endforeach; ?>
</nav>
