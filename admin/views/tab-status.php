<?php
/**
 * Service Status tab — live health roll-up + recent-activity summary.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;

$npa_plugin = NPA_Plugin::instance();
$npa_agg    = $npa_plugin->store->aggregates( 50 );
?>
<?php $npa_admin->tab_intro( 'dashicons-heart', __( 'Service status', 'newtide-public-agent' ), __( 'A live health roll-up and a snapshot of recent agent traffic.', 'newtide-public-agent' ) ); ?>

<?php $npa_admin->card_open( __( 'Usage analytics', 'newtide-public-agent' ), __( 'Traffic over the last two weeks, drawn from recorded call metadata.', 'newtide-public-agent' ) ); ?>
<?php
// analytics_html() is fully escaped at construction.
echo $npa_admin->analytics_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<?php $npa_admin->card_close(); ?>

<?php $npa_admin->card_open( __( 'Health', 'newtide-public-agent' ), __( 'Each dependency the plugin relies on, at a glance.', 'newtide-public-agent' ) ); ?>
<?php
// status_html() is fully escaped at construction.
echo $npa_admin->status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<?php $npa_admin->card_close(); ?>

<?php $npa_admin->card_open( __( 'Recent activity', 'newtide-public-agent' ), __( 'Configuration state and the last 50 recorded calls.', 'newtide-public-agent' ) ); ?>
<table class="npa-status widefat striped">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Configured', 'newtide-public-agent' ); ?></th>
			<td><?php echo $settings->is_connection_configured() ? esc_html__( 'Yes', 'newtide-public-agent' ) : esc_html( $settings->configuration_hint() ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Recent calls (last 50)', 'newtide-public-agent' ); ?></th>
			<td><?php echo esc_html( (string) $npa_agg['count'] ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Error rate', 'newtide-public-agent' ); ?></th>
			<td><?php echo esc_html( number_format_i18n( $npa_agg['error_rate'] * 100, 1 ) . '%' ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Average latency', 'newtide-public-agent' ); ?></th>
			<td>
				<?php
				if ( $npa_agg['live_count'] > 0 ) {
					echo esc_html( (string) $npa_agg['avg_latency_ms'] . ' ms' );
					if ( $npa_agg['mock_count'] > 0 ) {
						echo ' <span class="description">';
						printf(
							/* translators: %d: number of mock-served calls excluded from the average. */
							esc_html( _n( '(excludes %d mock call)', '(excludes %d mock calls)', (int) $npa_agg['mock_count'], 'newtide-public-agent' ) ),
							(int) $npa_agg['mock_count']
						);
						echo '</span>';
					}
				} else {
					esc_html_e( 'No live calls yet — recent traffic was served by the built-in mock.', 'newtide-public-agent' );
				}
				?>
			</td>
		</tr>
	</tbody>
</table>
<?php $npa_admin->card_close(); ?>
