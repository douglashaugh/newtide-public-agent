<?php
/**
 * Service Status → Turn diagnostics: where slow or failed turns were lost.
 *
 * Included by tab-status.php.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin  Admin controller.
 * @var NPA_Settings $settings   Settings store.
 * @var NPA_Plugin   $npa_plugin Plugin instance.
 */

defined( 'ABSPATH' ) || exit;

$npa_diag    = $npa_plugin->diagnostics;
$npa_diag_on = $npa_diag->enabled();
$npa_summary = $npa_diag->summary( 7 );
$npa_turns   = $npa_diag->recent_turns( 25, 7 );
$npa_hops    = NPA_Diagnostics::hop_labels();
$npa_php_max = (int) ini_get( 'max_execution_time' );
$npa_mcp_on  = $npa_plugin->mcp->is_enabled();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$npa_cleared = isset( $_GET['npa_diag_cleared'] ) ? absint( $_GET['npa_diag_cleared'] ) : null;

/**
 * Milliseconds as seconds, one decimal.
 *
 * @param int $ms Milliseconds.
 * @return string
 */
$npa_secs = static function ( $ms ) {
	return (int) $ms > 0
		/* translators: %s: seconds, e.g. 4.2. */
		? sprintf( __( '%ss', 'newtide-public-agent' ), number_format_i18n( (int) $ms / 1000, 1 ) )
		: '—';
};
?>
<div id="npa-diagnostics"></div>
<?php $npa_admin->card_open( __( 'Turn diagnostics', 'newtide-public-agent' ), __( 'Where slow or failed messages are being lost, over the last 7 days. Timings only — never message content.', 'newtide-public-agent' ) ); ?>

<?php if ( null !== $npa_cleared ) : ?>
	<div class="notice notice-success inline"><p>
		<?php
		printf(
			/* translators: %d: number of events deleted. */
			esc_html( _n( 'Cleared %d diagnostic event.', 'Cleared %d diagnostic events.', $npa_cleared, 'newtide-public-agent' ) ),
			(int) $npa_cleared
		);
		?>
	</p></div>
<?php endif; ?>

<?php if ( ! $npa_diag_on ) : ?>
	<p><?php esc_html_e( 'Turn diagnostics are off. Switch them on under Behavior to start tracing messages; what is shown below was recorded while they were on.', 'newtide-public-agent' ); ?></p>
<?php endif; ?>

<p class="npa-diag__verdict<?php echo $npa_summary['dropped'] > 0 ? ' is-problem' : ''; ?>"><?php echo esc_html( NPA_Diagnostics::verdict_text( $npa_summary ) ); ?></p>

