<?php
/**
 * Same-origin REST proxy: the browser widget POSTs here, PHP relays to the
 * gateway server-side (credential never leaves the server), and a sanitized
 * envelope comes back.
 *
 * The route has a NON-EMPTY permission_callback (verifies the wp_rest nonce)
 * — public visitors may chat, but the nonce ties each call to a page load and
 * enables the courtesy throttle. Raw gateway errors never reach the visitor.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Rest
 */
class NPA_Rest {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NS = 'npa/v1';

	/**
	 * Max message length accepted (characters).
	 *
	 * @var int
	 */
	const MAX_MESSAGE = 4000;

	/**
	 * Courtesy throttle: max requests per IP per window.
	 *
	 * @var int
	 */
	const RATE_MAX = 30;

	/**
	 * Courtesy throttle window, seconds.
	 *
	 * @var int
	 */
	const RATE_WINDOW = 60;

	/**
	 * Plugin instance.
	 *
	 * @var NPA_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param NPA_Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the /message route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_message' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'message'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'conversation_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'agent_id'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'agent_token'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'new_conversation' => array(
						'type' => 'boolean',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: require a valid wp_rest nonce (works for anonymous
	 * visitors too — the widget mints one via wp_create_nonce( 'wp_rest' )).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'npa_forbidden',
				__( 'Your session token is missing or expired. Please reload the page.', 'newtide-public-agent' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Sign an agent id so the widget can name which agent it is talking to.
	 *
	 * The browser cannot be trusted to pick an agent: the gateway credential can
	 * usually reach every agent in the tenant, so an unsigned id would let any
	 * visitor address an internal agent by editing one request. The mount markup
	 * therefore carries a token derived from the site's salts, and the proxy
	 * honours an id only when it verifies — meaning only ids this server itself
	 * rendered are ever routed to.
	 *
	 * @param string $agent_id Agent id.
	 * @return string Token.
	 */
	public static function agent_token( $agent_id ) {
		return wp_hash( 'npa_agent|' . (string) $agent_id );
	}

	/**
	 * Which agent this request is for: the signed id from the widget when the
	 * signature verifies, otherwise the site-wide default.
	 *
	 * Falling back rather than erroring keeps a stale cached page working — it
	 * answers as the default agent instead of failing the visitor outright.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string Agent id.
	 */
	private function resolve_agent( $request ) {
		$settings = $this->plugin->settings;

		/*
		 * Label, not identifier. Agents are configured by key now, so a stored
		 * agent id is at best a UUID and at worst a leftover — and Service
		 * Status is asking "which of my agents is busy", which only reads if
		 * additional agents show their row name and the default shows as the
		 * default rather than as an opaque string beside them.
		 */
		$default = array(
			'label'  => __( 'Main agent', 'newtide-public-agent' ),
			'client' => null,
		);

		$named = trim( (string) $request->get_param( 'agent_id' ) );
		$token = (string) $request->get_param( 'agent_token' );

		if ( '' === $named || '' === $token ) {
			return $default;
		}

		if ( ! hash_equals( self::agent_token( $named ), $token ) ) {
			return $default;
		}

		$agent = $settings->agent_by_fingerprint( $named );

		if ( null === $agent ) {
			return $default;
		}

		/*
		 * An additional agent answers with its own key, so the call has to be
		 * made with that key rather than the site's. The signature above proves
		 * the browser named a row this site rendered; the lookup proves the row
		 * still exists. Only then is a client built for it.
		 */
		$client = null;
		if ( $settings->public_api_available() && $agent['key'] !== $settings->get_public_key() ) {
			$client = new NPA_Gateway_Client_Public( $settings->get_public_api_base_url(), $agent['key'] );
		}

		return array(
			'label'  => $agent['label'],
			'client' => $client,
		);
	}

