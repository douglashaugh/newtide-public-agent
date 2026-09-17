<?php
/**
 * MCP server — lets the agent read the site it is deployed on.
 *
 * A public agent without this is blind to its own site: it can answer from
 * whatever the platform trained or retrieved, but not from the pages the
 * visitor is actually looking at. So this is treated as part of deploying an
 * agent rather than an optional extra — enabled by default, with its key
 * provisioned automatically.
 *
 * Speaks JSON-RPC 2.0 over Streamable HTTP at
 *   POST /wp-json/npa/v1/mcp
 *
 * Three implementation notes worth knowing before changing anything here:
 *
 *  1. Plain application/json, never SSE. The spec permits it, and on shared
 *     hosting SSE fights FastCGI buffering, mod_deflate and output buffering.
 *     GET and DELETE answer 405, which is what a stateless MCP server does, so
 *     the observable behaviour matches the reference implementation.
 *
 *  2. Stateless by construction. PHP builds and discards everything per request,
 *     so there is no session to coordinate and no state to scale.
 *
 *  3. Tool failures are results, not protocol errors. "No page by that slug" is
 *     something the agent should read and act on; reserve JSON-RPC error codes
 *     for malformed calls.
 *
 * @package NewTide_Public_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSON-RPC transport and dispatch for the MCP tool surface.
 */
class NPA_MCP_Server {

	/**
	 * Route, within the plugin's existing REST namespace.
	 *
	 * @var string
	 */
	const ROUTE = '/mcp';

	/**
	 * Protocol version advertised when the client does not name one.
	 *
	 * When a client does name a version we echo it: every method implemented
	 * here has been stable across recent revisions, so refusing a neighbouring
	 * version would break clients for no benefit.
	 *
	 * @var string
	 */
	const DEFAULT_PROTOCOL_VERSION = '2025-06-18';

	const E_PARSE      = -32700;
	const E_INVALID    = -32600;
	const E_NO_METHOD  = -32601;
	const E_BAD_PARAMS = -32602;
	const E_INTERNAL   = -32603;

	/**
	 * Rolling per-day call counters, for Service Status.
	 *
	 * @var string
	 */
	const STATS_OPTION = 'npa_mcp_stats';

	/**
	 * Plugin container.
	 *
	 * @var NPA_Plugin
	 */
	private $plugin;

	/**
	 * Tool registry.
	 *
	 * @var NPA_MCP_Tools
	 */
	private $tools;

