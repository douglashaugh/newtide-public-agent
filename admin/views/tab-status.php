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

$npa_plugin      = NPA_Plugin::instance();
$npa_agg         = $npa_plugin->store->aggregates( 50 );
$npa_t_on        = (bool) $settings->get( 'store_transcripts' );
$npa_t_stats     = $npa_plugin->store->transcript_stats();
$npa_t_retention = (int) $settings->get( 'transcript_retention_days', 30 );
$npa_t_next      = wp_next_scheduled( NPA_Plugin::PURGE_HOOK );

// Result of a purge we just performed (read-only notice; no state change here).
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$npa_purged = isset( $_GET['npa_purged'] ) ? absint( $_GET['npa_purged'] ) : null;
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

<?php $npa_admin->card_open( __( 'Transcripts', 'newtide-public-agent' ), __( 'Stored message content, its retention window, and controls to delete it.', 'newtide-public-agent' ) ); ?>

<?php if ( null !== $npa_purged ) : ?>
	<div class="notice notice-success inline">
		<p>
		<?php
		printf(
			/* translators: %d: number of transcript rows deleted. */
			esc_html( _n( 'Deleted %d stored message.', 'Deleted %d stored messages.', $npa_purged, 'newtide-public-agent' ) ),
			(int) $npa_purged
		);
		?>
		</p>
	</div>
<?php endif; ?>

<?php if ( ! $npa_t_on ) : ?>
	<p>
		<?php esc_html_e( 'Transcript storage is off — message content is not being written to the database. Only call metadata is recorded.', 'newtide-public-agent' ); ?>
	</p>
	<?php if ( $npa_t_stats['count'] > 0 ) : ?>
		<p>
			<strong><?php esc_html_e( 'Note:', 'newtide-public-agent' ); ?></strong>
			<?php esc_html_e( 'Messages stored while it was switched on are still held, and are still purged on the retention schedule below.', 'newtide-public-agent' ); ?>
		</p>
	<?php endif; ?>
<?php endif; ?>

<?php if ( $npa_t_on || $npa_t_stats['count'] > 0 ) : ?>
<table class="npa-status widefat striped">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Stored', 'newtide-public-agent' ); ?></th>
			<td>
			<?php
			printf(
				/* translators: 1: message count, 2: conversation count. */
				esc_html__( '%1$d messages across %2$d conversations', 'newtide-public-agent' ),
				(int) $npa_t_stats['count'],
				(int) $npa_t_stats['conversations']
			);
			?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Oldest record', 'newtide-public-agent' ); ?></th>
			<td><?php echo '' !== $npa_t_stats['oldest'] ? esc_html( $npa_t_stats['oldest'] ) : esc_html__( 'None', 'newtide-public-agent' ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Retention', 'newtide-public-agent' ); ?></th>
			<td>
			<?php
			printf(
				/* translators: %d: retention window in days. */
				esc_html( _n( '%d day', '%d days', $npa_t_retention, 'newtide-public-agent' ) ),
				(int) $npa_t_retention
			);
			?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Next purge', 'newtide-public-agent' ); ?></th>
			<td>
			<?php
			if ( $npa_t_next ) {
				printf(
					/* translators: %s: human-readable time until the next scheduled purge. */
					esc_html__( 'in %s', 'newtide-public-agent' ),
					esc_html( human_time_diff( time(), $npa_t_next ) )
				);
			} else {
				esc_html_e( 'Not scheduled — stored content will not expire.', 'newtide-public-agent' );
			}
			?>
			</td>
		</tr>
	</tbody>
</table>

<p class="npa-actions">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
		<?php wp_nonce_field( 'npa_purge_transcripts' ); ?>
		<input type="hidden" name="action" value="npa_purge_transcripts" />
		<input type="hidden" name="scope" value="expired" />
		<button type="submit" class="button"><?php esc_html_e( 'Purge expired now', 'newtide-public-agent' ); ?></button>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
		<?php wp_nonce_field( 'npa_purge_transcripts' ); ?>
		<input type="hidden" name="action" value="npa_purge_transcripts" />
		<input type="hidden" name="scope" value="all" />
		<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete all transcripts', 'newtide-public-agent' ); ?></button>
	</form>
</p>
<?php endif; ?>

<?php
$npa_t_rows = $npa_plugin->store->recent_transcripts( 40 );
if ( $npa_t_rows ) :
	?>
	<h4><?php esc_html_e( 'Most recent messages', 'newtide-public-agent' ); ?></h4>
	<table class="npa-status widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'When', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Who', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Message', 'newtide-public-agent' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $npa_t_rows as $npa_t_row ) : ?>
			<tr>
				<td><?php echo esc_html( $npa_t_row['created_at'] ); ?></td>
				<td>
					<?php
					echo 'visitor' === $npa_t_row['role']
						? esc_html__( 'Visitor', 'newtide-public-agent' )
						: esc_html__( 'Agent', 'newtide-public-agent' );
					?>
				</td>
				<td><?php echo esc_html( wp_trim_words( $npa_t_row['content'], 40, '…' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php $npa_admin->card_close(); ?>
