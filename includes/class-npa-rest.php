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
	private function resolve_agent_id( $request ) {
		$default = $this->plugin->settings->get_agent_id();

		$agent_id = trim( (string) $request->get_param( 'agent_id' ) );
		$token    = (string) $request->get_param( 'agent_token' );

		if ( '' === $agent_id || '' === $token ) {
			return $default;
		}

		return hash_equals( self::agent_token( $agent_id ), $token ) ? $agent_id : $default;
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
		$context         = $this->sanitize_context( (array) $request->get_param( 'context' ) );
		$agent_id        = $this->resolve_agent_id( $request );

		$client = $this->plugin->gateway_client();
		// Mock-served calls are flagged so their ~0 ms timings stay out of the
		// latency average (see NPA_Store::aggregates).
		$is_mock = $client instanceof NPA_Gateway_Client_Mock;

		$start = microtime( true );

		try {
			$result  = $client->send_message( $agent_id, $message, $conversation_id, $context );
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

			$http = ( 429 === $e->get_http_status() ) ? 429 : 502;
			return $this->error_response( $e->get_error_code(), self::friendly_message( $e->get_error_code() ), $http );
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