<div class="npa-diag__grid">
	<div>
		<h4><?php esc_html_e( 'Where turns failed', 'newtide-public-agent' ); ?></h4>
		<?php if ( $npa_summary['by_hop'] ) : ?>
			<table class="npa-status widefat striped">
				<tbody>
				<?php foreach ( $npa_summary['by_hop'] as $npa_hop => $npa_count ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( isset( $npa_hops[ $npa_hop ] ) ? $npa_hops[ $npa_hop ] : $npa_hop ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $npa_count ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'None.', 'newtide-public-agent' ); ?></p>
		<?php endif; ?>
	</div>

	<div>
		<h4><?php esc_html_e( 'How long each hop takes', 'newtide-public-agent' ); ?></h4>
		<table class="npa-status widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Hop', 'newtide-public-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Typical', 'newtide-public-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Slowest 5%', 'newtide-public-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Worst', 'newtide-public-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$npa_rows = array(
				array( __( 'Agent API (the agent and its model)', 'newtide-public-agent' ), $npa_summary['agent'] ),
				array( __( 'WordPress around it', 'newtide-public-agent' ), $npa_summary['wordpress'] ),
				array( __( 'What the visitor waited', 'newtide-public-agent' ), $npa_summary['browser'] ),
				array( __( 'Each site-knowledge (MCP) call', 'newtide-public-agent' ), $npa_summary['mcp'] ),
				array( __( 'WordPress starting up for each MCP call', 'newtide-public-agent' ), $npa_summary['mcp_boot'] ),
			);
			foreach ( $npa_rows as $npa_row ) :
				?>
				<tr>
					<th scope="row">
						<?php echo esc_html( $npa_row[0] ); ?>
						<span class="npa-diag__n">
						<?php
						/* translators: %s: sample count. */
						echo esc_html( sprintf( __( 'n = %s', 'newtide-public-agent' ), number_format_i18n( (int) $npa_row[1]['count'] ) ) );
						?>
						</span>
					</th>
					<td><?php echo esc_html( $npa_secs( $npa_row[1]['p50'] ) ); ?></td>
					<td><?php echo esc_html( $npa_secs( $npa_row[1]['p95'] ) ); ?></td>
					<td><?php echo esc_html( $npa_secs( $npa_row[1]['max'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<h4><?php esc_html_e( 'Limits on this site', 'newtide-public-agent' ); ?></h4>
<table class="npa-status widefat striped">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Agent timeout', 'newtide-public-agent' ); ?></th>
			<td>
				<?php
				printf(
					/* translators: %d: seconds. */
					esc_html__( '%d seconds', 'newtide-public-agent' ),
					(int) $npa_summary['timeout']
				);
				if ( $npa_summary['near'] > 0 ) {
					echo ' — ';
					printf(
						/* translators: %d: count. */
						esc_html( _n( '%d answer came within 20%% of it', '%d answers came within 20%% of it', (int) $npa_summary['near'], 'newtide-public-agent' ) ),
						(int) $npa_summary['near']
					);
				}
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'PHP max_execution_time', 'newtide-public-agent' ); ?></th>
			<td>
				<?php
				echo 0 === $npa_php_max
					? esc_html__( 'Unlimited', 'newtide-public-agent' )
					/* translators: %d: seconds. */
					: esc_html( sprintf( __( '%d seconds', 'newtide-public-agent' ), $npa_php_max ) );
				?>
				<p class="description"><?php esc_html_e( 'On Linux this counts PHP’s own work, not time spent waiting for the agent. The limits that usually cut a slow turn off are the host’s: its proxy timeout and PHP-FPM’s request_terminate_timeout. Neither is visible from here — ask the host.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Site knowledge (MCP)', 'newtide-public-agent' ); ?></th>
			<td>
				<?php
				echo $npa_mcp_on
					? esc_html__( 'On — each turn may call back into this site, using a second PHP worker while the first waits.', 'newtide-public-agent' )
					: esc_html__( 'Off', 'newtide-public-agent' );
				?>
			</td>
		</tr>
	</tbody>
</table>

<h4><?php esc_html_e( 'Recent turns', 'newtide-public-agent' ); ?></h4>
<?php if ( $npa_turns ) : ?>
	<p class="description"><?php esc_html_e( 'A visitor who saw an error was shown the Ref. below. Send it to the agent platform team with the agent API id: they can find the same request in their logs.', 'newtide-public-agent' ); ?></p>
	<div class="npa-diag__scroll">
	<table class="widefat striped npa-diag__turns">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'When', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Ref.', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Outcome', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Agent API', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'WordPress', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Visitor waited', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'MCP calls', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Page', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Agent API id', 'newtide-public-agent' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $npa_turns as $npa_turn ) : ?>
			<?php
			$npa_class  = $npa_turn['class'];
			$npa_mcp_ms = 0;
			foreach ( $npa_turn['mcp'] as $npa_call ) {
				$npa_mcp_ms += (int) $npa_call['latency_ms'];
			}
			if ( (int) $npa_turn['client_status'] >= 400 ) {
				/* translators: 1: HTTP status, 2: seconds. */
				$npa_client = sprintf( __( 'HTTP %1$d after %2$s', 'newtide-public-agent' ), (int) $npa_turn['client_status'], $npa_secs( $npa_turn['client_ms'] ) );
			} elseif ( 0 === (int) $npa_turn['client_status'] && (int) $npa_turn['client_ms'] > 0 ) {
				// The browser gave up, or the connection dropped, with no status at all.
				/* translators: %s: seconds. */
				$npa_client = sprintf( __( 'No reply after %s', 'newtide-public-agent' ), $npa_secs( $npa_turn['client_ms'] ) );
			} else {
				$npa_client = $npa_secs( $npa_turn['client_ms'] );
			}
			?>
			<tr>
				<td><?php echo esc_html( mysql2date( 'M j, H:i:s', $npa_turn['created_at'] ) ); ?></td>
				<td><code><?php echo esc_html( NPA_Diagnostics::reference( $npa_turn['turn_id'] ) ); ?></code></td>
				<td>
					<span class="npa-pill <?php echo esc_attr( $npa_class['ok'] ? ( 'slow' === $npa_class['code'] ? 'npa-pill--slow' : 'npa-pill--ok' ) : 'npa-pill--warn' ); ?>"><?php echo esc_html( $npa_class['ok'] ? ( 'slow' === $npa_class['code'] ? __( 'Slow', 'newtide-public-agent' ) : __( 'OK', 'newtide-public-agent' ) ) : __( 'Failed', 'newtide-public-agent' ) ); ?></span>
					<?php echo esc_html( $npa_class['label'] ); ?>
				</td>
				<td><?php echo esc_html( $npa_secs( $npa_turn['latency_ms'] ) ); ?></td>
				<td><?php echo esc_html( $npa_secs( max( 0, (int) $npa_turn['server_ms'] - (int) $npa_turn['latency_ms'] ) ) ); ?></td>
				<td><?php echo esc_html( $npa_client ); ?></td>
				<td>
					<?php
					echo $npa_turn['mcp']
						/* translators: 1: number of calls, 2: total seconds. */
						? esc_html( sprintf( __( '%1$d (%2$s)', 'newtide-public-agent' ), count( $npa_turn['mcp'] ), $npa_secs( $npa_mcp_ms ) ) )
						: '—';
					?>
				</td>
				<td><?php echo esc_html( '' !== $npa_turn['page_path'] ? $npa_turn['page_path'] : '—' ); ?></td>
				<td><?php echo '' !== $npa_turn['upstream_id'] ? '<code>' . esc_html( $npa_turn['upstream_id'] ) . '</code>' : '—'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
<?php else : ?>
	<p><?php esc_html_e( 'No turns traced yet.', 'newtide-public-agent' ); ?></p>
<?php endif; ?>

<p class="npa-actions">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
		<?php wp_nonce_field( 'npa_clear_diagnostics' ); ?>
		<input type="hidden" name="action" value="npa_clear_diagnostics" />
		<button type="submit" class="button"><?php esc_html_e( 'Clear diagnostic events', 'newtide-public-agent' ); ?></button>
	</form>
	<span class="description">
		<?php
		printf(
			/* translators: %d: days. */
			esc_html__( 'Browser reports and MCP timings are deleted after %d days automatically. Message counts and history are not affected.', 'newtide-public-agent' ),
			(int) NPA_Diagnostics::RETENTION_DAYS
		);
		?>
	</span>
</p>

<?php $npa_admin->card_close(); ?>
