<?php
/**
 * Thrown by a gateway client on a transport or gateway error.
 *
 * Carries a stable error code and the HTTP status so callers can branch
 * (401/403 -> tell the admin; 429 -> "busy, try again"; 5xx -> graceful
 * fallback) without parsing messages. The message is for logs/admins — never
 * surface it raw to a site visitor.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Gateway_Exception
 */
class NPA_Gateway_Exception extends Exception {

	/**
	 * Stable, machine-readable error code (e.g. unauthorized, rate_limited, server_error, transport).
	 *
	 * @var string
	 */
	protected $error_code;

	/**
	 * HTTP status associated with the error, or 0 for transport-level failures.
	 *
	 * @var int
	 */
	protected $http_status;

	/**
	 * Construct the exception.
	 *
	 * @param string         $message     Admin/log-facing message.
	 * @param string         $error_code  Stable error code.
	 * @param int            $http_status HTTP status (0 for transport-level).
	 * @param Throwable|null $previous    Previous throwable for chaining.
	 */
	public function __construct( string $message, string $error_code = 'error', int $http_status = 0, ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->error_code  = $error_code;
		$this->http_status = $http_status;
	}

	/**
	 * Get the stable error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get the associated HTTP status (0 for transport-level failures).
	 *
	 * @return int
	 */
	public function get_http_status(): int {
		return $this->http_status;
	}
}
