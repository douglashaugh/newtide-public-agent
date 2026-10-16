<?php
/**
 * Client for the NewTide Agent API — the OpenAI-compatible endpoint.
 *
 * `POST {base}/v1/chat/completions`, authenticated with a `wbk_` key as a
 * bearer token. The `model` field is ignored: the key selects the agent, same
 * as every other transport here.
 *
 * This is the first documented contract the plugin has had. The two clients
 * beside it were written against a provisional spec that turned out not to
 * exist, and against an API recovered by reading a minified bundle.
 *
 * It also threads conversations natively. `messages` is an array of role/content
 * pairs, so prior turns are structurally separate rather than quoted into one
 * string — which retires the prompt-composition workaround NPA_Conversation
 * needs for the single-turn transports, and the injection surface that came with
 * it.
 *
 * **Origin is required and is not documented.** The published curl examples send
 * only the bearer token and return 401. The key is checked against an
 * allowed-origins list exactly as a publishable key is, matched exactly — a
 * `www.` variant of a permitted host is refused. Measured 2026-09-15; see
 * docs/GATEWAY-CONTRACT.md.
 *
 * **The key is still a secret.** Origin scoping stops a browser on another site
 * using it; it stops nothing for anyone holding the key, since a server sets the
 * header itself. Keep it in wp-config.php and never in page output.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Gateway_Client_Agent_Api
 */
class NPA_Gateway_Client_Agent_Api implements NPA_Gateway_Client, NPA_Gateway_Streaming {

	/**
	 * Default production base URL.
	 *
	 * @var string
	 */
	const DEFAULT_BASE_URL = 'https://myagents-api.newtide.ai';

	/**
	 * The UAT endpoint. Named because a key issued in one environment is
	 * rejected by the other with the same 401 as a bad key, and the two
	 * addresses differ by four characters.
	 *
	 * @var string
	 */
	const UAT_BASE_URL = 'https://myagents-uat-api.newtide.ai';

	/**
	 * The endpoints a key might belong to, production first.
	 *
	 * @return string[]
	 */
	public static function known_base_urls() {
		return array( self::DEFAULT_BASE_URL, self::UAT_BASE_URL );
	}

