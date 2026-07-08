<?php
/**
 * Result of a health_check() call.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Gateway_Health
 */
class NPA_Gateway_Health {

	/**
	 * Construct a health result.
	 *
	 * @param bool   $ok         Whether the gateway is reachable and the credential valid.
	 * @param string $message    Human-readable status (shown in admin / Site Health).
	 * @param int    $latency_ms Round-trip latency in milliseconds.
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly string $message = '',
		public readonly int $latency_ms = 0
	) {}
}
