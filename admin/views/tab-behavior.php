<?php
/**
 * Behavior tab — how the widget acts and where it appears.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;
?>
<?php $npa_admin->tab_intro( 'dashicons-controls-repeat', __( 'Behavior', 'newtide-public-agent' ), __( 'Decide when the chat opens itself and who gets to see it.', 'newtide-public-agent' ) ); ?>

<?php $npa_admin->mode_scope_notice( 'behavior' ); ?>
<form method="post" action="options.php" class="npa-form">
	<?php settings_fields( NPA_Settings::GROUP ); ?>
	<?php $npa_admin->present_fields( array( 'auto_open_delay', 'hide_on_mobile', 'remember_state', 'audience', 'daily_message_cap', 'conversation_memory', 'log_enabled', 'store_transcripts', 'transcript_retention_days', 'transcript_max_conversations' ) ); ?>

	<div class="npa-columns">
		<?php $npa_admin->card_open( __( 'Timing', 'newtide-public-agent' ), __( 'When the panel opens on its own.', 'newtide-public-agent' ) ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="npa-auto-open-delay"><?php esc_html_e( 'Auto-open delay', 'newtide-public-agent' ); ?></label></th>
				<td>
					<input type="number" id="npa-auto-open-delay" class="small-text" min="0" max="600" step="1" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[auto_open_delay]" value="<?php echo esc_attr( (string) $settings->get( 'auto_open_delay' ) ); ?>" />
					<?php esc_html_e( 'seconds', 'newtide-public-agent' ); ?>
					<p class="description"><?php esc_html_e( 'Open the chat automatically after this many seconds. 0 disables auto-open.', 'newtide-public-agent' ); ?></p>
				</td>
			</tr>
		</table>
		<?php $npa_admin->card_close(); ?>

		<?php $npa_admin->card_open( __( 'Device & memory', 'newtide-public-agent' ), __( 'How the widget behaves per device and across visits.', 'newtide-public-agent' ) ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Mobile', 'newtide-public-agent' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[hide_on_mobile]" value="1" <?php checked( (bool) $settings->get( 'hide_on_mobile' ) ); ?> />
						<?php esc_html_e( 'Hide the widget on small screens (under 600px).', 'newtide-public-agent' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Remember state', 'newtide-public-agent' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[remember_state]" value="1" <?php checked( (bool) $settings->get( 'remember_state' ) ); ?> />
						<?php esc_html_e( 'Reopen the chat automatically if the visitor had it open (stored in their browser).', 'newtide-public-agent' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php $npa_admin->card_close(); ?>

		<?php $npa_admin->card_open( __( 'Who sees it', 'newtide-public-agent' ), __( 'Limit the widget to an audience, or keep it off certain pages.', 'newtide-public-agent' ) ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="npa-audience"><?php esc_html_e( 'Audience', 'newtide-public-agent' ); ?></label></th>
				<td>
					<select id="npa-audience" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[audience]">
						<option value="everyone" <?php selected( $settings->get( 'audience' ), 'everyone' ); ?>><?php esc_html_e( 'Everyone', 'newtide-public-agent' ); ?></option>
						<option value="logged_in" <?php selected( $settings->get( 'audience' ), 'logged_in' ); ?>><?php esc_html_e( 'Logged-in users only', 'newtide-public-agent' ); ?></option>
						<option value="anonymous" <?php selected( $settings->get( 'audience' ), 'anonymous' ); ?>><?php esc_html_e( 'Logged-out visitors only', 'newtide-public-agent' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Which visitors see the widget at all. Which pages it appears on is set on the Agent tab.', 'newtide-public-agent' ); ?></p>
				</td>
			</tr>
		</table>
		<?php $npa_admin->card_close(); ?>
	</div>


	<?php
	/*
	 * These four were on the Agent tab, inside a card scoped to Proxy mode, so
	 * selecting Agent API hid them entirely — and where they were visible they
	 * read as settings for one connection mode. None of them are: the cap,
	 * memory, logging and transcript storage apply to whichever transport is in
	 * use. They belong with the rest of how the plugin behaves.
	 */
	$npa_admin->card_open(
		__( 'Operation', 'newtide-public-agent' ),
		__( 'Limits, memory, and what the plugin keeps. These apply to every connection mode.', 'newtide-public-agent' )
	);
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="npa-cap"><?php esc_html_e( 'Daily message cap', 'newtide-public-agent' ); ?></label></th>
			<td>
				<input type="number" id="npa-cap" min="0" class="small-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[daily_message_cap]" value="<?php echo esc_attr( (string) $settings->get( 'daily_message_cap' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Courtesy limiter on this site. 0 = unlimited. Real rate limiting is enforced upstream by the agent API, which returns a retry time the widget passes on to the visitor.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Conversation memory', 'newtide-public-agent' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[conversation_memory]" value="1" <?php checked( (bool) $settings->get( 'conversation_memory' ) ); ?> />
					<?php esc_html_e( 'Let the agent follow up on what was said earlier in the same chat.', 'newtide-public-agent' ); ?>
				</label>
				<p class="description">
					<?php
					printf(
						/* translators: 1: turn limit, 2: how long a conversation is kept. */
						esc_html__( 'The agent API is single-turn — it answers each message with no memory of the last, so "and who runs it?" cannot work on its own. This site keeps the last %1$d exchanges for %2$s and sends them as context. Visitors get a "New chat" button to start over.', 'newtide-public-agent' ),
						(int) NPA_Conversation::MAX_TURNS,
						esc_html( human_time_diff( 0, NPA_Conversation::TTL ) )
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'A workaround for a platform limitation, and it has a cost: earlier messages are replayed to the agent, so each turn is longer, and visitor text ends up inside the prompt. Turn this off once the agent platform supports conversations itself.', 'newtide-public-agent' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Logging', 'newtide-public-agent' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[log_enabled]" value="1" <?php checked( (bool) $settings->get( 'log_enabled' ) ); ?> />
					<?php esc_html_e( 'Keep a diagnostic log of the last 50 calls (metadata only — never message content).', 'newtide-public-agent' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'For troubleshooting. Service Status draws on the usage table and reports whether this is on or off.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Store transcripts', 'newtide-public-agent' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[store_transcripts]" value="1" <?php checked( (bool) $settings->get( 'store_transcripts' ) ); ?> />
					<?php esc_html_e( 'Persist message content (off by default; introduces PII/retention obligations).', 'newtide-public-agent' ); ?>
				</label>
				<label class="npa-inline">
					<?php esc_html_e( 'Retention (days):', 'newtide-public-agent' ); ?>
					<input type="number" min="1" max="3650" class="small-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[transcript_retention_days]" value="<?php echo esc_attr( (string) $settings->get( 'transcript_retention_days' ) ); ?>" />
				</label>
				<label class="npa-inline">
					<?php esc_html_e( 'Keep at most (conversations):', 'newtide-public-agent' ); ?>
					<input type="number" min="0" max="100000" class="small-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[transcript_max_conversations]" value="<?php echo esc_attr( (string) $settings->get( 'transcript_max_conversations' ) ); ?>" />
				</label>
				<p class="description">
					<?php esc_html_e( 'Stores what visitors type and what the agent replies, readable on the Conversations tab. Anything older than the retention window is deleted by a daily job.', 'newtide-public-agent' ); ?>
					<?php esc_html_e( 'The conversation limit is a second ceiling applied after the age limit — 0 means no limit. Whole conversations are removed, oldest first, never half an exchange.', 'newtide-public-agent' ); ?>
					<?php esc_html_e( 'Turning storage off stops new storage; it does not delete what is already held — use Delete all on the Service Status tab for that.', 'newtide-public-agent' ); ?>
				</p>
			</td>
		</tr>
	</table>
	<?php $npa_admin->card_close(); ?>

	<?php submit_button(); ?>
</form>
