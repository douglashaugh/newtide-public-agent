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
$npa_mode         = $settings->get_mode();
/*
 * Which route Proxy mode takes. This MUST mirror NPA_Plugin::gateway_client()
 * exactly: a dedicated gateway wins only when is_configured() passes — base URL
 * AND agent id AND credential. An earlier version treated a stored credential
 * alone as "legacy", which hid this panel's public-API sections on a site whose
 * runtime was using the public API regardless. The old Publishing guide told
 * people to paste their pk_ key into the gateway credential, so a stray one is
 * common; see $npa_unused_credential below.
 */
$npa_legacy_gw    = $settings->is_configured();
$npa_public_api   = $settings->public_api_available() && ! $npa_legacy_gw;
$npa_resolved     = $npa_public_api ? $npa_agents : array();

// A credential that is stored but cannot be reached by any code path.
$npa_unused_credential = $settings->gateway_key_is_set() && ! $npa_legacy_gw;
$npa_is_embed     = ( 'embed' === $npa_mode );
$npa_page_ids     = array_map( 'absint', (array) $settings->get( 'page_ids', array() ) );
?>
<?php $npa_admin->tab_intro( 'dashicons-admin-links', __( 'Agent connection', 'newtide-public-agent' ), __( 'Link this site to your published NewTide agent and choose where it appears.', 'newtide-public-agent' ) ); ?>
<form method="post" action="options.php" class="npa-form">
	<?php settings_fields( NPA_Settings::GROUP ); ?>
	<?php
	// Keys this form is responsible for; anything omitted keeps its stored value.
	$npa_present = array( 'mode', 'placement', 'page_scope', 'page_ids', 'gateway_base_url', 'agent_id', 'daily_message_cap', 'log_enabled', 'store_transcripts', 'transcript_retention_days', 'conversation_memory' );
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
				<p class="description"><?php echo wp_kses_post( __( '<strong>Embed</strong> injects RisingTide’s official <code>agent-embed.js</code> using a publishable <code>pk_</code> key — recommended for published public agents. <strong>Proxy</strong> relays through your server to the same agent API, and renders the plugin’s own chat widget — so the Appearance and Behavior tabs apply. Both modes use the publishable key below. See the <em>Publishing</em> tab for how to get one.', 'newtide-public-agent' ) ); ?></p>
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
					<p class="description"><?php esc_html_e( 'The pk_ key from RisingTide (Advanced Settings → Create key). Used by both connection modes, and it is what selects the agent. The key is publishable — designed to appear in page HTML — and access is scoped by its allowed-origins list, which must include this site’s address.', 'newtide-public-agent' ); ?></p>
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
			<th scope="row"><label for="npa-placement"><?php esc_html_e( 'Placement', 'newtide-public-agent' ); ?></label></th>
			<td>
				<select id="npa-placement" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[placement]">
					<option value="floating" <?php selected( $settings->get( 'placement' ), 'floating' ); ?>><?php esc_html_e( 'Floating bubble (site-wide)', 'newtide-public-agent' ); ?></option>
					<option value="inline" <?php selected( $settings->get( 'placement' ), 'inline' ); ?>><?php esc_html_e( 'Inline (via the [newtide_agent] shortcode or block)', 'newtide-public-agent' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Floating shows the chat bubble automatically on every allowed page — no shortcode needed. Inline mounts it only where you place the shortcode or block. Applies to both connection modes. In Embed mode the Appearance and Behavior options do not affect the widget; those are set in RisingTide.', 'newtide-public-agent' ); ?></p>
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

	<div data-npa-mode="proxy" <?php echo $npa_is_embed ? 'hidden' : ''; ?>>
	<?php if ( $settings->public_api_available() ) : ?>
		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'Proxy mode uses the public agent API.', 'newtide-public-agent' ); ?></strong>
				<?php
				printf(
					/* translators: %s: the derived API host. */
					esc_html__( 'Messages are relayed through your server to %s using the publishable key above, and this site’s own address is sent as the origin — so your site URL must be in the key’s allowed-origins list, exactly as it is for Embed mode.', 'newtide-public-agent' ),
					'<code>' . esc_html( (string) wp_parse_url( $settings->get_public_api_base_url(), PHP_URL_HOST ) ) . '</code>'
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'This API is not yet formally documented by NewTide. It is what the embedded widget itself calls, so it is the same service — but confirm with the platform team before relying on it for a production site.', 'newtide-public-agent' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'Proxy mode has nothing to talk to yet.', 'newtide-public-agent' ); ?></strong>
				<?php esc_html_e( 'Set a publishable key and platform URL above and it will relay through the public agent API. Without either, the plugin answers from its built-in mock — an administrator sees those canned replies when previewing, visitors are shown your error message instead.', 'newtide-public-agent' ); ?>
			</p>
		</div>
	<?php endif; ?>
	<?php if ( $npa_unused_credential ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'A gateway credential is stored but not being used.', 'newtide-public-agent' ); ?></strong>
				<?php esc_html_e( 'This site is talking to the public agent API, which authenticates with the publishable key instead. A dedicated gateway needs all three of an API base URL, an agent ID and this credential before it takes over.', 'newtide-public-agent' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'If you pasted your pk_ key here — an earlier version of the Publishing guide wrongly said to — clear it and put it in Publishable key above. It is doing nothing where it is.', 'newtide-public-agent' ); ?>
			</p>
		</div>
	<?php endif; ?>
	<?php $npa_admin->card_open( __( 'Gateway settings', 'newtide-public-agent' ), __( 'The server-side gateway path. Used only by Proxy mode.', 'newtide-public-agent' ) ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="npa-base-url"><?php esc_html_e( 'API base URL (advanced)', 'newtide-public-agent' ); ?></label></th>
			<td>
				<?php if ( defined( 'NPA_GATEWAY_BASE_URL' ) ) : ?>
					<input type="url" id="npa-base-url" class="regular-text" value="<?php echo esc_attr( $settings->get_gateway_base_url() ); ?>" disabled />
					<p class="description"><?php esc_html_e( 'Defined via the NPA_GATEWAY_BASE_URL constant.', 'newtide-public-agent' ); ?></p>
				<?php else : ?>
					<input type="url" id="npa-base-url" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[gateway_base_url]" value="<?php echo esc_attr( $settings->get( 'gateway_base_url' ) ); ?>" placeholder="https://…" />
					<p class="description">
						<?php
						$npa_derived = NPA_Gateway_Client_Public::api_base_from_platform( $settings->get_platform_url() );
						if ( '' !== $npa_derived ) {
							printf(
								/* translators: %s: the API base URL derived from the platform URL. */
								esc_html__( 'Leave blank. The API address is derived from your Platform URL — currently %s. Set this only if NewTide gives you a different endpoint.', 'newtide-public-agent' ),
								'<code>' . esc_html( $npa_derived ) . '</code>'
							);
						} else {
							esc_html_e( 'Leave blank unless NewTide gives you a dedicated endpoint. Normally the API address is derived from your Platform URL.', 'newtide-public-agent' );
						}
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Gateway credential (advanced)', 'newtide-public-agent' ); ?></th>
			<td>
				<?php if ( $npa_key_constant ) : ?>
					<p><span class="npa-pill npa-pill--ok"><?php esc_html_e( 'Configured via wp-config.php', 'newtide-public-agent' ); ?></span></p>
					<p class="description"><?php esc_html_e( 'The credential is defined by the NPA_GATEWAY_KEY constant and never stored in the database. This is the recommended setup.', 'newtide-public-agent' ); ?></p>
				<?php else : ?>
					<input type="password" id="npa-key" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[gateway_key]" value="" autocomplete="new-password" placeholder="<?php echo 'none' === $npa_key_source ? esc_attr__( 'Not set', 'newtide-public-agent' ) : esc_attr__( '••••••••  (leave blank to keep)', 'newtide-public-agent' ); ?>" />
					<?php if ( 'none' !== $npa_key_source ) : ?>
						<p><span class="npa-pill npa-pill--ok"><?php esc_html_e( 'A credential is set', 'newtide-public-agent' ); ?></span></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Only for a dedicated gateway. The public agent API authenticates with the publishable key above, so most sites leave this empty. Stored write-only; the saved value is never shown. Prefer defining NPA_GATEWAY_KEY in wp-config.php.', 'newtide-public-agent' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="npa-agent-id"><?php esc_html_e( 'Agent', 'newtide-public-agent' ); ?></label></th>
			<td>
				<?php if ( $npa_public_api ) : ?>
					<?php
					/*
					 * On the public API the key selects the agent — nothing here
					 * chooses it. Show what the key actually resolves to, and
					 * carry that id forward so the stored value stops drifting:
					 * a stale id is harmless to the conversation but misattributes
					 * every row in the usage table and the busiest-agents chart.
					 */
					$npa_live = ! empty( $npa_resolved ) ? $npa_resolved[0] : null;
					?>
					<?php if ( $npa_live ) : ?>
						<p>
							<span class="npa-pill npa-pill--ok"><?php echo esc_html( '' !== $npa_live->name ? $npa_live->name : $npa_live->id ); ?></span>
						</p>
						<?php if ( '' !== $npa_live->id ) : ?>
							<p class="description"><code><?php echo esc_html( $npa_live->id ); ?></code></p>
						<?php endif; ?>
						<input type="hidden" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[agent_id]" value="<?php echo esc_attr( $npa_live->id ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Resolved from your publishable key. To use a different agent, create a key on that agent in RisingTide — there is nothing to choose here.', 'newtide-public-agent' ); ?>
							<?php if ( '' !== $npa_current && $npa_live->id !== $npa_current ) : ?>
								<br /><strong><?php esc_html_e( 'Saving this tab will replace the stored agent ID with the one above.', 'newtide-public-agent' ); ?></strong>
								<?php printf( /* translators: %s: the stale agent id currently stored. */ esc_html__( 'It currently reads %s, which is not the agent answering.', 'newtide-public-agent' ), '<code>' . esc_html( $npa_current ) . '</code>' ); ?>
							<?php endif; ?>
						</p>
					<?php else : ?>
						<p><span class="npa-pill"><?php esc_html_e( 'Not resolved yet', 'newtide-public-agent' ); ?></span></p>
						<input type="hidden" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[agent_id]" value="<?php echo esc_attr( $npa_current ); ?>" />
						<p class="description"><?php esc_html_e( 'The key selects the agent, so nothing is chosen here. Run Test connection below — once the API answers, the agent it resolves to is shown.', 'newtide-public-agent' ); ?></p>
					<?php endif; ?>
				<?php elseif ( ! empty( $npa_agents ) ) : ?>
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
					<p class="description"><?php esc_html_e( 'Enter the published agent ID for a dedicated gateway. (A list appears here once the gateway can be reached.)', 'newtide-public-agent' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>

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
				<p class="description">
					<?php esc_html_e( 'Stores what visitors type and what the agent replies. Anything older than the retention window is deleted by a daily job. Turning this off stops new storage; it does not delete what is already held — use Delete all on the Service Status tab for that.', 'newtide-public-agent' ); ?>
				</p>
			</td>
		</tr>
	</table>
	<?php $npa_admin->card_close(); ?>
	</div>
	</div>

	<?php if ( $settings->public_api_available() ) : ?>
		<?php $npa_admin->card_open( __( 'Conversation probe (diagnostic)', 'newtide-public-agent' ), __( 'Does the agent remember anything between messages?', 'newtide-public-agent' ) ); ?>
		<p class="description">
			<?php esc_html_e( 'The agent API takes a single message and its own embed widget sends nothing else, so each turn may arrive with no memory of the last. This asks the agent to remember a random code, then asks for it back — trying a few request shapes in case the server supports threading its client never uses.', 'newtide-public-agent' ); ?>
		</p>
		<p class="description">
			<strong><?php esc_html_e( 'This talks to your live agent.', 'newtide-public-agent' ); ?></strong>
			<?php esc_html_e( 'Two real messages per shape, so it counts toward usage and rate limits, and takes up to a minute.', 'newtide-public-agent' ); ?>
		</p>
		<p class="npa-actions">
			<button type="button" class="button" id="npa-probe-conversation"><?php esc_html_e( 'Run conversation probe', 'newtide-public-agent' ); ?></button>
			<span id="npa-probe-status" class="npa-test-result" role="status" aria-live="polite"></span>
		</p>
		<div id="npa-probe-results"></div>
		<?php $npa_admin->card_close(); ?>
	<?php endif; ?>

	<p class="npa-actions">
		<button type="button" class="button" id="npa-test-connection"><?php esc_html_e( 'Test connection', 'newtide-public-agent' ); ?></button>
		<span id="npa-test-result" class="npa-test-result" role="status" aria-live="polite"></span>
	</p>

	<?php submit_button(); ?>
</form>

<?php $npa_admin->card_open( __( 'Test drive', 'newtide-public-agent' ), __( 'Chat with your configured agent right here — the fastest way to confirm it answers.', 'newtide-public-agent' ) ); ?>
<div class="npa-testdrive" id="npa-testdrive">
	<div class="npa-testdrive__log" id="npa-td-log" aria-live="polite">
		<div class="npa-testdrive__hint"><?php esc_html_e( 'Send a message to try your agent. Save any connection changes above first.', 'newtide-public-agent' ); ?></div>
	</div>
	<form class="npa-testdrive__form" id="npa-td-form">
		<input type="text" id="npa-td-input" class="regular-text" placeholder="<?php esc_attr_e( 'Type a message to your agent…', 'newtide-public-agent' ); ?>" autocomplete="off" />
		<button type="submit" class="button button-primary" id="npa-td-send"><?php esc_html_e( 'Send', 'newtide-public-agent' ); ?></button>
	</form>
	<p class="description"><?php esc_html_e( 'Uses your live connection — or the built-in mock until you configure one. Messages count toward today’s usage and appear in Service Status.', 'newtide-public-agent' ); ?></p>
</div>
<?php $npa_admin->card_close(); ?>
