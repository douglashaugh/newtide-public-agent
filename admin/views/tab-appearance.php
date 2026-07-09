<?php
/**
 * Appearance tab — how the widget looks.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="options.php" class="npa-form">
	<?php settings_fields( NPA_Settings::GROUP ); ?>
	<?php $npa_admin->present_fields( array( 'position', 'accent', 'theme', 'header_title', 'launcher_shape', 'powered_by' ) ); ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="npa-position"><?php esc_html_e( 'Position', 'newtide-public-agent' ); ?></label></th>
			<td>
				<select id="npa-position" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[position]">
					<option value="bottom-right" <?php selected( $settings->get( 'position' ), 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'newtide-public-agent' ); ?></option>
					<option value="bottom-left" <?php selected( $settings->get( 'position' ), 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'newtide-public-agent' ); ?></option>
					<option value="top-right" <?php selected( $settings->get( 'position' ), 'top-right' ); ?>><?php esc_html_e( 'Top right', 'newtide-public-agent' ); ?></option>
					<option value="top-left" <?php selected( $settings->get( 'position' ), 'top-left' ); ?>><?php esc_html_e( 'Top left', 'newtide-public-agent' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-accent"><?php esc_html_e( 'Accent colour', 'newtide-public-agent' ); ?></label></th>
			<td>
				<input type="color" id="npa-accent" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[accent]" value="<?php echo esc_attr( $settings->get( 'accent' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Used for the launcher, header, and the visitor’s message bubbles.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-theme"><?php esc_html_e( 'Colour scheme', 'newtide-public-agent' ); ?></label></th>
			<td>
				<select id="npa-theme" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[theme]">
					<option value="auto" <?php selected( $settings->get( 'theme' ), 'auto' ); ?>><?php esc_html_e( 'Auto (follow visitor’s device)', 'newtide-public-agent' ); ?></option>
					<option value="light" <?php selected( $settings->get( 'theme' ), 'light' ); ?>><?php esc_html_e( 'Light', 'newtide-public-agent' ); ?></option>
					<option value="dark" <?php selected( $settings->get( 'theme' ), 'dark' ); ?>><?php esc_html_e( 'Dark', 'newtide-public-agent' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-header-title"><?php esc_html_e( 'Header title', 'newtide-public-agent' ); ?></label></th>
			<td>
				<input type="text" id="npa-header-title" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[header_title]" value="<?php echo esc_attr( $settings->get( 'header_title' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Shown at the top of the open chat panel.', 'newtide-public-agent' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-launcher-shape"><?php esc_html_e( 'Launcher shape', 'newtide-public-agent' ); ?></label></th>
			<td>
				<select id="npa-launcher-shape" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[launcher_shape]">
					<option value="pill" <?php selected( $settings->get( 'launcher_shape' ), 'pill' ); ?>><?php esc_html_e( 'Pill (label text)', 'newtide-public-agent' ); ?></option>
					<option value="bubble" <?php selected( $settings->get( 'launcher_shape' ), 'bubble' ); ?>><?php esc_html_e( 'Bubble (round icon)', 'newtide-public-agent' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Powered by', 'newtide-public-agent' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[powered_by]" value="1" <?php checked( (bool) $settings->get( 'powered_by' ) ); ?> />
					<?php esc_html_e( 'Show a small “Powered by NewTide” line in the chat panel.', 'newtide-public-agent' ); ?>
				</label>
			</td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>
