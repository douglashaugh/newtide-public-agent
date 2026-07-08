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
}
