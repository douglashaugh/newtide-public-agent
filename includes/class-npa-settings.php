<?php
/**
 * Configuration: the single options array, defaults, sanitization, and the
 * secret-handling rules.
 *
 * One serialized option (`npa_options`) holds all non-secret config. The
 * gateway credential is handled separately and preferentially: a wp-config.php
 * constant (NPA_GATEWAY_KEY) that never touches the database, with a filter and
 * a write-only option field as fallbacks. The key is never rendered back to the
 * browser.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Settings
 */
class NPA_Settings {

	/**
	 * Option name holding the settings array.
	 *
	 * @var string
	 */
	const OPTION = 'npa_options';

	/**
	 * Settings group for the Settings API.
	 *
	 * @var string
	 */
	const GROUP = 'npa_settings_group';

	/**
	 * Allowed launcher positions.
	 *
	 * @var string[]
	 */
	const POSITIONS = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );

	/**
	 * Allowed colour-scheme themes ('auto' follows the visitor's OS setting).
	 *
	 * @var string[]
	 */
	const THEMES = array( 'auto', 'light', 'dark' );

	/**
	 * Allowed launcher shapes.
	 *
	 * @var string[]
	 */
	const SHAPES = array( 'pill', 'bubble' );

	/**
	 * Allowed launcher-icon sources.
	 *
	 * @var string[]
	 */
	const LAUNCHER_ICON_TYPES = array( 'default', 'image', 'emoji', 'builtin' );

	/**
	 * Allowed launcher sizes.
	 *
	 * @var string[]
	 */
	const LAUNCHER_SIZES = array( 'small', 'medium', 'large' );

	/**
	 * Allowed audience gates for widget visibility.
	 *
	 * @var string[]
	 */
	const AUDIENCES = array( 'everyone', 'logged_in', 'anonymous' );

	/**
	 * Connection modes. 'proxy' = the plugin's own widget through the server-side
	 * gateway; 'embed' = inject RisingTide's official agent-embed.js widget with a
	 * publishable key (M11).
	 *
	 * @var string[]
	 */
	const MODES = array( 'proxy', 'embed' );

	/**
	 * Embed-mode placement: floating site-wide bubble, or inline via the
	 * shortcode/block.
	 *
	 * @var string[]
	 */
	const PLACEMENTS = array( 'floating', 'inline' );

	/**
	 * Page-targeting scope: 'all' pages, or only a 'selected' set.
	 *
	 * @var string[]
	 */
	const PAGE_SCOPES = array( 'all', 'selected' );

	/**
	 * Register the setting with WordPress (sanitize callback lives here).
	 *
	 * The admin page fields are added later (M5); registering the setting now
	 * means any save — via options.php or update_option — is sanitized.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * Register the setting and its sanitize callback.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Default settings. Used on first run and as the sanitize baseline.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'                   => true,
			'agent_id'                  => NPA_Gateway_Client_Mock::DEFAULT_AGENT_ID,
			'gateway_base_url'          => '',
			'gateway_key'               => '', // Write-only fallback; prefer the NPA_GATEWAY_KEY constant.
			'launcher_label'            => __( 'Chat with us', 'newtide-public-agent' ),
			'greeting'                  => __( 'Hi! How can I help you today?', 'newtide-public-agent' ),
			'position'                  => 'bottom-right',
			'accent'                    => '#2563eb',
			// Connection (M11): transport + embed-mode config.
			'mode'                      => 'proxy',
			'public_key'                => '', // pk_ publishable key for embed mode (not secret).
			'platform_url'              => 'https://ai.newtide.ai', // PROD; override with NPA_PLATFORM_URL for internal UAT testing.
			'placement'                 => 'floating',
			// Appearance.
			'header_title'              => __( 'Chat with our agent', 'newtide-public-agent' ),
			'theme'                     => 'auto',
			'launcher_shape'            => 'pill',
			'launcher_size'             => 'medium',
			'launcher_icon_type'        => 'default', // default | image | emoji | builtin.
			'launcher_icon_id'          => 0,         // Attachment id when type = image.
			'launcher_icon_emoji'       => '',        // Emoji when type = emoji.
			'launcher_icon_builtin'     => 'chat',    // Built-in slug when type = builtin.
			'powered_by'                => true,
			// Behaviour.
			'auto_open_delay'           => 0, // Seconds; 0 = do not auto-open.
			'hide_on_mobile'            => false,
			'remember_state'            => false,
			'audience'                  => 'everyone',
			'exclude_ids'               => '', // Comma-separated post/page IDs to suppress on.
			'page_scope'                => 'all', // 'all' pages or only the 'selected' ones.
			'page_ids'                  => array(), // Selected page IDs when page_scope = 'selected'.
			// Additional page-targeted agents. Each entry is a map; see sanitize_agents().
			'agents'                    => array(),
			// Content / messaging.
			'input_placeholder'         => __( 'Type your message', 'newtide-public-agent' ),
			'suggested_prompts'         => '', // One prompt per line.
			'error_message'             => __( 'Sorry, something went wrong. Please try again.', 'newtide-public-agent' ),
			// Privacy / limits.
			'log_enabled'               => false,
			'store_transcripts'         => false,
			'transcript_retention_days' => 30,
			'daily_message_cap'         => 0, // 0 = unlimited.
		);
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array
	 */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value if the key is unknown.
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Sanitize a settings array: whitelist known keys, neutralize each value,
	 * and apply the write-only rule for the credential. Unknown keys dropped.
	 *
	 * @param mixed $input Raw input (typically from options.php).
	 * @return array Clean settings.
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = $this->all();
		$clean    = self::defaults();

		/*
		 * Tabbed forms submit only some fields. An optional `_present` list names
		 * the keys the submitted form was responsible for; any key not listed
		 * keeps its stored value (so saving one tab never clears another's
		 * checkboxes). Absent the marker (programmatic saves/tests), fall back
		 * to "a key is present if it appears in the input array".
		 */
		$present = isset( $input['_present'] ) && is_array( $input['_present'] )
			? array_map( 'sanitize_key', $input['_present'] )
			: null;

		$has = static function ( $key ) use ( $input, $present ) {
			return ( null === $present ) ? array_key_exists( $key, $input ) : in_array( $key, $present, true );
		};

		// Booleans.
		$clean['enabled']           = $has( 'enabled' ) ? ! empty( $input['enabled'] ) : (bool) $existing['enabled'];
		$clean['powered_by']        = $has( 'powered_by' ) ? ! empty( $input['powered_by'] ) : (bool) $existing['powered_by'];
		$clean['hide_on_mobile']    = $has( 'hide_on_mobile' ) ? ! empty( $input['hide_on_mobile'] ) : (bool) $existing['hide_on_mobile'];
		$clean['remember_state']    = $has( 'remember_state' ) ? ! empty( $input['remember_state'] ) : (bool) $existing['remember_state'];
		$clean['log_enabled']       = $has( 'log_enabled' ) ? ! empty( $input['log_enabled'] ) : (bool) $existing['log_enabled'];
		$clean['store_transcripts'] = $has( 'store_transcripts' ) ? ! empty( $input['store_transcripts'] ) : (bool) $existing['store_transcripts'];

		// Text / URL.
		$clean['agent_id']          = $has( 'agent_id' ) ? sanitize_text_field( $input['agent_id'] ) : $existing['agent_id'];
		$clean['gateway_base_url']  = $has( 'gateway_base_url' ) ? esc_url_raw( trim( (string) $input['gateway_base_url'] ) ) : $existing['gateway_base_url'];
		$clean['launcher_label']    = $has( 'launcher_label' ) ? sanitize_text_field( $input['launcher_label'] ) : $existing['launcher_label'];
		$clean['greeting']          = $has( 'greeting' ) ? sanitize_text_field( $input['greeting'] ) : $existing['greeting'];
		$clean['header_title']      = $has( 'header_title' ) ? sanitize_text_field( $input['header_title'] ) : $existing['header_title'];
		$clean['input_placeholder'] = $has( 'input_placeholder' ) ? sanitize_text_field( $input['input_placeholder'] ) : $existing['input_placeholder'];
		$clean['error_message']     = $has( 'error_message' ) ? sanitize_text_field( $input['error_message'] ) : $existing['error_message'];
		$clean['public_key']        = $has( 'public_key' ) ? sanitize_text_field( $input['public_key'] ) : $existing['public_key'];
		$clean['platform_url']      = $has( 'platform_url' ) ? esc_url_raw( trim( (string) $input['platform_url'] ) ) : $existing['platform_url'];

		// Suggested prompts: newline-separated, sanitized per line, blanks dropped.
		if ( $has( 'suggested_prompts' ) ) {
			$lines                      = preg_split( '/\r\n|\r|\n/', (string) $input['suggested_prompts'] );
			$lines                      = array_filter( array_map( 'sanitize_text_field', $lines ), 'strlen' );
			$clean['suggested_prompts'] = implode( "\n", array_slice( array_values( $lines ), 0, 6 ) );
		} else {
			$clean['suggested_prompts'] = $existing['suggested_prompts'];
		}

		// Exclude IDs: comma-separated positive integers, normalized.
		if ( $has( 'exclude_ids' ) ) {
			$ids                  = array_filter( array_map( 'absint', explode( ',', (string) $input['exclude_ids'] ) ) );
			$clean['exclude_ids'] = implode( ',', array_unique( $ids ) );
		} else {
			$clean['exclude_ids'] = $existing['exclude_ids'];
		}

		// Page-targeting scope + the selected page ids (checkbox list).
		$scope               = $has( 'page_scope' ) ? sanitize_key( $input['page_scope'] ) : $existing['page_scope'];
		$clean['page_scope'] = in_array( $scope, self::PAGE_SCOPES, true ) ? $scope : 'all';

		if ( $has( 'page_ids' ) ) {
			$raw_pages         = ( isset( $input['page_ids'] ) && is_array( $input['page_ids'] ) ) ? $input['page_ids'] : array();
			$clean['page_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $raw_pages ) ) ) );
		} else {
			$clean['page_ids'] = is_array( $existing['page_ids'] ) ? array_values( array_filter( array_map( 'absint', $existing['page_ids'] ) ) ) : array();
		}

		// Additional page-targeted agents (the Additional Agents tab).
		if ( $has( 'agents' ) ) {
			$raw_agents      = ( isset( $input['agents'] ) && is_array( $input['agents'] ) ) ? $input['agents'] : array();
			$clean['agents'] = self::sanitize_agents( $raw_agents );
		} else {
			$clean['agents'] = is_array( $existing['agents'] ) ? self::sanitize_agents( $existing['agents'] ) : array();
		}

		// Position whitelist.
		$position          = $has( 'position' ) ? sanitize_key( $input['position'] ) : $existing['position'];
		$clean['position'] = in_array( $position, self::POSITIONS, true ) ? $position : 'bottom-right';

		// Theme whitelist.
		$theme          = $has( 'theme' ) ? sanitize_key( $input['theme'] ) : $existing['theme'];
		$clean['theme'] = in_array( $theme, self::THEMES, true ) ? $theme : 'auto';

		// Launcher-shape whitelist.
		$shape                   = $has( 'launcher_shape' ) ? sanitize_key( $input['launcher_shape'] ) : $existing['launcher_shape'];
		$clean['launcher_shape'] = in_array( $shape, self::SHAPES, true ) ? $shape : 'pill';

		// Launcher-size whitelist.
		$size                   = $has( 'launcher_size' ) ? sanitize_key( $input['launcher_size'] ) : $existing['launcher_size'];
		$clean['launcher_size'] = in_array( $size, self::LAUNCHER_SIZES, true ) ? $size : 'medium';

		// Custom launcher icon: source type + the value for that source.
		$icon_type                   = $has( 'launcher_icon_type' ) ? sanitize_key( $input['launcher_icon_type'] ) : $existing['launcher_icon_type'];
		$clean['launcher_icon_type'] = in_array( $icon_type, self::LAUNCHER_ICON_TYPES, true ) ? $icon_type : 'default';

		$clean['launcher_icon_id'] = $has( 'launcher_icon_id' ) ? absint( $input['launcher_icon_id'] ) : (int) $existing['launcher_icon_id'];

		// Emoji is free text; keep tags out and cap at a few code points so it
		// stays a glyph, not a paragraph. sanitize_key() would strip the emoji.
		if ( $has( 'launcher_icon_emoji' ) ) {
			$emoji                        = trim( wp_strip_all_tags( (string) $input['launcher_icon_emoji'] ) );
			$clean['launcher_icon_emoji'] = function_exists( 'mb_substr' ) ? mb_substr( $emoji, 0, 8 ) : substr( $emoji, 0, 16 );
		} else {
			$clean['launcher_icon_emoji'] = $existing['launcher_icon_emoji'];
		}

		$builtin                        = $has( 'launcher_icon_builtin' ) ? sanitize_key( $input['launcher_icon_builtin'] ) : $existing['launcher_icon_builtin'];
		$clean['launcher_icon_builtin'] = NPA_Icons::is_valid( $builtin ) ? $builtin : 'chat';

		// Audience whitelist.
		$audience          = $has( 'audience' ) ? sanitize_key( $input['audience'] ) : $existing['audience'];
		$clean['audience'] = in_array( $audience, self::AUDIENCES, true ) ? $audience : 'everyone';

		// Connection mode whitelist.
		$mode          = $has( 'mode' ) ? sanitize_key( $input['mode'] ) : $existing['mode'];
		$clean['mode'] = in_array( $mode, self::MODES, true ) ? $mode : 'proxy';

		// Embed placement whitelist.
		$placement          = $has( 'placement' ) ? sanitize_key( $input['placement'] ) : $existing['placement'];
		$clean['placement'] = in_array( $placement, self::PLACEMENTS, true ) ? $placement : 'floating';

		// Accent hex.
		$accent          = $has( 'accent' ) ? sanitize_hex_color( (string) $input['accent'] ) : $existing['accent'];
		$clean['accent'] = $accent ? $accent : self::defaults()['accent'];

		// Integers.
		$retention                          = $has( 'transcript_retention_days' ) ? absint( $input['transcript_retention_days'] ) : (int) $existing['transcript_retention_days'];
		$clean['transcript_retention_days'] = min( 3650, max( 1, $retention ) );
		$clean['daily_message_cap']         = $has( 'daily_message_cap' ) ? absint( $input['daily_message_cap'] ) : (int) $existing['daily_message_cap'];

		// Auto-open delay: 0–600 seconds.
		$delay                    = $has( 'auto_open_delay' ) ? absint( $input['auto_open_delay'] ) : (int) $existing['auto_open_delay'];
		$clean['auto_open_delay'] = min( 600, $delay );

		// Credential — write-only and constant-aware.
		if ( defined( 'NPA_GATEWAY_KEY' ) ) {
			// Constant is authoritative; never persist a key to the database.
			$clean['gateway_key'] = '';
		} elseif ( ! $has( 'gateway_key' ) ) {
			$clean['gateway_key'] = $existing['gateway_key'];
		} else {
			$submitted            = trim( (string) $input['gateway_key'] );
			$clean['gateway_key'] = ( '' === $submitted ) ? $existing['gateway_key'] : sanitize_text_field( $submitted );
		}

		return $clean;
	}

	/**
	 * Sanitize the additional-agents repeater: a list of maps. Each is normalized
	 * to a fixed shape; entirely-empty rows are dropped and the list is capped.
	 * Overrides left blank mean "inherit the global setting" at render time.
	 *
	 * @param array $rows Raw rows from the form (or stored).
	 * @return array<int,array>
	 */
	public static function sanitize_agents( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$icon_types = array( 'inherit', 'default', 'image', 'emoji', 'builtin' );
		$out        = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$mode = ( isset( $row['mode'] ) && in_array( $row['mode'], self::MODES, true ) ) ? $row['mode'] : 'proxy';

			$page_ids = ( isset( $row['page_ids'] ) && is_array( $row['page_ids'] ) )
				? array_values( array_unique( array_filter( array_map( 'absint', $row['page_ids'] ) ) ) )
				: array();

			$name       = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			$agent_id   = isset( $row['agent_id'] ) ? sanitize_text_field( $row['agent_id'] ) : '';
			$public_key = isset( $row['public_key'] ) ? sanitize_text_field( $row['public_key'] ) : '';
			$greeting   = isset( $row['greeting'] ) ? sanitize_text_field( $row['greeting'] ) : '';
			$label      = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';

			$accent = isset( $row['accent'] ) ? sanitize_hex_color( (string) $row['accent'] ) : '';
			$accent = $accent ? $accent : '';

			$icon_type = isset( $row['icon_type'] ) ? sanitize_key( $row['icon_type'] ) : 'inherit';
			$icon_type = in_array( $icon_type, $icon_types, true ) ? $icon_type : 'inherit';
			$icon_id   = isset( $row['icon_id'] ) ? absint( $row['icon_id'] ) : 0;

			$icon_emoji = '';
			if ( isset( $row['icon_emoji'] ) ) {
				$icon_emoji = trim( wp_strip_all_tags( (string) $row['icon_emoji'] ) );
				$icon_emoji = function_exists( 'mb_substr' ) ? mb_substr( $icon_emoji, 0, 8 ) : substr( $icon_emoji, 0, 16 );
			}

			$icon_builtin = ( isset( $row['icon_builtin'] ) && NPA_Icons::is_valid( $row['icon_builtin'] ) ) ? $row['icon_builtin'] : 'chat';

			// Drop rows with no name, no target, and no pages — an empty add.
			$has_target = ( 'embed' === $mode ) ? ( '' !== $public_key ) : ( '' !== $agent_id );
			if ( '' === $name && ! $has_target && empty( $page_ids ) ) {
				continue;
			}

			$out[] = array(
				'name'         => $name,
				'mode'         => $mode,
				'agent_id'     => $agent_id,
				'public_key'   => $public_key,
				'page_ids'     => $page_ids,
				'accent'       => $accent,
				'greeting'     => $greeting,
				'label'        => $label,
				'icon_type'    => $icon_type,
				'icon_id'      => $icon_id,
				'icon_emoji'   => $icon_emoji,
				'icon_builtin' => $icon_builtin,
			);

			if ( count( $out ) >= 20 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The configured additional agents (normalized list).
	 *
	 * @return array<int,array>
	 */
	public function get_agents() {
		$rows = $this->get( 'agents', array() );
		return is_array( $rows ) ? self::sanitize_agents( $rows ) : array();
	}

	// Secret handling.

	/**
	 * Resolve the gateway credential: constant first, then filter, then the
	 * write-only option fallback. Never echoed to the browser.
	 *
	 * @return string
	 */
	public function get_gateway_key() {
		if ( defined( 'NPA_GATEWAY_KEY' ) && '' !== (string) NPA_GATEWAY_KEY ) {
			return (string) NPA_GATEWAY_KEY;
		}

		/**
		 * Filter the gateway credential (for hosting/enterprise injection).
		 *
		 * @param string $key The stored option value (may be empty).
		 */
		$key = apply_filters( 'npa_gateway_key', (string) $this->get( 'gateway_key', '' ) );

		return (string) $key;
	}

	/**
	 * Where the resolved key comes from: constant | filter | option | none.
	 *
	 * @return string
	 */
	public function key_source() {
		if ( defined( 'NPA_GATEWAY_KEY' ) && '' !== (string) NPA_GATEWAY_KEY ) {
			return 'constant';
		}
		$option = (string) $this->get( 'gateway_key', '' );
		$key    = $this->get_gateway_key();
		if ( '' === $key ) {
			return 'none';
		}
		return ( $key === $option ) ? 'option' : 'filter';
	}

	/**
	 * Whether a credential is configured (from any source).
	 *
	 * @return bool
	 */
	public function gateway_key_is_set() {
		return '' !== $this->get_gateway_key();
	}

	/**
	 * Resolve the gateway base URL: NPA_GATEWAY_BASE_URL constant overrides the
	 * stored option when defined.
	 *
	 * @return string
	 */
	public function get_gateway_base_url() {
		if ( defined( 'NPA_GATEWAY_BASE_URL' ) && '' !== (string) NPA_GATEWAY_BASE_URL ) {
			return (string) NPA_GATEWAY_BASE_URL;
		}
		return (string) $this->get( 'gateway_base_url', '' );
	}

	/**
	 * The configured agent id.
	 *
	 * @return string
	 */
	public function get_agent_id() {
		return (string) $this->get( 'agent_id', '' );
	}

	/**
	 * The connection mode ('proxy' | 'embed').
	 *
	 * @return string
	 */
	public function get_mode() {
		$mode = (string) $this->get( 'mode', 'proxy' );
		return in_array( $mode, self::MODES, true ) ? $mode : 'proxy';
	}

	/**
	 * The publishable embed key (pk_…). Not a secret: a constant override is
	 * offered only for parity/convenience, and the value may appear in page HTML.
	 *
	 * @return string
	 */
	public function get_public_key() {
		if ( defined( 'NPA_PUBLIC_KEY' ) && '' !== (string) NPA_PUBLIC_KEY ) {
			return (string) NPA_PUBLIC_KEY;
		}
		return (string) $this->get( 'public_key', '' );
	}

	/**
	 * The RisingTide platform URL that serves agent-embed.js. A
	 * NPA_PLATFORM_URL constant overrides the stored option.
	 *
	 * @return string
	 */
	public function get_platform_url() {
		if ( defined( 'NPA_PLATFORM_URL' ) && '' !== (string) NPA_PLATFORM_URL ) {
			return (string) NPA_PLATFORM_URL;
		}
		return (string) $this->get( 'platform_url', '' );
	}

	/**
	 * Whether embed mode has what it needs to render (mode + key + platform URL).
	 *
	 * @return bool
	 */
	public function is_embed_configured() {
		return 'embed' === $this->get_mode()
			&& '' !== $this->get_public_key()
			&& '' !== $this->get_platform_url();
	}

	/**
	 * Whether the plugin has the minimum config to talk to the gateway.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->get_gateway_base_url()
			&& '' !== $this->get_agent_id()
			&& $this->gateway_key_is_set();
	}
}
