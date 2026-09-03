<?php
/**
 * Structured, opt-in logger with a bounded "recent calls" ring buffer.
 *
 * Powers the admin status panel without unbounded growth. Never logs the
 * gateway key; never logs full message bodies by default (privacy).
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Logger
 */
class NPA_Logger {

	/**
	 * Option key holding the ring buffer.
	 *
	 * @var string
	 */
	const RING_OPTION = 'npa_log_ring';

	/**
	 * Maximum number of entries retained in the ring buffer.
	 *
	 * @var int
	 */
	const RING_MAX = 50;

	/**
	 * Whether logging is currently enabled.
	 *
	 * Honours the NPA_LOG_ENABLED constant first (force on/off), then falls
	 * back to the stored setting (added in a later milestone; default off).
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if ( defined( 'NPA_LOG_ENABLED' ) ) {
			return (bool) NPA_LOG_ENABLED;
		}

		/*
		 * Read the option directly rather than through NPA_Settings: the logger is
		 * constructed before the settings object exists, and this is a single
		 * boolean on the one options array. The constant above still wins.
		 */
		$opts = get_option( NPA_Settings::OPTION, array() );

		return is_array( $opts ) && ! empty( $opts['log_enabled'] );
	}

	/**
	 * Append a structured entry to the ring buffer.
	 *
	 * @param array $entry Sanitized, PII-conscious fields (timestamp, agent_id,
	 *                     latency_ms, status, finish_reason, error_code, note).
	 * @return void
	 */
	public function log( array $entry ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$entry = wp_parse_args(
			$entry,
			array(
				'time'          => time(),
				'agent_id'      => '',
				'latency_ms'    => 0,
				'status'        => 0,
				'finish_reason' => '',
				'error_code'    => '',
				'note'          => '',
			)
		);

		$ring   = $this->recent( self::RING_MAX );
		$ring[] = $entry;

		if ( count( $ring ) > self::RING_MAX ) {
			$ring = array_slice( $ring, -self::RING_MAX );
		}

		update_option( self::RING_OPTION, $ring, false );
	}

	/**
	 * Return the most recent N entries (oldest first).
	 *
	 * @param int $limit Maximum entries to return.
	 * @return array<int,array>
	 */
	public function recent( $limit = self::RING_MAX ) {
		$ring = get_option( self::RING_OPTION, array() );
		if ( ! is_array( $ring ) ) {
			$ring = array();
		}
		$limit = max( 1, (int) $limit );
		return array_slice( $ring, -$limit );
	}
}
