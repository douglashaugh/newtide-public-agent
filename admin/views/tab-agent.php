<?php
/**
 * Agent tab — gateway connection, credential status, agent picker, and the
 * "Test connection" action.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;

$npa_key_source   = $settings->key_source();
$npa_key_constant = defined( 'NPA_GATEWAY_KEY' );
$npa_agents       = $npa_admin->available_agents();
$npa_current      = $settings->get_agent_id();
?>
<form method="post" action="options.php" class="npa-form">
	<?php settings_fields( NPA_Settings::GROUP ); ?>
	<?php
	$npa_present = array( 'gateway_base_url', 'agent_id', 'daily_message_cap', 'log_enabled', 'store_transcripts', 'transcript_retention_days' );
	if ( ! $npa_key_constant ) {
		$npa_present[] = 'gateway_key';
	}
	$npa_admin->present_fields( $npa_present );
	?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="npa-base-url"><?php esc_html_e( 'Gateway base URL', 'newtide-public-agent' ); ?></label></th>
			<td>
				<?php if ( defined( 'NPA_GATEWAY_BASE_URL' ) ) : ?>
					<input type="url" id="npa-base-url" class="regular-text" value="<?php echo esc_attr( $settings->get_gateway_base_url() ); ?>" disabled />
					<p class="description"><?php esc_html_e( 'Defined via the NPA_GATEWAY_BASE_URL constant.', 'newtide-public-agent' ); ?></p>
				<?php else : ?>
					<input type="url" id="npa-base-url" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[gateway_base_url]" value="<?php echo esc_attr( $settings->get( 'gateway_base_url' ) ); ?>" placeholder="https://…" />
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Gateway credential', 'newtide-public-agent' ); ?></th>
			<td>
				<?php if ( $npa_key_constant ) : ?>
					<p><span class="npa-pill npa-pill--ok"><?php esc_html_e( 'Configured via wp-config.php', 'newtide-public-agent' ); ?></span></p>
					<p class="description"><?php esc_html_e( 'The credential is defined by the NPA_GATEWAY_KEY constant and never stored in the database. This is the recommended setup.', 'newtide-public-agent' ); ?></p>
				<?php else : ?>
					<input type="password" id="npa-key" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[gateway_key]" value="" autocomplete="new-password" placeholder="<?php echo 'none' === $npa_key_source ? esc_attr__( 'Not set', 'newtide-public-agent' ) : esc_attr__( '••••••••  (leave blank to keep)', 'newtide-public-agent' ); ?>" />
					<?php if ( 'none' !== $npa_key_source ) : ?>
						<p><span class="npa-pill npa-pill--ok"><?php esc_html_e( 'A credential is set', 'newtide-public-agent' ); ?></span></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Stored write-only; the saved value is never shown. Prefer defining NPA_GATEWAY_KEY in wp-config.php instead.', 'newtide-public-agent' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="npa-agent-id"><?php esc_html_e( 'Agent', 'newtide-public-agent' ); ?></label></th>
			<td>
				<?php if ( ! empty( $npa_agents ) ) : ?>
					<select id="npa-agent-id" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[agent_id]">
						<?php
						$npa_found = false;
						foreach ( $npa_agents as $npa_agent ) :
							$npa_found = $npa_found || ( $npa_agent->id === $npa_current );
							?>
							<option value="<?php echo esc_attr( $npa_agent->id ); ?>" <?php selected( $npa_agent->id, $npa_current ); ?>>
								<?php echo esc_html( '' !== $npa_agent->name ? $npa_agent->name : $npa_agent->id ); ?>
							</option>
						<?php endforeach; ?>
						<?php if ( ! $npa_found && '' !== $npa_current ) : ?>
							<option value="<?php echo esc_attr( $npa_current ); ?>" selected><?php echo esc_html( $npa_current ); ?></option>
						<?php endif; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Published agents available to this credential.', 'newtide-public-agent' ); ?></p>
				<?php else : ?>
					<input type="text" id="npa-agent-id" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[agent_id]" value="<?php echo esc_attr( $npa_current ); ?>" />
					<p class="description"><?php esc_html_e( 'Enter the published agent ID. (A list will appear here once the gateway can be reached.)', 'newtide-public-agent' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="npa-cap"><?php esc_html_e( 'Daily message cap', 'newtide-public-agent' ); ?></label></th>
			<td>
				<input type="number" id="npa-cap" min="0" class="small-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[daily_message_cap]" value="<?php echo esc_attr( (string) $settings->get( 'daily_message_cap' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Courtesy limiter. 0 = unlimited. (Abuse prevention is enforced by the gateway.)', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Logging', 'newtide-public-agent' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[log_enabled]" value="1" <?php checked( (bool) $settings->get( 'log_enabled' ) ); ?> />
					<?php esc_html_e( 'Record call metadata (no message content) for the status panel.', 'newtide-public-agent' ); ?>
				</label>
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
			</td>
		</tr>
	</table>

	<p class="npa-actions">
		<button type="button" class="button" id="npa-test-connection"><?php esc_html_e( 'Test connection', 'newtide-public-agent' ); ?></button>
		<span id="npa-test-result" class="npa-test-result" role="status" aria-live="polite"></span>
	</p>

	<?php submit_button(); ?>
</form>
