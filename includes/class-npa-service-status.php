<?php
/**
 * Central health / backoff / auto-disable registry.
 *
 * Every data feature registers a status provider here (ADR-009/010). The admin
 * "Service Status" tab (added in a later milestone) renders the roll-up.
 * Failure policy: warn at 3 consecutive failures, auto-disable at 10.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Service_Status
 */
class NPA_Service_Status {

	/**
	 * Option key holding per-feature failure counters.
	 *
	 * @var string
	 */
	const COUNTERS_OPTION = 'npa_service_counters';

	/**
	 * Consecutive failures at which to warn.
	 *
	 * @var int
	 */
	const WARN_AT = 3;

	/**
	 * Consecutive failures at which to auto-disable.
	 *
	 * @var int
	 */
	const DISABLE_AT = 10;

	/**
	 * Registered status providers, keyed by feature id.
	 *
	 * @var array<string,array{label:string,callback:callable}>
	 */
	private $providers = array();

	/**
	 * Register a status provider for a feature.
	 *
	 * @param string   $id       Unique feature id.
	 * @param string   $label    Human-readable label.
	 * @param callable $callback Returns an array: { ok:bool, message:string, ... }.
	 * @return void
	 */
	public function register( $id, $label, callable $callback ) {
		$this->providers[ sanitize_key( $id ) ] = array(
			'label'    => $label,
			'callback' => $callback,
		);
	}

	/**
	 * Collect the current status of every registered provider.
	 *
	 * @return array<string,array>
	 */
	public function collect() {
		$out = array();
		foreach ( $this->providers as $id => $provider ) {
			$status = call_user_func( $provider['callback'] );
			if ( ! is_array( $status ) ) {
				$status = array(
					'ok'      => false,
					'message' => __( 'Status provider returned no data.', 'newtide-public-agent' ),
				);
			}
			$status['label']    = $provider['label'];
			$status['failures'] = $this->failure_count( $id );
			$out[ $id ]         = $status;
		}
		return $out;
	}

	/**
	 * Record a success for a feature, resetting its failure counter.
	 *
	 * @param string $id Feature id.
	 * @return void
	 */
	public function record_success( $id ) {
		$counters = $this->counters();
		unset( $counters[ sanitize_key( $id ) ] );
		update_option( self::COUNTERS_OPTION, $counters, false );
	}

	/**
	 * Record a failure for a feature and return the new consecutive count.
	 *
	 * @param string $id Feature id.
	 * @return int New consecutive failure count.
	 */
	public function record_failure( $id ) {
		$id               = sanitize_key( $id );
		$counters         = $this->counters();
		$counters[ $id ]  = isset( $counters[ $id ] ) ? (int) $counters[ $id ] + 1 : 1;
		update_option( self::COUNTERS_OPTION, $counters, false );
		return $counters[ $id ];
	}

	/**
	 * Current consecutive failure count for a feature.
	 *
	 * @param string $id Feature id.
	 * @return int
	 */
	public function failure_count( $id ) {
		$counters = $this->counters();
		$id       = sanitize_key( $id );
		return isset( $counters[ $id ] ) ? (int) $counters[ $id ] : 0;
	}

	/**
	 * Whether a feature has crossed the auto-disable threshold.
	 *
	 * @param string $id Feature id.
	 * @return bool
	 */
	public function is_auto_disabled( $id ) {
		return $this->failure_count( $id ) >= self::DISABLE_AT;
	}

	/**
	 * Read the raw counters array.
	 *
	 * @return array<string,int>
	 */
	private function counters() {
		$counters = get_option( self::COUNTERS_OPTION, array() );
		return is_array( $counters ) ? $counters : array();
	}
}
