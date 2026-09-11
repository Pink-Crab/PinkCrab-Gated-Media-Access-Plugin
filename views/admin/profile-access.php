<?php
/**
 * One person's access records, on their profile screen.
 *
 * Read-only: granting and revoking live on the Access screens, one link away.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{rows: array<int, array<string, string>>, columns: array<string, string>, manage_url: string} $data
 */

?>
<h2><?php esc_html_e( 'Access', 'gated-media-access' ); ?></h2>

<?php if ( array() === $data['rows'] ) : ?>
	<p><?php esc_html_e( 'This user holds no access records.', 'gated-media-access' ); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<?php foreach ( $data['columns'] as $label ) : ?>
					<th><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['rows'] as $row ) : ?>
				<tr>
					<?php foreach ( array_keys( $data['columns'] ) as $column ) : ?>
						<td><?php echo wp_kses_post( $row[ $column ] ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p><a href="<?php echo esc_url( $data['manage_url'] ); ?>"><?php esc_html_e( 'Manage on the Access screen', 'gated-media-access' ); ?></a></p>
<?php endif; ?>
