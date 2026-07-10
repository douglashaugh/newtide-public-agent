<?php
/**
 * Plugin Name:       NewTide Public Agent
 * Plugin URI:        https://github.com/asamarie/Public-Agent-Plugin
 * Description:        Embed a published NewTide / Agent Harbor public agent on your WordPress site. Thin client over the Public Agent Gateway; the gateway owns identity, safety, rate-limiting, and cost control.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            NewTide
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       newtide-public-agent
 * Domain Path:       /languages
 *
 * @package NewTide\PublicAgent
 */

// ADR: no direct file access.
defined( 'ABSPATH' ) || exit;

/*
 * ADR-002 — Two version fields, always in lockstep.
 * The header `Version:` above (what Plugin Update Checker compares) and the
 * NPA_VERSION constant below MUST be bumped together on every release.
 * A mismatch is a release bug: WP will never offer the update.
 */
define( 'NPA_VERSION', '0.2.0' );
define( 'NPA_PLUGIN_FILE', __FILE__ );
define( 'NPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NPA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Core bootstrap.
require_once NPA_PLUGIN_DIR . 'includes/class-npa-plugin.php';

/*
 * Deployment (ADR-001) — git-as-deploy via Plugin Update Checker on `main`.
 * Drop the library into lib/plugin-update-checker/ (vendored, committed) and
 * this block wires auto-updates from the GitHub repo. Guarded so the plugin
 * never fatals if the library is not yet present (e.g. a fresh checkout).
 */
$npa_puc = NPA_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( is_readable( $npa_puc ) ) {
	require_once $npa_puc;

	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		$npa_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/asamarie/Public-Agent-Plugin/',
			NPA_PLUGIN_FILE,
			'newtide-public-agent'
		);
		$npa_update_checker->setBranch( 'main' );
	}
}
unset( $npa_puc );

// Create the schema on activation (updates are handled by maybe_upgrade()).
register_activation_hook( __FILE__, array( 'NPA_Plugin', 'activate' ) );

/**
 * Boot the plugin once WordPress and all plugins are loaded.
 */
add_action( 'plugins_loaded', array( 'NPA_Plugin', 'instance' ) );
