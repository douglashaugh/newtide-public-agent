<?php
/**
 * HTTP gateway client — real calls to the Public Agent Gateway.
 *
 * Built against the PROVISIONAL contract in docs/GATEWAY-CONTRACT.md. Field
 * names / paths are unconfirmed until the real spec lands; when it does, this
 * class and that doc are the only things that should need to change. All
 * request shaping goes through filters so a small reconciliation is a config
 * change, not a rewrite.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Gateway_Client_Http
 */
class NPA_Gateway_Client_Http implements NPA_Gateway_Client {

	/**
	 * Gateway base URL (no trailing slash needed).
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Gateway credential.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Request timeout, seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Plugin version (for metadata).
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Site host (for metadata).
	 *
	 * @var string
	 */
	private $site;

	/**
	 * @param string   $base_url Gateway base URL.
	 * @param string   $key      Credential.
	 * @param int|null $timeout  Seconds; defaults to NPA_HTTP_TIMEOUT or 15.
	 * @param string|null $version Plugin version.
	 * @param string|null $site    Site host.
	 */
	public function __construct( $base_url, $key, $timeout = null, $version = null, $site = null ) {
		$this->base_url = untrailingslashit( (string) $base_url );
		$this->key      = (string) $key;
		$this->timeout  = null !== $timeout ? (int) $timeout : ( defined( 'NPA_HTTP_TIMEOUT' ) ? (int) NPA_HTTP_TIMEOUT : 15 );
		$this->version  = null !== $version ? (string) $version : ( defined( 'NPA_VERSION' ) ? NPA_VERSION : '' );
		$this->site     = null !== $site ? (string) $site : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $agent_id        Agent id.
	 * @param string $message         User message.
	 * @param string $conversation_id Conversation token.
	 * @param array  $context         Page context.
	 * @return NPA_Gateway_Result
	 * @throws NPA_Gateway_Exception On transport or gateway error.
	 */
	public function send_message( string $agent_id, string $message, string $conversation_id, array $context ): NPA_Gateway_Result {
		$url = $this->base_url . '/v1/agents/' . rawurlencode( $agent_id ) . '/messages';

		$body = array(
			'message'         => $message,
			'conversation_id' => $conversation_id,
			'context'         => $context,
			'metadata'        => array(
				'source'         => 'wordpress-plugin',
				'plugin_version' => $this->version,
				'site'           => $this->site,
			),
		);

		/**
		 * Filter the send_message request body (contract reconciliation seam).
		 *
		 * @param array  $body     Request body.
		 * @param string $agent_id Agent id.
		 */
		$body = apply_filters( 'npa_http_message_body', $body, $agent_id );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $this->timeout,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = $this->parse_or_throw( $response );

		return new NPA_Gateway_Result(
			isset( $data['reply'] ) ? (string) $data['reply'] : '',
			isset( $data['conversation_id'] ) && '' !== (string) $data['conversation_id'] ? (string) $data['conversation_id'] : $conversation_id,
			isset( $data['finish_reason'] ) ? (string) $data['finish_reason'] : 'stop',
			isset( $data['usage']['input_tokens'] ) ? (int) $data['usage']['input_tokens'] : 0,
			isset( $data['usage']['output_tokens'] ) ? (int) $data['usage']['output_tokens'] : 0,
			$data
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return NPA_Gateway_Agent[]
	 * @throws NPA_Gateway_Exception On a credential/transport error.
	 */
	public function list_agents(): array {
		$response = wp_remote_get(
			$this->base_url . '/v1/agents',
			array(
				'timeout' => $this->timeout,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new NPA_Gateway_Exception( $response->get_error_message(), 'transport', 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// No list endpoint -> degrade to manual entry.
		if ( 404 === $code ) {
			return array();
		}

		$data = $this->parse_or_throw( $response );

		$rows   = isset( $data['agents'] ) && is_array( $data['agents'] ) ? $data['agents'] : $data;
		$agents = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$agents[] = new NPA_Gateway_Agent(
				(string) $row['id'],
				isset( $row['name'] ) ? (string) $row['name'] : '',
				isset( $row['description'] ) ? (string) $row['description'] : ''
			);
		}

		return $agents;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Never throws — reports an unhealthy result on any failure.
	 *
	 * @return NPA_Gateway_Health
	 */
	public function health_check(): NPA_Gateway_Health {
		$start = microtime( true );

		$response = wp_remote_get(
			$this->base_url . '/v1/health',
			array(
				'timeout' => $this->timeout,
				'headers' => $this->headers(),
			)
		);

		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return new NPA_Gateway_Health( false, $response->get_error_message(), $latency );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return new NPA_Gateway_Health( true, __( 'Connected.', 'newtide-public-agent' ), $latency );
		}

		if ( 401 === $code || 403 === $code ) {
			return new NPA_Gateway_Health( false, __( 'Credential rejected.', 'newtide-public-agent' ), $latency );
		}

		/* translators: %d: HTTP status code. */
		return new NPA_Gateway_Health( false, sprintf( __( 'Gateway returned HTTP %d.', 'newtide-public-agent' ), $code ), $latency );
	}

	/**
	 * Request headers, including auth.
	 *
	 * @return array
	 */
	private function headers() {
		$headers = array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $this->key,
		);

		/**
		 * Filter the gateway request headers (e.g. to switch to X-NewTide-Key).
		 *
		 * @param array $headers Default headers.
		 */
		return apply_filters( 'npa_http_headers', $headers );
	}

	/**
	 * Parse a successful JSON response or throw a mapped exception.
	 *
	 * @param array|WP_Error $response Response from wp_remote_*.
	 * @return array Decoded body.
	 * @throws NPA_Gateway_Exception On transport or non-2xx.
	 */
	private function parse_or_throw( $response ) {
		if ( is_wp_error( $response ) ) {
			throw new NPA_Gateway_Exception( $response->get_error_message(), 'transport', 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code >= 200 && $code < 300 ) {
			return $data;
		}

		$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : ( 'Gateway error (HTTP ' . $code . ').' );
		throw new NPA_Gateway_Exception( $message, self::code_for_status( $code ), $code );
	}

	/**
	 * Map an HTTP status to a stable error code.
	 *
	 * @param int $code HTTP status.
	 * @return string
	 */
	public static function code_for_status( $code ) {
		if ( 401 === $code || 403 === $code ) {
			return 'unauthorized';
		}
		if ( 429 === $code ) {
			return 'rate_limited';
		}
		if ( $code >= 500 ) {
			return 'server_error';
		}
		return 'error';
	}
}