	/**
	 * Service key.
	 *
	 * @var NPA_MCP_Key
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param NPA_Plugin    $plugin Plugin container.
	 * @param NPA_MCP_Tools $tools  Tool registry.
	 * @param NPA_MCP_Key   $key    Service key.
	 */
	public function __construct( $plugin, NPA_MCP_Tools $tools, NPA_MCP_Key $key ) {
		$this->plugin = $plugin;
		$this->tools  = $tools;
		$this->key    = $key;
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'parse_guard' ), 10, 3 );
	}

	/**
	 * Register the endpoint.
	 *
	 * Both method sets go in ONE register_rest_route() call. Registering them
	 * separately makes WordPress advertise only the last set in the Allow
	 * header, so a client that checks what the endpoint accepts before posting
	 * sees no POST and never connects — invisible from the server side, because
	 * a direct POST still works.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			NPA_Rest::NS,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( $this, 'check_access' ),
				),
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( $this, 'method_not_allowed' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Answer non-POST verbs.
	 *
	 * @return WP_REST_Response
	 */
	public function method_not_allowed() {
		return new WP_REST_Response(
			array( 'error' => 'This MCP endpoint is stateless: only POST is served.' ),
			405
		);
	}

	/**
	 * Convert a malformed body into JSON-RPC -32700.
	 *
	 * WordPress parses an application/json body during dispatch and, on failure,
	 * returns its own 400 rest_invalid_json — which never reaches the callback
	 * and is not a JSON-RPC message, so the client sees a transport failure it
	 * cannot interpret. Catching it here keeps every response on this route a
	 * well-formed JSON-RPC object.
	 *
	 * @param mixed           $result  Short-circuit value, null to continue.
	 * @param WP_REST_Server  $server  Unused.
	 * @param WP_REST_Request $request Incoming request.
	 * @return mixed
	 */
	public function parse_guard( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( 'POST' !== $request->get_method() || $request->get_route() !== '/' . NPA_Rest::NS . self::ROUTE ) {
			return $result;
		}

		$body = trim( (string) $request->get_body() );

		if ( '' === $body ) {
			return $result; // Handled in the callback.
		}

		json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->rpc_error( null, self::E_PARSE, 'Parse error: body is not valid JSON.' );
		}

		return $result;
	}

	/**
	 * Gate the endpoint on the service key.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function check_access( WP_REST_Request $request ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error(
				'npa_mcp_disabled',
				__( 'The site content server is switched off.', 'newtide-public-agent' ),
				array( 'status' => 403 )
			);
		}

		$presented = $this->key->from_request( $request );

		if ( '' === $presented ) {
			return new WP_Error(
				'npa_mcp_missing_key',
				__( 'A key is required. Send it as an Authorization: Bearer header, an X-NPA-MCP-Key header, or an api_key query parameter.', 'newtide-public-agent' ),
				array( 'status' => 401 )
			);
		}

		if ( ! $this->key->verify( $presented ) ) {
			return new WP_Error(
				'npa_mcp_invalid_key',
				__( 'Invalid key.', 'newtide-public-agent' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Whether the server is switched on.
	 *
	 * Defaults to true. An agent that cannot read its own site is the failure
	 * this feature exists to prevent, so shipping it off by default would ship
	 * the problem.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->plugin->settings->get( 'mcp_enabled', true );
	}

	// ── Dispatch ────────────────────────────────────────────────────────────

	/**
	 * Handle one JSON-RPC message.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$message = json_decode( $request->get_body(), true );

		if ( null === $message && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->rpc_error( null, self::E_PARSE, 'Parse error: body is not valid JSON.' );
		}

		// Batching was removed from MCP; an array is a different protocol revision.
		if ( is_array( $message ) && array_key_exists( 0, $message ) ) {
			return $this->rpc_error( null, self::E_INVALID, 'Batch requests are not supported; send one JSON-RPC object.' );
		}

		if ( ! is_array( $message ) ) {
			return $this->rpc_error( null, self::E_INVALID, 'Invalid request: expected a JSON-RPC object.' );
		}

		$id     = isset( $message['id'] ) ? $message['id'] : null;
		$method = isset( $message['method'] ) ? (string) $message['method'] : '';
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		if ( '' === $method ) {
			return $this->rpc_error( $id, self::E_INVALID, 'Invalid request: no method.' );
		}

		// A notification carries no id and must not receive a response body.
		$is_notification = ! array_key_exists( 'id', $message ) || null === $id;

		try {
			switch ( $method ) {
				case 'initialize':
					$result = $this->initialize( $params );
					break;

				case 'ping':
					$result = new stdClass();
					break;

				case 'tools/list':
					$result = array( 'tools' => $this->tools->definitions() );
					break;

				case 'tools/call':
					$result = $this->call_tool( $params );
					break;

				default:
					if ( 0 === strpos( $method, 'notifications/' ) || $is_notification ) {
						return new WP_REST_Response( null, 202 );
					}

					return $this->rpc_error( $id, self::E_NO_METHOD, sprintf( 'Unknown method: %s', $method ) );
			}
		} catch ( InvalidArgumentException $e ) {
			return $this->rpc_error( $id, self::E_BAD_PARAMS, $e->getMessage() );
		} catch ( Throwable $e ) {
			$this->note_error( $method, $e->getMessage() );

			return $this->rpc_error( $id, self::E_INTERNAL, sprintf( 'Internal error handling %s.', $method ) );
		}

		if ( $is_notification ) {
			return new WP_REST_Response( null, 202 );
		}

		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Build the initialize result.
	 *
	 * `instructions` is the one place to orient the agent about how the tools
	 * relate, which is what stops it guessing a content type instead of calling
	 * describe_site first.
	 *
	 * @param array $params Client parameters.
	 * @return array
	 */
	private function initialize( array $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';

		$instructions = sprintf(
			'Read-only access to the published content of %1$s (%2$s). Call describe_site first: it '
			. 'reports which content types and taxonomies this site actually has, and their names vary '
			. 'from site to site. Then search_content to find material, get_content to read one piece in '
			. 'full, and list_taxonomy_terms to browse when a text search misses. Search matches words '
			. 'rather than meaning, so one empty result is not evidence the site lacks the subject. You '
			. 'see exactly what a logged-out visitor sees — never drafts or private content.',
			get_bloginfo( 'name' ),
			home_url( '/' )
		);

		/**
		 * Filter the orientation text sent to the client on initialize.
		 *
		 * @param string $instructions Default orientation.
		 */
		$instructions = (string) apply_filters( 'npa_mcp_instructions', $instructions );

		/**
		 * Filter the advertised server identity.
		 *
		 * @param array $info name/title/version.
		 */
		$info = (array) apply_filters(
			'npa_mcp_server_info',
			array(
				'name'    => 'newtide-public-agent',
				'title'   => sprintf(
					/* translators: %s: site name. */
					__( '%s — site content', 'newtide-public-agent' ),
					get_bloginfo( 'name' )
				),
				'version' => defined( 'NPA_VERSION' ) ? NPA_VERSION : '0',
			)
		);

		return array(
			'protocolVersion' => '' !== $requested ? $requested : self::DEFAULT_PROTOCOL_VERSION,
			'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
			'serverInfo'      => $info,
			'instructions'    => $instructions,
		);
	}

	/**
	 * Run one tool.
	 *
	 * @param array $params tools/call parameters.
	 * @return array
	 * @throws InvalidArgumentException When the call names no tool, or an unknown one.
	 */
	private function call_tool( array $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $name ) {
			throw new InvalidArgumentException( 'tools/call requires a tool name.' );
		}

		if ( ! $this->tools->exists( $name ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown tool: %s', $name ) );
		}

		try {
			$payload = $this->tools->call( $name, $args );

			$this->record_call( $name, true );

			return array(
				'content' => array(
					array( 'type' => 'text', 'text' => $this->encode( $payload ) ),
				),
			);
		} catch ( InvalidArgumentException $e ) {
			/*
			 * Bad arguments are the agent's problem to fix, so the message goes
			 * back as tool output it can read — not as a protocol error it
			 * cannot see.
			 */
			$this->record_call( $name, false );

			return array(
				'content' => array( array( 'type' => 'text', 'text' => $e->getMessage() ) ),
				'isError' => true,
			);
		} catch ( Throwable $e ) {
			$this->record_call( $name, false );
			$this->note_error( $name, $e->getMessage() );

			return array(
				'content' => array( array( 'type' => 'text', 'text' => 'The tool failed to run: ' . $e->getMessage() ) ),
				'isError' => true,
			);
		}
	}

	/**
	 * Encode a result for the agent.
	 *
	 * Not pretty-printed: indentation roughly doubles the payload, and every one
	 * of those bytes is a token the conversation pays for.
	 *
	 * @param mixed $payload Result to encode.
	 * @return string
	 */
	private function encode( $payload ) {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $json ? '{"error":"Result could not be encoded as JSON."}' : $json;
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * Deliberately HTTP 200: a JSON-RPC error is a well-formed reply, and
	 * returning a 4xx makes clients treat it as a transport failure.
	 *
	 * @param mixed  $id      Request id, or null.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Human-readable message.
	 * @return WP_REST_Response
	 */
	private function rpc_error( $id, $code, $message ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array( 'code' => $code, 'message' => $message ),
			),
			200
		);
	}

	// ── Telemetry ───────────────────────────────────────────────────────────

	/**
	 * Record a tool call for the Service Status card.
	 *
	 * @param string $tool Tool name.
	 * @param bool   $ok   Whether it succeeded.
	 * @return void
	 */
	private function record_call( $tool, $ok ) {
		$stats = get_option( self::STATS_OPTION, array() );

		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		$today = gmdate( 'Y-m-d' );

		if ( ! isset( $stats['day'] ) || $stats['day'] !== $today ) {
			$stats = array( 'day' => $today, 'tools' => array() );
		}

		$row = isset( $stats['tools'][ $tool ] ) ? $stats['tools'][ $tool ] : array( 'ok' => 0, 'err' => 0 );

		$row[ $ok ? 'ok' : 'err' ]++;

		$stats['tools'][ $tool ] = $row;
		$stats['last']           = time();
		$stats['last_tool']      = $tool;

		update_option( self::STATS_OPTION, $stats, false );
	}

	/**
	 * Record an internal failure in the plugin log.
	 *
	 * @param string $where   Method or tool name.
	 * @param string $message Error message.
	 * @return void
	 */
	private function note_error( $where, $message ) {
		if ( ! isset( $this->plugin->logger ) ) {
			return;
		}

		$this->plugin->logger->log(
			array(
				'error_code' => 'mcp_error',
				'note'       => $where . ': ' . $message,
			)
		);
	}

	/**
	 * Today's call counters.
	 *
	 * @return array
	 */
	public static function stats() {
		$stats = get_option( self::STATS_OPTION, array() );

		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Public endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( NPA_Rest::NS . self::ROUTE );
	}

	/**
	 * Endpoint URL with the key embedded, for platforms whose connector config
	 * accepts a URL and nothing else.
	 *
	 * @param string $key Service key.
	 * @return string
	 */
	public static function connector_url( $key ) {
		return add_query_arg( 'api_key', rawurlencode( $key ), self::endpoint_url() );
	}

	// ── Service Status ──────────────────────────────────────────────────────

	/**
	 * Status report for the Service Status tab.
	 *
	 * Framed around the agent rather than the subsystem: an admin does not need
	 * to know what MCP is, they need to know whether their agent can read the
	 * site it is answering for.
	 *
	 * @return array
	 */
	public function status() {
		if ( ! $this->is_enabled() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Off — the agent cannot read this site\'s pages, so it answers without them.', 'newtide-public-agent' ),
			);
		}

		$stats = self::stats();
		$ok    = 0;
		$err   = 0;

		foreach ( isset( $stats['tools'] ) ? $stats['tools'] : array() as $row ) {
			$ok  += isset( $row['ok'] ) ? (int) $row['ok'] : 0;
			$err += isset( $row['err'] ) ? (int) $row['err'] : 0;
		}

		$tool_count = count( $this->tools->names() );

		if ( 0 === $ok + $err ) {
			return array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %d: number of tools. */
					__( 'Ready — %d tools available. No calls yet; the agent will use them once its connector is configured.', 'newtide-public-agent' ),
					$tool_count
				),
			);
		}

		/*
		 * Errors outnumbering successes is worth flagging: the usual cause is an
		 * agent guessing content types instead of calling describe_site, which
		 * is a prompt problem the admin can actually fix.
		 */
		return array(
			'ok'      => $err < $ok,
			'message' => sprintf(
				/* translators: 1: successful calls, 2: failed calls, 3: tool count, 4: human time since last call. */
				__( '%1$d calls answered, %2$d failed today across %3$d tools. Last call %4$s ago.', 'newtide-public-agent' ),
				$ok,
				$err,
				$tool_count,
				isset( $stats['last'] ) ? human_time_diff( (int) $stats['last'], time() ) : '—'
			),
		);
	}
}
