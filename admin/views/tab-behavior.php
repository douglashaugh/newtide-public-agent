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
<form method="post" action="options.php" class="npa-form">
	<?php settings_fields( NPA_Settings::GROUP ); ?>
	<?php $npa_admin->present_fields( array( 'auto_open_delay', 'hide_on_mobile', 'remember_state', 'audience', 'exclude_ids' ) ); ?>

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
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="npa-exclude-ids"><?php esc_html_e( 'Hide on these pages', 'newtide-public-agent' ); ?></label></th>
				<td>
					<input type="text" id="npa-exclude-ids" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[exclude_ids]" value="<?php echo esc_attr( $settings->get( 'exclude_ids' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma-separated page or post IDs where the widget should not appear (e.g. 12, 40, 105).', 'newtide-public-agent' ); ?></p>
				</td>
			</tr>
		</table>
		<?php $npa_admin->card_close(); ?>
	</div>

	<?php submit_button(); ?>
</form>
