<?php
/**
 * Tests tab — run the deterministic battery and render the snapshot with
 * plain-language "why this matters" copy per suite.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;

$npa_snapshot = NPA_Plugin::instance()->test_runner->last_snapshot();
?>
<?php $npa_admin->tab_intro( 'dashicons-yes-alt', __( 'Tests', 'newtide-public-agent' ), __( 'A fast, deterministic battery — quality control you can run any time.', 'newtide-public-agent' ) ); ?>

<?php $npa_admin->card_open( __( 'Test battery', 'newtide-public-agent' ), __( 'Runs against fixtures and the mock gateway — never live traffic.', 'newtide-public-agent' ) ); ?>
<p class="description">
	<?php esc_html_e( 'Both quality control and a plain-language record of what the plugin guarantees.', 'newtide-public-agent' ); ?>
</p>

<p class="npa-actions">
	<button type="button" class="button button-primary" id="npa-run-tests"><?php esc_html_e( 'Run tests', 'newtide-public-agent' ); ?></button>
	<span id="npa-tests-status" class="npa-test-result" role="status" aria-live="polite"></span>
</p>

<div id="npa-tests-results">
	<?php
	// results_html() is fully escaped at construction.
	echo $npa_admin->results_html( $npa_snapshot ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</div>
<?php $npa_admin->card_close(); ?>
