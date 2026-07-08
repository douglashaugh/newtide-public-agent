<?php
/**
 * Admin settings page: a capability-gated, tabbed surface
 * (General / Agent / Service Status / Tests) plus the nonce-protected
 * "Test connection" and "Run tests" actions.
 *
 * Templates in views/ are HTML only; all data is escaped at output.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Admin
 */
class NPA_Admin {

	/**
	 * Menu / page slug.
	 *
	 * @var string
	 */
	const SLUG = 'newtide-public-agent';

	/**
	 * Nonce action shared by the admin AJAX endpoints.
	 *
	 * @var string
	 */
	const NONCE = 'npa_admin';

	/**
	 * The plugin instance.
	 *
	 * @var NPA_Plugin
	 */
	private $plugin;

	/**
	 * Our admin page hook suffix (for the enqueue guard).
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Constructor.
	 *
	 * @param NPA_Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_npa_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_npa_run_tests', array( $this, 'ajax_run_tests' ) );
	}

	/**
	 * Add the top-level menu page.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hook = add_menu_page(
			__( 'NewTide Public Agent', 'newtide-public-agent' ),
			__( 'NewTide Agent', 'newtide-public-agent' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			80
		);
	}

	/**
	 * Enqueue admin assets on our page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style(
			'npa-admin',
			NPA_PLUGIN_URL . 'assets/css/newtide-public-agent-admin.css',
			array(),
			NPA_VERSION
		);

		wp_enqueue_script(
			'npa-admin',
			NPA_PLUGIN_URL . 'assets/js/newtide-public-agent-admin.js',
			array(),
			NPA_VERSION,
			true
		);

		wp_localize_script(
			'npa-admin',
			'NPA_ADMIN',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'testingText' => __( 'Testing…', 'newtide-public-agent' ),
				'runningText' => __( 'Running…', 'newtide-public-agent' ),
				'errorText'   => __( 'Request failed. Please try again.', 'newtide-public-agent' ),
			)
		);
	}

	// Page rendering.

	/**
	 * The active tab slug.
	 *
	 * @return string
	 */
	public function current_tab() {
		// Reading a tab name for display only; nonce not applicable to tab nav.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'general';
	}

	/**
	 * Tab slug => label map.
	 *
	 * @return array<string,string>
	 */
	public function tabs() {
		return array(
			'general' => __( 'General', 'newtide-public-agent' ),
			'agent'   => __( 'Agent', 'newtide-public-agent' ),
			'status'  => __( 'Service Status', 'newtide-public-agent' ),
			'tests'   => __( 'Tests', 'newtide-public-agent' ),
		);
	}

	/**
	 * URL of a given tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the settings page shell + active tab.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'newtide-public-agent' ) );
		}

		$tab       = $this->current_tab();
		$npa_admin = $this;
		$settings  = $this->plugin->settings;

		echo '<div class="wrap npa-wrap">';
		echo '<h1>' . esc_html__( 'NewTide Public Agent', 'newtide-public-agent' ) . '</h1>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $this->tabs() as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $this->tab_url( $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		$view = NPA_PLUGIN_DIR . 'admin/views/tab-' . $tab . '.php';
		if ( is_readable( $view ) ) {
			require $view;
		}

		echo '</div>';
	}

	/**
	 * A hidden field marking which keys the current form is responsible for
	 * (see NPA_Settings::sanitize()).
	 *
	 * @param string[] $keys Setting keys on this form.
	 * @return void
	 */
	public function present_fields( array $keys ) {
		foreach ( $keys as $key ) {
			printf(
				'<input type="hidden" name="%s[_present][]" value="%s" />',
				esc_attr( NPA_Settings::OPTION ),
				esc_attr( $key )
			);
		}
	}

	/**
	 * Published agents for the picker; empty array if the gateway can't list
	 * them (no endpoint, bad credential) so the UI degrades to manual entry.
	 *
	 * @return NPA_Gateway_Agent[]
	 */
	public function available_agents() {
		try {
			return $this->plugin->gateway_client()->list_agents();
		} catch ( NPA_Gateway_Exception $e ) {
			return array();
		}
	}

