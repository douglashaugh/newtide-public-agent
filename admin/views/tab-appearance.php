<?php
/**
 * Appearance tab — how the widget looks. Rich customization (custom launcher
 * icon, theme-palette colours, size/shape) with a live preview that reflects
 * every change instantly (see the appearance module in the admin JS).
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;

$option = NPA_Settings::OPTION;

// Current values (also seed the live preview so it is correct before JS runs).
$cur_position = (string) $settings->get( 'position' );
$cur_accent   = (string) $settings->get( 'accent' );
$cur_theme    = (string) $settings->get( 'theme' );
$cur_shape    = (string) $settings->get( 'launcher_shape' );
$cur_size     = (string) $settings->get( 'launcher_size' );
$cur_header   = (string) $settings->get( 'header_title' );
$cur_label    = (string) $settings->get( 'launcher_label' );
$cur_powered  = (bool) $settings->get( 'powered_by' );

$icon_type    = (string) $settings->get( 'launcher_icon_type' );
$icon_id      = (int) $settings->get( 'launcher_icon_id' );
$icon_emoji   = (string) $settings->get( 'launcher_icon_emoji' );
$icon_builtin = (string) $settings->get( 'launcher_icon_builtin' );

$icon_image_url = $icon_id ? wp_get_attachment_image_url( $icon_id, array( 64, 64 ) ) : '';

// Initial launcher-icon markup for the preview, mirroring the front-end logic.
if ( 'image' === $icon_type && $icon_image_url ) {
	$preview_icon = '<img class="newtide-public-agent__launcher-icon newtide-public-agent__launcher-icon--image" src="' . esc_url( $icon_image_url ) . '" alt="" aria-hidden="true" />';
} elseif ( 'emoji' === $icon_type && '' !== $icon_emoji ) {
	$preview_icon = '<span class="newtide-public-agent__launcher-icon newtide-public-agent__launcher-icon--emoji" aria-hidden="true">' . esc_html( $icon_emoji ) . '</span>';
} elseif ( 'builtin' === $icon_type ) {
	$preview_icon = NPA_Icons::svg( $icon_builtin );
} else {
	$preview_icon = NPA_Icons::svg( 'chat' );
}

// Preview widget classes from the current settings.
$preview_classes = array(
	'newtide-public-agent',
	'newtide-public-agent--' . $cur_position,
	'newtide-public-agent--shape-' . $cur_shape,
	'newtide-public-agent--size-' . $cur_size,
);
if ( 'auto' !== $cur_theme ) {
	$preview_classes[] = 'newtide-public-agent--theme-' . $cur_theme;
}
?>
<?php $npa_admin->tab_intro( 'dashicons-art', __( 'Appearance', 'newtide-public-agent' ), __( 'Shape the launcher and chat panel so they feel at home on your site — watch every change in the live preview.', 'newtide-public-agent' ) ); ?>

<form method="post" action="options.php" class="npa-form npa-appearance-form">
	<?php settings_fields( NPA_Settings::GROUP ); ?>
	<?php
	$npa_admin->present_fields(
		array(
			'position',
			'accent',
			'theme',
			'header_title',
			'launcher_shape',
			'launcher_size',
			'launcher_icon_type',
			'launcher_icon_id',
			'launcher_icon_emoji',
			'launcher_icon_builtin',
			'powered_by',
		)
	);
	?>

	<div class="npa-appearance">
		<div class="npa-appearance__controls">

			<?php $npa_admin->card_open( __( 'Launcher icon', 'newtide-public-agent' ), __( 'The glyph on the round bubble launcher. Upload your own, pick an emoji, or choose a built-in icon.', 'newtide-public-agent' ) ); ?>
			<div class="npa-iconpicker">
				<div class="npa-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Launcher icon source', 'newtide-public-agent' ); ?>">
					<?php
					$types = array(
						'default' => __( 'Default', 'newtide-public-agent' ),
						'image'   => __( 'Upload image', 'newtide-public-agent' ),
						'emoji'   => __( 'Emoji', 'newtide-public-agent' ),
						'builtin' => __( 'Built-in', 'newtide-public-agent' ),
					);
					foreach ( $types as $val => $lbl ) :
						?>
						<label class="npa-segmented__opt">
							<input type="radio" name="<?php echo esc_attr( $option ); ?>[launcher_icon_type]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $icon_type, $val ); ?> data-npa-icon-type />
							<span><?php echo esc_html( $lbl ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<!-- Image source -->
				<div class="npa-iconpanel" data-icon-panel="image" <?php echo 'image' === $icon_type ? '' : 'hidden'; ?>>
					<input type="hidden" name="<?php echo esc_attr( $option ); ?>[launcher_icon_id]" id="npa-icon-id" value="<?php echo esc_attr( (string) $icon_id ); ?>" />
					<div class="npa-icon-image">
						<div class="npa-icon-thumb" id="npa-icon-thumb" data-url="<?php echo esc_attr( $icon_image_url ); ?>">
							<?php if ( $icon_image_url ) : ?>
								<img src="<?php echo esc_url( $icon_image_url ); ?>" alt="" />
							<?php endif; ?>
						</div>
						<div class="npa-icon-image__actions">
							<button type="button" class="button" id="npa-icon-upload"><?php esc_html_e( 'Choose image', 'newtide-public-agent' ); ?></button>
							<button type="button" class="button-link npa-icon-remove" id="npa-icon-remove" <?php echo $icon_image_url ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'newtide-public-agent' ); ?></button>
						</div>
					</div>
					<p class="description"><?php esc_html_e( 'A square PNG or SVG works best. Shown at ~26–34px.', 'newtide-public-agent' ); ?></p>
				</div>

				<!-- Emoji source -->
				<div class="npa-iconpanel" data-icon-panel="emoji" <?php echo 'emoji' === $icon_type ? '' : 'hidden'; ?>>
					<input type="text" class="npa-emoji-input" id="npa-icon-emoji" name="<?php echo esc_attr( $option ); ?>[launcher_icon_emoji]" value="<?php echo esc_attr( $icon_emoji ); ?>" maxlength="8" placeholder="💬" aria-label="<?php esc_attr_e( 'Launcher emoji', 'newtide-public-agent' ); ?>" />
					<p class="description"><?php esc_html_e( 'Paste or type a single emoji.', 'newtide-public-agent' ); ?></p>
				</div>

				<!-- Built-in source -->
				<div class="npa-iconpanel" data-icon-panel="builtin" <?php echo 'builtin' === $icon_type ? '' : 'hidden'; ?>>
					<input type="hidden" name="<?php echo esc_attr( $option ); ?>[launcher_icon_builtin]" id="npa-icon-builtin" value="<?php echo esc_attr( $icon_builtin ); ?>" />
					<div class="npa-icon-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Built-in icons', 'newtide-public-agent' ); ?>">
						<?php foreach ( NPA_Icons::choices() as $slug => $label ) : ?>
							<button type="button" class="npa-icon-choice<?php echo $slug === $icon_builtin ? ' is-active' : ''; ?>" data-icon="<?php echo esc_attr( $slug ); ?>" title="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" aria-pressed="<?php echo $slug === $icon_builtin ? 'true' : 'false'; ?>">
								<?php echo NPA_Icons::svg( $slug, 'npa-icon-choice__svg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, self-authored SVG markup. ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>

				<p class="description npa-icon-shape-note"><?php esc_html_e( 'The launcher icon shows when the launcher shape is “Bubble” (below). The pill shape shows the label text instead.', 'newtide-public-agent' ); ?></p>
			</div>
			<?php $npa_admin->card_close(); ?>

			<?php $npa_admin->card_open( __( 'Colour', 'newtide-public-agent' ), __( 'The accent drives the launcher, header, and the visitor’s message bubbles.', 'newtide-public-agent' ) ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="npa-accent"><?php esc_html_e( 'Accent colour', 'newtide-public-agent' ); ?></label></th>
					<td>
						<input type="color" id="npa-accent" name="<?php echo esc_attr( $option ); ?>[accent]" value="<?php echo esc_attr( $cur_accent ); ?>" />
						<code class="npa-accent-hex" id="npa-accent-hex"><?php echo esc_html( $cur_accent ); ?></code>
						<p class="description npa-palette-label" id="npa-palette-label" hidden><?php esc_html_e( 'Recommended from your theme:', 'newtide-public-agent' ); ?></p>
						<div class="npa-palette" id="npa-palette" aria-label="<?php esc_attr_e( 'Recommended colours from your theme', 'newtide-public-agent' ); ?>"></div>
						<p class="description npa-palette-empty" id="npa-palette-empty" hidden><?php esc_html_e( 'No theme palette found — pick any colour above.', 'newtide-public-agent' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="npa-theme"><?php esc_html_e( 'Colour scheme', 'newtide-public-agent' ); ?></label></th>
					<td>
						<select id="npa-theme" name="<?php echo esc_attr( $option ); ?>[theme]" data-npa-preview-control="theme">
							<option value="auto" <?php selected( $cur_theme, 'auto' ); ?>><?php esc_html_e( 'Auto (follow visitor’s device)', 'newtide-public-agent' ); ?></option>
							<option value="light" <?php selected( $cur_theme, 'light' ); ?>><?php esc_html_e( 'Light', 'newtide-public-agent' ); ?></option>
							<option value="dark" <?php selected( $cur_theme, 'dark' ); ?>><?php esc_html_e( 'Dark', 'newtide-public-agent' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<?php $npa_admin->card_close(); ?>

			<?php $npa_admin->card_open( __( 'Shape & size', 'newtide-public-agent' ), __( 'The launcher’s form and where it sits on the page.', 'newtide-public-agent' ) ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="npa-launcher-shape"><?php esc_html_e( 'Launcher shape', 'newtide-public-agent' ); ?></label></th>
					<td>
						<select id="npa-launcher-shape" name="<?php echo esc_attr( $option ); ?>[launcher_shape]" data-npa-preview-control="shape">
							<option value="pill" <?php selected( $cur_shape, 'pill' ); ?>><?php esc_html_e( 'Pill (label text)', 'newtide-public-agent' ); ?></option>
							<option value="bubble" <?php selected( $cur_shape, 'bubble' ); ?>><?php esc_html_e( 'Bubble (round icon)', 'newtide-public-agent' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="npa-launcher-size"><?php esc_html_e( 'Launcher size', 'newtide-public-agent' ); ?></label></th>
					<td>
						<select id="npa-launcher-size" name="<?php echo esc_attr( $option ); ?>[launcher_size]" data-npa-preview-control="size">
							<option value="small" <?php selected( $cur_size, 'small' ); ?>><?php esc_html_e( 'Small', 'newtide-public-agent' ); ?></option>
							<option value="medium" <?php selected( $cur_size, 'medium' ); ?>><?php esc_html_e( 'Medium', 'newtide-public-agent' ); ?></option>
							<option value="large" <?php selected( $cur_size, 'large' ); ?>><?php esc_html_e( 'Large', 'newtide-public-agent' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="npa-position"><?php esc_html_e( 'Position', 'newtide-public-agent' ); ?></label></th>
					<td>
						<select id="npa-position" name="<?php echo esc_attr( $option ); ?>[position]" data-npa-preview-control="position">
							<option value="bottom-right" <?php selected( $cur_position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'newtide-public-agent' ); ?></option>
							<option value="bottom-left" <?php selected( $cur_position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'newtide-public-agent' ); ?></option>
							<option value="top-right" <?php selected( $cur_position, 'top-right' ); ?>><?php esc_html_e( 'Top right', 'newtide-public-agent' ); ?></option>
							<option value="top-left" <?php selected( $cur_position, 'top-left' ); ?>><?php esc_html_e( 'Top left', 'newtide-public-agent' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<?php $npa_admin->card_close(); ?>

			<?php $npa_admin->card_open( __( 'Header & branding', 'newtide-public-agent' ), __( 'The panel title and the “Powered by” line.', 'newtide-public-agent' ) ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="npa-header-title"><?php esc_html_e( 'Header title', 'newtide-public-agent' ); ?></label></th>
					<td>
						<input type="text" id="npa-header-title" class="regular-text" name="<?php echo esc_attr( $option ); ?>[header_title]" value="<?php echo esc_attr( $cur_header ); ?>" data-npa-preview-control="header" />
						<p class="description"><?php esc_html_e( 'Shown at the top of the open chat panel.', 'newtide-public-agent' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Powered by', 'newtide-public-agent' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[powered_by]" value="1" <?php checked( $cur_powered ); ?> data-npa-preview-control="powered" />
							<?php esc_html_e( 'Show a small “Powered by NewTide” line in the chat panel.', 'newtide-public-agent' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php $npa_admin->card_close(); ?>

			<?php submit_button(); ?>
		</div><!-- .npa-appearance__controls -->

		<aside class="npa-appearance__preview" aria-label="<?php esc_attr_e( 'Live preview', 'newtide-public-agent' ); ?>">
			<div class="npa-preview">
				<div class="npa-preview__bar">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<?php esc_html_e( 'Live preview', 'newtide-public-agent' ); ?>
				</div>
				<div class="npa-preview__stage">
					<div id="npa-preview-widget" class="<?php echo esc_attr( implode( ' ', $preview_classes ) ); ?>" style="--npa-accent:<?php echo esc_attr( $cur_accent ); ?>">
						<div class="newtide-public-agent__panel" role="dialog" aria-label="<?php esc_attr_e( 'Preview chat', 'newtide-public-agent' ); ?>">
							<div class="newtide-public-agent__header">
								<span class="newtide-public-agent__title" data-npa-preview="header"><?php echo esc_html( $cur_header ); ?></span>
								<button type="button" class="newtide-public-agent__close" tabindex="-1" aria-hidden="true">&times;</button>
							</div>
							<div class="newtide-public-agent__log">
								<div class="newtide-public-agent__msg newtide-public-agent__msg--agent"><div class="newtide-public-agent__bubble"><?php esc_html_e( 'Hi! How can I help you today?', 'newtide-public-agent' ); ?></div></div>
								<div class="newtide-public-agent__msg newtide-public-agent__msg--user"><div class="newtide-public-agent__bubble"><?php esc_html_e( 'Do you ship internationally?', 'newtide-public-agent' ); ?></div></div>
								<div class="newtide-public-agent__msg newtide-public-agent__msg--agent"><div class="newtide-public-agent__bubble"><?php esc_html_e( 'We do — worldwide, in 3–5 days.', 'newtide-public-agent' ); ?></div></div>
							</div>
							<div class="newtide-public-agent__form">
								<input class="newtide-public-agent__input" type="text" placeholder="<?php echo esc_attr( (string) $settings->get( 'input_placeholder' ) ); ?>" tabindex="-1" aria-hidden="true" />
								<button type="button" class="newtide-public-agent__send" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Send', 'newtide-public-agent' ); ?></button>
							</div>
							<div class="newtide-public-agent__powered" data-npa-preview="powered" <?php echo $cur_powered ? '' : 'hidden'; ?>><?php esc_html_e( 'Powered by NewTide', 'newtide-public-agent' ); ?></div>
						</div>
						<button type="button" class="newtide-public-agent__launcher" tabindex="-1" aria-hidden="true">
							<span class="npa-preview-icon-slot" data-npa-preview="icon"><?php echo $preview_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped icon markup built above. ?></span>
							<span class="newtide-public-agent__launcher-text" data-npa-preview="label"><?php echo esc_html( $cur_label ); ?></span>
						</button>
					</div>
				</div>
				<p class="npa-preview__note"><?php esc_html_e( 'The panel is shown open for preview. On your site it opens when a visitor taps the launcher.', 'newtide-public-agent' ); ?></p>
			</div>
		</aside>
	</div><!-- .npa-appearance -->
</form>
