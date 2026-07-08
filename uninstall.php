<?php
/**
 * Clean removal of plugin data.
 *
 * Deletes plugin options and transients. Does NOT touch a wp-config.php
 * constant (NPA_GATEWAY_KEY) — that is not ours to remove. The custom
 * table drop (added with the Store subsystem) is intentionally left for a
 * later milestone so uninstall stays truthful about what exists today.
 *
 * @package NewTide\PublicAgent
 */

// Only run from the WordPress uninstall lifecycle.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$npa_options = array(
	'npa_options',
	'npa_log_ring',
	'npa_service_counters',
	'npa_test_snapshot',
);

foreach ( $npa_options as $npa_option ) {
	delete_option( $npa_option );
}

unset( $npa_options, $npa_option );
