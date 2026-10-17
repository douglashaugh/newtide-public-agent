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
	 * Where the "Powered by NewTide" credit points.
	 *
	 * One definition, used by the widget and by the Appearance preview, so the
	 * preview cannot drift from what a visitor gets.
	 *
	 * @var string
	 */
	const POWERED_BY_URL = 'https://www.newtide.ai';

	/**
	 * The NewTide logo in the credit, relative to the plugin root. Two files
	 * because the panel has two colour schemes: the brand navy on light, white
	 * lettering on dark, where navy would all but vanish. Both keep the blue.
	 *
	 * @var string[]
	 */
	const POWERED_BY_LOGOS = array(
		'light' => 'assets/images/newtide-logo.png',
		'dark'  => 'assets/images/newtide-logo-dark.png',
	);

	/**
	 * Footer credit markup, shared by the Appearance preview so the two
	 * cannot drift. The widget builds the same structure in JavaScript.
	 *
	 * @return string Escaped HTML.
	 */
	public static function powered_by_inner_html() {
		$html = '<span class="newtide-public-agent__powered-label">' . esc_html__( 'Powered by', 'newtide-public-agent' ) . '</span>';

		foreach ( self::POWERED_BY_LOGOS as $scheme => $path ) {
			$html .= sprintf(
				'<img class="newtide-public-agent__powered-logo newtide-public-agent__powered-logo--%1$s" src="%2$s" alt="%3$s" width="367" height="64" />',
				esc_attr( $scheme ),
				esc_url( NPA_PLUGIN_URL . $path ),
				esc_attr__( 'NewTide', 'newtide-public-agent' )
			);
		}

		return $html;
	}

	/**
	 * Widget script/style handle.
	 *
	 * @var string
	 */
	const HANDLE = 'npa-widget';

	/**
	 * Chart renderer handle; a dependency of the widget script.
	 *
	 * @var string
	 */
	const CHARTS_HANDLE = 'npa-widget-charts';

	/**
	 * Handle for RisingTide's injected embed loader (agent-embed.js).
	 *
	 * @var string
	 */
	const EMBED_HANDLE = 'npa-embed';

	/**
	 * Plugin instance.
	 *
	 * @var NPA_Plugin
	 */
	private $plugin;

	/**
	 * The container id for an inline embed placement (empty = floating bubble).
	 * agent-embed.js guards against a second instance per page, so at most one
	 * embed is wired per request.
	 *
	 * @var string
	 */
	private $embed_container = '';

	/**
	 * Whether the embed loader has already been enqueued this request.
	 *
	 * @var bool
	 */
	private $embed_enqueued = false;

	/**
	 * Sequence for unique inline mount ids.
	 *
	 * @var int
	 */
	private static $embed_seq = 0;

	/**
	 * The publishable key to stamp onto the embed loader for THIS request. Lets a
	 * page-matched additional agent inject its own key instead of the global one.
	 *
	 * @var string
	 */
	private $active_public_key = '';

	/**
	 * A resolved proxy config queued for auto-injection in the footer (a
	 * page-matched additional agent, or the site-wide floating widget). Null =
	 * nothing to inject.
	 *
	 * @var array|null
	 */
	private $pending_proxy_config = null;

	/**
	 * Whether a shortcode or block already mounted a proxy widget on this
	 * request. The site-wide bubble stands down when one has, so an author who
	 * placed the shortcode explicitly gets one widget rather than two.
	 *
	 * @var bool
	 */
	private $mounted_in_content = false;

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
		add_filter( 'script_loader_tag', array( $this, 'embed_script_tag' ), 10, 2 );
		// Auto-inject a page-matched additional agent's proxy widget in the footer.
		add_action( 'wp_footer', array( $this, 'render_auto_agent' ) );
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

		// Draws ```chart blocks in replies (0.16.0). A dependency of the widget,
		// so it is only ever loaded where the widget is.
		wp_register_script(
			self::CHARTS_HANDLE,
			NPA_PLUGIN_URL . 'assets/js/newtide-public-agent-charts.js',
			array(),
			NPA_VERSION,
			true
		);

		wp_register_script(
			self::HANDLE,
			NPA_PLUGIN_URL . 'assets/js/newtide-public-agent-public.js',
			array( self::CHARTS_HANDLE ),
			NPA_VERSION,
			true
		);

		$this->register_embed_assets();

		wp_localize_script(
			self::HANDLE,
			'NPA_WIDGET',
			array(
				'restUrl' => esc_url_raw( rest_url( 'npa/v1/message' ) ),
				'reportUrl' => esc_url_raw( rest_url( 'npa/v1/report' ) ),
				// Answer feedback (0.18.0): thumbs under each reply.
				'feedbackUrl' => esc_url_raw( rest_url( 'npa/v1/feedback' ) ),
				'feedback'    => (bool) NPA_Plugin::instance()->settings->get( 'feedback', true ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				// Turn diagnostics (0.16.1). The widget waits a little longer
				// than the server does, so the server's own error wins a race.
				'diagnostics'   => (bool) NPA_Plugin::instance()->settings->get( 'diagnostics', true ),
				'clientTimeout' => ( NPA_Plugin::instance()->settings->agent_timeout() + 45 ) * 1000,
				// Streaming (0.17.0). A streamed reply may run to its total
				// limit, so the widget's own deadline sits past that.
				'stream'        => (bool) NPA_Plugin::instance()->settings->get( 'streaming', true ),
				'streamTimeout' => ( NPA_Gateway_Client_Agent_Api::stream_limit( NPA_Plugin::instance()->settings->agent_timeout() ) + 30 ) * 1000,
				'locale'  => get_locale(),
				/**
				 * Filter the address the "Powered by NewTide" credit links to.
				 *
				 * Returning '' renders the credit as plain text, which is what
				 * it was before 0.9.1 — the credit can be switched off entirely
				 * under Appearance, so a site that wants it without an outbound
				 * link can have that too.
				 *
				 * @param string $url Destination.
				 */
				'poweredUrl' => apply_filters( 'npa_powered_by_url', NPA_Public::POWERED_BY_URL ),
				'poweredLogos' => array(
					'light' => NPA_PLUGIN_URL . NPA_Public::POWERED_BY_LOGOS['light'],
					'dark'  => NPA_PLUGIN_URL . NPA_Public::POWERED_BY_LOGOS['dark'],
				),
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
					'poweredByLabel' => __( 'Powered by', 'newtide-public-agent' ),
					'poweredByLogo'  => __( 'NewTide', 'newtide-public-agent' ),
					'tryAsking'  => __( 'Try asking', 'newtide-public-agent' ),
					'chartData'   => __( 'Show data', 'newtide-public-agent' ),
					'chartFailed' => __( 'Chart data (could not be drawn)', 'newtide-public-agent' ),
					'chartLine'   => __( 'Line chart', 'newtide-public-agent' ),
					'chartBar'    => __( 'Bar chart', 'newtide-public-agent' ),
					'chartSeries' => __( 'Series', 'newtide-public-agent' ),
					'chartKeys'   => __( 'Use the arrow keys to read each value, or open Show data for the table.', 'newtide-public-agent' ),
					'copy'       => __( 'Copy', 'newtide-public-agent' ),
					'copyAll'    => __( 'Copy the whole conversation', 'newtide-public-agent' ),
					'copyTurn'   => __( 'Copy this question and answer', 'newtide-public-agent' ),
					'copied'     => __( 'Copied', 'newtide-public-agent' ),
					'copyFailed' => __( 'Could not copy. Try selecting the text instead.', 'newtide-public-agent' ),
					'rateLabel'  => __( 'Was this helpful?', 'newtide-public-agent' ),
					'rateUp'     => __( 'Helpful', 'newtide-public-agent' ),
					'rateDown'   => __( 'Not helpful', 'newtide-public-agent' ),
					'rateWhy'    => __( 'What was wrong? (optional)', 'newtide-public-agent' ),
					'rateSend'   => __( 'Send', 'newtide-public-agent' ),
					'rateThanks' => __( 'Thanks for the feedback.', 'newtide-public-agent' ),
					/* translators: %s: short reference code for a failed message. */
					'reference'  => __( 'Reference: %s', 'newtide-public-agent' ),
					'waitWorking' => __( 'Working on it…', 'newtide-public-agent' ),
					'waitLong'   => __( 'Still working. Questions that need data can take up to a minute.', 'newtide-public-agent' ),
					'you'        => __( 'You', 'newtide-public-agent' ),
					'assistant'  => __( 'Assistant', 'newtide-public-agent' ),
					'transcript' => __( 'Chat transcript', 'newtide-public-agent' ),
					'newChat'   => __( 'New chat', 'newtide-public-agent' ),
					'resize'    => __( 'Resize chat. Use the arrow keys to adjust, or drag.', 'newtide-public-agent' ),
				),
			)
		);

		$this->decide_auto_injection();
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
	 * Register RisingTide's embed loader and, for a floating placement, enqueue
	 * it site-wide when gated (mode + config + visibility). Called from
	 * register_assets() on wp_enqueue_scripts.
	 *
	 * @return void
	 */
	private function register_embed_assets() {
		$s        = $this->plugin->settings;
		$platform = $s->get_platform_url();

		if ( '' !== $platform ) {
			// Remote loader; no version query string, printed in the footer.
			wp_register_script(
				self::EMBED_HANDLE,
				trailingslashit( $platform ) . 'agent-embed.js',
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- remote loader, versioned by the platform.
				true
			);
		}
	}

	/**
	 * Decide what auto-injects on this request. A page-matched additional agent
	 * wins on its pages; otherwise the global embed floating bubble behaves as
	 * before. Runs at the tail of register_assets() (on wp_enqueue_scripts), so
	 * proxy assets enqueue here and the markup renders later in the footer.
	 *
	 * @return void
	 */
	private function decide_auto_injection() {
		$s = $this->plugin->settings;

		$active = $this->active_additional_agent();
		if ( $active && $this->passes_common_gates() ) {
			$this->inject_additional_agent( $active );
			return;
		}

		// Nothing site-wide unless the widget is on, set to float, and allowed here.
		if ( ! $s->get( 'enabled' )
			|| 'floating' !== $s->get( 'placement' )
			|| ! $this->should_display() ) {
			return;
		}

		if ( 'embed' === $s->get_mode() ) {
			if ( $s->is_embed_configured() ) {
				$this->active_public_key = $s->get_public_key();
				$this->enqueue_embed( '' );
			}
			return;
		}

		/*
		 * Proxy mode, floating: the site-wide bubble. This previously existed only
		 * for Embed, so a Proxy site with the widget enabled and scoped to all
		 * pages rendered nothing at all unless a shortcode, block or additional
		 * agent placed it — while the admin offered an enable switch, a page
		 * scope and four launcher positions, all of which implied otherwise.
		 *
		 * The markup is queued rather than emitted: render_auto_agent() runs on
		 * wp_footer, after the content, so it can stand down if a shortcode or
		 * block already mounted a widget on this page and avoid a double render.
		 */
		$config = $this->resolve_config( array() );
		if ( '' !== $config['agent'] ) {
			$this->pending_proxy_config = $config;
			$this->enqueue();
		}
	}

	/**
	 * The first additional agent configured to target the current page, or null.
	 *
	 * @return array|null
	 */
	private function active_additional_agent() {
		if ( ! $this->plugin->settings->get( 'enabled' ) ) {
			return null;
		}
		$current = (int) get_queried_object_id();
		if ( ! $current ) {
			return null;
		}
		foreach ( $this->plugin->settings->get_agents() as $agent ) {
			$pages = isset( $agent['page_ids'] ) ? array_map( 'absint', (array) $agent['page_ids'] ) : array();

			if ( ! in_array( $current, $pages, true ) ) {
				continue;
			}

			/*
			 * A row this visitor is not the audience for does not claim the
			 * page. It is skipped rather than returned-and-blocked, so a later
			 * row can match and, failing that, the site-wide agent still
			 * appears — a logged-out visitor on a members-only agent's page
			 * gets the public agent rather than no help at all. The site-wide
			 * one is still subject to its own audience and page rules.
			 */
			if ( ! NPA_Settings::audience_allows( $this->plugin->settings->agent_audience( $agent ) ) ) {
				continue;
			}

			return $agent;
		}
		return null;
	}

	/**
	 * The audience gate — the check shared by the primary widget and every
	 * additional agent (i.e. everything except the primary's own page allowlist).
	 *
	 * A per-page exclusion list used to live here too. It was removed: page
	 * targeting belongs to the Agent tab's allowlist, and two rules deciding the
	 * same thing could disagree, with nothing on screen explaining which won.
	 *
	 * @return bool
	 */
	private function passes_common_gates() {
		$s = $this->plugin->settings;

		return NPA_Settings::audience_allows( $s->get( 'audience', 'everyone' ) );
	}

	/**
	 * Wire up a page-matched additional agent: embed injects its own key; proxy
	 * enqueues assets and queues its resolved config for footer output.
	 *
	 * @param array $agent Sanitized additional-agent row.
	 * @return void
	 */
	private function inject_additional_agent( array $agent ) {
		$s   = $this->plugin->settings;
		$key = isset( $agent['public_key'] ) ? trim( (string) $agent['public_key'] ) : '';

		// A row without its own key cannot reach an agent: the key is what
		// selects one, in both transports.
		if ( '' === $key ) {
			return;
		}

		if ( 'embed' === $s->get_mode() ) {
			if ( '' !== $s->get_platform_url() ) {
				$this->active_public_key = $key;
				$this->enqueue_embed( '' );
			}
			return;
		}

		$config = $this->resolve_agent_config( $agent );
		if ( '' !== $config['agent'] ) {
			$this->pending_proxy_config = $config;
			$this->enqueue();
		}
	}

	/**
	 * Merge an additional agent's non-empty overrides over the global config. A
	 * blank override inherits the global Appearance/messaging value.
	 *
	 * @param array $agent Sanitized additional-agent row.
	 * @return array Resolved config for mount_html().
	 */
	private function resolve_agent_config( array $agent ) {
		$config = $this->resolve_config( array() );

		$key = isset( $agent['public_key'] ) ? trim( (string) $agent['public_key'] ) : '';
		if ( '' !== $key ) {
			$config['agent'] = NPA_Settings::key_fingerprint( $key );
		}
		if ( '' !== trim( (string) $agent['greeting'] ) ) {
			$config['greeting'] = sanitize_text_field( (string) $agent['greeting'] );
		}
		if ( '' !== trim( (string) $agent['label'] ) ) {
			$config['label'] = sanitize_text_field( (string) $agent['label'] );
		}
		$accent = sanitize_hex_color( (string) $agent['accent'] );
		if ( $accent ) {
			$config['accent'] = $accent;
		}

		$icon_type = isset( $agent['icon_type'] ) ? (string) $agent['icon_type'] : 'inherit';
		if ( 'inherit' !== $icon_type && '' !== $icon_type ) {
			$config['icon_type']    = $icon_type;
			$config['icon_id']      = (int) $agent['icon_id'];
			$config['icon_emoji']   = (string) $agent['icon_emoji'];
			$config['icon_builtin'] = (string) $agent['icon_builtin'];
		}

		return $config;
	}

	/**
	 * Output a page-matched additional agent's proxy widget in the footer. No-op
	 * unless decide_auto_injection() queued one for this request.
	 *
	 * @return void
	 */
	public function render_auto_agent() {
		if ( null === $this->pending_proxy_config || $this->mounted_in_content ) {
			return;
		}
		// mount_html escapes each attribute and emits static/curated icon markup.
		echo $this->mount_html( $this->pending_proxy_config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Enqueue the embed loader once. A non-empty container id switches
	 * agent-embed.js to inline mounting; empty = the floating bubble.
	 *
	 * @param string $container Target element id, or '' for floating.
	 * @return void
	 */
	private function enqueue_embed( $container ) {
		if ( $this->embed_enqueued ) {
			return;
		}
		if ( '' !== $container ) {
			$this->embed_container = $container;
		}
		wp_enqueue_script( self::EMBED_HANDLE );
		$this->embed_enqueued = true;
	}

	/**
	 * Append the publishable key (and, for inline, the container) to the embed
	 * loader's <script> tag. Only the embed handle is touched; the secret gateway
	 * credential is never involved here.
	 *
	 * @param string $tag    The full <script> HTML.
	 * @param string $handle The script handle.
	 * @return string
	 */
	public function embed_script_tag( $tag, $handle ) {
		if ( self::EMBED_HANDLE !== $handle ) {
			return $tag;
		}

		$key = '' !== $this->active_public_key ? $this->active_public_key : $this->plugin->settings->get_public_key();
		if ( '' === $key ) {
			return $tag;
		}

		$attrs = ' data-api-key="' . esc_attr( $key ) . '"';
		if ( '' !== $this->embed_container ) {
			$attrs .= ' data-container="' . esc_attr( $this->embed_container ) . '"';
		}

		return str_replace( ' src=', $attrs . ' src=', $tag );
	}

	/**
	 * Render an embed-mode placement for the shortcode/block. Floating auto-injects
	 * site-wide (so a placement is a no-op); inline outputs a mount node and wires
	 * the loader to it.
	 *
	 * @return string
	 */
	private function render_embed_placement() {
		$s = $this->plugin->settings;

		if ( ! $s->is_embed_configured() ) {
			return '';
		}

		if ( 'inline' !== $s->get( 'placement' ) ) {
			return '';
		}

		$id = 'npa-embed-mount-' . ( ++self::$embed_seq );
		$this->enqueue_embed( $id );

		return '<div id="' . esc_attr( $id ) . '" class="newtide-public-agent-embed"></div>';
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

		// Audience gate (shared with additional agents).
		if ( ! $this->passes_common_gates() ) {
			return false;
		}

		// Page allowlist (Agent tab): when scoped to selected pages, show only on
		// those. Empty selection means nowhere.
		if ( 'selected' === (string) $s->get( 'page_scope', 'all' ) ) {
			$allowed = array_filter( array_map( 'absint', (array) $s->get( 'page_ids', array() ) ) );
			$current = (int) get_queried_object_id();
			if ( ! $current || ! in_array( $current, $allowed, true ) ) {
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

		/*
		 * 'agent' is a reference to a configured agent, not an id the API uses:
		 * the publishable key selects the agent upstream. Naming the key by
		 * fingerprint lets the proxy look up which key to call with, while the
		 * browser only ever names a row this site has stored.
		 */
		$defaults = array(
			'agent'    => $s->agent_reference(),
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
		$size  = (string) $s->get( 'launcher_size', 'medium' );
		$size  = in_array( $size, NPA_Settings::LAUNCHER_SIZES, true ) ? $size : 'medium';

		$panel_size = (string) $s->get( 'panel_size', 'standard' );
		$panel_size = in_array( $panel_size, NPA_Settings::PANEL_SIZES, true ) ? $panel_size : 'standard';

		$prompts = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $s->get( 'suggested_prompts', '' ) ) ), 'strlen' );
		// A list saved before 0.16.0 may hold up to six; show the first three.
		$prompts = array_slice( array_values( $prompts ), 0, NPA_Settings::MAX_STARTERS );

		return array(
			'agent'        => sanitize_text_field( $merged['agent'] ),
			'greeting'     => sanitize_text_field( $merged['greeting'] ),
			'label'        => sanitize_text_field( $merged['label'] ),
			'position'     => $position,
			'accent'       => $accent ? $accent : '#2563eb',
			'header'       => sanitize_text_field( (string) $s->get( 'header_title' ) ),
			'theme'        => $theme,
			'shape'        => $shape,
			'size'         => $size,
			'icon_type'    => (string) $s->get( 'launcher_icon_type', 'default' ),
			'icon_id'      => (int) $s->get( 'launcher_icon_id', 0 ),
			'icon_emoji'   => (string) $s->get( 'launcher_icon_emoji', '' ),
			'icon_builtin' => (string) $s->get( 'launcher_icon_builtin', 'chat' ),
			'powered'      => (bool) $s->get( 'powered_by' ),
			'panel_size'   => $panel_size,
			'allow_resize' => (bool) $s->get( 'allow_resize' ),
			'auto_grow'    => (bool) $s->get( 'auto_grow' ),
			'copy'         => (bool) $s->get( 'copy_buttons' ),
			'charts'       => (bool) $s->get( 'charts' ),
			'auto_open'    => (int) $s->get( 'auto_open_delay', 0 ),
			'hide_mobile'  => (bool) $s->get( 'hide_on_mobile' ),
			'remember'     => (bool) $s->get( 'remember_state' ),
			'placeholder'  => sanitize_text_field( (string) $s->get( 'input_placeholder' ) ),
			'prompts'      => array_values( $prompts ),
			'error'        => sanitize_text_field( (string) $s->get( 'error_message' ) ),
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
			'newtide-public-agent--size-' . $config['size'],
			'newtide-public-agent--panel-' . $config['panel_size'],
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
			// Signs the agent id so the proxy will route to it (see NPA_Rest::agent_token).
			// Without this a per-page or shortcode agent silently answers as the default.
			'data-agent-token' => NPA_Rest::agent_token( $config['agent'] ),
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
			'data-allow-resize' => $config['allow_resize'] ? '1' : '0',
			'data-auto-grow'   => $config['auto_grow'] ? '1' : '0',
			'data-copy'        => $config['copy'] ? '1' : '0',
			'data-charts'      => $config['charts'] ? '1' : '0',
			'style'            => '--npa-accent:' . $config['accent'],
		);

		$attr_html = '';
		foreach ( $attrs as $name => $value ) {
			$attr_html .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		// The launcher glyph: the author's custom icon (image/emoji/built-in) or the
		// default chat glyph. CSS shows it for the bubble shape; the pill shows text.
		$icon = $this->launcher_icon_html( $config );

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
	 * Resolve the launcher icon markup from the chosen source. Falls back to the
	 * built-in chat glyph whenever a custom source is selected but unusable (image
	 * deleted, emoji blank), so the launcher is never iconless.
	 *
	 * @param array $config Resolved config.
	 * @return string
	 */
	private function launcher_icon_html( array $config ) {
		switch ( $config['icon_type'] ) {
			case 'image':
				if ( $config['icon_id'] > 0 ) {
					$url = wp_get_attachment_image_url( $config['icon_id'], array( 64, 64 ) );
					if ( $url ) {
						return '<img class="newtide-public-agent__launcher-icon newtide-public-agent__launcher-icon--image" src="' . esc_url( $url ) . '" alt="" aria-hidden="true" />';
					}
				}
				break;
			case 'emoji':
				$emoji = trim( (string) $config['icon_emoji'] );
				if ( '' !== $emoji ) {
					return '<span class="newtide-public-agent__launcher-icon newtide-public-agent__launcher-icon--emoji" aria-hidden="true">' . esc_html( $emoji ) . '</span>';
				}
				break;
			case 'builtin':
				return NPA_Icons::svg( $config['icon_builtin'] );
		}

		return NPA_Icons::svg( 'chat' );
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

		if ( 'embed' === $this->plugin->settings->get_mode() ) {
			return $this->render_embed_placement();
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

		// Claim the page so the site-wide bubble does not also render.
		$this->mounted_in_content = true;

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

		if ( 'embed' === $this->plugin->settings->get_mode() ) {
			return $this->render_embed_placement();
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

		// Claim the page so the site-wide bubble does not also render.
		$this->mounted_in_content = true;

		$this->enqueue();
		return $html;
	}
}
