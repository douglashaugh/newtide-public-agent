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
<?php $npa_admin->tab_intro( 'dashicons-format-chat', __( 'Widget & messaging', 'newtide-public-agent' ), __( 'Turn the agent on and choose the words your visitors read.', 'newtide-public-agent' ) ); ?>
<form method="post" action="options.php" class="npa-form">
	<?php settings_fields( NPA_Settings::GROUP ); ?>
	<?php $npa_admin->present_fields( array( 'enabled', 'launcher_label', 'greeting', 'input_placeholder', 'suggested_prompts', 'error_message' ) ); ?>

	<div class="npa-columns">
		<?php $npa_admin->card_open( __( 'Visibility', 'newtide-public-agent' ), __( 'The master switch for the front-end widget.', 'newtide-public-agent' ) ); ?>
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
		</table>
		<?php $npa_admin->card_close(); ?>

		<?php $npa_admin->card_open( __( 'Greeting & labels', 'newtide-public-agent' ), __( 'The words on the launcher and when the chat opens.', 'newtide-public-agent' ) ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="npa-launcher-label"><?php esc_html_e( 'Launcher label', 'newtide-public-agent' ); ?></label></th>
				<td>
					<input type="text" id="npa-launcher-label" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[launcher_label]" value="<?php echo esc_attr( $settings->get( 'launcher_label' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Text on the button that opens the chat.', 'newtide-public-agent' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="npa-greeting"><?php esc_html_e( 'Greeting', 'newtide-public-agent' ); ?></label></th>
				<td>
					<textarea id="npa-greeting" class="large-text" rows="2" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[greeting]"><?php echo esc_textarea( $settings->get( 'greeting' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'First message shown when the chat opens.', 'newtide-public-agent' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="npa-input-placeholder"><?php esc_html_e( 'Input placeholder', 'newtide-public-agent' ); ?></label></th>
				<td>
					<input type="text" id="npa-input-placeholder" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[input_placeholder]" value="<?php echo esc_attr( $settings->get( 'input_placeholder' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Hint text shown in the empty message box.', 'newtide-public-agent' ); ?></p>
				</td>
			</tr>
		</table>
		<?php $npa_admin->card_close(); ?>

		<?php $npa_admin->card_open( __( 'Prompts & errors', 'newtide-public-agent' ), __( 'Starter chips and the fallback failure message.', 'newtide-public-agent' ) ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="npa-suggested-prompts"><?php esc_html_e( 'Suggested prompts', 'newtide-public-agent' ); ?></label></th>
				<td>
					<textarea id="npa-suggested-prompts" class="large-text" rows="4" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[suggested_prompts]"><?php echo esc_textarea( $settings->get( 'suggested_prompts' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line. Shown as clickable chips when the chat opens — the first 6 are used and extra lines are ignored.', 'newtide-public-agent' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="npa-error-message"><?php esc_html_e( 'Error message', 'newtide-public-agent' ); ?></label></th>
				<td>
					<input type="text" id="npa-error-message" class="regular-text" name="<?php echo esc_attr( NPA_Settings::OPTION ); ?>[error_message]" value="<?php echo esc_attr( $settings->get( 'error_message' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Shown to the visitor if a message fails to send.', 'newtide-public-agent' ); ?></p>
				</td>
			</tr>
		</table>
		<?php $npa_admin->card_close(); ?>
	</div>

	<?php submit_button(); ?>
</form>