	/**
	 * Handle a chat message: throttle, budget-check, relay to the gateway,
	 * dual-write usage, and return a clean envelope.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_message( $request ) {
		$message = trim( (string) $request->get_param( 'message' ) );

		if ( '' === $message ) {
			return $this->error_response( 'empty_message', __( 'Please enter a message.', 'newtide-public-agent' ), 400 );
		}

		if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
			return $this->error_response( 'message_too_long', __( 'That message is too long. Please shorten it.', 'newtide-public-agent' ), 400 );
		}

		if ( ! $this->throttle_ok() ) {
			return $this->error_response( 'rate_limited', self::friendly_message( 'rate_limited' ), 429 );
		}

		if ( $this->plugin->budget->is_exhausted() ) {
			return $this->error_response( 'budget_exhausted', __( 'The assistant has reached today’s message limit. Please try again tomorrow.', 'newtide-public-agent' ), 429 );
		}

		$conversation_id = sanitize_text_field( (string) $request->get_param( 'conversation_id' ) );

		/*
		 * Conversation memory. The upstream API is single-turn and ignores every
		 * threading field, so continuity is reconstructed here or not at all —
		 * see NPA_Conversation. Off leaves every turn independent, which is what
		 * the platform itself does today.
		 */
		$remember = (bool) $this->plugin->settings->get( 'conversation_memory' )
			&& $this->plugin->gateway_client() instanceof NPA_Gateway_Client_Public;

		if ( $request->get_param( 'new_conversation' ) ) {
			NPA_Conversation::forget( $conversation_id );
			$conversation_id = '';
		}

		if ( $remember ) {
			// Only ever continue an id this server issued; anything else starts
			// a new conversation rather than failing.
			if ( ! NPA_Conversation::is_valid_id( $conversation_id ) ) {
				$conversation_id = NPA_Conversation::new_id();
			}

			$history = NPA_Conversation::load( $conversation_id );
		} else {
			$history = array();
		}
		$context         = $this->sanitize_context( (array) $request->get_param( 'context' ) );
		$resolved        = $this->resolve_agent( $request );
		$agent_id        = $resolved['label'];

		// A page-targeted agent brings its own client, keyed to its own agent.
		$client = ( null !== $resolved['client'] ) ? $resolved['client'] : $this->plugin->gateway_client();

		/*
		 * A transport that models a conversation is handed the turns and threads
		 * them itself. One that takes a single string gets the transcript folded
		 * into the message, which is the workaround NPA_Conversation exists for.
		 * Asking the client which it is keeps that decision in one place.
		 */
		$native   = $client->supports_history();
		$outbound = ( $remember && ! $native ) ? NPA_Conversation::compose( $history, $message ) : $message;
		$send_history = $native ? $history : array();
		// Mock-served calls are flagged so their ~0 ms timings stay out of the
		// latency average (see NPA_Store::aggregates).
		$is_mock = $client instanceof NPA_Gateway_Client_Mock;

		/*
		 * Never let the mock answer a real visitor.
		 *
		 * Proxy mode falls back to the mock whenever a gateway is not configured,
		 * which is deliberate — it lets an admin preview the widget before the
		 * gateway exists. On a live site it also meant a visitor could be told
		 * "Mock agent reply. You said: …" by what looks like the company's
		 * support agent. Previewing is an admin activity, so gate it on the
		 * capability rather than on the environment: an admin still sees the
		 * mock, everyone else gets the configured error message.
		 */
		if ( $is_mock && ! current_user_can( 'manage_options' ) ) {
			$this->plugin->logger->log(
				array(
					'agent_id'   => $agent_id,
					'status'     => 503,
					'error_code' => 'not_configured',
					'note'       => 'mock_withheld',
				)
			);

			return $this->error_response(
				'not_configured',
				(string) $this->plugin->settings->get( 'error_message' ),
				503
			);
		}

		$start = microtime( true );

