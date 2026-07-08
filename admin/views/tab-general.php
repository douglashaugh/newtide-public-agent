<?php
/**
 * General tab — widget presentation options.
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
	<?php $npa_admin->present_fields( array( 'enabled', 'launcher_label', 'greeting', 'position', 'accent' ) ); ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable widget', 'newtide-public-agent' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[enabled]" value="1" <?php checked( (bool) $settings->get( 'enabled' ) ); ?> />
					<?php esc_html_e( 'Show the agent widget on the front end.', 'newtide-public-agent' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-launcher-label"><?php esc_html_e( 'Launcher label', 'newtide-public-agent' ); ?></label></th>
			<td>
				<input type="text" id="npa-launcher-label" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[launcher_label]" value="<?php echo esc_attr( $settings->get( 'launcher_label' ) ); ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-greeting"><?php esc_html_e( 'Greeting', 'newtide-public-agent' ); ?></label></th>
			<td>
				<textarea id="npa-greeting" class="large-text" rows="2" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[greeting]"><?php echo esc_textarea( $settings->get( 'greeting' ) ); ?></textarea>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-position"><?php esc_html_e( 'Position', 'newtide-public-agent' ); ?></label></th>
			<td>
				<select id="npa-position" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[position]">
					<option value="bottom-right" <?php selected( $settings->get( 'position' ), 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'newtide-public-agent' ); ?></option>
					<option value="bottom-left" <?php selected( $settings->get( 'position' ), 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'newtide-public-agent' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="npa-accent"><?php esc_html_e( 'Accent colour', 'newtide-public-agent' ); ?></label></th>
			<td>
				<input type="color" id="npa-accent" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[accent]" value="<?php echo esc_attr( $settings->get( 'accent' ) ); ?>" />
			</td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>
