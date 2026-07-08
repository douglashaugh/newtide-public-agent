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
	const POSITIONS = array( 'bottom-right', 'bottom-left' );

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

		$clean['enabled']           = ! empty( $input['enabled'] );
		$clean['log_enabled']       = ! empty( $input['log_enabled'] );
		$clean['store_transcripts'] = ! empty( $input['store_transcripts'] );

		if ( isset( $input['agent_id'] ) ) {
			$clean['agent_id'] = sanitize_text_field( $input['agent_id'] );
		} else {
			$clean['agent_id'] = $existing['agent_id'];
		}

		if ( isset( $input['gateway_base_url'] ) ) {
			$clean['gateway_base_url'] = esc_url_raw( trim( $input['gateway_base_url'] ) );
		} else {
			$clean['gateway_base_url'] = $existing['gateway_base_url'];
		}

		if ( isset( $input['launcher_label'] ) ) {
			$clean['launcher_label'] = sanitize_text_field( $input['launcher_label'] );
		} else {
			$clean['launcher_label'] = $existing['launcher_label'];
		}

		if ( isset( $input['greeting'] ) ) {
			$clean['greeting'] = sanitize_text_field( $input['greeting'] );
		} else {
			$clean['greeting'] = $existing['greeting'];
		}

		$position          = isset( $input['position'] ) ? sanitize_key( $input['position'] ) : $existing['position'];
		$clean['position'] = in_array( $position, self::POSITIONS, true ) ? $position : 'bottom-right';

		$accent          = isset( $input['accent'] ) ? sanitize_hex_color( $input['accent'] ) : $existing['accent'];
		$clean['accent'] = $accent ? $accent : self::defaults()['accent'];

		$retention = isset( $input['transcript_retention_days'] ) ? absint( $input['transcript_retention_days'] ) : (int) $existing['transcript_retention_days'];
		$clean['transcript_retention_days'] = min( 3650, max( 1, $retention ) );

		$clean['daily_message_cap'] = isset( $input['daily_message_cap'] ) ? absint( $input['daily_message_cap'] ) : (int) $existing['daily_message_cap'];

		// Credential — write-only and constant-aware.
		if ( defined( 'NPA_GATEWAY_KEY' ) ) {
			// Constant is authoritative; never persist a key to the database.
			$clean['gateway_key'] = '';
		} else {
			$submitted = isset( $input['gateway_key'] ) ? trim( (string) $input['gateway_key'] ) : '';
			if ( '' === $submitted ) {
				// Empty submission preserves the stored key (write-only field).
				$clean['gateway_key'] = $existing['gateway_key'];
			} else {
				$clean['gateway_key'] = sanitize_text_field( $submitted );
			}
		}

		return $clean;
	}

	/* --------------------------------------------------------------------- *
	 * Secret handling
	 * --------------------------------------------------------------------- */

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
