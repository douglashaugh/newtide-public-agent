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
$npa_pages        = get_pages(
	array(
		'post_status' => 'publish',
		'sort_column' => 'menu_order,post_title',
	)
);
$npa_page_scope   = (string) $settings->get( 'page_scope', 'all' );
$npa_page_ids     = array_map( 'absint', (array) $settings->get( 'page_ids', array() ) );
?>
<?php $npa_admin->tab_intro( 'dashicons-admin-links', __( 'Agent connection', 'newtide-public-agent' ), __( 'Link this site to your published NewTide agent and choose where it appears.', 'newtide-public-agent' ) ); ?>
<form method="post" action="options.php" class="npa-form">
	<?php settings_fields( NPA_Settings::GROUP ); ?>
	<?php
	$npa_present = array( 'mode', 'placement', 'page_scope', 'page_ids', 'gateway_base_url', 'agent_id', 'daily_message_cap', 'log_enabled', 'store_transcripts', 'transcript_retention_days' );
	if ( ! $npa_key_constant ) {
		$npa_present[] = 'gateway_key';
	}
	if ( ! defined( 'NPA_PUBLIC_KEY' ) ) {
		$npa_present[] = 'public_key';
	}
	if ( ! defined( 'NPA_PLATFORM_URL' ) ) {
		$npa_present[] = 'platform_url';
	}
	$npa_admin->present_fields( $npa_present );
	?>

	<div class="npa-columns">
	<?php $npa_admin->card_open( __( 'Connection', 'newtide-public-agent' ), __( 'How this site talks to your NewTide agent.', 'newtide-public-agent' ) ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="npa-mode"><?php esc_html_e( 'Connection mode', 'newtide-public-agent' ); ?></label></th>
			<td>
				<select id="npa-mode" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[mode]">
					<option value="proxy" <?php selected( $settings->get_mode(), 'proxy' ); ?>><?php esc_html_e( 'Proxy — the plugin’s own widget via the server-side gateway', 'newtide-public-agent' ); ?></option>
					<option value="embed" <?php selected( $settings->get_mode(), 'embed' ); ?>><?php esc_html_e( 'Embed — RisingTide’s public widget via a publishable key', 'newtide-public-agent' ); ?></option>
				</select>
				<p class="description"><?php echo wp_kses_post( __( '<strong>Embed</strong> injects RisingTide’s official <code>agent-embed.js</code> using a publishable <code>pk_</code> key — recommended for published public agents. <strong>Proxy</strong> uses the plugin’s own chat widget through a server-side gateway credential. See the <em>Publishing</em> tab for how to get a key.', 'newtide-public-agent' ) ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="npa-public-key"><?php esc_html_e( 'Publishable key', 'newtide-public-agent' ); ?></label></th>
			<td>
				<?php if ( defined( 'NPA_PUBLIC_KEY' ) ) : ?>
					<p><span class="npa-pill npa-pill--ok"><?php esc_html_e( 'Defined in wp-config.php', 'newtide-public-agent' ); ?></span></p>
					<p class="description"><?php esc_html_e( 'Set via the NPA_PUBLIC_KEY constant.', 'newtide-public-agent' ); ?></p>
				<?php else : ?>
					<input type="text" id="npa-public-key" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[public_key]" value="<?php echo esc_attr( $settings->get( 'public_key' ) ); ?>" placeholder="pk_…" />
					<p class="description"><?php esc_html_e( 'The pk_ key from RisingTide (Advanced Settings → Create key). Used in Embed mode. This key is publishable — it is meant to appear in page HTML; access is scoped by the key’s allowed-origins list.', 'newtide-public-agent' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="npa-platform-url"><?php esc_html_e( 'Platform URL (advanced)', 'newtide-public-agent' ); ?></label></th>
			<td>
				<?php if ( defined( 'NPA_PLATFORM_URL' ) ) : ?>
					<input type="url" id="npa-platform-url" class="regular-text" value="<?php echo esc_attr( $settings->get_platform_url() ); ?>" disabled />
					<p class="description"><?php esc_html_e( 'Defined via the NPA_PLATFORM_URL constant.', 'newtide-public-agent' ); ?></p>
				<?php else : ?>
					<input type="url" id="npa-platform-url" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[platform_url]" value="<?php echo esc_attr( $settings->get( 'platform_url' ) ); ?>" placeholder="https://ai.newtide.ai" />
					<p class="description"><?php esc_html_e( 'Advanced — leave as the production default (https://ai.newtide.ai) unless NewTide tells you otherwise. (Internal UAT testing uses https://uat-ai.newtide.ai.)', 'newtide-public-agent' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="npa-placement"><?php esc_html_e( 'Embed placement', 'newtide-public-agent' ); ?></label></th>
			<td>
				<select id="npa-placement" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[placement]">
					<option value="floating" <?php selected( $settings->get( 'placement' ), 'floating' ); ?>><?php esc_html_e( 'Floating bubble (site-wide)', 'newtide-public-agent' ); ?></option>
					<option value="inline" <?php selected( $settings->get( 'placement' ), 'inline' ); ?>><?php esc_html_e( 'Inline (via the [newtide_agent] shortcode or block)', 'newtide-public-agent' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Floating shows RisingTide’s bottom-right bubble on every allowed page. Inline mounts the chat where you place the shortcode/block (one per page). Applies to Embed mode; Appearance/Behavior options do not affect the embedded widget — those are set in RisingTide.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>
	</table>
	<?php $npa_admin->card_close(); ?>

	<?php $npa_admin->card_open( __( 'Pages', 'newtide-public-agent' ), __( 'Where the chat is allowed to appear across your site.', 'newtide-public-agent' ) ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Show on pages', 'newtide-public-agent' ); ?></th>
			<td>
				<fieldset>
					<label>
						<input type="radio" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[page_scope]" value="all" <?php checked( $npa_page_scope, 'all' ); ?> />
						<?php esc_html_e( 'All pages', 'newtide-public-agent' ); ?>
					</label><br />
					<label>
						<input type="radio" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[page_scope]" value="selected" <?php checked( $npa_page_scope, 'selected' ); ?> />
						<?php esc_html_e( 'Only the pages I select below', 'newtide-public-agent' ); ?>
					</label>
					<?php if ( ! empty( $npa_pages ) ) : ?>
						<ul class="npa-page-list">
							<?php foreach ( $npa_pages as $npa_page ) : ?>
								<li>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[page_ids][]" value="<?php echo esc_attr( (string) $npa_page->ID ); ?>" <?php checked( in_array( (int) $npa_page->ID, $npa_page_ids, true ) ); ?> />
										<?php echo esc_html( '' !== $npa_page->post_title ? $npa_page->post_title : __( '(no title)', 'newtide-public-agent' ) ); ?>
										<span class="description">#<?php echo (int) $npa_page->ID; ?></span>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No published pages found.', 'newtide-public-agent' ); ?></p>
					<?php endif; ?>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Where the chat may appear. “All pages” shows it site-wide; “Only the pages I select” limits it to the checked pages. Still subject to the Enable toggle and the audience / hide-list rules on the Behavior tab.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>

	</table>
	<?php $npa_admin->card_close(); ?>

	<?php $npa_admin->card_open( __( 'Proxy-mode settings', 'newtide-public-agent' ), __( 'Used only when Connection mode is Proxy — the server-side gateway path.', 'newtide-public-agent' ) ); ?>
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
	<?php $npa_admin->card_close(); ?>
	</div>

	<p class="npa-actions">
		<button type="button" class="button" id="npa-test-connection"><?php esc_html_e( 'Test connection', 'newtide-public-agent' ); ?></button>
		<span id="npa-test-result" class="npa-test-result" role="status" aria-live="polite"></span>
	</p>

	<?php submit_button(); ?>
</form>
