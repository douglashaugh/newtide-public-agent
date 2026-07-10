<?php
/**
 * Home tab — a dashboard landing: setup checklist with a progress meter, quick
 * links to each configuration area, and settings export / import tools.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;

$checklist = $npa_admin->get_setup_checklist();
$done      = 0;
foreach ( $checklist as $item ) {
	$done += $item['done'] ? 1 : 0;
}
$total   = max( 1, count( $checklist ) );
$percent = (int) round( $done / $total * 100 );

// Import result notice (set by handle_import()'s redirect).
$import_note = isset( $_GET['npa_import'] ) ? sanitize_key( wp_unslash( $_GET['npa_import'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$quick_links = array(
	'agent'      => array( 'dashicons-admin-links', __( 'Connect your agent', 'newtide-public-agent' ), __( 'Credential, agent, and where the chat appears.', 'newtide-public-agent' ) ),
	'appearance' => array( 'dashicons-art', __( 'Style it', 'newtide-public-agent' ), __( 'Colours, launcher icon, and a live preview.', 'newtide-public-agent' ) ),
	'additional' => array( 'dashicons-groups', __( 'Add more agents', 'newtide-public-agent' ), __( 'Run different agents on specific pages.', 'newtide-public-agent' ) ),
	'status'     => array( 'dashicons-chart-bar', __( 'See usage', 'newtide-public-agent' ), __( 'Traffic, error rate, and health.', 'newtide-public-agent' ) ),
);
?>
<?php $npa_admin->tab_intro( 'dashicons-superhero', __( 'Welcome', 'newtide-public-agent' ), __( 'Get your public agent live, then fine-tune how it looks and behaves.', 'newtide-public-agent' ) ); ?>

<?php if ( 'ok' === $import_note ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings imported.', 'newtide-public-agent' ); ?></p></div>
<?php elseif ( 'badfile' === $import_note ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That file didn’t look like a NewTide settings export.', 'newtide-public-agent' ); ?></p></div>
<?php elseif ( 'nofile' === $import_note ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please choose a file to import.', 'newtide-public-agent' ); ?></p></div>
<?php endif; ?>

<div class="npa-columns npa-columns--2">
	<?php $npa_admin->card_open( __( 'Setup checklist', 'newtide-public-agent' ), __( 'A few steps to a live agent.', 'newtide-public-agent' ) ); ?>
	<div class="npa-setup">
		<div class="npa-setup__meter" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-valuemin="0" aria-valuemax="100">
			<div class="npa-setup__fill" style="width:<?php echo esc_attr( (string) $percent ); ?>%"></div>
		</div>
		<p class="npa-setup__count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: completed steps, 2: total steps. */
					__( '%1$d of %2$d complete', 'newtide-public-agent' ),
					$done,
					count( $checklist )
				)
			);
			?>
			<?php if ( count( $checklist ) === $done ) : ?>
				<span class="npa-pill npa-pill--ok"><?php esc_html_e( 'All set!', 'newtide-public-agent' ); ?></span>
			<?php endif; ?>
		</p>
		<ul class="npa-setup__list">
			<?php foreach ( $checklist as $item ) : ?>
				<li class="<?php echo $item['done'] ? 'is-done' : 'is-todo'; ?>">
					<span class="npa-setup__mark" aria-hidden="true"><?php echo $item['done'] ? '✓' : '○'; ?></span>
					<a href="<?php echo esc_url( $npa_admin->tab_url( $item['tab'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
					<span class="description"><?php echo esc_html( $item['hint'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php $npa_admin->card_close(); ?>

	<?php $npa_admin->card_open( __( 'Backup & migrate', 'newtide-public-agent' ), __( 'Move this configuration to another site.', 'newtide-public-agent' ) ); ?>
	<p class="description"><?php esc_html_e( 'Export downloads every setting (agents, appearance, behavior) as a JSON file. Your secret gateway credential is never included.', 'newtide-public-agent' ); ?></p>
	<div class="npa-tools">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="npa_export" />
			<?php wp_nonce_field( 'npa_export' ); ?>
			<button type="submit" class="button">
				<span class="dashicons dashicons-download" aria-hidden="true" style="vertical-align:text-bottom"></span>
				<?php esc_html_e( 'Export settings', 'newtide-public-agent' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="npa-import-form">
			<input type="hidden" name="action" value="npa_import" />
			<?php wp_nonce_field( 'npa_import' ); ?>
			<input type="file" name="npa_import_file" accept="application/json,.json" required />
			<button type="submit" class="button">
				<span class="dashicons dashicons-upload" aria-hidden="true" style="vertical-align:text-bottom"></span>
				<?php esc_html_e( 'Import', 'newtide-public-agent' ); ?>
			</button>
		</form>
	</div>
	<?php $npa_admin->card_close(); ?>
</div>

<div class="npa-quicklinks">
	<?php foreach ( $quick_links as $slug => $meta ) : ?>
		<a class="npa-quicklink" href="<?php echo esc_url( $npa_admin->tab_url( $slug ) ); ?>">
			<span class="npa-quicklink__icon dashicons <?php echo esc_attr( $meta[0] ); ?>" aria-hidden="true"></span>
			<span class="npa-quicklink__title"><?php echo esc_html( $meta[1] ); ?></span>
			<span class="npa-quicklink__desc"><?php echo esc_html( $meta[2] ); ?></span>
		</a>
	<?php endforeach; ?>
</div>
