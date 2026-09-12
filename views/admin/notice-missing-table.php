<?php
/**
 * Shown when the payments table could not be created. A checkout that cannot be recorded is worse than one that never starts, so nothing else of ours runs.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{table: string, plugins_url: string} $data
 */

?>
<div class="notice notice-error">
	<p>
		<?php
		printf(
			/* translators: %s: the database table name. */
			esc_html__( 'Gated Media Access could not create its payments table (%s), so nothing in the plugin is running.', 'gated-media-access' ),
			esc_html( $data['table'] )
		);
		?>
	</p>
	<p><?php esc_html_e( 'The database user usually needs permission to create tables. Once that is granted, deactivate and reactivate the plugin to try again.', 'gated-media-access' ); ?></p>
	<p><a href="<?php echo esc_url( $data['plugins_url'] ); ?>"><?php esc_html_e( 'Go to Plugins', 'gated-media-access' ); ?></a></p>
</div>
