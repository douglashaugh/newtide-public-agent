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

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->register_core_tests();
		$this->register_gateway_tests();

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
}
