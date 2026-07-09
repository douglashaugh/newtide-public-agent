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
					'send'      => __( 'Send', 'newtide-public-agent' ),
					'close'     => __( 'Close chat', 'newtide-public-agent' ),
					'input'     => __( 'Type your message', 'newtide-public-agent' ),
					'typing'    => __( 'Assistant is typing…', 'newtide-public-agent' ),
					'error'     => __( 'Sorry, something went wrong. Please try again.', 'newtide-public-agent' ),
					'dialog'    => __( 'Chat with our agent', 'newtide-public-agent' ),
					'sent'      => __( 'You said', 'newtide-public-agent' ),
					'received'  => __( 'Assistant replied', 'newtide-public-agent' ),
					'poweredBy' => __( 'Powered by NewTide', 'newtide-public-agent' ),
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
	 * Whether the widget should render for the current request, per the
	 * audience gate and the per-page exclusion list. Placement (shortcode /
	 * block) already implies the author wants it here; this is the extra
	 * server-side suppression layer.
	 *
	 * @return bool
	 */
	private function should_display() {
		$s = $this->plugin->settings;

		// Audience gate.
		$audience = (string) $s->get( 'audience', 'everyone' );
		if ( 'logged_in' === $audience && ! is_user_logged_in() ) {
			return false;
		}
		if ( 'anonymous' === $audience && is_user_logged_in() ) {
			return false;
		}

		// Per-page exclusion list.
		$raw = (string) $s->get( 'exclude_ids', '' );
		if ( '' !== $raw ) {
			$excluded = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
			$current  = (int) get_queried_object_id();
			if ( $current && in_array( $current, $excluded, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve a placement's config from global defaults + per-placement values.
	 *
	 * The per-placement overrides cover the original five presentation
	 * attributes; the newer options are global (admin-only) and always read
	 * straight from settings.
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

		$theme = (string) $s->get( 'theme', 'auto' );
		$theme = in_array( $theme, NPA_Settings::THEMES, true ) ? $theme : 'auto';
		$shape = (string) $s->get( 'launcher_shape', 'pill' );
		$shape = in_array( $shape, NPA_Settings::SHAPES, true ) ? $shape : 'pill';

		$prompts = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $s->get( 'suggested_prompts', '' ) ) ), 'strlen' );

		return array(
			'agent'       => sanitize_text_field( $merged['agent'] ),
			'greeting'    => sanitize_text_field( $merged['greeting'] ),
			'label'       => sanitize_text_field( $merged['label'] ),
			'position'    => $position,
			'accent'      => $accent ? $accent : '#2563eb',
			'header'      => sanitize_text_field( (string) $s->get( 'header_title' ) ),
			'theme'       => $theme,
			'shape'       => $shape,
			'powered'     => (bool) $s->get( 'powered_by' ),
			'auto_open'   => (int) $s->get( 'auto_open_delay', 0 ),
			'hide_mobile' => (bool) $s->get( 'hide_on_mobile' ),
			'remember'    => (bool) $s->get( 'remember_state' ),
			'placeholder' => sanitize_text_field( (string) $s->get( 'input_placeholder' ) ),
			'prompts'     => array_values( $prompts ),
			'error'       => sanitize_text_field( (string) $s->get( 'error_message' ) ),
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

		$classes = array(
			'newtide-public-agent',
			'newtide-public-agent--' . $config['position'],
			'newtide-public-agent--shape-' . $config['shape'],
		);
		if ( 'auto' !== $config['theme'] ) {
			$classes[] = 'newtide-public-agent--theme-' . $config['theme'];
		}
		if ( $config['hide_mobile'] ) {
			$classes[] = 'newtide-public-agent--hide-mobile';
		}

		$attrs = array(
			'class'            => implode( ' ', $classes ),
			'data-npa-widget'  => '',
			'data-agent'       => $config['agent'],
			'data-greeting'    => $config['greeting'],
			'data-label'       => $config['label'],
			'data-header'      => $config['header'],
			'data-position'    => $config['position'],
			'data-placeholder' => $config['placeholder'],
			'data-error'       => $config['error'],
			'data-prompts'     => implode( "\n", $config['prompts'] ),
			'data-auto-open'   => (string) max( 0, $config['auto_open'] ),
			'data-remember'    => $config['remember'] ? '1' : '0',
			'data-powered'     => $config['powered'] ? '1' : '0',
			'style'            => '--npa-accent:' . $config['accent'],
		);

		$attr_html = '';
		foreach ( $attrs as $name => $value ) {
			$attr_html .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		// A chat glyph for the bubble launcher; CSS shows icon or text per shape.
		$icon = '<svg class="newtide-public-agent__launcher-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 3C6.5 3 2 6.7 2 11.2c0 2.3 1.2 4.4 3.1 5.8-.1 1-.6 2.4-1.6 3.5 1.6-.2 3.3-.8 4.6-1.8 1.2.4 2.5.6 3.9.6 5.5 0 10-3.7 10-8.1S17.5 3 12 3Z"/></svg>';

		return sprintf(
			'<div%1$s>'
				. '<button type="button" class="newtide-public-agent__launcher" aria-haspopup="dialog" aria-expanded="false" aria-label="%2$s">'
					. '%3$s<span class="newtide-public-agent__launcher-text">%2$s</span>'
				. '</button>'
				. '</div>',
			$attr_html,
			esc_attr( $config['label'] ),
			$icon
		);
	}

	/**
	 * Shortcode handler: [newtide_agent agent="" greeting="" label="" position="" accent=""].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		if ( ! $this->plugin->settings->get( 'enabled' ) || ! $this->should_display() ) {
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
		if ( ! $this->plugin->settings->get( 'enabled' ) || ! $this->should_display() ) {
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
