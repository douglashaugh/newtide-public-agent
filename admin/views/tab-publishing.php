<?php
/**
 * Publishing tab — an in-plugin walkthrough of how to make a RisingTide agent
 * public (turn on public access, create a key, get the embed snippet), mirrored
 * from the "Making an Agent Public on RisingTide" guide so the whole workflow
 * lives next to the plugin settings.
 *
 * Informational only — no form. Content is static and escaped at output.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;

$npa_agent_id = $settings->get_agent_id();
?>
<div class="npa-guide">

	<p><?php echo esc_html__( 'Publishing an agent puts a chat widget on a website that anyone can use — no login required. You build the agent in RisingTide, turn on public access, create a key tied to your site, then connect it here. This is the same workflow as the "Making an Agent Public on RisingTide" guide, kept here so everything is in one place.', 'newtide-public-agent' ); ?></p>

	<h2><?php esc_html_e( 'Before you start', 'newtide-public-agent' ); ?></h2>
	<ul class="ul-disc">
		<li><?php echo wp_kses_post( __( '<strong>You need Company Super Admin access.</strong> Only super admins can publish an agent or create keys. If Advanced Settings has no <em>Public Agent</em> section, that is a permissions issue — ask a Company Super Admin on your team to grant access or run the step for you.', 'newtide-public-agent' ) ); ?></li>
		<li><?php echo wp_kses_post( __( '<strong>Save the agent at least once first.</strong> The publishing controls live under <em>Advanced Settings</em>, which only appears after the agent has been saved.', 'newtide-public-agent' ) ); ?></li>
		<li><?php echo wp_kses_post( __( '<strong>Use an incognito window</strong> if you are logged into more than one environment (PROD + UAT), so you do not end up signed in as the wrong user.', 'newtide-public-agent' ) ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'Steps in RisingTide', 'newtide-public-agent' ); ?></h2>
	<ol class="npa-steps">
		<li><strong><?php esc_html_e( 'Open Agent Harbor', 'newtide-public-agent' ); ?></strong><?php echo wp_kses_post( __( 'From the dashboard, open <em>Agent Harbor</em> — where all your agents live.', 'newtide-public-agent' ) ); ?></li>
		<li><strong><?php esc_html_e( 'Open the agent’s settings', 'newtide-public-agent' ); ?></strong><?php echo wp_kses_post( __( 'Find the agent you want to publish and click the <em>gear icon</em> on its card to open <em>Agent Settings</em>.', 'newtide-public-agent' ) ); ?></li>
		<li><strong><?php esc_html_e( 'Open Advanced Settings', 'newtide-public-agent' ); ?></strong><?php echo wp_kses_post( __( 'On the <em>Settings</em> tab, scroll to the bottom and expand <em>Advanced Settings</em>. Not there? The agent has not been saved yet — hit <em>Save</em> and look again.', 'newtide-public-agent' ) ); ?></li>
		<li><strong><?php esc_html_e( 'Turn on public access', 'newtide-public-agent' ); ?></strong><?php echo wp_kses_post( __( 'Under <em>Advanced Settings &rsaquo; Public Agent</em>, switch the toggle on. You should see a “Public access enabled for this agent” confirmation. No toggle at all means you are not a Company Super Admin.', 'newtide-public-agent' ) ); ?></li>
		<li><strong><?php esc_html_e( 'Create a public key', 'newtide-public-agent' ); ?></strong><?php echo wp_kses_post( __( 'Click <em>+ Create key</em>. The key is what connects the website chat widget to your agent.', 'newtide-public-agent' ) ); ?></li>
		<li>
			<strong><?php esc_html_e( 'Fill out the key', 'newtide-public-agent' ); ?></strong>
			<?php esc_html_e( 'In the “Create public key” dialog:', 'newtide-public-agent' ); ?>
			<ul class="ul-disc" style="margin-top:0.4rem;">
				<li><?php echo wp_kses_post( __( '<strong>Name</strong> — anything you will recognize (e.g. “Public Agent”).', 'newtide-public-agent' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Allowed origins</strong> — the real site URL(s) where the chat may appear, one per line, starting with <code>https://</code>. The widget only works on these domains, so a placeholder like <code>example.com</code> will not do — use the actual site.', 'newtide-public-agent' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Bind to user</strong> — pick a <strong>non-admin</strong> user. The chat runs with this user’s permissions, so it should only reach things that are safe to be public. Admin accounts are rejected.', 'newtide-public-agent' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Expected traffic</strong> — pick Small / Medium / Large for rate limits (or customize). Small is fine for testing.', 'newtide-public-agent' ) ); ?></li>
			</ul>
			<?php echo wp_kses_post( __( 'Then click <em>Create key</em>.', 'newtide-public-agent' ) ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Copy the key right away', 'newtide-public-agent' ); ?></strong>
			<?php echo wp_kses_post( __( 'RisingTide shows your API key and a ready-to-paste embed snippet. <strong>Copy the key immediately — the full key is shown only once.</strong> If you lose it, you will have to create a new key. The snippet looks like this:', 'newtide-public-agent' ) ); ?>
			<code class="npa-code">&lt;script
	src="https://uat-ai.newtide.ai/agent-embed.js"
	data-api-key="pk_••••••••••••••••••••••••••••"&gt;
&lt;/script&gt;</code>
		</li>
		<li><strong><?php esc_html_e( 'Connect it to this plugin', 'newtide-public-agent' ); ?></strong><?php echo wp_kses_post( __( 'Instead of pasting the raw snippet into your theme, put the agent ID and key into this plugin (see below). The plugin then renders the widget for you, with the placement, appearance, and behaviour options on the other tabs.', 'newtide-public-agent' ) ); ?></li>
	</ol>

	<h2><?php esc_html_e( 'Connecting it to the plugin', 'newtide-public-agent' ); ?></h2>
	<p>
	<?php
	echo wp_kses_post(
		sprintf(
			/* translators: 1: Agent tab link, 2: General tab link. */
			__( 'Put the <strong>agent ID</strong> on the %1$s tab, and the <strong>key</strong> either in a <code>NPA_GATEWAY_KEY</code> constant in <code>wp-config.php</code> (recommended — it never touches the database) or in the key field. Then place the widget with the <code>[newtide_agent]</code> shortcode or the block, and tune it on the %2$s, Appearance, and Behavior tabs.', 'newtide-public-agent' ),
			'<a href="' . esc_url( $npa_admin->tab_url( 'agent' ) ) . '">' . esc_html__( 'Agent', 'newtide-public-agent' ) . '</a>',
			'<a href="' . esc_url( $npa_admin->tab_url( 'general' ) ) . '">' . esc_html__( 'General', 'newtide-public-agent' ) . '</a>'
		)
	);
	?>
	</p>
	<?php if ( '' !== $npa_agent_id ) : ?>
		<p>
		<?php
		echo wp_kses_post(
			sprintf(
				/* translators: %s: the configured agent id. */
				__( 'This plugin is currently pointed at agent ID <span class="npa-config-value">%s</span>.', 'newtide-public-agent' ),
				esc_html( $npa_agent_id )
			)
		);
		?>
		</p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'If the chat loads but will not answer', 'newtide-public-agent' ); ?></h2>
	<p><?php echo wp_kses_post( __( 'Because the widget runs as the bound non-admin user, that user needs the agent’s <strong>“use”</strong> permission (on the agent’s <em>Permissions</em> tab in RisingTide), plus access to any data group the agent relies on. If the widget appears but the agent errors out, check this first.', 'newtide-public-agent' ) ); ?></p>

	<div class="npa-note npa-note--warn">
		<p style="margin:0;"><strong><?php esc_html_e( 'Safety note', 'newtide-public-agent' ); ?></strong></p>
		<p style="margin:0.35rem 0 0;"><?php echo wp_kses_post( __( 'If your agent can search knowledge / Data Helm, an unrestricted public agent will happily answer internal questions for anyone on the internet. Add a line to the <strong>system prompt</strong> to keep it in bounds — for example: <em>“Only answer questions relevant to the business. Never share internal, NewTide, or Data Helm information.”</em> The system prompt is the real guardrail, so test it with a few nosy questions before going live.', 'newtide-public-agent' ) ); ?></p>
	</div>

</div>
