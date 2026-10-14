<?php
/**
 * What's new tab — the release notes for the version installed, read from the
 * Changelog in readme.txt (NPA_Release_Notes), with earlier releases below.
 *
 * @package NewTide\PublicAgent
 *
 * @var NPA_Admin    $npa_admin Admin controller.
 * @var NPA_Settings $settings  Settings store.
 */

defined( 'ABSPATH' ) || exit;

// Opening this tab is reading the notes: the update notice has done its job.
NPA_Release_Notes::mark_seen( get_current_user_id() );

$npa_entries = NPA_Release_Notes::entries();
$npa_current = NPA_Release_Notes::for_version( NPA_VERSION );
$npa_earlier = array_values(
	array_filter(
		$npa_entries,
		static function ( $entry ) {
			return NPA_VERSION !== $entry['version'];
		}
	)
);
?>
<?php
$npa_admin->tab_intro(
	'dashicons-megaphone',
	__( 'What’s new', 'newtide-public-agent' ),
	sprintf(
		/* translators: %s: plugin version, e.g. 0.16.0. */
		__( 'Release notes for the version you are running, %s, and the releases before it.', 'newtide-public-agent' ),
		NPA_VERSION
	)
);
?>

<?php
$npa_admin->card_open(
	sprintf(
		/* translators: %s: plugin version. */
		__( 'Version %s', 'newtide-public-agent' ),
		NPA_VERSION
	),
	__( 'Installed on this site.', 'newtide-public-agent' )
);
?>
<div class="npa-release-notes">
	<?php if ( $npa_current ) : ?>
		<?php
		// NPA_Markdown escapes first and sanitizes with wp_kses last.
		echo NPA_Markdown::to_html( $npa_current['body'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	<?php else : ?>
		<p><?php esc_html_e( 'There are no release notes for this version in readme.txt.', 'newtide-public-agent' ); ?></p>
	<?php endif; ?>
</div>
<?php $npa_admin->card_close(); ?>

<?php if ( $npa_earlier ) : ?>
	<?php $npa_admin->card_open( __( 'Earlier releases', 'newtide-public-agent' ), __( 'Newest first. Open one to read it.', 'newtide-public-agent' ) ); ?>
	<div class="npa-release-notes npa-release-notes--earlier">
		<?php foreach ( $npa_earlier as $npa_entry ) : ?>
			<details class="npa-release-notes__entry">
				<summary><?php echo esc_html( $npa_entry['version'] ); ?></summary>
				<?php
				echo NPA_Markdown::to_html( $npa_entry['body'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</details>
		<?php endforeach; ?>
	</div>
	<?php $npa_admin->card_close(); ?>
<?php endif; ?>
