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

<?php
/*
 * What the usage table can answer beyond "how many calls".
 *
 * The window is 30 days rather than the 14 of the chart above, because
 * conversations are far rarer than page views and a fortnight of a quiet site
 * is a handful of rows.
 */
$npa_window   = 30;
$npa_convo    = $npa_plugin->store->conversation_stats( $npa_window );
$npa_pages    = $npa_plugin->store->conversation_start_pages( $npa_window, 8 );
$npa_tokens   = $npa_plugin->store->token_totals( $npa_window );
$npa_errors   = $npa_plugin->store->error_breakdown( $npa_window );
$npa_hours    = $npa_plugin->store->busiest_hours( $npa_window );
$npa_peak     = array_keys( $npa_hours, max( $npa_hours ), true );
$npa_has_data = $npa_convo['messages'] > 0;
?>

<?php
$npa_admin->card_open(
	__( 'Conversations', 'newtide-public-agent' ),
	sprintf(
		/* translators: %d: number of days. */
		__( 'The last %d days, from recorded call metadata.', 'newtide-public-agent' ),
		$npa_window
	)
);
?>

<?php if ( ! $npa_has_data ) : ?>
	<p><?php esc_html_e( 'No agent traffic recorded in this window yet.', 'newtide-public-agent' ); ?></p>
<?php else : ?>
	<ul class="npa-stat-grid">
		<li class="npa-stat">
			<span class="npa-stat__value"><?php echo esc_html( number_format_i18n( $npa_convo['conversations'] ) ); ?></span>
			<span class="npa-stat__label"><?php esc_html_e( 'Conversations', 'newtide-public-agent' ); ?></span>
		</li>
		<li class="npa-stat">
			<span class="npa-stat__value"><?php echo esc_html( number_format_i18n( $npa_convo['messages'] ) ); ?></span>
			<span class="npa-stat__label"><?php esc_html_e( 'Questions asked', 'newtide-public-agent' ); ?></span>
		</li>
		<li class="npa-stat">
			<span class="npa-stat__value"><?php echo esc_html( number_format_i18n( $npa_convo['messages_per'] ) ); ?></span>
			<span class="npa-stat__label"><?php esc_html_e( 'Questions per conversation', 'newtide-public-agent' ); ?></span>
		</li>
		<?php if ( ! empty( $npa_peak ) && max( $npa_hours ) > 0 ) : ?>
			<li class="npa-stat">
				<span class="npa-stat__value"><?php echo esc_html( sprintf( '%02d:00', (int) $npa_peak[0] ) ); ?></span>
				<span class="npa-stat__label"><?php esc_html_e( 'Busiest hour', 'newtide-public-agent' ); ?></span>
			</li>
		<?php endif; ?>
		<?php if ( $npa_tokens['messages'] > 0 ) : ?>
			<li class="npa-stat">
				<span class="npa-stat__value"><?php echo esc_html( number_format_i18n( $npa_tokens['input'] + $npa_tokens['output'] ) ); ?></span>
				<span class="npa-stat__label"><?php esc_html_e( 'Tokens used', 'newtide-public-agent' ); ?></span>
			</li>
		<?php endif; ?>
	</ul>

	<?php
	/*
	 * "Questions per conversation" is the number worth reading twice. One
	 * question per conversation across a lot of conversations usually means
	 * people are not getting an answer they can build on.
	 */
	?>
	<p class="description">
		<?php
		if ( $npa_convo['conversations'] > 4 && $npa_convo['messages_per'] < 1.5 ) {
			esc_html_e( 'Most visitors ask once and stop. That can mean the first answer was enough — or that it was not useful enough to follow up. The Conversations tab shows what they actually asked.', 'newtide-public-agent' );
		} else {
			esc_html_e( 'A conversation is one visitor’s session with the agent; questions are the messages they sent within it.', 'newtide-public-agent' );
		}
		?>
	</p>
<?php endif; ?>

<?php $npa_admin->card_close(); ?>

<?php
$npa_admin->card_open(
	__( 'Where conversations start', 'newtide-public-agent' ),
	__( 'The page a visitor was reading when they opened the chat.', 'newtide-public-agent' )
);
?>

