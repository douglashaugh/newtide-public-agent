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
<h2><?php esc_html_e( 'Service status', 'newtide-public-agent' ); ?></h2>
<?php
// status_html() is fully escaped at construction.
echo $npa_admin->status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>

<h2><?php esc_html_e( 'Recent activity', 'newtide-public-agent' ); ?></h2>
<table class="npa-status widefat striped">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Configured', 'newtide-public-agent' ); ?></th>
			<td><?php echo $settings->is_configured() ? esc_html__( 'Yes', 'newtide-public-agent' ) : esc_html__( 'No — set the gateway URL, credential, and agent', 'newtide-public-agent' ); ?></td>
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
			<td><?php echo esc_html( (string) $npa_agg['avg_latency_ms'] . ' ms' ); ?></td>
		</tr>
	</tbody>
</table>
