<?php
/**
 * Shared, deterministic test battery (ADR-011).
 *
 * Features register suites here. The battery is fast and deterministic with
 * NO live HTTP — it runs against stored data / fixtures / the mock gateway.
 * Results persist to a snapshot and render in the admin "Tests" tab, where
 * each suite carries plain-language "why this matters" copy. QC and a
 * user-facing trust artifact at once.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Test_Runner
 */
class NPA_Test_Runner {

	/**
	 * Option key holding the most recent results snapshot.
	 *
	 * @var string
	 */
	const SNAPSHOT_OPTION = 'npa_test_snapshot';

	/**
	 * Registered suites, keyed by id.
	 *
	 * @var array<string,array{label:string,why:string,callback:callable}>
	 */
	private $suites = array();

	/**
	 * Register a test suite.
	 *
	 * @param string   $id       Unique suite id.
	 * @param string   $label    Human-readable suite name.
	 * @param string   $why      Plain-language "why this matters" copy.
	 * @param callable $callback Returns an array of checks, each:
	 *                           { label:string, pass:bool }.
	 * @return void
	 */
	public function register_suite( $id, $label, $why, callable $callback ) {
		$this->suites[ sanitize_key( $id ) ] = array(
			'label'    => $label,
			'why'      => $why,
			'callback' => $callback,
		);
	}

	/**
	 * Run every registered suite and persist a snapshot.
	 *
	 * @return array The results structure.
	 */
	public function run_all() {
		$results     = array();
		$total_pass  = 0;
		$total_count = 0;

		foreach ( $this->suites as $id => $suite ) {
			$checks = call_user_func( $suite['callback'] );
			$checks = is_array( $checks ) ? $checks : array();

			$suite_pass = 0;
			foreach ( $checks as $check ) {
				if ( ! empty( $check['pass'] ) ) {
					++$suite_pass;
				}
			}

			$results[ $id ] = array(
				'label'  => $suite['label'],
				'why'    => $suite['why'],
				'checks' => $checks,
				'passed' => $suite_pass,
				'total'  => count( $checks ),
			);

			$total_pass  += $suite_pass;
			$total_count += count( $checks );
		}

		$snapshot = array(
			'time'    => time(),
			'version' => defined( 'NPA_VERSION' ) ? NPA_VERSION : '',
			'passed'  => $total_pass,
			'total'   => $total_count,
			'suites'  => $results,
		);

		update_option( self::SNAPSHOT_OPTION, $snapshot, false );

		return $snapshot;
	}

	/**
	 * Read the last persisted results snapshot.
	 *
	 * @return array|null
	 */
	public function last_snapshot() {
		$snapshot = get_option( self::SNAPSHOT_OPTION, null );
		return is_array( $snapshot ) ? $snapshot : null;
	}

	/**
	 * Number of registered suites.
	 *
	 * @return int
	 */
	public function suite_count() {
		return count( $this->suites );
	}
}
