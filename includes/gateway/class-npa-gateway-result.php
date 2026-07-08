<?php
/**
 * Immutable result of a successful send_message() call.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Gateway_Result
 */
class NPA_Gateway_Result {

	/**
	 * Construct a result.
	 *
	 * @param string $reply_text      The agent's reply text.
	 * @param string $conversation_id Echoed / assigned session token.
	 * @param string $finish_reason   One of: stop, length, filtered, error.
	 * @param int    $input_tokens    Prompt tokens reported by the gateway.
	 * @param int    $output_tokens   Completion tokens reported by the gateway.
	 * @param array  $raw             The raw decoded gateway payload (for logging/debug).
	 */
	public function __construct(
		public readonly string $reply_text,
		public readonly string $conversation_id,
		public readonly string $finish_reason = 'stop',
		public readonly int $input_tokens = 0,
		public readonly int $output_tokens = 0,
		public readonly array $raw = array()
	) {}
}