	/**
	 * HTML for the Service Status roll-up (reused by the tab and, later, notices).
	 *
	 * @return string
	 */
	public function status_html() {
		$rows = $this->plugin->service_status->collect();
		$out  = '<table class="npa-status widefat striped"><tbody>';
		foreach ( $rows as $row ) {
			$ok    = ! empty( $row['ok'] );
			$pill  = $ok ? 'npa-pill--ok' : 'npa-pill--warn';
			$label = $ok ? __( 'OK', 'newtide-public-agent' ) : __( 'Attention', 'newtide-public-agent' );
			$out  .= '<tr>';
			$out  .= '<th scope="row">' . esc_html( $row['label'] ) . '</th>';
			$out  .= '<td><span class="npa-pill ' . esc_attr( $pill ) . '">' . esc_html( $label ) . '</span></td>';
			$out  .= '<td>' . esc_html( isset( $row['message'] ) ? $row['message'] : '' ) . '</td>';
			$out  .= '</tr>';
		}
		$out .= '</tbody></table>';
		return $out;
	}

	/**
	 * HTML for a test-battery snapshot (reused by the tab and the AJAX action).
	 *
	 * @param array|null $snapshot Snapshot from NPA_Test_Runner.
	 * @return string
	 */
	public function results_html( $snapshot ) {
		if ( empty( $snapshot ) || empty( $snapshot['suites'] ) ) {
			return '<p>' . esc_html__( 'No test results yet. Click “Run tests”.', 'newtide-public-agent' ) . '</p>';
		}

		$out = sprintf(
			'<p class="npa-tests-summary">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: passed checks, 2: total checks. */
					__( 'Passed %1$d of %2$d checks.', 'newtide-public-agent' ),
					(int) $snapshot['passed'],
					(int) $snapshot['total']
				)
			)
		);

		foreach ( $snapshot['suites'] as $suite ) {
			$all_pass = (int) $suite['passed'] === (int) $suite['total'];
			$pill     = $all_pass ? 'npa-pill--ok' : 'npa-pill--warn';

			$out .= '<div class="npa-suite">';
			$out .= '<h3>' . esc_html( $suite['label'] );
			$out .= ' <span class="npa-pill ' . esc_attr( $pill ) . '">' . esc_html( $suite['passed'] . '/' . $suite['total'] ) . '</span></h3>';
			$out .= '<p class="description">' . esc_html( $suite['why'] ) . '</p>';
			$out .= '<ul class="npa-checks">';
			foreach ( $suite['checks'] as $check ) {
				$pass  = ! empty( $check['pass'] );
				$mark  = $pass ? '✓' : '✕';
				$class = $pass ? 'npa-check--pass' : 'npa-check--fail';
				$out  .= '<li class="' . esc_attr( $class ) . '"><span class="npa-check-mark">' . esc_html( $mark ) . '</span> ' . esc_html( $check['label'] ) . '</li>';
			}
			$out .= '</ul></div>';
		}

		return $out;
	}

	// AJAX actions (capability + nonce on both).

	/**
	 * Run a gateway health check and report the result.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'newtide-public-agent' ) ), 403 );
		}

		$health = $this->plugin->gateway_client()->health_check();

		if ( $health->ok ) {
			$this->plugin->service_status->record_success( 'gateway' );
		} else {
			$this->plugin->service_status->record_failure( 'gateway' );
		}

		$this->plugin->logger->log(
			array(
				'agent_id'   => $this->plugin->settings->get_agent_id(),
				'latency_ms' => $health->latency_ms,
				'status'     => $health->ok ? 200 : 0,
				'note'       => 'health_check',
			)
		);

		wp_send_json_success(
			array(
				'ok'      => (bool) $health->ok,
				'message' => $health->message,
				'latency' => (int) $health->latency_ms,
			)
		);
	}

	/**
	 * Run the test battery and return rendered results.
	 *
	 * @return void
	 */
	public function ajax_run_tests() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'newtide-public-agent' ) ), 403 );
		}

		$snapshot = $this->plugin->test_runner->run_all();

		wp_send_json_success(
			array(
				'passed' => (int) $snapshot['passed'],
				'total'  => (int) $snapshot['total'],
				'html'   => $this->results_html( $snapshot ),
			)
		);
	}
}
