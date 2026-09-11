<?php
/**
 * One person's access records, on their profile screen.
 *
 * Core's own table, because this sits inside core's profile form rather than on a screen of ours. Read-only: granting and revoking live on the Access screens, one link away.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{rows: array<int, array<string, string>>, columns: array<string, string>, manage_url: string} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<h2><?php esc_html_e( 'Access', 'gated-media-access' ); ?></h2>

<?php if ( array() === $data['rows'] ) : ?>
	<?php View::render( 'components/empty', array( 'message' => __( 'This user holds no access records.', 'gated-media-access' ) ) ); ?>
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
						<td><?php echo $row[ $column ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the Access list's own column renderer. ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p><a href="<?php echo esc_url( $data['manage_url'] ); ?>"><?php esc_html_e( 'Manage on the Access screen', 'gated-media-access' ); ?></a></p>
<?php endif; ?>