<?php if ( empty( $npa_pages ) ) : ?>
	<p>
		<?php esc_html_e( 'No page data yet. The page a conversation starts on has been recorded since version 0.13.0, so this fills in as new conversations happen — earlier traffic has none.', 'newtide-public-agent' ); ?>
	</p>
<?php else : ?>
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Page', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Conversations started', 'newtide-public-agent' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $npa_pages as $npa_row ) : ?>
				<tr>
					<td>
						<?php
						$npa_title = trim( (string) $npa_row['page_title'] );
						$npa_path  = (string) $npa_row['page_path'];
						?>
						<?php if ( '' !== $npa_title ) : ?>
							<strong><?php echo esc_html( $npa_title ); ?></strong><br />
						<?php endif; ?>
						<a href="<?php echo esc_url( home_url( $npa_path ) ); ?>" target="_blank" rel="noopener">
							<code><?php echo esc_html( $npa_path ); ?></code>
						</a>
					</td>
					<td><?php echo esc_html( number_format_i18n( (int) $npa_row['starts'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description">
		<?php esc_html_e( 'Counted once per conversation, at the page it began on — so a visitor who keeps chatting while they browse is credited to where they first asked. Addresses are stored without their query string.', 'newtide-public-agent' ); ?>
	</p>
<?php endif; ?>

<?php $npa_admin->card_close(); ?>

<?php if ( ! empty( $npa_errors ) ) : ?>
	<?php
	$npa_admin->card_open(
		__( 'What has been failing', 'newtide-public-agent' ),
		__( 'Errors recorded in the same window, most frequent first.', 'newtide-public-agent' )
	);
	?>
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Error', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Times', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last seen', 'newtide-public-agent' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $npa_errors as $npa_err ) : ?>
				<tr>
					<td><code><?php echo esc_html( $npa_err['error_code'] ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( (int) $npa_err['hits'] ) ); ?></td>
					<td><?php echo esc_html( mysql2date( 'M j, Y H:i', $npa_err['last_seen'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php $npa_admin->card_close(); ?>
<?php endif; ?>

<?php $npa_admin->card_open( __( 'Health', 'newtide-public-agent' ), __( 'Each dependency the plugin relies on, at a glance.', 'newtide-public-agent' ) ); ?>
<?php
// status_html() is fully escaped at construction.
echo $npa_admin->status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<?php $npa_admin->card_close(); ?>

<?php $npa_admin->card_open( __( 'Connection', 'newtide-public-agent' ), __( 'Whether this site can reach an agent, and what went wrong if it could not.', 'newtide-public-agent' ) ); ?>
<table class="npa-status widefat striped">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Configured', 'newtide-public-agent' ); ?></th>
			<td><?php echo $settings->is_connection_configured() ? esc_html__( 'Yes', 'newtide-public-agent' ) : esc_html( $settings->configuration_hint() ); ?></td>
		</tr>
		<?php
		$npa_last_error = NPA_Rest::last_error();
		if ( $npa_last_error ) :
			?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Last error', 'newtide-public-agent' ); ?></th>
			<td>
				<code><?php echo esc_html( $npa_last_error['code'] ); ?></code>
				<?php if ( $npa_last_error['status'] ) : ?>
					<code><?php echo esc_html( 'HTTP ' . $npa_last_error['status'] ); ?></code>
				<?php endif; ?>
				<?php if ( '' !== $npa_last_error['detail'] ) : ?>
					<p class="description"><?php echo esc_html( $npa_last_error['detail'] ); ?></p>
				<?php endif; ?>
				<?php
				$npa_hint = NPA_Rest::error_hint( $npa_last_error['code'], $npa_last_error['detail'] );
				if ( '' !== $npa_hint ) :
					?>
					<p class="description"><strong><?php esc_html_e( 'Likely cause:', 'newtide-public-agent' ); ?></strong> <?php echo esc_html( $npa_hint ); ?></p>
				<?php endif; ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: human-readable time since the error. */
						esc_html__( '%s ago. Visitors saw only your error message — this detail is shown to administrators.', 'newtide-public-agent' ),
						esc_html( human_time_diff( (int) $npa_last_error['time'], time() ) )
					);
					?>
				</p>
			</td>
		</tr>
		<?php endif; ?>

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
