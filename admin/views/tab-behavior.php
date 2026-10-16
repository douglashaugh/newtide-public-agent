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
	<?php $npa_admin->present_fields( array( 'auto_open_delay', 'hide_on_mobile', 'remember_state', 'audience', 'daily_message_cap', 'conversation_memory', 'log_enabled', 'diagnostics', 'streaming', 'agent_timeout', 'store_transcripts', 'transcript_retention_days', 'transcript_max_conversations' ) ); ?>

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
			<th scope="row"><?php esc_html_e( 'Turn diagnostics', 'newtide-public-agent' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[diagnostics]" value="1" <?php checked( (bool) $settings->get( 'diagnostics' ) ); ?> />
					<?php esc_html_e( 'Trace each message from the visitor’s browser to the agent and back, to find where slow or failed turns are lost.', 'newtide-public-agent' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Timings and outcomes only — never message content. Failed messages show visitors a short reference code you can look up under Service Status → Turn diagnostics. The agent API receives the same id as an X-Request-Id header.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Stream replies', 'newtide-public-agent' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[streaming]" value="1" <?php checked( (bool) $settings->get( 'streaming' ) ); ?> />
					<?php esc_html_e( 'Show the reply as the agent writes it, instead of all at once when it is finished.', 'newtide-public-agent' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Visitors see the first words as soon as the agent starts answering, and a long answer is no longer cut off by the agent timeout as long as it keeps arriving. Works with the Agent API connection; other connections answer all at once as before. If replies still arrive all at once with this on, something in front of the site (a cache or proxy) is holding the stream back — ask your host to allow streaming (text/event-stream) for /wp-json/npa/v1/message.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="npa-agent-timeout"><?php esc_html_e( 'Agent timeout', 'newtide-public-agent' ); ?></label></th>
			<td>
				<input type="number" id="npa-agent-timeout" min="<?php echo esc_attr( (string) NPA_Settings::TIMEOUT_RANGE[0] ); ?>" max="<?php echo esc_attr( (string) NPA_Settings::TIMEOUT_RANGE[1] ); ?>" class="small-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[agent_timeout]" value="<?php echo esc_attr( (string) $settings->get( 'agent_timeout' ) ); ?>" <?php disabled( defined( 'NPA_HTTP_TIMEOUT' ) ); ?> />
				<?php esc_html_e( 'seconds', 'newtide-public-agent' ); ?>
				<p class="description">
					<?php esc_html_e( 'How long this site waits for the agent before giving up. With streaming on, this is how long the agent may go silent — typically while it looks up data, before it starts writing — and a reply that keeps arriving can run longer. Without streaming it is the limit for the whole answer: keep it below your host’s own request timeout (often 60 seconds; 100 on Cloudflare), or the host cuts the visitor off first.', 'newtide-public-agent' ); ?>
					<?php if ( defined( 'NPA_HTTP_TIMEOUT' ) ) : ?>
						<?php
						printf(
							/* translators: %d: seconds. */
							esc_html__( 'Set to %d seconds by NPA_HTTP_TIMEOUT in wp-config.php, which overrides this field.', 'newtide-public-agent' ),
							(int) NPA_HTTP_TIMEOUT
						);
						?>
					<?php endif; ?>
				</p>
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
