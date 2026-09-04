<?php
/**
 * Clean removal of plugin data.
 *
 * Deletes plugin options and transients and drops the usage table. Does NOT
 * touch a wp-config.php constant (NPA_GATEWAY_KEY) — that is not ours to remove.
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
	'npa_schema_version',
);

foreach ( $npa_options as $npa_option ) {
	delete_option( $npa_option );
}

// Drop the usage and transcript tables. Transcripts hold visitor-authored
// content, so uninstalling must leave none of it behind.
global $wpdb;
$npa_tables = array(
	$wpdb->prefix . 'npa_usage',
	$wpdb->prefix . 'npa_transcripts',
);

foreach ( $npa_tables as $npa_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$npa_table}" );
}

delete_transient( 'npa_last_usage' );

// Remove the retention-purge cron event.
$npa_purge = wp_next_scheduled( 'npa_purge_transcripts' );
if ( $npa_purge ) {
	wp_unschedule_event( $npa_purge, 'npa_purge_transcripts' );
}

unset( $npa_options, $npa_option, $npa_tables, $npa_table, $npa_purge );
