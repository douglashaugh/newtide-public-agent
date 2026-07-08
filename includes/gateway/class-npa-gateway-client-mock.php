<?php
/**
 * Deterministic mock gateway client.
 *
 * The backbone of the plugin's testability (plan §7): deterministic replies and
 * switchable scenarios that simulate 401 / 429 / 5xx and slow responses, so the
 * error paths are exercised without a live endpoint. Build and test the whole
 * plugin against this; swap in the HTTP client only at the end.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Gateway_Client_Mock
 */
class NPA_Gateway_Client_Mock implements NPA_Gateway_Client {

	/**
	 * The real target agent, used as the canned agent so mock and production
	 * exercise the same identifier.
	 *
	 * @var string
	 */
	const DEFAULT_AGENT_ID = '37cf3d4c-e12b-485f-978d-019aa5db96be';

	/**
	 * Active scenario: ok | unauthorized | rate_limited | server_error | slow.
	 *
	 * @var string
	 */
	private $scenario;

	/**
	 * Simulated round-trip latency in milliseconds (reported, not slept).
	 *
	 * @var int
	 */
	private $latency_ms;

	/**
	 * @param string $scenario   Initial scenario.
	 * @param int    $latency_ms Simulated latency for health/slow scenarios.
	 */
	public function __construct( string $scenario = 'ok', int $latency_ms = 20 ) {
		$this->scenario   = $scenario;
		$this->latency_ms = $latency_ms;
	}

	/**
	 * Switch the active scenario (used by the test battery).
	 *
	 * @param string $scenario One of the supported scenarios.
	 * @return void
	 */
	public function set_scenario( string $scenario ) {
		$this->scenario = $scenario;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $agent_id        Agent id.
	 * @param string $message         User message.
	 * @param string $conversation_id Conversation token (empty on first turn).
	 * @param array  $context         Page context.
	 * @return NPA_Gateway_Result
	 * @throws NPA_Gateway_Exception When the active scenario simulates an error.
	 */
	public function send_message( string $agent_id, string $message, string $conversation_id, array $context ): NPA_Gateway_Result {
		$this->maybe_throw();

		if ( '' === $conversation_id ) {
			$conversation_id = 'mock-conv-' . substr( md5( $message ), 0, 8 );
		}

		$reply = sprintf(
			/* translators: %s: the visitor's message, echoed by the mock. */
			__( 'Mock agent reply. You said: "%s". (This is a canned response from the mock gateway.)', 'newtide-public-agent' ),
			$message
		);

		$finish_reason = ( 'slow' === $this->scenario ) ? 'stop' : 'stop';

		return new NPA_Gateway_Result(
			$reply,
			$conversation_id,
			$finish_reason,
			str_word_count( $message ),
			str_word_count( wp_strip_all_tags( $reply ) ),
			array(
				'mock'     => true,
				'scenario' => $this->scenario,
				'agent_id' => $agent_id,
				'context'  => $context,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return NPA_Gateway_Agent[]
	 * @throws NPA_Gateway_Exception When the active scenario simulates an error.
	 */
	public function list_agents(): array {
		$this->maybe_throw();

		return array(
			new NPA_Gateway_Agent(
				self::DEFAULT_AGENT_ID,
				__( 'RisingTide Public Agent (mock)', 'newtide-public-agent' ),
				__( 'Canned agent served by the mock gateway for local development.', 'newtide-public-agent' )
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Never throws — reports an unhealthy result for error scenarios.
	 *
	 * @return NPA_Gateway_Health
	 */
	public function health_check(): NPA_Gateway_Health {
		switch ( $this->scenario ) {
			case 'unauthorized':
				return new NPA_Gateway_Health( false, __( 'Credential rejected (mock).', 'newtide-public-agent' ), $this->latency_ms );
			case 'rate_limited':
				return new NPA_Gateway_Health( false, __( 'Rate limited (mock).', 'newtide-public-agent' ), $this->latency_ms );
			case 'server_error':
				return new NPA_Gateway_Health( false, __( 'Gateway error (mock).', 'newtide-public-agent' ), $this->latency_ms );
			case 'slow':
				return new NPA_Gateway_Health( true, __( 'Connected but slow (mock).', 'newtide-public-agent' ), max( 8000, $this->latency_ms ) );
			default:
				return new NPA_Gateway_Health( true, __( 'Connected (mock).', 'newtide-public-agent' ), $this->latency_ms );
		}
	}

	/**
	 * Throw the exception mapped to the active error scenario, if any.
	 *
	 * @return void
	 * @throws NPA_Gateway_Exception
	 */
	private function maybe_throw() {
		switch ( $this->scenario ) {
			case 'unauthorized':
				throw new NPA_Gateway_Exception( 'Mock: invalid or revoked credential.', 'unauthorized', 401 );
			case 'rate_limited':
				throw new NPA_Gateway_Exception( 'Mock: rate limited by gateway.', 'rate_limited', 429 );
			case 'server_error':
				throw new NPA_Gateway_Exception( 'Mock: gateway internal error.', 'server_error', 500 );
		}
	}
}
