<?php
/**
 * Additional Agents tab — attach extra agents to specific pages. Each row is one
 * agent with its own page targeting and optional look overrides. On a page an
 * additional agent targets, it takes over from the global agent (first match
 * wins). See NPA_Public::active_additional_agent() for the front-end resolution.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;

$option          = NPA_Settings::OPTION;
$agents          = $settings->get_agents();
$all_pages       = get_pages(
	array(
		'post_status' => 'publish',
		'sort_column' => 'menu_order,post_title',
	)
);
$builtin_choices = NPA_Icons::choices();

/**
 * Render one repeater row. $i is an int for saved rows or the literal "__i__"
 * placeholder for the JS clone template.
 */
$render_row = function ( $i, array $row ) use ( $option, $all_pages, $builtin_choices ) {
	$row      = wp_parse_args(
		$row,
		array(
			'name'         => '',
			'mode'         => 'proxy',
			'agent_id'     => '',
			'public_key'   => '',
			'page_ids'     => array(),
			'accent'       => '',
			'greeting'     => '',
			'label'        => '',
			'icon_type'    => 'inherit',
			'icon_id'      => 0,
			'icon_emoji'   => '',
			'icon_builtin' => 'chat',
		)
	);
	$page_ids = array_map( 'absint', (array) $row['page_ids'] );
	$img      = $row['icon_id'] ? wp_get_attachment_image_url( (int) $row['icon_id'], array( 64, 64 ) ) : '';
	$base     = $option . '[agents][' . $i . ']';
	?>
	<div class="npa-agent-row" data-agent-row>
		<div class="npa-agent-row__head">
			<span class="npa-agent-row__num"></span>
			<strong class="npa-agent-row__name"><?php echo esc_html( '' !== $row['name'] ? $row['name'] : __( 'New agent', 'newtide-public-agent' ) ); ?></strong>
			<button type="button" class="button-link npa-agent-remove" data-agent-remove><?php esc_html_e( 'Remove', 'newtide-public-agent' ); ?></button>
		</div>

		<div class="npa-agent-row__grid">
			<p class="npa-field npa-field--wide">
				<label><?php esc_html_e( 'Label (for your reference)', 'newtide-public-agent' ); ?></label>
				<input type="text" class="regular-text npa-agent-name-input" name="<?php echo esc_attr( $base ); ?>[name]" value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Sales bot for Pricing page', 'newtide-public-agent' ); ?>" />
			</p>

			<p class="npa-field">
				<label><?php esc_html_e( 'Connection mode', 'newtide-public-agent' ); ?></label>
				<select name="<?php echo esc_attr( $base ); ?>[mode]" data-agent-mode-select>
					<option value="proxy" <?php selected( $row['mode'], 'proxy' ); ?>><?php esc_html_e( 'Proxy (plugin widget)', 'newtide-public-agent' ); ?></option>
					<option value="embed" <?php selected( $row['mode'], 'embed' ); ?>><?php esc_html_e( 'Embed (publishable key)', 'newtide-public-agent' ); ?></option>
				</select>
			</p>

			<p class="npa-field" data-agent-mode="proxy" <?php echo 'embed' === $row['mode'] ? 'hidden' : ''; ?>>
				<label><?php esc_html_e( 'Agent ID', 'newtide-public-agent' ); ?></label>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[agent_id]" value="<?php echo esc_attr( $row['agent_id'] ); ?>" placeholder="<?php esc_attr_e( 'Published agent ID', 'newtide-public-agent' ); ?>" />
			</p>

			<p class="npa-field" data-agent-mode="embed" <?php echo 'embed' === $row['mode'] ? '' : 'hidden'; ?>>
				<label><?php esc_html_e( 'Publishable key', 'newtide-public-agent' ); ?></label>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[public_key]" value="<?php echo esc_attr( $row['public_key'] ); ?>" placeholder="pk_…" />
			</p>

			<p class="npa-field npa-field--wide">
				<label><?php esc_html_e( 'Show on these pages', 'newtide-public-agent' ); ?></label>
				<?php if ( ! empty( $all_pages ) ) : ?>
					<select class="npa-agent-pages" name="<?php echo esc_attr( $base ); ?>[page_ids][]" multiple size="6">
						<?php foreach ( $all_pages as $p ) : ?>
							<option value="<?php echo esc_attr( (string) $p->ID ); ?>" <?php selected( in_array( (int) $p->ID, $page_ids, true ) ); ?>>
								<?php echo esc_html( '' !== $p->post_title ? $p->post_title : __( '(no title)', 'newtide-public-agent' ) ); ?> (#<?php echo (int) $p->ID; ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Ctrl/Cmd-click to select multiple. This agent replaces the global one on the selected pages.', 'newtide-public-agent' ); ?></span>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'No published pages found.', 'newtide-public-agent' ); ?></span>
				<?php endif; ?>
			</p>

			<p class="npa-field">
				<label><?php esc_html_e( 'Accent colour', 'newtide-public-agent' ); ?></label>
				<input type="text" class="npa-agent-accent" name="<?php echo esc_attr( $base ); ?>[accent]" value="<?php echo esc_attr( $row['accent'] ); ?>" placeholder="<?php esc_attr_e( 'Inherit — e.g. #2563eb', 'newtide-public-agent' ); ?>" />
			</p>

			<p class="npa-field">
				<label><?php esc_html_e( 'Launcher label', 'newtide-public-agent' ); ?></label>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( $row['label'] ); ?>" placeholder="<?php esc_attr_e( 'Inherit', 'newtide-public-agent' ); ?>" />
			</p>

			<p class="npa-field npa-field--wide">
				<label><?php esc_html_e( 'Greeting', 'newtide-public-agent' ); ?></label>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[greeting]" value="<?php echo esc_attr( $row['greeting'] ); ?>" placeholder="<?php esc_attr_e( 'Inherit', 'newtide-public-agent' ); ?>" />
			</p>

			<div class="npa-field npa-field--wide npa-agent-icon" data-agent-mode="proxy" <?php echo 'embed' === $row['mode'] ? 'hidden' : ''; ?>>
				<label><?php esc_html_e( 'Launcher icon', 'newtide-public-agent' ); ?></label>
				<div class="npa-agent-icon__controls">
					<select class="npa-agent-icon-type" name="<?php echo esc_attr( $base ); ?>[icon_type]" data-agent-icon-select>
						<option value="inherit" <?php selected( $row['icon_type'], 'inherit' ); ?>><?php esc_html_e( 'Inherit global', 'newtide-public-agent' ); ?></option>
						<option value="default" <?php selected( $row['icon_type'], 'default' ); ?>><?php esc_html_e( 'Default chat glyph', 'newtide-public-agent' ); ?></option>
						<option value="emoji" <?php selected( $row['icon_type'], 'emoji' ); ?>><?php esc_html_e( 'Emoji', 'newtide-public-agent' ); ?></option>
						<option value="builtin" <?php selected( $row['icon_type'], 'builtin' ); ?>><?php esc_html_e( 'Built-in', 'newtide-public-agent' ); ?></option>
						<option value="image" <?php selected( $row['icon_type'], 'image' ); ?>><?php esc_html_e( 'Custom image', 'newtide-public-agent' ); ?></option>
					</select>

					<input type="text" class="npa-emoji-input" name="<?php echo esc_attr( $base ); ?>[icon_emoji]" value="<?php echo esc_attr( $row['icon_emoji'] ); ?>" maxlength="8" placeholder="💬" data-agent-icon-panel="emoji" <?php echo 'emoji' === $row['icon_type'] ? '' : 'hidden'; ?> />

					<select class="npa-agent-icon-builtin" name="<?php echo esc_attr( $base ); ?>[icon_builtin]" data-agent-icon-panel="builtin" <?php echo 'builtin' === $row['icon_type'] ? '' : 'hidden'; ?>>
						<?php foreach ( $builtin_choices as $slug => $lbl ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $row['icon_builtin'], $slug ); ?>><?php echo esc_html( $lbl ); ?></option>
						<?php endforeach; ?>
					</select>

					<span class="npa-agent-icon-image" data-agent-icon-panel="image" <?php echo 'image' === $row['icon_type'] ? '' : 'hidden'; ?>>
						<input type="hidden" class="npa-agent-icon-id" name="<?php echo esc_attr( $base ); ?>[icon_id]" value="<?php echo esc_attr( (string) $row['icon_id'] ); ?>" />
						<span class="npa-agent-icon-thumb" data-url="<?php echo esc_attr( (string) $img ); ?>">
						<?php
						if ( $img ) :
							?>
							<img src="<?php echo esc_url( $img ); ?>" alt="" /><?php endif; ?></span>
						<button type="button" class="button npa-agent-icon-upload"><?php esc_html_e( 'Choose', 'newtide-public-agent' ); ?></button>
					</span>
				</div>
			</div>
		</div>
	</div>
	<?php
};
?>
<?php $npa_admin->tab_intro( 'dashicons-groups', __( 'Additional Agents', 'newtide-public-agent' ), __( 'Run more than one agent — each on the pages you choose.', 'newtide-public-agent' ) ); ?>

<div class="npa-card npa-explain">
	<div class="npa-card__head">
		<h3 class="npa-card__title"><?php esc_html_e( 'How additional agents work', 'newtide-public-agent' ); ?></h3>
	</div>
	<ul class="npa-explain__list">
		<li><?php echo wp_kses_post( __( '<strong>One agent per page.</strong> Add an agent, pick the pages it should run on, and it appears there automatically — no shortcode needed.', 'newtide-public-agent' ) ); ?></li>
		<li><?php echo wp_kses_post( __( '<strong>It takes over on its pages.</strong> On a page an additional agent targets, it replaces your global (default) agent. If two agents target the same page, the one listed first wins.', 'newtide-public-agent' ) ); ?></li>
		<li><?php echo wp_kses_post( __( '<strong>Everywhere else, your default agent runs</strong> exactly as configured on the Agent tab.', 'newtide-public-agent' ) ); ?></li>
		<li><?php echo wp_kses_post( __( '<strong>Two connection modes.</strong> <em>Proxy</em> uses the plugin’s own styled widget (an Agent ID); <em>Embed</em> injects RisingTide’s official widget (a publishable <code>pk_</code> key).', 'newtide-public-agent' ) ); ?></li>
		<li><?php echo wp_kses_post( __( '<strong>Blank overrides inherit.</strong> Leave colour, label, greeting, or icon empty and this agent uses your global Appearance settings — fill them in only to differ.', 'newtide-public-agent' ) ); ?></li>
	</ul>
</div>

<form method="post" action="options.php" class="npa-form npa-agents-form">
	<?php settings_fields( NPA_Settings::GROUP ); ?>
	<?php $npa_admin->present_fields( array( 'agents' ) ); ?>

	<div class="npa-agents-list" id="npa-agents-list">
		<?php
		if ( ! empty( $agents ) ) {
			foreach ( $agents as $idx => $row ) {
				$render_row( $idx, $row );
			}
		}
		?>
	</div>

	<p class="npa-agents-empty" id="npa-agents-empty" <?php echo empty( $agents ) ? '' : 'hidden'; ?>>
		<?php esc_html_e( 'No additional agents yet. Add one to run a different agent on specific pages.', 'newtide-public-agent' ); ?>
	</p>

	<p>
		<button type="button" class="button button-secondary" id="npa-add-agent">
			<span class="dashicons dashicons-plus-alt2" aria-hidden="true" style="vertical-align:text-bottom"></span>
			<?php esc_html_e( 'Add agent', 'newtide-public-agent' ); ?>
		</button>
	</p>

	<template id="npa-agent-row-template"><?php $render_row( '__i__', array() ); ?></template>

	<?php submit_button(); ?>
</form>
