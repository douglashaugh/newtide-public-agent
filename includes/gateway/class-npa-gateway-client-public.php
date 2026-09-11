<?php
/**
 * Client for the RisingTide **public agent API** — the service the embedded
 * widget actually talks to.
 *
 * Discovered by reading the chunk that `/embed/public-chat` lazy-loads; see
 * docs/GATEWAY-CONTRACT.md for the full account. It differs from the
 * provisional contract NPA_Gateway_Client_Http was written against in every
 * respect that matters:
 *
 *   - a separate host: ai.newtide.ai  ->  ai-api.newtide.ai
 *   - auth via `X-Api-Key`, not `Authorization: Bearer`
 *   - the allowed-origins check reads an `X-Embed-Origin` request header, which
 *     the CALLER supplies — that is what lets a server use this API at all,
 *     rather than it being browser-only
 *   - `POST /public/chat/stream` answers with Server-Sent Events, not JSON
 *
 * The credential here is the publishable `pk_` key, so proxying is not about
 * hiding a secret. What it buys is the plugin rendering its own widget — and
 * therefore the Appearance and Behavior settings meaning something — plus
 * keeping the key out of page HTML and moving the origin claim server-side.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Gateway_Client_Public
 */
class NPA_Gateway_Client_Public implements NPA_Gateway_Client {

	/**
	 * API base URL, no trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Publishable key sent as X-Api-Key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Origin claimed in X-Embed-Origin — this site's own origin.
	 *
	 * @var string
	 */
	private $origin;

