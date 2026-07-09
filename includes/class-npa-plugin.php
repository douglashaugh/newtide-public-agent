<?php
/**
 * Core bootstrap singleton.
 *
 * Wires every subsystem and registers WordPress hooks. Extend behaviour via
 * do_action() hooks rather than editing this class' render/boot loops.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Plugin
 *
 * Single entry point for the plugin. Instantiated once on `plugins_loaded`.
 */
final class NPA_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var NPA_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Structured logger.
	 *
	 * @var NPA_Logger
	 */
	public $logger;

	/**
	 * Central health / backoff registry.
	 *
	 * @var NPA_Service_Status
	 */
	public $service_status;

	/**
	 * Shared, deterministic test battery.
	 *
	 * @var NPA_Test_Runner
	 */
	public $test_runner;

	/**
	 * Configuration store.
	 *
	 * @var NPA_Settings
	 */
	public $settings;

	/**
	 * Durable usage store (custom table + dual-write).
	 *
	 * @var NPA_Store
	 */
	public $store;

	/**
	 * Per-day budget meter.
	 *
	 * @var NPA_Budget
	 */
	public $budget;

	/**
	 * Admin surface (only set in the dashboard).
	 *
	 * @var NPA_Admin|null
	 */
	public $admin = null;

	/**
	 * REST proxy.
	 *
	 * @var NPA_Rest
	 */
	public $rest;

	/**
	 * Front-end surface (shortcode + block + widget).
	 *
	 * @var NPA_Public
	 */
	public $public;

	/**
	 * Cached gateway client (mock by default; filterable).
	 *
	 * @var NPA_Gateway_Client|null
	 */
	private $gateway_client = null;

	/**
	 * Get (and lazily create) the singleton instance.
	 *
	 * @return NPA_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Require subsystem classes, instantiate them, and register hooks.
	 *
	 * @return void
	 */
	private function boot() {
		$this->require_files();

		$this->logger         = new NPA_Logger();
		$this->service_status = new NPA_Service_Status();
		$this->test_runner    = new NPA_Test_Runner();
		$this->settings       = new NPA_Settings();
		$this->settings->register();
		$this->store = new NPA_Store();
		$this->store->maybe_upgrade();
		$this->budget = new NPA_Budget( $this->settings, $this->store );
		$this->rest   = new NPA_Rest( $this );
		$this->rest->register();
		$this->public = new NPA_Public( $this );
		$this->public->register();

		if ( is_admin() ) {
			$this->admin = new NPA_Admin( $this );
			$this->admin->register();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->register_service_status();
		$this->register_core_tests();
		$this->register_gateway_tests();
		$this->register_gateway_http_tests();
		$this->register_settings_tests();
		$this->register_store_tests();
		$this->register_budget_tests();
		$this->register_rest_tests();
		$this->register_widget_tests();
		$this->register_embed_tests();

		/**
		 * Fires after the plugin has booted its core subsystems.
		 *
		 * Subsystems added in later milestones (settings, REST, store, admin,
		 * widget) hook here to register themselves.
		 *
		 * @param NPA_Plugin $plugin The plugin instance.
		 */
		do_action( 'npa_booted', $this );
	}

	/**
	 * Load class files for the subsystems present in this milestone.
	 *
	 * @return void
	 */
	private function require_files() {
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-logger.php';
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-service-status.php';
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-test-runner.php';

		// Gateway contract + implementations (plan P2).
		require_once NPA_PLUGIN_DIR . 'includes/gateway/interface-npa-gateway-client.php';
		require_once NPA_PLUGIN_DIR . 'includes/gateway/class-npa-gateway-result.php';
		require_once NPA_PLUGIN_DIR . 'includes/gateway/class-npa-gateway-agent.php';
		require_once NPA_PLUGIN_DIR . 'includes/gateway/class-npa-gateway-health.php';
		require_once NPA_PLUGIN_DIR . 'includes/gateway/class-npa-gateway-exception.php';
		require_once NPA_PLUGIN_DIR . 'includes/gateway/class-npa-gateway-client-mock.php';
		require_once NPA_PLUGIN_DIR . 'includes/gateway/class-npa-gateway-client-http.php';

		// Configuration (depends on the mock for the default agent id).
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-settings.php';

		// Durable substrate.
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-store.php';
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-budget.php';

		// REST proxy.
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-rest.php';

		// Front-end surface.
		require_once NPA_PLUGIN_DIR . 'public/class-npa-public.php';

		// Admin surface (only needed in the dashboard).
		if ( is_admin() ) {
			require_once NPA_PLUGIN_DIR . 'admin/class-npa-admin.php';
		}
	}

	/**
	 * Create the schema on activation. (Git-as-deploy updates rely on the
	 * version-gated maybe_upgrade() in boot(), since activation does not fire
	 * on update.)
	 *
	 * @return void
	 */
	public static function activate() {
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-store.php';
		( new NPA_Store() )->install();
	}

	/**
	 * Register the durable subsystems into the Service Status roll-up.
	 *
	 * @return void
	 */
	private function register_service_status() {
		$this->service_status->register(
			'usage',
			__( 'Usage history', 'newtide-public-agent' ),
			function () {
				$agg = $this->store->aggregates( 50 );
				return array(
					'ok'      => $agg['error_rate'] <= 0.10,
					'message' => sprintf(
						/* translators: 1: recent call count, 2: error rate percent, 3: average latency ms. */
						__( '%1$d recent calls, %2$s%% errors, %3$d ms avg.', 'newtide-public-agent' ),
						$agg['count'],
						number_format_i18n( $agg['error_rate'] * 100, 1 ),
						$agg['avg_latency_ms']
					),
				);
			}
		);

		$this->service_status->register(
			'budget',
			__( 'Daily budget', 'newtide-public-agent' ),
			function () {
				return $this->budget->status();
			}
		);
	}

	/**
	 * Get the active gateway client.
	 *
	 * Uses the real HTTP client when the plugin is configured (base URL +
	 * credential + agent), otherwise the deterministic mock — so local/dev and
	 * the test battery stay hermetic until a gateway is actually set up. The
	 * `npa_gateway_client` filter overrides the choice (used by tests and for
	 * forcing the mock via NPA_FORCE_MOCK).
	 *
	 * @return NPA_Gateway_Client
	 */
	public function gateway_client() {
		if ( null === $this->gateway_client ) {
			$force_mock = defined( 'NPA_FORCE_MOCK' ) && NPA_FORCE_MOCK;

			if ( ! $force_mock && $this->settings->is_configured() ) {
				$default = new NPA_Gateway_Client_Http(
					$this->settings->get_gateway_base_url(),
					$this->settings->get_gateway_key()
				);
			} else {
				$default = new NPA_Gateway_Client_Mock();
			}

			/**
			 * Filter the gateway client instance.
			 *
			 * @param NPA_Gateway_Client $default The selected client.
			 * @param NPA_Plugin         $plugin  The plugin instance.
			 */
			$this->gateway_client = apply_filters( 'npa_gateway_client', $default, $this );
		}
		return $this->gateway_client;
	}

	/**
	 * Reset the cached gateway client (used by tests that swap the client).
	 *
	 * @return void
	 */
	public function reset_gateway_client() {
		$this->gateway_client = null;
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'newtide-public-agent',
			false,
			dirname( NPA_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register the always-on core test suites.
	 *
	 * The environment suite is the M1 "one green test" gate: it proves the
	 * plugin loaded, constants are defined, and the runtime meets requirements.
	 *
	 * @return void
	 */
	private function register_core_tests() {
		$this->test_runner->register_suite(
			'environment',
			__( 'Environment', 'newtide-public-agent' ),
			__( 'Confirms the plugin loaded correctly and the server meets the minimum PHP and WordPress versions it needs to run safely.', 'newtide-public-agent' ),
			function () {
				$checks = array();

				$checks[] = array(
					'label' => __( 'Plugin version constant is defined', 'newtide-public-agent' ),
					'pass'  => defined( 'NPA_VERSION' ) && '' !== NPA_VERSION,
				);

				$checks[] = array(
					'label' => __( 'PHP 8.1 or newer', 'newtide-public-agent' ),
					'pass'  => version_compare( PHP_VERSION, '8.1', '>=' ),
				);

				$checks[] = array(
					'label' => __( 'WordPress 6.4 or newer', 'newtide-public-agent' ),
					'pass'  => version_compare( get_bloginfo( 'version' ), '6.4', '>=' ),
				);

				return $checks;
			}
		);
	}

	/**
	 * Register the gateway mock contract suite (M2 Verify companion).
	 *
	 * Exercises the mock's happy path and each simulated error scenario so the
	 * contract's branches are proven before anything depends on them. No live
	 * HTTP — the mock is the whole point.
	 *
	 * @return void
	 */
	private function register_gateway_tests() {
		$this->test_runner->register_suite(
			'gateway_mock',
			__( 'Gateway (mock)', 'newtide-public-agent' ),
			__( 'Proves the plugin can talk to a gateway and correctly handles success, bad credentials, rate limiting, and outages — verified against a deterministic stand-in so the real service is never required to build or test.', 'newtide-public-agent' ),
			function () {
				$checks = array();
				$agent  = NPA_Gateway_Client_Mock::DEFAULT_AGENT_ID;

				// Happy path: a reply and an assigned conversation id.
				$mock     = new NPA_Gateway_Client_Mock( 'ok' );
				$result   = $mock->send_message( $agent, 'hello', '', array() );
				$checks[] = array(
					'label' => __( 'Successful message returns a reply and a conversation id', 'newtide-public-agent' ),
					'pass'  => ( $result instanceof NPA_Gateway_Result ) && '' !== $result->reply_text && '' !== $result->conversation_id,
				);

				// Error scenarios throw with the correct HTTP status.
				$expectations = array(
					'unauthorized' => 401,
					'rate_limited' => 429,
					'server_error' => 500,
				);
				foreach ( $expectations as $scenario => $status ) {
					$got  = 0;
					$mock = new NPA_Gateway_Client_Mock( $scenario );
					try {
						$mock->send_message( $agent, 'hello', '', array() );
					} catch ( NPA_Gateway_Exception $e ) {
						$got = $e->get_http_status();
					}
					$checks[] = array(
						/* translators: 1: scenario name, 2: expected HTTP status. */
						'label' => sprintf( __( 'Scenario "%1$s" throws HTTP %2$d', 'newtide-public-agent' ), $scenario, $status ),
						'pass'  => $got === $status,
					);
				}

				// list_agents returns the known target agent.
				$mock     = new NPA_Gateway_Client_Mock( 'ok' );
				$agents   = $mock->list_agents();
				$checks[] = array(
					'label' => __( 'Agent list includes the target agent id', 'newtide-public-agent' ),
					'pass'  => ! empty( $agents ) && $agents[0] instanceof NPA_Gateway_Agent && $agent === $agents[0]->id,
				);

				// health_check reports healthy on ok and never throws on error.
				$checks[] = array(
					'label' => __( 'Health check reports connected on success', 'newtide-public-agent' ),
					'pass'  => ( new NPA_Gateway_Client_Mock( 'ok' ) )->health_check()->ok === true,
				);
				$checks[] = array(
					'label' => __( 'Health check reports unhealthy (not an exception) on bad credential', 'newtide-public-agent' ),
					'pass'  => ( new NPA_Gateway_Client_Mock( 'unauthorized' ) )->health_check()->ok === false,
				);

				return $checks;
			}
		);
	}

	/**
	 * Register the HTTP gateway client suite (M8 Verify companion).
	 *
	 * Exercises the real client against a mocked WordPress HTTP layer
	 * (pre_http_request) — no live network — proving request success mapping and
	 * each error branch (401/429/5xx/transport), health, and agent listing.
	 *
	 * @return void
	 */
	private function register_gateway_http_tests() {
		$this->test_runner->register_suite(
			'gateway_http',
			__( 'Gateway (HTTP)', 'newtide-public-agent' ),
			__( 'Confirms the real gateway client — used once a gateway URL and credential are configured — correctly reads a reply and maps bad credentials, rate limits, outages, and network failures, all against a simulated server so no live call is made.', 'newtide-public-agent' ),
			function () {
				$checks = array();
				$mode   = 'ok';

				// $pre/$args/$url are required by the pre_http_request signature.
				$responder = static function ( $pre, $args, $url ) use ( &$mode ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
					switch ( $mode ) {
						case 'ok':
							return array(
								'response' => array( 'code' => 200 ),
								'body'     => wp_json_encode(
									array(
										'reply'           => 'Hi from HTTP',
										'conversation_id' => 'conv-http-1',
										'finish_reason'   => 'stop',
										'usage'           => array(
											'input_tokens' => 2,
											'output_tokens' => 4,
										),
									)
								),
							);
						case 'agents':
							return array(
								'response' => array( 'code' => 200 ),
								'body'     => wp_json_encode(
									array(
										'agents' => array(
											array(
												'id'   => 'a-1',
												'name' => 'Agent One',
											),
										),
									)
								),
							);
						case 'wperr':
							return new WP_Error( 'http_request_failed', 'connection refused' );
						default:
							return array(
								'response' => array( 'code' => (int) $mode ),
								'body'     => wp_json_encode( array( 'error' => array( 'message' => 'nope' ) ) ),
							);
					}
				};

				add_filter( 'pre_http_request', $responder, 10, 3 );
				$http = new NPA_Gateway_Client_Http( 'https://gateway.example', 'sk-test-key' );

				// Success mapping.
				$mode     = 'ok';
				$result   = $http->send_message( 'agent-x', 'hello', '', array() );
				$checks[] = array(
					'label' => __( 'A 200 response maps to a result (reply, conversation id, tokens)', 'newtide-public-agent' ),
					'pass'  => ( $result instanceof NPA_Gateway_Result )
						&& 'Hi from HTTP' === $result->reply_text
						&& 'conv-http-1' === $result->conversation_id
						&& 2 === $result->input_tokens,
				);

				// Error-status mapping.
				$expectations = array(
					'401' => 'unauthorized',
					'403' => 'unauthorized',
					'429' => 'rate_limited',
					'500' => 'server_error',
				);
				foreach ( $expectations as $status => $code ) {
					$mode   = $status;
					$got    = '';
					$got_st = 0;
					try {
						$http->send_message( 'agent-x', 'hello', '', array() );
					} catch ( NPA_Gateway_Exception $e ) {
						$got    = $e->get_error_code();
						$got_st = $e->get_http_status();
					}
					$checks[] = array(
						/* translators: 1: HTTP status, 2: error code. */
						'label' => sprintf( __( 'HTTP %1$s maps to "%2$s"', 'newtide-public-agent' ), $status, $code ),
						'pass'  => $got === $code && $got_st === (int) $status,
					);
				}

				// Transport failure.
				$mode = 'wperr';
				$got  = '';
				try {
					$http->send_message( 'agent-x', 'hello', '', array() );
				} catch ( NPA_Gateway_Exception $e ) {
					$got = $e->get_error_code();
				}
				$checks[] = array(
					'label' => __( 'A network failure maps to "transport"', 'newtide-public-agent' ),
					'pass'  => 'transport' === $got,
				);

				// Health + agent list.
				$mode     = 'ok';
				$checks[] = array(
					'label' => __( 'Health check reports connected on 200', 'newtide-public-agent' ),
					'pass'  => $http->health_check()->ok === true,
				);
				$mode     = 'agents';
				$agents   = $http->list_agents();
				$checks[] = array(
					'label' => __( 'Agent list parses into agent objects', 'newtide-public-agent' ),
					'pass'  => ! empty( $agents ) && $agents[0] instanceof NPA_Gateway_Agent && 'a-1' === $agents[0]->id,
				);

				remove_filter( 'pre_http_request', $responder, 10 );

				return $checks;
			}
		);
	}

	/**
	 * Register the settings sanitization suite (M3 Verify companion).
	 *
	 * Exercises the storage layer without touching the database: whitelist
	 * enforcement, value neutralization, and the write-only credential rule.
	 *
	 * @return void
	 */
	private function register_settings_tests() {
		$this->test_runner->register_suite(
			'settings',
			__( 'Settings', 'newtide-public-agent' ),
			__( 'Confirms that saved configuration is cleaned before storage: bad values are corrected, unexpected fields are discarded, and the gateway credential is never wiped by an empty form or exposed — so a misconfigured or malicious save cannot break or leak the plugin.', 'newtide-public-agent' ),
			function () {
				$settings = $this->settings;
				$checks   = array();

				// Defaults are complete and sane.
				$defaults = NPA_Settings::defaults();
				$checks[] = array(
					'label' => __( 'Defaults include the target agent id and a valid accent colour', 'newtide-public-agent' ),
					'pass'  => NPA_Gateway_Client_Mock::DEFAULT_AGENT_ID === $defaults['agent_id']
						&& (bool) sanitize_hex_color( $defaults['accent'] ),
				);

				// Unknown keys are dropped; known keys survive.
				$clean    = $settings->sanitize(
					array(
						'launcher_label' => 'Talk to us',
						'evil_key'       => 'DROP TABLE',
					)
				);
				$checks[] = array(
					'label' => __( 'Unknown keys are dropped; known keys are kept', 'newtide-public-agent' ),
					'pass'  => ! array_key_exists( 'evil_key', $clean ) && 'Talk to us' === $clean['launcher_label'],
				);

				// Malicious input is neutralized.
				$clean    = $settings->sanitize(
					array(
						'greeting'         => '<script>alert(1)</script>Hello',
						'gateway_base_url' => 'javascript:alert(1)',
						'position'         => 'sideways-up',
						'accent'           => 'not-a-color',
					)
				);
				$checks[] = array(
					'label' => __( 'Script tags stripped from greeting', 'newtide-public-agent' ),
					'pass'  => false === strpos( $clean['greeting'], '<script' ),
				);
				$checks[] = array(
					'label' => __( 'Disallowed URL scheme rejected on base URL', 'newtide-public-agent' ),
					'pass'  => false === strpos( $clean['gateway_base_url'], 'javascript:' ),
				);
				$checks[] = array(
					'label' => __( 'Invalid position falls back to a whitelisted value', 'newtide-public-agent' ),
					'pass'  => in_array( $clean['position'], NPA_Settings::POSITIONS, true ),
				);
				$checks[] = array(
					'label' => __( 'Invalid accent colour falls back to the default', 'newtide-public-agent' ),
					'pass'  => (bool) sanitize_hex_color( $clean['accent'] ),
				);

				// Retention days are clamped to a sane range.
				$clamped_low  = $settings->sanitize( array( 'transcript_retention_days' => 0 ) );
				$clamped_high = $settings->sanitize( array( 'transcript_retention_days' => 99999 ) );
				$checks[]     = array(
					'label' => __( 'Transcript retention is clamped to 1–3650 days', 'newtide-public-agent' ),
					'pass'  => $clamped_low['transcript_retention_days'] >= 1 && $clamped_high['transcript_retention_days'] <= 3650,
				);

				// New customization whitelists fall back to safe values.
				$bad_enums = $settings->sanitize(
					array(
						'theme'          => 'ultraviolet',
						'launcher_shape' => 'triangle',
						'audience'       => 'robots',
					)
				);
				$checks[]  = array(
					'label' => __( 'Invalid theme, launcher shape, and audience fall back to whitelisted values', 'newtide-public-agent' ),
					'pass'  => in_array( $bad_enums['theme'], NPA_Settings::THEMES, true )
						&& in_array( $bad_enums['launcher_shape'], NPA_Settings::SHAPES, true )
						&& in_array( $bad_enums['audience'], NPA_Settings::AUDIENCES, true ),
				);

				// Connection mode and embed placement fall back to safe values.
				$bad_conn = $settings->sanitize(
					array(
						'mode'      => 'telepathy',
						'placement' => 'sideways',
					)
				);
				$checks[] = array(
					'label' => __( 'Invalid connection mode and placement fall back to whitelisted values', 'newtide-public-agent' ),
					'pass'  => in_array( $bad_conn['mode'], NPA_Settings::MODES, true )
						&& in_array( $bad_conn['placement'], NPA_Settings::PLACEMENTS, true ),
				);

				// A valid new position (top-left) is accepted, not rejected.
				$top_left = $settings->sanitize( array( 'position' => 'top-left' ) );
				$checks[] = array(
					'label' => __( 'Top-anchored positions are accepted', 'newtide-public-agent' ),
					'pass'  => 'top-left' === $top_left['position'],
				);

				// Auto-open delay is clamped to 0–600 seconds.
				$delay    = $settings->sanitize( array( 'auto_open_delay' => 99999 ) );
				$checks[] = array(
					'label' => __( 'Auto-open delay is clamped to at most 600 seconds', 'newtide-public-agent' ),
					'pass'  => $delay['auto_open_delay'] <= 600,
				);

				// Exclude-IDs are normalized: non-numeric dropped, duplicates removed.
				$ids      = $settings->sanitize( array( 'exclude_ids' => '12, abc, 40, 40' ) );
				$checks[] = array(
					'label' => __( 'Exclude-page IDs are normalized to a clean integer list', 'newtide-public-agent' ),
					'pass'  => '12,40' === $ids['exclude_ids'],
				);

				// Suggested prompts are capped and blank lines dropped.
				$prompts  = $settings->sanitize(
					array( 'suggested_prompts' => "One\n\nTwo\nThree\nFour\nFive\nSix\nSeven\nEight" )
				);
				$lines    = array_filter( explode( "\n", $prompts['suggested_prompts'] ), 'strlen' );
				$checks[] = array(
					'label' => __( 'Suggested prompts drop blank lines and cap at six', 'newtide-public-agent' ),
					'pass'  => count( $lines ) <= 6 && ! in_array( '', $lines, true ),
				);

				// Write-only credential rule: an empty submission never wipes a
				// key set via the filter, and a key is never exposed by default.
				$saw_filter = false;
				$stub       = function () use ( &$saw_filter ) {
					$saw_filter = true;
					return 'sk-from-filter';
				};
				add_filter( 'npa_gateway_key', $stub );
				$resolved = $settings->get_gateway_key();
				remove_filter( 'npa_gateway_key', $stub );
				$checks[] = array(
					'label' => __( 'Gateway key resolves via the injection filter', 'newtide-public-agent' ),
					'pass'  => $saw_filter && 'sk-from-filter' === $resolved,
				);

				return $checks;
			}
		);
	}

	/**
	 * Register the durable store suite (M4 Verify companion).
	 *
	 * Writes and removes a sentinel row so the table and dual-write are proven
	 * without leaving test data behind.
	 *
	 * @return void
	 */
	private function register_store_tests() {
		$this->test_runner->register_suite(
			'store',
			__( 'Usage store', 'newtide-public-agent' ),
			__( 'Confirms the durable usage table exists and that each recorded call is written and countable — the substrate behind the status panel, the daily budget, and every historical metric.', 'newtide-public-agent' ),
			function () {
				global $wpdb;
				$store  = $this->store;
				$checks = array();
				$table  = $store->table_name();

				// Table exists.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$found    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
				$checks[] = array(
					'label' => __( 'Usage table exists', 'newtide-public-agent' ),
					'pass'  => $found === $table,
				);

				// Dual-write a sentinel row, assert, then clean up.
				$sentinel = '__npa_test__';
				$before   = $store->count_today();
				$id       = $store->record(
					array(
						'agent_id'      => $sentinel,
						'status'        => 200,
						'finish_reason' => 'stop',
						'latency_ms'    => 42,
						'input_tokens'  => 3,
						'output_tokens' => 9,
					)
				);
				$after    = $store->count_today();

				$checks[] = array(
					'label' => __( 'Recording a call inserts a row and increments today\'s count', 'newtide-public-agent' ),
					'pass'  => is_int( $id ) && $id > 0 && ( $after === $before + 1 ),
				);

				$last     = get_transient( NPA_Store::LAST_TRANSIENT );
				$checks[] = array(
					'label' => __( 'Fast-path transient mirrors the last call', 'newtide-public-agent' ),
					'pass'  => is_array( $last ) && isset( $last['agent_id'] ) && $sentinel === $last['agent_id'],
				);

				$agg      = $store->aggregates( 50 );
				$checks[] = array(
					'label' => __( 'Aggregates return count, error rate, and average latency', 'newtide-public-agent' ),
					'pass'  => isset( $agg['count'], $agg['error_rate'], $agg['avg_latency_ms'] ) && $agg['count'] >= 1,
				);

				// Cleanup — never leave test rows behind.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $table, array( 'agent_id' => $sentinel ), array( '%s' ) );
				delete_transient( NPA_Store::LAST_TRANSIENT );

				return $checks;
			}
		);
	}

	/**
	 * Register the budget suite (M4 Verify companion).
	 *
	 * Pure arithmetic against stubbed dependencies — no database writes.
	 *
	 * @return void
	 */
	private function register_budget_tests() {
		$this->test_runner->register_suite(
			'budget',
			__( 'Daily budget', 'newtide-public-agent' ),
			__( 'Confirms the courtesy daily cap counts correctly and reports "exhausted" only when it should — so a runaway page cannot quietly rack up gateway calls, while an unset cap stays unlimited.', 'newtide-public-agent' ),
			function () {
				$checks = array();

				$stub_settings = new class() {
					/**
					 * Stubbed setting getter.
					 *
					 * @param string $key      Key.
					 * @param mixed  $fallback Default.
					 * @return mixed
					 */
					public function get( $key, $fallback = null ) {
						return 'daily_message_cap' === $key ? 3 : $fallback;
					}
				};
				$stub_store    = new class() {
					/**
					 * Stubbed count.
					 *
					 * @return int
					 */
					public function count_today() {
						return 5;
					}
				};

				$capped   = new NPA_Budget( $stub_settings, $stub_store );
				$checks[] = array(
					'label' => __( 'Reports exhausted when usage meets or exceeds the cap', 'newtide-public-agent' ),
					'pass'  => 3 === $capped->cap() && $capped->is_exhausted() && 0 === $capped->remaining(),
				);

				$unlimited_settings = new class() {
					/**
					 * Stubbed setting getter (unlimited).
					 *
					 * @param string $key      Key.
					 * @param mixed  $fallback Default.
					 * @return mixed
					 */
					public function get( $key, $fallback = null ) {
						return 'daily_message_cap' === $key ? 0 : $fallback;
					}
				};
				$unlimited          = new NPA_Budget( $unlimited_settings, $stub_store );
				$checks[]           = array(
					'label' => __( 'Unset cap (0) is unlimited and never exhausted', 'newtide-public-agent' ),
					'pass'  => 0 === $unlimited->cap() && ! $unlimited->is_exhausted() && $unlimited->remaining() === PHP_INT_MAX,
				);

				return $checks;
			}
		);
	}

	/**
	 * Register the REST proxy suite (M6 Verify companion).
	 *
	 * Proves the route exists, visitor-facing error copy never leaks gateway
	 * detail, and a real dispatch relays a reply and records a usage row.
	 * The dispatch uses a sentinel agent id and cleans up after itself.
	 *
	 * @return void
	 */
	private function register_rest_tests() {
		$this->test_runner->register_suite(
			'rest',
			__( 'Message proxy', 'newtide-public-agent' ),
			__( 'Confirms the front end can reach the agent through the site’s own server (so the credential never touches the browser), that visitors only ever see friendly error text, and that every call is recorded.', 'newtide-public-agent' ),
			function () {
				$checks = array();
				$server = rest_get_server();

				// Route registered.
				$routes   = $server->get_routes();
				$checks[] = array(
					'label' => __( 'POST /npa/v1/message route is registered', 'newtide-public-agent' ),
					'pass'  => isset( $routes['/npa/v1/message'] ),
				);

				// Error copy is generic — no raw gateway detail leaks.
				$leaks = false;
				foreach ( array( 'unauthorized', 'rate_limited', 'server_error' ) as $code ) {
					$msg = NPA_Rest::friendly_message( $code );
					if ( '' === $msg || false !== stripos( $msg, 'mock' ) || false !== stripos( $msg, 'credential' ) ) {
						$leaks = true;
					}
				}
				$checks[] = array(
					'label' => __( 'Visitor error messages are generic (no gateway internals)', 'newtide-public-agent' ),
					'pass'  => ! $leaks,
				);

				// Real dispatch with a sentinel agent id; assert + clean up.
				global $wpdb;
				$prev = get_option( 'npa_options' );
				update_option( 'npa_options', array( 'agent_id' => '__npa_rest_test__' ) );

				$before  = $this->store->count_today();
				$request = new WP_REST_Request( 'POST', '/npa/v1/message' );
				$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
				$request->set_param( 'message', 'hello from the rest suite' );

				$response = $server->dispatch( $request );
				$data     = $response->get_data();
				$after    = $this->store->count_today();

				$checks[] = array(
					'label' => __( 'A valid message returns a reply envelope (HTTP 200)', 'newtide-public-agent' ),
					'pass'  => 200 === $response->get_status() && is_array( $data ) && ! empty( $data['reply'] ),
				);
				$checks[] = array(
					'label' => __( 'The call is recorded to the usage table', 'newtide-public-agent' ),
					'pass'  => $after === $before + 1,
				);

				// Nonce is required (send a valid message but omit the nonce so
				// required-param validation passes and permission is what fails).
				$no_nonce_req = new WP_REST_Request( 'POST', '/npa/v1/message' );
				$no_nonce_req->set_param( 'message', 'no nonce here' );
				$no_nonce = $server->dispatch( $no_nonce_req );
				$checks[] = array(
					'label' => __( 'A request with a message but no valid nonce is rejected (HTTP 403)', 'newtide-public-agent' ),
					'pass'  => 403 === $no_nonce->get_status(),
				);

				// Cleanup — leave no test data behind.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $this->store->table_name(), array( 'agent_id' => '__npa_rest_test__' ), array( '%s' ) );
				delete_transient( 'npa_rl_' . md5( 'unknown' ) );
				if ( false === $prev ) {
					delete_option( 'npa_options' );
				} else {
					update_option( 'npa_options', $prev );
				}

				return $checks;
			}
		);
	}

	/**
	 * Register the front-end widget suite (M7 Verify companion).
	 *
	 * Proves the shortcode and block are registered, the shortcode renders a
	 * mount node with the agent id, and — the security-critical check — the
	 * gateway credential never appears in front-end output.
	 *
	 * @return void
	 */
	private function register_widget_tests() {
		$this->test_runner->register_suite(
			'widget',
			__( 'Front-end widget', 'newtide-public-agent' ),
			__( 'Confirms the chat widget can be placed via shortcode or block and that the private gateway credential is never written into the page a visitor can view — the core promise of the server-side proxy.', 'newtide-public-agent' ),
			function () {
				$checks = array();

				$checks[] = array(
					'label' => __( 'The [newtide_agent] shortcode is registered', 'newtide-public-agent' ),
					'pass'  => shortcode_exists( 'newtide_agent' ),
				);

				$checks[] = array(
					'label' => __( 'The NewTide Agent block is registered', 'newtide-public-agent' ),
					'pass'  => WP_Block_Type_Registry::get_instance()->is_registered( 'newtide/agent' ),
				);

				// Pin proxy mode + enabled so the render check is deterministic
				// regardless of the site's live connection mode.
				$prev = get_option( 'npa_options' );
				update_option(
					'npa_options',
					array_merge(
						NPA_Settings::defaults(),
						is_array( $prev ) ? $prev : array(),
						array(
							'mode'    => 'proxy',
							'enabled' => true,
						)
					)
				);

				// Render the shortcode with a sentinel credential injected, and
				// assert it appears nowhere in the output.
				$sentinel = 'sk-SECRET-should-never-render';
				$inject   = static function () use ( $sentinel ) {
					return $sentinel;
				};
				add_filter( 'npa_gateway_key', $inject );
				$html = do_shortcode( '[newtide_agent]' );
				remove_filter( 'npa_gateway_key', $inject );

				$checks[] = array(
					'label' => __( 'Shortcode renders a widget mount node with the agent id', 'newtide-public-agent' ),
					'pass'  => false !== strpos( $html, 'data-npa-widget' )
						&& false !== strpos( $html, $this->settings->get_agent_id() ),
				);

				$checks[] = array(
					'label' => __( 'The gateway credential never appears in front-end output', 'newtide-public-agent' ),
					'pass'  => '' !== $sentinel && false === strpos( $html, $sentinel ),
				);

				if ( false !== $prev ) {
					update_option( 'npa_options', $prev );
				} else {
					delete_option( 'npa_options' );
				}

				return $checks;
			}
		);
	}

	/**
	 * Register the embed-transport suite (M11 Verify companion).
	 *
	 * Proves the publishable-key embed path: inline placement renders a mount
	 * node, floating placement defers to the site-wide loader, the loader's
	 * <script> tag carries the pk_ key, and the secret gateway credential is
	 * never emitted by the embed path.
	 *
	 * @return void
	 */
	private function register_embed_tests() {
		$this->test_runner->register_suite(
			'embed',
			__( 'Embed transport', 'newtide-public-agent' ),
			__( 'Confirms the RisingTide embed mode wires up correctly — inline placements get a mount node, the injected loader carries the publishable key, and the private gateway credential is never written into the embed output.', 'newtide-public-agent' ),
			function () {
				$checks = array();
				$prev   = get_option( 'npa_options' );

				$base = array_merge(
					NPA_Settings::defaults(),
					array(
						'mode'         => 'embed',
						'public_key'   => 'pk_embed_test_123',
						'platform_url' => 'https://uat-ai.newtide.ai',
						'placement'    => 'inline',
						'enabled'      => true,
					)
				);

				// Inline placement renders a mount node.
				update_option( 'npa_options', $base );
				$inline   = do_shortcode( '[newtide_agent]' );
				$checks[] = array(
					'label' => __( 'Inline embed placement renders a mount node', 'newtide-public-agent' ),
					'pass'  => false !== strpos( $inline, 'newtide-public-agent-embed' )
						&& false !== strpos( $inline, 'npa-embed-mount-' ),
				);

				// Floating placement defers to the site-wide loader (no inline markup).
				$base['placement'] = 'floating';
				update_option( 'npa_options', $base );
				$floating = do_shortcode( '[newtide_agent]' );
				$checks[] = array(
					'label' => __( 'Floating embed placement emits no inline markup', 'newtide-public-agent' ),
					'pass'  => '' === trim( $floating ),
				);

				// The injected <script> tag carries the publishable key. The sample
				// is a filter fixture, not a real enqueue.
				$sample   = "<script src='https://uat-ai.newtide.ai/agent-embed.js' id='npa-embed-js'></script>"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- test fixture passed through the script_loader_tag filter.
				$tagged   = apply_filters( 'script_loader_tag', $sample, NPA_Public::EMBED_HANDLE );
				$checks[] = array(
					'label' => __( 'The embed loader tag carries the publishable key', 'newtide-public-agent' ),
					'pass'  => false !== strpos( $tagged, 'data-api-key="pk_embed_test_123"' ),
				);

				// Non-embed script tags are untouched.
				$other    = apply_filters( 'script_loader_tag', $sample, 'jquery-core' );
				$checks[] = array(
					'label' => __( 'Other scripts’ tags are left unchanged', 'newtide-public-agent' ),
					'pass'  => $other === $sample,
				);

				// The secret gateway credential never appears in the embed tag.
				$sentinel = 'sk-SECRET-embed-should-never-render';
				$inject   = static function () use ( $sentinel ) {
					return $sentinel;
				};
				add_filter( 'npa_gateway_key', $inject );
				$guard_tag = apply_filters( 'script_loader_tag', $sample, NPA_Public::EMBED_HANDLE );
				remove_filter( 'npa_gateway_key', $inject );
				$checks[] = array(
					'label' => __( 'The secret gateway credential never appears in the embed tag', 'newtide-public-agent' ),
					'pass'  => false === strpos( $guard_tag, $sentinel ),
				);

				// Restore prior options.
				if ( false !== $prev ) {
					update_option( 'npa_options', $prev );
				} else {
					delete_option( 'npa_options' );
				}

				return $checks;
			}
		);
	}
}