		try {
			$result  = $client->send_message( $agent_id, $outbound, $conversation_id, $context, $send_history );
			$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

			$this->plugin->store->record(
				array(
					'agent_id'        => $agent_id,
					'conversation_id' => $result->conversation_id,
					'status'          => 200,
					'finish_reason'   => $result->finish_reason,
					'latency_ms'      => $latency,
					'input_tokens'    => $result->input_tokens,
					'output_tokens'   => $result->output_tokens,
					'is_mock'         => $is_mock,
				)
			);
			if ( $remember ) {
				NPA_Conversation::append( $conversation_id, $message, $result->reply_text );
			}

			// Transcripts store what was actually said, never the composed prompt.
			$this->store_turn( $agent_id, $result->conversation_id, $message, $result->reply_text );
			$this->plugin->service_status->record_success( 'gateway' );
			$this->plugin->logger->log(
				array(
					'agent_id'      => $agent_id,
					'latency_ms'    => $latency,
					'status'        => 200,
					'finish_reason' => $result->finish_reason,
					'note'          => 'message',
				)
			);

			return new WP_REST_Response(
				array(
					'reply'           => $result->reply_text,
					// Rendered here rather than in the widget: turning model
					// output into markup is the one place a mistake is an XSS
					// hole, and in PHP it is covered by the test battery.
					'reply_html'      => NPA_Markdown::to_html( $result->reply_text ),
					'conversation_id' => $result->conversation_id,
					'finish_reason'   => $result->finish_reason,
				),
				200
			);
		} catch ( NPA_Gateway_Exception $e ) {
			$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

			$this->plugin->store->record(
				array(
					'agent_id'        => $agent_id,
					'conversation_id' => $conversation_id,
					'status'          => $e->get_http_status(),
					'finish_reason'   => 'error',
					'latency_ms'      => $latency,
					'error_code'      => $e->get_error_code(),
					'is_mock'         => $is_mock,
				)
			);
			$this->plugin->service_status->record_failure( 'gateway' );
			$this->plugin->logger->log(
				array(
					'agent_id'   => $agent_id,
					'latency_ms' => $latency,
					'status'     => $e->get_http_status(),
					'error_code' => $e->get_error_code(),
					'note'       => 'message_error',
				)
			);

			/*
				 * Keep the visitor's message generic — gateway internals must never
				 * reach the browser — but stop the admin flying blind. The upstream
				 * detail is the only thing that distinguishes "the agent's bound
				 * user lacks permission" from "the stream was empty" from "the key
				 * is for the other environment", and all three surface to a visitor
				 * as the same sentence. Stashed for Service Status, and returned
				 * inline only to someone who could read it in the admin anyway.
				 */
			self::remember_error( $e->get_error_code(), $e->getMessage(), $e->get_http_status() );

			$http     = ( 429 === $e->get_http_status() ) ? 429 : 502;
			$response = $this->error_response( $e->get_error_code(), self::friendly_message( $e->get_error_code() ), $http );

			if ( current_user_can( 'manage_options' ) ) {
				$data                    = $response->get_data();
				$data['error']['detail'] = $e->getMessage();
				$response->set_data( $data );
			}

			return $response;
		}
	}

	/**
	 * Persist one exchange when transcript storage is switched on.
	 *
	 * Off by default and a no-op unless the site owner opted in — this is the
	 * only path in the plugin that writes visitor-authored content to the
	 * database, so the gate lives here rather than being spread across callers.
	 *
	 * @param string $agent_id        Agent that answered.
	 * @param string $conversation_id Conversation the turn belongs to.
	 * @param string $message         The visitor's message.
	 * @param string $reply           The agent's reply.
	 * @return void
	 */
	private function store_turn( $agent_id, $conversation_id, $message, $reply ) {
		if ( ! $this->plugin->settings->get( 'store_transcripts' ) ) {
			return;
		}

		$turn = array(
			'visitor' => $message,
			'agent'   => $reply,
		);

		foreach ( $turn as $role => $content ) {
			$this->plugin->store->record_transcript(
				array(
					'conversation_id' => $conversation_id,
					'agent_id'        => $agent_id,
					'role'            => $role,
					'content'         => $content,
				)
			);
		}
	}

	/**
	 * Transient holding the most recent upstream failure, for the admin.
	 *
	 * @var string
	 */
	const LAST_ERROR = 'npa_last_error';

	/**
	 * Record the most recent upstream failure so an administrator can see what
	 * actually went wrong. Message content is never included — only the code,
	 * the API's own explanation, and when it happened.
	 *
	 * @param string $code    Stable error code.
	 * @param string $detail  Upstream message.
	 * @param int    $status  HTTP status.
	 * @return void
	 */
	private static function remember_error( $code, $detail, $status ) {
		set_transient(
			self::LAST_ERROR,
			array(
				'code'   => (string) $code,
				'detail' => wp_strip_all_tags( (string) $detail ),
				'status' => (int) $status,
				'time'   => time(),
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * The most recent upstream failure, or null.
	 *
	 * @return array|null
	 */
	public static function last_error() {
		$stored = get_transient( self::LAST_ERROR );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * A suggestion to accompany an upstream failure, for the admin only.
	 *
	 * "Internal error." is what a permissions gap looks like from outside: the
	 * agent is registered, the key resolves, `/public/agent/info` succeeds, and
	 * then execution fails with a string that names nothing. It cost a long
	 * investigation to land on the cause the Publishing tab already warns about,
	 * so say it at the point of failure rather than leaving it in a guide.
	 *
	 * @param string $code   Stable error code.
	 * @param string $detail Upstream message.
	 * @return string Suggestion, or '' when there is nothing useful to add.
	 */
	public static function error_hint( $code, $detail ) {
		$detail = strtolower( (string) $detail );

		if ( false !== strpos( $detail, 'internal error' ) ) {
			return __( 'The agent itself failed — the connection, key and origin were all fine. The usual cause is permissions: a publishable key runs as the non-admin user it is bound to, and that user needs the agent’s “use” permission plus access to every knowledge or data source the agent reads. A newly created agent does not inherit these; check the agent’s Permissions tab in RisingTide.', 'newtide-public-agent' );
		}

		if ( 'unauthorized' === $code ) {
			return __( 'Check the key was created on the platform this site points at — a UAT key cannot be used against production, or the reverse.', 'newtide-public-agent' );
		}

		if ( 'rate_limited' === $code ) {
			return __( 'The agent API is throttling this site. It clears on its own; the expected traffic level set when the key was created governs the limit.', 'newtide-public-agent' );
		}

		return '';
	}

	/**
	 * Generic, visitor-safe message for a gateway error code. Never leaks the
	 * raw gateway message or the fact that a credential was rejected.
	 *
	 * @param string $code Stable error code.
	 * @return string
	 */
	public static function friendly_message( $code ) {
		switch ( $code ) {
			case 'rate_limited':
				return __( 'The assistant is busy right now. Please try again in a moment.', 'newtide-public-agent' );
			case 'unauthorized':
				return __( 'The assistant is temporarily unavailable. Please try again later.', 'newtide-public-agent' );
			case 'server_error':
			default:
				return __( 'Something went wrong reaching the assistant. Please try again.', 'newtide-public-agent' );
		}
	}

	/**
	 * Build an error envelope response.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Visitor-facing message.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response
	 */
	private function error_response( $code, $message, $status ) {
		return new WP_REST_Response(
			array(
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}

	/**
	 * Sanitize the optional page context.
	 *
	 * @param array $ctx Raw context.
	 * @return array
	 */
	private function sanitize_context( array $ctx ) {
		return array(
			'page_url'   => isset( $ctx['page_url'] ) ? esc_url_raw( (string) $ctx['page_url'] ) : '',
			'page_title' => isset( $ctx['page_title'] ) ? sanitize_text_field( (string) $ctx['page_title'] ) : '',
			'locale'     => isset( $ctx['locale'] ) ? sanitize_text_field( (string) $ctx['locale'] ) : get_locale(),
		);
	}

	/**
	 * Courtesy per-IP throttle (NOT abuse defense — the gateway owns that).
	 *
	 * @return bool True if the request is under the limit.
	 */
	private function throttle_ok() {
		$key   = 'npa_rl_' . md5( $this->client_ip() );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_MAX ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	/**
	 * Best-effort client IP for throttling.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' !== $ip ? $ip : 'unknown';
	}
}