	/**
	 * Request timeout, seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Derive the API host from a platform URL.
	 *
	 * The API lives on a sibling host: the first label of the platform host
	 * gains an `-api` suffix (`uat-ai.newtide.ai` -> `uat-ai-api.newtide.ai`,
	 * `ai.newtide.ai` -> `ai-api.newtide.ai`). Returns '' when the input has no
	 * usable host, so callers can fall back rather than build a broken URL.
	 *
	 * @param string $platform_url Platform URL from settings.
	 * @return string API base URL without a trailing slash, or ''.
	 */
	public static function api_base_from_platform( $platform_url ) {
		$platform_url = trim( (string) $platform_url );
		if ( '' === $platform_url ) {
			return '';
		}

		$parts = wp_parse_url( $platform_url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'];

		$dot = strpos( $host, '.' );
		if ( false === $dot ) {
			return '';
		}

		$api_host = substr( $host, 0, $dot ) . '-api' . substr( $host, $dot );

		/**
		 * Filter the derived public-API host.
		 *
		 * The naming convention is inferred, so give integrators an escape hatch
		 * rather than making them edit the plugin if it ever differs.
		 *
		 * @param string $api_host     Derived host.
		 * @param string $platform_url The platform URL it came from.
		 */
		$api_host = apply_filters( 'npa_public_api_host', $api_host, $platform_url );

		return $scheme . '://' . $api_host;
	}

	/**
	 * This site's origin, for the X-Embed-Origin header.
	 *
	 * The browser sends the page that framed the widget; a server-side caller
	 * sends the site it is acting for. Either way it must match an entry in the
	 * key's allowed-origins list, exactly as an origin — scheme and host only,
	 * never a trailing slash or path.
	 *
	 * @return string
	 */
	public static function site_origin() {
		$parts = wp_parse_url( home_url() );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$origin = $scheme . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		/**
		 * Filter the origin claimed for the allowed-origins check.
		 *
		 * @param string $origin Derived site origin.
		 */
		return apply_filters( 'npa_public_api_origin', $origin );
	}

	/**
	 * Constructor.
	 *
	 * @param string      $base_url API base URL.
	 * @param string      $key      Publishable key.
	 * @param string|null $origin   Origin to claim; defaults to this site's.
	 * @param int|null    $timeout  Seconds; defaults to NPA_HTTP_TIMEOUT or 30.
	 */
	public function __construct( $base_url, $key, $origin = null, $timeout = null ) {
		$this->base_url = untrailingslashit( (string) $base_url );
		$this->key      = (string) $key;
		$this->origin   = null !== $origin ? (string) $origin : self::site_origin();
		// A streamed reply can run past the 15s used for plain JSON calls.
		$this->timeout = null !== $timeout ? (int) $timeout : ( defined( 'NPA_HTTP_TIMEOUT' ) ? (int) NPA_HTTP_TIMEOUT : 30 );
	}

	/**
	 * Request headers. The origin header is omitted when unknown rather than
	 * sent empty, which would fail the check on its own.
	 *
	 * @param bool $json Whether to declare a JSON request body.
	 * @return array
	 */
	private function headers( $json = false ) {
		$headers = array(
			'X-Api-Key' => $this->key,
			'Accept'    => 'text/event-stream, application/json',
		);

		if ( '' !== $this->origin ) {
			$headers['X-Embed-Origin'] = $this->origin;
		}

		if ( $json ) {
			$headers['Content-Type'] = 'application/json';
		}

		/**
		 * Filter the public-API request headers.
		 *
		 * @param array $headers Default headers.
		 */
		return apply_filters( 'npa_public_api_headers', $headers );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The agent is resolved server-side from the key, so $agent_id and
	 * $conversation_id are not sent — the API accepts `{message}` alone. The
	 * conversation id is echoed back so the widget keeps threading its own view
	 * of the exchange; see docs/GATEWAY-CONTRACT.md on server-side memory being
	 * unproven on this path.
	 *
	 * @param string $agent_id        Ignored — the key selects the agent.
	 * @param string $message         User message.
	 * @param string $conversation_id Echoed back unchanged.
	 * @param array  $context         Ignored by this API.
	 * @return NPA_Gateway_Result
	 * @throws NPA_Gateway_Exception On transport or API error.
	 */
	public function send_message( string $agent_id, string $message, string $conversation_id, array $context ): NPA_Gateway_Result {
		$body = array( 'message' => $message );

		/**
		 * Filter the chat request body (contract reconciliation seam).
		 *
		 * @param array  $body     Request body.
		 * @param string $agent_id Agent id, if the caller tracks one.
		 */
		$body = apply_filters( 'npa_public_api_chat_body', $body, $agent_id );

		$response = wp_remote_post(
			$this->base_url . '/public/chat/stream',
			array(
				'timeout' => $this->timeout,
				'headers' => $this->headers( true ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Internal, log-facing message — not browser output.
			throw new NPA_Gateway_Exception( $response->get_error_message(), 'transport', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$decoded = json_decode( $raw, true );
			$message = ( is_array( $decoded ) && ! empty( $decoded['message'] ) )
				? (string) $decoded['message']
				: 'Public agent API error (HTTP ' . $code . ').';

			// Internal, log-facing message — not browser output.
			throw new NPA_Gateway_Exception( $message, NPA_Gateway_Client_Http::code_for_status( $code ), $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$reply = self::collect_stream_text( $raw );

		if ( '' === $reply ) {
			// A 2xx with nothing usable in it is still a failed exchange; saying
			// "the agent replied with nothing" would be worse than an error.
			throw new NPA_Gateway_Exception( 'The API returned no reply text.', 'server_error', 502 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return new NPA_Gateway_Result(
			$reply,
			$conversation_id,
			'stop',
			0,
			0,
			array( 'stream_bytes' => strlen( $raw ) )
		);
	}

	/**
	 * Accumulate the reply text from a Server-Sent Events body.
	 *
	 * Frames are separated by a blank line; each carries one or more `data:`
	 * lines holding JSON. Text arrives as `{"Event":"TextDelta","Data":{"Text":"…"}}`
	 * — the casing varies, so both `Text` and `text` are accepted, matching what
	 * the platform's own client does. Anything unrecognised is skipped rather
	 * than treated as an error: an unknown event type is not a failure.
	 *
	 * Public and static so the test battery can exercise it on fixtures without
	 * any HTTP at all.
	 *
	 * @param string $raw Full response body.
	 * @return string Accumulated reply text.
	 */
	public static function collect_stream_text( $raw ) {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return '';
		}

		// Normalize line endings so CRLF streams split the same as LF ones.
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$frames     = preg_split( '/\n{2,}/', $normalized );
		$text       = '';

		foreach ( (array) $frames as $frame ) {
			$frame = trim( $frame );
			if ( '' === $frame ) {
				continue;
			}

			$payload = '';
			foreach ( explode( "\n", $frame ) as $line ) {
				$line = ltrim( $line );
				if ( 0 === strpos( $line, 'data:' ) ) {
					// A frame may carry several data: lines; they concatenate.
					$payload .= ltrim( substr( $line, 5 ) );
				}
			}

			if ( '' === $payload || '[DONE]' === $payload ) {
				continue;
			}

			$decoded = json_decode( $payload, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$event = isset( $decoded['Event'] ) ? (string) $decoded['Event'] : ( isset( $decoded['event'] ) ? (string) $decoded['event'] : '' );
			if ( 'TextDelta' !== $event ) {
				continue;
			}

			$data = isset( $decoded['Data'] ) ? $decoded['Data'] : ( isset( $decoded['data'] ) ? $decoded['data'] : null );
			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( isset( $data['Text'] ) && is_string( $data['Text'] ) ) {
				$text .= $data['Text'];
			} elseif ( isset( $data['text'] ) && is_string( $data['text'] ) ) {
				$text .= $data['text'];
			}
		}

		return trim( $text );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The key resolves to exactly one agent, so this returns that agent alone.
	 *
	 * @return NPA_Gateway_Agent[]
	 */
	public function list_agents(): array {
		$info = $this->agent_info();

		if ( null === $info ) {
			return array();
		}

		$id = '';
		foreach ( array( 'id', 'agentId', 'AgentId', 'agent_id' ) as $key ) {
			if ( ! empty( $info[ $key ] ) ) {
				$id = (string) $info[ $key ];
				break;
			}
		}

		$name = '';
		foreach ( array( 'name', 'agentName', 'AgentName', 'title' ) as $key ) {
			if ( ! empty( $info[ $key ] ) ) {
				$name = (string) $info[ $key ];
				break;
			}
		}

		if ( '' === $id && '' === $name ) {
			return array();
		}

		return array(
			new NPA_Gateway_Agent(
				'' !== $id ? $id : 'public-agent',
				'' !== $name ? $name : __( 'Public agent', 'newtide-public-agent' ),
				isset( $info['description'] ) ? (string) $info['description'] : ''
			),
		);
	}

	/**
	 * GET /public/agent/info, decoded. Null on any failure — callers decide
	 * what an unknown agent means.
	 *
	 * @return array|null
	 */
	private function agent_info() {
		$response = wp_remote_get(
			$this->base_url . '/public/agent/info',
			array(
				'timeout' => $this->timeout,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) || ! isset( $decoded['data'] ) ) {
			return null;
		}

		return is_array( $decoded['data'] ) ? $decoded['data'] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Never throws — reports an unhealthy result on any failure. Unlike a
	 * server-side health endpoint this genuinely validates the credential AND
	 * the origin, because the API applies both to /public/agent/info.
	 *
	 * @return NPA_Gateway_Health
	 */
	public function health_check(): NPA_Gateway_Health {
		$start = microtime( true );

		$response = wp_remote_get(
			$this->base_url . '/public/agent/info',
			array(
				'timeout' => $this->timeout,
				'headers' => $this->headers(),
			)
		);

		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return new NPA_Gateway_Health( false, $response->get_error_message(), $latency );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$detail  = ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) ? (string) $decoded['message'] : '';

		if ( $code >= 200 && $code < 300 && is_array( $decoded ) && ! empty( $decoded['success'] ) ) {
			return new NPA_Gateway_Health( true, __( 'Connected to the public agent API.', 'newtide-public-agent' ), $latency );
		}

		if ( 401 === $code || 403 === $code ) {
			return new NPA_Gateway_Health(
				false,
				'' !== $detail
					? $detail
					: __( 'Key or origin rejected. Check that this site’s URL is in the key’s allowed origins.', 'newtide-public-agent' ),
				$latency
			);
		}

		/* translators: %d: HTTP status code. */
		return new NPA_Gateway_Health( false, '' !== $detail ? $detail : sprintf( __( 'Public agent API returned HTTP %d.', 'newtide-public-agent' ), $code ), $latency );
	}
}
