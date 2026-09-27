<?php
/**
 * Site knowledge tab — the agent's read access to this site (MCP).
 *
 * Everything needed to hand the connection to whoever runs the agent: the
 * switch, the endpoint and key to paste into the agent platform, evidence that
 * an agent is actually calling, and a guide written for the agent builder.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;

$npa_plugin   = NPA_Plugin::instance();
$npa_enabled  = (bool) $settings->get( 'mcp_enabled', true );
$npa_key      = $npa_plugin->mcp_key->get();
$npa_constant = $npa_plugin->mcp_key->is_constant();
$npa_today    = NPA_MCP_Server::today();
$npa_names    = $npa_plugin->mcp_tools->names();

// Rotation result notice (set by handle_rotate_mcp_key()'s redirect).
$npa_rotated = isset( $_GET['npa_rotated'] ) ? sanitize_key( wp_unslash( $_GET['npa_rotated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<?php $npa_admin->tab_intro( 'dashicons-book-alt', __( 'Site knowledge', 'newtide-public-agent' ), __( 'Let your agent read the pages it answers for. Share the connection details and the guide below with whoever sets up the agent.', 'newtide-public-agent' ) ); ?>

<?php if ( 'ok' === $npa_rotated ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'New key created. The old key has stopped working — send the new one to whoever runs your agent.', 'newtide-public-agent' ); ?></p></div>
<?php elseif ( 'constant' === $npa_rotated ) : ?>
	<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'The key is set by NPA_MCP_KEY in wp-config.php, so it can only be changed there.', 'newtide-public-agent' ); ?></p></div>
<?php endif; ?>

<?php if ( ! $npa_enabled ) : ?>
	<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Site knowledge is off.', 'newtide-public-agent' ); ?></strong> <?php esc_html_e( 'Your agent cannot read this site’s pages, so it answers without them. Requests are refused with a “switched off” error until you turn it back on.', 'newtide-public-agent' ); ?></p></div>
<?php elseif ( 0 === $npa_today['last'] ) : ?>
	<div class="notice notice-info inline"><p><strong><?php esc_html_e( 'No agent has connected yet.', 'newtide-public-agent' ); ?></strong> <?php esc_html_e( 'Everything on this side is ready. Send the connection details and the guide to whoever sets up your agent; this page will show its calls once it starts reading.', 'newtide-public-agent' ); ?></p></div>
<?php endif; ?>

<div class="npa-columns">
	<?php $npa_admin->card_open( __( 'Switch', 'newtide-public-agent' ), __( 'Whether the agent may read this site.', 'newtide-public-agent' ) ); ?>
	<form method="post" action="options.php" class="npa-form">
		<?php settings_fields( NPA_Settings::GROUP ); ?>
		<?php $npa_admin->present_fields( array( 'mcp_enabled' ) ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Site knowledge', 'newtide-public-agent' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[mcp_enabled]" value="1" <?php checked( $npa_enabled ); ?> />
						<?php esc_html_e( 'Let the agent read this site’s published content.', 'newtide-public-agent' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Read-only, and limited to what a logged-out visitor can already see: never drafts, private pages or anything behind a password.', 'newtide-public-agent' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save', 'newtide-public-agent' ), 'secondary' ); ?>
	</form>
	<?php $npa_admin->card_close(); ?>

	<?php $npa_admin->card_open( __( 'Connection details', 'newtide-public-agent' ), __( 'Paste these into your agent platform’s MCP connector.', 'newtide-public-agent' ) ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="npa-mcp-url"><?php esc_html_e( 'Server URL', 'newtide-public-agent' ); ?></label></th>
			<td>
				<div class="npa-copy-field">
					<input type="text" id="npa-mcp-url" readonly value="<?php echo esc_attr( NPA_MCP_Server::endpoint_url() ); ?>" />
					<button type="button" class="button" data-npa-copy="npa-mcp-url"><?php esc_html_e( 'Copy', 'newtide-public-agent' ); ?></button>
				</div>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-mcp-key"><?php esc_html_e( 'Key', 'newtide-public-agent' ); ?></label></th>
			<td>
				<div class="npa-copy-field">
					<input type="password" id="npa-mcp-key" readonly autocomplete="off" value="<?php echo esc_attr( $npa_key ); ?>" />
					<button type="button" class="button" data-npa-reveal="npa-mcp-key" aria-controls="npa-mcp-key"><?php esc_html_e( 'Show', 'newtide-public-agent' ); ?></button>
					<button type="button" class="button" data-npa-copy="npa-mcp-key"><?php esc_html_e( 'Copy', 'newtide-public-agent' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'Send as an Authorization: Bearer header, or as X-NPA-MCP-Key. Share it privately — not in the same message as the guide.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-mcp-connector"><?php esc_html_e( 'URL with key', 'newtide-public-agent' ); ?></label></th>
			<td>
				<div class="npa-copy-field">
					<input type="password" id="npa-mcp-connector" readonly autocomplete="off" value="<?php echo esc_attr( NPA_MCP_Server::connector_url( $npa_key ) ); ?>" />
					<button type="button" class="button" data-npa-reveal="npa-mcp-connector" aria-controls="npa-mcp-connector"><?php esc_html_e( 'Show', 'newtide-public-agent' ); ?></button>
					<button type="button" class="button" data-npa-copy="npa-mcp-connector"><?php esc_html_e( 'Copy', 'newtide-public-agent' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'Only for platforms that accept a URL and nothing else. The key is inside it, so treat it like the key.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Rotate', 'newtide-public-agent' ); ?></th>
			<td>
				<?php if ( $npa_constant ) : ?>
					<p class="description"><?php esc_html_e( 'This key is set by NPA_MCP_KEY in wp-config.php. Change it there.', 'newtide-public-agent' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'npa_rotate_mcp_key' ); ?>
						<input type="hidden" name="action" value="npa_rotate_mcp_key" />
						<button type="submit" class="button button-link-delete" onclick="return confirm( '<?php echo esc_js( __( 'Create a new key? The current key stops working immediately, and your agent cannot read the site until it is given the new one.', 'newtide-public-agent' ) ); ?>' );"><?php esc_html_e( 'Create a new key', 'newtide-public-agent' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'If the key has been shared too widely. The old one stops working at once.', 'newtide-public-agent' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php $npa_admin->card_close(); ?>

	<?php $npa_admin->card_open( __( 'Activity today', 'newtide-public-agent' ), __( 'Calls from your agent, per tool.', 'newtide-public-agent' ) ); ?>
	<?php if ( 0 === $npa_today['last'] ) : ?>
		<p class="description"><?php esc_html_e( 'No calls yet. Once an agent is connected, each read shows up here.', 'newtide-public-agent' ); ?></p>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: 1: human time since the last call, 2: tool name. */
				esc_html__( 'Last call %1$s ago, to %2$s.', 'newtide-public-agent' ),
				esc_html( human_time_diff( $npa_today['last'], time() ) ),
				'<code>' . esc_html( $npa_today['last_tool'] ) . '</code>'
			);
			?>
		</p>
	<?php endif; ?>
	<table class="npa-status widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Tool', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Answered', 'newtide-public-agent' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Failed', 'newtide-public-agent' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $npa_names as $npa_name => $npa_title ) : ?>
				<?php $npa_row = isset( $npa_today['tools'][ $npa_name ] ) ? $npa_today['tools'][ $npa_name ] : array(); ?>
				<tr>
					<td><code><?php echo esc_html( $npa_name ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( isset( $npa_row['ok'] ) ? (int) $npa_row['ok'] : 0 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( isset( $npa_row['err'] ) ? (int) $npa_row['err'] : 0 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $npa_today['err'] > $npa_today['ok'] ) : ?>
		<p class="description"><?php esc_html_e( 'More failures than answers usually means the agent is guessing content types instead of calling describe_site first. The guide’s “Recommended agent instructions” fixes that.', 'newtide-public-agent' ); ?></p>
	<?php endif; ?>
	<?php $npa_admin->card_close(); ?>
</div>

<?php $npa_admin->card_open( __( 'Guide for agent builders', 'newtide-public-agent' ), __( 'Everything the person connecting your agent needs, generated for this site. It never contains the key.', 'newtide-public-agent' ) ); ?>
<p class="npa-actions">
	<a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=npa_print_mcp_guide' ), 'npa_print_mcp_guide' ) ); ?>"><?php esc_html_e( 'Save as PDF', 'newtide-public-agent' ); ?></a>
	<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=npa_download_mcp_guide' ), 'npa_download_mcp_guide' ) ); ?>"><?php esc_html_e( 'Download Markdown', 'newtide-public-agent' ); ?></a>
	<span class="description"><?php esc_html_e( 'Save as PDF opens a print-ready copy in a new tab; choose “Save as PDF” in the print dialog. Send the guide, then send the key separately.', 'newtide-public-agent' ); ?></span>
</p>
<?php echo $npa_plugin->mcp_guide->to_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped block by block in NPA_MCP_Guide::to_html(). ?>
<?php $npa_admin->card_close(); ?>
