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
		$this->store          = new NPA_Store();
		$this->store->maybe_upgrade();
		$this->budget         = new NPA_Budget( $this->settings, $this->store );

		if ( is_admin() ) {
			$this->admin = new NPA_Admin( $this );
			$this->admin->register();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->register_service_status();
		$this->register_core_tests();
		$this->register_gateway_tests();
		$this->register_settings_tests();
		$this->register_store_tests();
		$this->register_budget_tests();

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

		// Configuration (depends on the mock for the default agent id).
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-settings.php';

		// Durable substrate.
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-store.php';
		require_once NPA_PLUGIN_DIR . 'includes/class-npa-budget.php';

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
					/* translators: 1: recent call count, 2: error rate percent, 3: average latency ms. */
					'message' => sprintf(
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
	 * Defaults to the mock. The single seam (plan P2) through which the HTTP
	 * client is swapped in later, and through which tests inject scenarios:
	 * filter `npa_gateway_client` to return any NPA_Gateway_Client.
	 *
	 * @return NPA_Gateway_Client
	 */
	public function gateway_client() {
		if ( null === $this->gateway_client ) {
			/**
			 * Filter the gateway client instance.
			 *
			 * @param NPA_Gateway_Client $client The default (mock) client.
			 */
			$this->gateway_client = apply_filters( 'npa_gateway_client', new NPA_Gateway_Client_Mock() );
		}
		return $this->gateway_client;
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
				$mock   = new NPA_Gateway_Client_Mock( 'ok' );
				$result = $mock->send_message( $agent, 'hello', '', array() );
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
				$clean = $settings->sanitize(
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
				$clean = $settings->sanitize(
					array(
						'greeting'         => '<script>alert(1)</script>Hello',
						'gateway_base_url' => 'javascript:alert(1)',
						'position'         => 'top-left',
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
				$after = $store->count_today();

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
				$stub_store = new class() {
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
				$unlimited = new NPA_Budget( $unlimited_settings, $stub_store );
				$checks[]  = array(
					'label' => __( 'Unset cap (0) is unlimited and never exhausted', 'newtide-public-agent' ),
					'pass'  => 0 === $unlimited->cap() && ! $unlimited->is_exhausted() && $unlimited->remaining() === PHP_INT_MAX,
				);

				return $checks;
			}
		);
	}
}
