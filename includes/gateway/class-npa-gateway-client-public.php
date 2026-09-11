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
	 * Value sent as the `Origin` header — the platform origin, mirroring the
	 * iframe the browser would make this call from.
	 *
	 * @var string
	 */
	private $platform_origin;

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
	 * Reverse of api_base_from_platform(): the platform origin an API base came
	 * from, used as the `Origin` header.
	 *
	 * Strips the `-api` suffix from the host's first label
	 * (`uat-ai-api.newtide.ai` -> `https://uat-ai.newtide.ai`). When the host does
	 * not follow that convention — a site pointed at some other base URL — falls
	 * back to the API's own origin, which still satisfies "Origin is required"
	 * without inventing a hostname.
	 *
	 * @param string $api_base API base URL.
	 * @return string
	 */
	public static function platform_origin_from_api_base( $api_base ) {
		$parts = wp_parse_url( (string) $api_base );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'];

		$dot = strpos( $host, '.' );
		if ( false !== $dot ) {
			$first = substr( $host, 0, $dot );
			if ( '-api' === substr( $first, -4 ) ) {
				$host = substr( $first, 0, -4 ) . substr( $host, $dot );
			}
		}

		/**
		 * Filter the value sent as the Origin header.
		 *
		 * @param string $origin   Derived platform origin.
		 * @param string $api_base The API base URL it came from.
		 */
		return apply_filters( 'npa_public_api_request_origin', $scheme . '://' . $host, $api_base );
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
	 * @param string      $base_url        API base URL.
	 * @param string      $key             Publishable key.
	 * @param string|null $origin          Site origin for X-Embed-Origin; defaults to this site's.
	 * @param int|null    $timeout         Seconds; defaults to NPA_HTTP_TIMEOUT or 30.
	 * @param string|null $platform_origin Value for the Origin header; derived from $base_url by default.
	 */
	public function __construct( $base_url, $key, $origin = null, $timeout = null, $platform_origin = null ) {
		$this->base_url        = untrailingslashit( (string) $base_url );
		$this->key             = (string) $key;
		$this->origin          = null !== $origin ? (string) $origin : self::site_origin();
		$this->platform_origin = null !== $platform_origin ? (string) $platform_origin : self::platform_origin_from_api_base( $this->base_url );
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
	private function headers( $json = false, $origin = null ) {
		$headers = array(
			'X-Api-Key' => $this->key,
			'Accept'    => 'text/event-stream, application/json',
		);

		/*
		 * Two origin headers, and which one the API measures against a key's
		 * allowed-origins list is not documented. `X-Embed-Origin` always carries
		 * this site, because that is what the browser client puts there. `Origin`
		 * is negotiated — see origin_candidates().
		 */
		$origin = ( null === $origin ) ? $this->platform_origin : (string) $origin;

		if ( '' !== $origin ) {
			$headers['Origin'] = $origin;
		}

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
	 * Transient key remembering which Origin value this key accepts.
	 *
	 * @var string
	 */
	const ORIGIN_PREF = 'npa_api_origin_';

	/**
	 * Origin values to try, best first.
	 *
	 * The API rejects a call whose Origin is not permitted for the key, but does
	 * not say which of the two origin headers it measured. Both readings are
	 * defensible and both occur in practice: a key whose allowed-origins list
	 * holds only the customer's site needs Origin to BE that site, while a key
	 * listing a platform host needs the platform value the browser would send.
	 * Guessing wrong produces "Origin not permitted for this API key" with no
	 * indication of what to change, so try the site first, fall back to the
	 * platform, and remember which one worked.
	 *
	 * @return string[]
	 */
	private function origin_candidates() {
		$preferred = get_transient( self::ORIGIN_PREF . md5( $this->key ) );

		$order = ( 'platform' === $preferred )
			? array( $this->platform_origin, $this->origin )
			: array( $this->origin, $this->platform_origin );

		return array_values( array_unique( array_filter( $order ) ) );
	}

	/**
	 * Whether a response is the API refusing the Origin specifically, as opposed
	 * to refusing the key. Only the former is worth retrying.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Message from the API envelope.
	 * @return bool
	 */
	private function is_origin_rejection( $code, $message ) {
		return ( 401 === $code || 403 === $code ) && false !== stripos( (string) $message, 'origin' );
	}

	/**
	 * Make a request, trying each Origin candidate until one is not refused on
	 * origin grounds. Remembers the winner so later calls go straight there.
	 *
	 * @param string     $path Endpoint path.
	 * @param array|null $body JSON body for a POST, or null for a GET.
	 * @return array { error:WP_Error|null, code:int, body:string, api_message:string, origin:string, origin_rejected:bool }
	 */
	private function dispatch( $path, $body = null ) {
		$url        = $this->base_url . $path;
		$candidates = $this->origin_candidates();
		$last       = null;

		foreach ( $candidates as $origin ) {
			$args = array(
				'timeout' => $this->timeout,
				'headers' => $this->headers( null !== $body, $origin ),
			);

			if ( null !== $body ) {
				$args['body'] = wp_json_encode( $body );
				$response     = wp_remote_post( $url, $args );
			} else {
				$response = wp_remote_get( $url, $args );
			}

			if ( is_wp_error( $response ) ) {
				return array(
					'error'           => $response,
					'code'            => 0,
					'body'            => '',
					'api_message'     => $response->get_error_message(),
					'origin'          => $origin,
					'origin_rejected' => false,
				);
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$raw     = (string) wp_remote_retrieve_body( $response );
			$decoded = json_decode( $raw, true );
			$message = ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) ? (string) $decoded['message'] : '';

			$rejected = $this->is_origin_rejection( $code, $message );

			$last = array(
				'error'           => null,
				'code'            => $code,
				'body'            => $raw,
				'api_message'     => $message,
				'origin'          => $origin,
				'origin_rejected' => $rejected,
			);

			if ( ! $rejected ) {
				// Remember what worked; a wrong guess costs an extra round trip
				// on every call otherwise.
				set_transient(
					self::ORIGIN_PREF . md5( $this->key ),
					( $origin === $this->platform_origin ) ? 'platform' : 'site',
					DAY_IN_SECONDS
				);
				return $last;
			}
		}

		return $last;
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

		$attempt = $this->dispatch( '/public/chat/stream', $body );

		if ( null !== $attempt['error'] ) {
			// Internal, log-facing message — not browser output.
			throw new NPA_Gateway_Exception( $attempt['api_message'], 'transport', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$code = $attempt['code'];
		$raw  = $attempt['body'];

		if ( $code < 200 || $code >= 300 ) {
			$message = '' !== $attempt['api_message']
				? $attempt['api_message']
				: 'Public agent API error (HTTP ' . $code . ').';

			// Internal, log-facing message — not browser output.
			throw new NPA_Gateway_Exception( $message, NPA_Gateway_Client_Http::code_for_status( $code ), $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$reply = self::collect_stream_text( $raw );

		if ( '' === $reply ) {
			/*
			 * An agent-side failure arrives as an error frame inside a 200, so
			 * look for one before concluding the stream was merely empty. This is
			 * the difference between telling an admin "Internal error." and
			 * telling them "no reply text", which describes our parser rather
			 * than their problem.
			 */
			$upstream = self::collect_stream_error( $raw );

			if ( '' !== $upstream ) {
				throw new NPA_Gateway_Exception( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					'The agent returned an error: ' . $upstream,
					'server_error',
					502
				);
			}

			// No text and no error frame: quote the body so an unfamiliar shape
			// is visible rather than guessed at.
			$snippet = trim( preg_replace( '/\s+/', ' ', substr( $raw, 0, 300 ) ) );

			throw new NPA_Gateway_Exception( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				'' !== $snippet
					? 'The API returned no reply text. Response began: ' . $snippet
					: 'The API returned an empty response body.',
				'server_error',
				502
			);
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
	 * Send an arbitrary body to the chat endpoint and report what came back.
	 *
	 * Exists for the conversation probe: the API is undocumented, and the only
	 * way to learn whether it accepts more than `{message}` is to offer it more
	 * and see. Never throws — the probe wants to report a failure, not handle an
	 * exception — and is never used on a visitor path.
	 *
	 * @param array $body Request body to send verbatim.
	 * @return array { ok:bool, code:int, text:string, note:string }
	 */
	public function probe_chat( array $body ) {
		$attempt = $this->dispatch( '/public/chat/stream', $body );

		if ( null !== $attempt['error'] ) {
			return array(
				'ok'   => false,
				'code' => 0,
				'text' => '',
				'note' => $attempt['api_message'],
			);
		}

		$code = $attempt['code'];
		$note = ( $code < 200 || $code >= 300 )
			? ( '' !== $attempt['api_message'] ? $attempt['api_message'] : 'HTTP ' . $code )
			: '';

		return array(
			'ok'   => ( $code >= 200 && $code < 300 ),
			'code' => $code,
			'text' => self::collect_stream_text( $attempt['body'] ),
			'note' => $note,
		);
	}

	/**
	 * Pull an upstream failure out of a Server-Sent Events body.
	 *
	 * The API reports agent-side failures *inside* a 200 response — an
	 * `event: error` frame carrying `{"error":"…"}` — so the HTTP status says
	 * nothing and a parser looking only for text sees an empty stream. Reporting
	 * that as "no reply text" hid the real message ("Internal error.") behind a
	 * symptom, which cost a round of diagnosis.
	 *
	 * @param string $raw Full response body.
	 * @return string Error message, or '' when the stream carries none.
	 */
	public static function collect_stream_error( $raw ) {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return '';
		}

		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $raw );

		foreach ( preg_split( '/\n{2,}/', $normalized ) as $frame ) {
			$frame = trim( $frame );
			if ( '' === $frame ) {
				continue;
			}

			$event   = '';
			$payload = '';

			foreach ( explode( "\n", $frame ) as $line ) {
				$line = ltrim( $line );
				if ( 0 === strpos( $line, 'event:' ) ) {
					$event = trim( substr( $line, 6 ) );
				} elseif ( 0 === strpos( $line, 'data:' ) ) {
					$payload .= ltrim( substr( $line, 5 ) );
				}
			}

			if ( '' === $payload ) {
				continue;
			}

			$decoded = json_decode( $payload, true );

			// Either the frame is named as an error, or its payload carries one.
			$named   = ( 'error' === strtolower( $event ) );
			$carried = is_array( $decoded ) && ( isset( $decoded['error'] ) || isset( $decoded['Error'] ) );

			if ( ! $named && ! $carried ) {
				continue;
			}

			if ( is_array( $decoded ) ) {
				foreach ( array( 'error', 'Error', 'message', 'Message' ) as $field ) {
					if ( ! empty( $decoded[ $field ] ) && is_string( $decoded[ $field ] ) ) {
						return $decoded[ $field ];
					}
				}
			}

			// Named as an error but shaped unfamiliarly — better the raw payload
			// than nothing.
			if ( $named ) {
				return trim( preg_replace( '/\s+/', ' ', substr( $payload, 0, 200 ) ) );
			}
		}

		return '';
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
		$attempt = $this->dispatch( '/public/agent/info' );

		if ( null !== $attempt['error'] ) {
			return null;
		}

		$decoded = json_decode( $attempt['body'], true );

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

		$attempt = $this->dispatch( '/public/agent/info' );

		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( null !== $attempt['error'] ) {
			return new NPA_Gateway_Health( false, $attempt['api_message'], $latency );
		}

		$code    = $attempt['code'];
		$decoded = json_decode( $attempt['body'], true );
		$detail  = $attempt['api_message'];

		if ( $code >= 200 && $code < 300 && is_array( $decoded ) && ! empty( $decoded['success'] ) ) {
			return new NPA_Gateway_Health( true, __( 'Connected to the public agent API.', 'newtide-public-agent' ), $latency );
		}

		/*
		 * An origin refusal is the one failure a site owner can fix themselves,
		 * and the API does not say which value it objected to — so name both, and
		 * the fact that every candidate was tried. Without this the message is
		 * "Origin not permitted" with nothing to act on.
		 */
		if ( $attempt['origin_rejected'] ) {
			return new NPA_Gateway_Health(
				false,
				sprintf(
					/* translators: 1: the API's message, 2: site origin, 3: platform origin. */
					__( '%1$s This site tried both %2$s and %3$s as the Origin, and neither is permitted for this key. Add %2$s to the key’s allowed origins in RisingTide — exactly that, with no trailing slash and no path.', 'newtide-public-agent' ),
					'' !== $detail ? $detail : __( 'Origin not permitted for this API key.', 'newtide-public-agent' ),
					$this->origin,
					$this->platform_origin
				),
				$latency
			);
		}

		if ( 401 === $code || 403 === $code ) {
			return new NPA_Gateway_Health(
				false,
				'' !== $detail
					? $detail
					: __( 'Key rejected. Check the key was created on the same platform this site points at.', 'newtide-public-agent' ),
				$latency
			);
		}

		/* translators: %d: HTTP status code. */
		return new NPA_Gateway_Health( false, '' !== $detail ? $detail : sprintf( __( 'Public agent API returned HTTP %d.', 'newtide-public-agent' ), $code ), $latency );
	}
}