	/**
	 * API base URL, no trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * The `wbk_` key, sent as a bearer token.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Origin announced for the allowed-origins check.
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
	 * Constructor.
	 *
	 * @param string      $base_url API base URL.
	 * @param string      $key      The wbk_ key.
	 * @param string|null $origin   Origin to announce; defaults to this site's.
	 * @param int|null    $timeout  Seconds; defaults to NPA_HTTP_TIMEOUT or 30.
	 */
	public function __construct( $base_url, $key, $origin = null, $timeout = null ) {
		$this->base_url = untrailingslashit( '' !== trim( (string) $base_url ) ? (string) $base_url : self::DEFAULT_BASE_URL );
		$this->key      = trim( (string) $key );
		$this->origin   = null !== $origin ? (string) $origin : NPA_Gateway_Client_Public::site_origin();
		$this->timeout  = null !== $timeout ? (int) $timeout : ( defined( 'NPA_HTTP_TIMEOUT' ) ? (int) NPA_HTTP_TIMEOUT : 30 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Yes — `messages` carries the whole exchange.
	 *
	 * @return bool
	 */
	public function supports_history(): bool {
		return true;
	}

	/**
	 * Request headers.
	 *
	 * @return array
	 */
	private function headers() {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		// Required, though the published examples omit it. Without it every
		// call is 401 regardless of the key.
		if ( '' !== $this->origin ) {
			$headers['Origin'] = $this->origin;
		}

		/**
		 * Filter the Agent API request headers.
		 *
		 * @param array $headers Default headers.
		 */
		return apply_filters( 'npa_agent_api_headers', $headers );
	}

	/**
	 * Build the `messages` array: prior turns, then the new message.
	 *
	 * Each turn becomes its own user/assistant pair. This is the whole reason
	 * for preferring this transport — a model that is given a conversation
	 * rather than a transcript pasted into one prompt.
	 *
	 * @param string $message The new visitor message.
	 * @param array  $history Prior exchanges, oldest first, each { visitor, agent }.
	 * @return array
	 */
	public static function build_messages( $message, array $history ) {
		$messages = array();

		foreach ( $history as $turn ) {
			$visitor = isset( $turn['visitor'] ) ? trim( (string) $turn['visitor'] ) : '';
			$agent   = isset( $turn['agent'] ) ? trim( (string) $turn['agent'] ) : '';

			// Both halves or neither: a dangling user turn with no reply would
			// present the previous question as if it were still unanswered.
			if ( '' === $visitor || '' === $agent ) {
				continue;
			}

			$messages[] = array(
				'role'    => 'user',
				'content' => $visitor,
			);
			$messages[] = array(
				'role'    => 'assistant',
				'content' => $agent,
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => (string) $message,
		);

		return $messages;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $agent_id        Ignored — the key selects the agent.
	 * @param string $message         User message.
	 * @param string $conversation_id Echoed back; this API is stateless per call.
	 * @param array  $context         Unused by this API.
	 * @param array  $history         Prior exchanges, sent as real turns.
	 * @return NPA_Gateway_Result
	 * @throws NPA_Gateway_Exception On transport or API error.
	 */
	public function send_message( string $agent_id, string $message, string $conversation_id, array $context, array $history = array() ): NPA_Gateway_Result {
		$body = array( 'messages' => self::build_messages( $message, $history ) );

		/**
		 * Filter the chat-completions request body.
		 *
		 * The hook to add a system message with `Accept instructions` enabled on
		 * the agent, or tools with `Allow caller-provided tools`.
		 *
		 * @param array  $body     Request body.
		 * @param string $agent_id Agent id, if the caller tracks one.
		 */
		$body = apply_filters( 'npa_agent_api_body', $body, $agent_id );

		$response = wp_remote_post(
			$this->base_url . '/v1/chat/completions',
			array(
				'timeout' => $this->timeout,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Internal, log-facing message — not browser output.
			throw new NPA_Gateway_Exception( $response->get_error_message(), 'transport', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return self::result_from_body(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			$conversation_id
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function supports_streaming(): bool {
		return true;
	}

	/**
	 * Longest a streamed reply may take in all, seconds.
	 *
	 * While streaming, the agent timeout is how long the agent may go silent,
	 * not how long the whole answer may take — a reply that keeps arriving is
	 * not stuck. This caps the total.
	 *
	 * @param int $timeout The agent timeout, seconds.
	 * @return int
	 */
	public static function stream_limit( $timeout ) {
		return max( 120, 3 * (int) $timeout );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Still a wp_remote_post(), so every filter and the diagnostics hooks see it
	 * as they do any other call. The pieces are read off WordPress's
	 * `requests-request.progress` action as they arrive, and `http_api_curl`
	 * adjusts the cURL handle: an idle limit in place of a total one, and a
	 * progress callback that fires about once a second while nothing arrives.
	 *
	 * A transport that buffers the whole body (no progress events, or a host
	 * that is not cURL) still works: the body is read once it is complete, as a
	 * stream if it is one and as an ordinary completion if the API chose not to
	 * stream.
	 *
	 * @param string        $agent_id        Ignored — the key selects the agent.
	 * @param string        $message         User message.
	 * @param string        $conversation_id Echoed back.
	 * @param array         $context         Unused by this API.
	 * @param array         $history         Prior exchanges, sent as real turns.
	 * @param callable      $on_text         Called with each new piece of reply text.
	 * @param callable|null $on_tick         Called while waiting.
	 * @return NPA_Gateway_Result
	 * @throws NPA_Gateway_Exception On transport or API error.
	 */
	public function stream_message( string $agent_id, string $message, string $conversation_id, array $context, array $history, callable $on_text, ?callable $on_tick = null ): NPA_Gateway_Result {
		unset( $context );

		$body = array(
			'messages' => self::build_messages( $message, $history ),
			'stream'   => true,
		);

		/** This filter is documented in send_message(). */
		$body = apply_filters( 'npa_agent_api_body', $body, $agent_id );

		$url    = $this->base_url . '/v1/chat/completions';
		$reader = new NPA_Sse_Reader( $on_text );
		$idle   = max( 1, $this->timeout );

		$progress = static function ( $data ) use ( $reader ) {
			$reader->feed( (string) $data );
		};

		$curl = static function ( $handle, $args = array(), $target = '' ) use ( $url, $idle, $on_tick ) {
			unset( $args );

			if ( $target !== $url ) {
				return;
			}

			// Give up only when the agent has said nothing for $idle seconds.
			curl_setopt( $handle, CURLOPT_LOW_SPEED_LIMIT, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_LOW_SPEED_TIME, $idle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt

			if ( null !== $on_tick && defined( 'CURLOPT_XFERINFOFUNCTION' ) ) {
				curl_setopt( $handle, CURLOPT_NOPROGRESS, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				curl_setopt( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
					$handle,
					CURLOPT_XFERINFOFUNCTION,
					static function () use ( $on_tick ) {
						call_user_func( $on_tick );
						return 0;
					}
				);
			}
		};

		$headers             = $this->headers();
		$headers['Accept']   = 'text/event-stream';
		$headers['X-Stream'] = 'true';

		add_action( 'requests-request.progress', $progress, 10, 1 );
		add_action( 'http_api_curl', $curl, 10, 3 );

		try {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => self::stream_limit( $this->timeout ),
					'headers' => $headers,
					'body'    => wp_json_encode( $body ),
				)
			);
		} finally {
			remove_action( 'requests-request.progress', $progress, 10 );
			remove_action( 'http_api_curl', $curl, 10 );
		}

		if ( is_wp_error( $response ) ) {
			// Internal, log-facing message — not browser output.
			throw new NPA_Gateway_Exception( $response->get_error_message(), 'transport', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			throw new NPA_Gateway_Exception( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				self::error_text( json_decode( $raw, true ), $code ),
				NPA_Gateway_Client_Http::code_for_status( $code ),
				$code
			);
		}

		// Nothing came through the progress hook: read the body whole.
		if ( 0 === $reader->frames && '' === $reader->text ) {
			$reader->feed( $raw );
		}
		$reader->finish();

		if ( '' !== $reader->error ) {
			throw new NPA_Gateway_Exception( $reader->error, 'server_error', 502 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Not a stream at all: the API answered with an ordinary completion.
		if ( 0 === $reader->frames ) {
			$result = self::result_from_body( $code, $raw, $conversation_id );
			call_user_func( $on_text, $result->reply_text );
			return $result;
		}

		if ( '' === trim( $reader->text ) ) {
			throw new NPA_Gateway_Exception( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				'tool_calls' === $reader->finish
					? 'The agent asked to call a tool, which this plugin does not provide. Turn off caller-provided tools for this agent.'
					: 'The stream ended without any reply text.',
				'server_error',
				502
			);
		}

		return new NPA_Gateway_Result(
			$reader->text,
			$conversation_id,
			'' !== $reader->finish ? $reader->finish : 'stop',
			isset( $reader->usage['prompt_tokens'] ) ? (int) $reader->usage['prompt_tokens'] : 0,
			isset( $reader->usage['completion_tokens'] ) ? (int) $reader->usage['completion_tokens'] : 0,
			array(
				'streamed' => true,
				'frames'   => $reader->frames,
				'done'     => $reader->done,
			)
		);
	}

	/**
	 * A result from a complete, non-streamed response body.
	 *
	 * @param int    $code            HTTP status.
	 * @param string $raw             Body.
	 * @param string $conversation_id Echoed back.
	 * @return NPA_Gateway_Result
	 * @throws NPA_Gateway_Exception On an error status or an empty reply.
	 */
	private static function result_from_body( $code, $raw, $conversation_id ) {
		$decoded = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			throw new NPA_Gateway_Exception( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				self::error_text( $decoded, $code ),
				NPA_Gateway_Client_Http::code_for_status( $code ),
				$code
			);
		}

		$choice = ( is_array( $decoded ) && ! empty( $decoded['choices'][0] ) ) ? $decoded['choices'][0] : array();
		$reply  = isset( $choice['message']['content'] ) ? (string) $choice['message']['content'] : '';

		if ( '' === trim( $reply ) ) {
			$finish = isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : '';

			throw new NPA_Gateway_Exception( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				'tool_calls' === $finish
					? 'The agent asked to call a tool, which this plugin does not provide. Turn off caller-provided tools for this agent.'
					: 'The API returned no reply text. Response began: ' . trim( preg_replace( '/\s+/', ' ', substr( $raw, 0, 300 ) ) ),
				'server_error',
				502
			);
		}

		return new NPA_Gateway_Result(
			$reply,
			$conversation_id,
			isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : 'stop',
			isset( $decoded['usage']['prompt_tokens'] ) ? (int) $decoded['usage']['prompt_tokens'] : 0,
			isset( $decoded['usage']['completion_tokens'] ) ? (int) $decoded['usage']['completion_tokens'] : 0,
			is_array( $decoded ) ? $decoded : array()
		);
	}

	/**
	 * A readable message from an error response.
	 *
	 * The envelope is terse — `{"error":"Unauthorized"}` — and `error` may be a
	 * string or an object, so handle both rather than printing "Array".
	 *
	 * @param mixed $decoded Decoded body.
	 * @param int   $code    HTTP status.
	 * @return string
	 */
	private static function error_text( $decoded, $code ) {
		if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
			if ( is_string( $decoded['error'] ) ) {
				return $decoded['error'];
			}
			if ( is_array( $decoded['error'] ) && ! empty( $decoded['error']['message'] ) ) {
				return (string) $decoded['error']['message'];
			}
		}

		return 'Agent API error (HTTP ' . $code . ').';
	}

	/**
	 * The spellings of one host, in the order worth trying.
	 *
	 * The allow-list is matched exactly, so scheme and www are two axes that
	 * differ invisibly: https://example.com, http://example.com and the two www
	 * forms are four distinct entries, and a key normally holds one. https and
	 * the form the site already uses come first, being the likely answers.
	 *
	 * Derived from one host, so this only ever produces spellings of an address
	 * the caller already has.
	 *
	 * @param string $url A site URL, e.g. home_url().
	 * @return string[] Candidate origins, most likely first.
	 */
	public static function origin_candidates( $url ) {
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );

		if ( '' === $host ) {
			return array();
		}

		$bare = preg_replace( '/^www\./i', '', $host );
		$hosts = ( 0 === strcasecmp( $host, $bare ) ) ? array( $bare, 'www.' . $bare ) : array( 'www.' . $bare, $bare );

		$out = array();
		foreach ( array( 'https', 'http' ) as $scheme ) {
			foreach ( $hosts as $h ) {
				$out[] = $scheme . '://' . $h;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * No list endpoint: one key, one agent.
	 *
	 * @return NPA_Gateway_Agent[]
	 */
	public function list_agents(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * The only check available is a real (tiny) completion — there is no health
	 * route — so this costs one call, and proves the three things that actually
	 * fail: the key, the origin and reachability.
	 *
	 * @return NPA_Gateway_Health
	 */
	public function health_check(): NPA_Gateway_Health {
		$start = microtime( true );

		try {
			$this->send_message( '', 'Reply with just: OK', '', array() );
			$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

			return new NPA_Gateway_Health( true, __( 'Connected to the Agent API.', 'newtide-public-agent' ), $latency );
		} catch ( NPA_Gateway_Exception $e ) {
			$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( 'unauthorized' === $e->get_error_code() ) {
				return new NPA_Gateway_Health(
					false,
					sprintf(
						/* translators: 1: the origin this site announces, 2: the API address in use. */
						__( 'Rejected. Three things produce this: the key is wrong, this site’s address is not on the key’s allowed origins, or the key belongs to a different environment. It announces %1$s to %2$s. The origin match is exact, so a www or http variant counts as a different address.', 'newtide-public-agent' ),
						$this->origin,
						$this->base_url
					),
					$latency
				);
			}

			return new NPA_Gateway_Health( false, $e->getMessage(), $latency );
		}
	}
}
