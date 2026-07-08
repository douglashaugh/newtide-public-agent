<?php
/**
 * Front-end surface: the [newtide_agent] shortcode, the Gutenberg block, and
 * the widget asset enqueue.
 *
 * Assets load only on pages that actually place the widget. The browser
 * receives non-secret config + the REST nonce only — never the gateway key.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Public
 */
class NPA_Public {

	/**
	 * Widget script/style handle.
	 *
	 * @var string
	 */
	const HANDLE = 'npa-widget';

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
	 * Register front-end hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'newtide_agent', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register (not enqueue) the widget assets and attach non-secret config.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			self::HANDLE,
			NPA_PLUGIN_URL . 'assets/css/newtide-public-agent-public.css',
			array(),
			NPA_VERSION
		);

		wp_register_script(
			self::HANDLE,
			NPA_PLUGIN_URL . 'assets/js/newtide-public-agent-public.js',
			array(),
			NPA_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'NPA_WIDGET',
			array(
				'restUrl' => esc_url_raw( rest_url( 'npa/v1/message' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'locale'  => get_locale(),
				'i18n'    => array(
					'send'     => __( 'Send', 'newtide-public-agent' ),
					'close'    => __( 'Close chat', 'newtide-public-agent' ),
					'input'    => __( 'Type your message', 'newtide-public-agent' ),
					'typing'   => __( 'Assistant is typing…', 'newtide-public-agent' ),
					'error'    => __( 'Sorry, something went wrong. Please try again.', 'newtide-public-agent' ),
					'dialog'   => __( 'Chat with our agent', 'newtide-public-agent' ),
					'sent'     => __( 'You said', 'newtide-public-agent' ),
					'received' => __( 'Assistant replied', 'newtide-public-agent' ),
				),
			)
		);
	}

	/**
	 * Enqueue the widget assets on demand (called from a render path).
	 *
	 * @return void
	 */
	private function enqueue() {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Resolve a placement's config from global defaults + per-placement values.
	 *
	 * @param array $overrides Raw attribute overrides (may be empty).
	 * @return array Sanitized config.
	 */
	private function resolve_config( array $overrides ) {
		$s = $this->plugin->settings;

		$defaults = array(
			'agent'    => $s->get_agent_id(),
			'greeting' => $s->get( 'greeting' ),
			'label'    => $s->get( 'launcher_label' ),
			'position' => $s->get( 'position' ),
			'accent'   => $s->get( 'accent' ),
		);

		$merged = array_merge( $defaults, array_filter( $overrides, 'strlen' ) );

		$position = in_array( $merged['position'], NPA_Settings::POSITIONS, true ) ? $merged['position'] : 'bottom-right';
		$accent   = sanitize_hex_color( $merged['accent'] );

		return array(
			'agent'    => sanitize_text_field( $merged['agent'] ),
			'greeting' => sanitize_text_field( $merged['greeting'] ),
			'label'    => sanitize_text_field( $merged['label'] ),
			'position' => $position,
			'accent'   => $accent ? $accent : '#2563eb',
		);
	}

	/**
	 * Build the widget mount markup for a resolved config.
	 *
	 * @param array $config Sanitized config.
	 * @return string
	 */
	private function mount_html( array $config ) {
		if ( '' === $config['agent'] ) {
			return '';
		}

		return sprintf(
			'<div class="newtide-public-agent newtide-public-agent--%1$s" data-npa-widget data-agent="%2$s" data-greeting="%3$s" data-label="%4$s" data-position="%1$s" style="--npa-accent:%5$s">'
				. '<button type="button" class="newtide-public-agent__launcher" aria-haspopup="dialog" aria-expanded="false">%4$s</button>'
				. '</div>',
			esc_attr( $config['position'] ),
			esc_attr( $config['agent'] ),
			esc_attr( $config['greeting'] ),
			esc_attr( $config['label'] ),
			esc_attr( $config['accent'] )
		);
	}

	/**
	 * Shortcode handler: [newtide_agent agent="" greeting="" label="" position="" accent=""].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		if ( ! $this->plugin->settings->get( 'enabled' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'agent'    => '',
				'greeting' => '',
				'label'    => '',
				'position' => '',
				'accent'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			'newtide_agent'
		);

		$config = $this->resolve_config( $atts );
		$html   = $this->mount_html( $config );

		if ( '' === $html ) {
			return '';
		}

		$this->enqueue();
		return $html;
	}

	/**
	 * Register the Gutenberg block (dynamic; PHP renders the front end).
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			NPA_PLUGIN_DIR . 'src/block',
			array( 'render_callback' => array( $this, 'render_block' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		if ( ! $this->plugin->settings->get( 'enabled' ) ) {
			return '';
		}

		$attributes = is_array( $attributes ) ? $attributes : array();

		$config = $this->resolve_config(
			array(
				'agent'    => isset( $attributes['agent'] ) ? (string) $attributes['agent'] : '',
				'greeting' => isset( $attributes['greeting'] ) ? (string) $attributes['greeting'] : '',
				'label'    => isset( $attributes['label'] ) ? (string) $attributes['label'] : '',
				'position' => isset( $attributes['position'] ) ? (string) $attributes['position'] : '',
				'accent'   => isset( $attributes['accent'] ) ? (string) $attributes['accent'] : '',
			)
		);

		$html = $this->mount_html( $config );
		if ( '' === $html ) {
			return '';
		}

		$this->enqueue();
		return $html;
	}
}
