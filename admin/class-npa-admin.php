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
		add_action( 'admin_post_npa_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_npa_import', array( $this, 'handle_import' ) );
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
			$this->asset_version( 'assets/css/newtide-public-agent-admin.css' )
		);

		wp_enqueue_script(
			'npa-admin',
			NPA_PLUGIN_URL . 'assets/js/newtide-public-agent-admin.js',
			array(),
			$this->asset_version( 'assets/js/newtide-public-agent-admin.js' ),
			true
		);

		wp_localize_script(
			'npa-admin',
			'NPA_ADMIN',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'restUrl'     => esc_url_raw( rest_url( 'npa/v1/message' ) ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'testingText' => __( 'Testing…', 'newtide-public-agent' ),
				'runningText' => __( 'Running…', 'newtide-public-agent' ),
				'errorText'   => __( 'Request failed. Please try again.', 'newtide-public-agent' ),
			)
		);

		// The Appearance tab is asset-heavy: the media picker for a custom icon,
		// the real front-end stylesheet for the live preview, and the theme palette
		// + built-in icons handed to JS.
		if ( 'appearance' === $this->current_tab() ) {
			wp_enqueue_media();

			wp_enqueue_style(
				'npa-widget-preview',
				NPA_PLUGIN_URL . 'assets/css/newtide-public-agent-public.css',
				array( 'npa-admin' ),
				$this->asset_version( 'assets/css/newtide-public-agent-public.css' )
			);

			wp_localize_script(
				'npa-admin',
				'NPA_APPEARANCE',
				array(
					'palette'       => $this->theme_palette(),
					'icons'         => NPA_Icons::localized(),
					'defaultAccent' => NPA_Settings::defaults()['accent'],
					'frameTitle'    => __( 'Choose a launcher icon', 'newtide-public-agent' ),
					'frameButton'   => __( 'Use this image', 'newtide-public-agent' ),
				)
			);
		}

		// The Additional Agents tab is a repeater with per-row icon images.
		if ( 'additional' === $this->current_tab() ) {
			wp_enqueue_media();
			wp_localize_script(
				'npa-admin',
				'NPA_AGENTS',
				array(
					'frameTitle'  => __( 'Choose an agent icon', 'newtide-public-agent' ),
					'frameButton' => __( 'Use this image', 'newtide-public-agent' ),
				)
			);
		}
	}

	/**
	 * Recommended colours pulled from the active theme: the editor colour palette
	 * declared in theme.json / global styles. Deduped by hex, theme colours first.
	 * Non-hex entries (rgba/gradients) are skipped since the accent is a hex value.
	 *
	 * @return array<int,array{color:string,name:string}>
	 */
	public function theme_palette() {
		$out  = array();
		$seen = array();

		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $out;
		}

		$palette = wp_get_global_settings( array( 'color', 'palette' ) );

		// The setting is either origin-bucketed (theme/custom/default) or a flat
		// list, depending on WordPress version and whether a theme.json is present.
		$entries = array();
		if ( is_array( $palette ) ) {
			if ( isset( $palette['theme'] ) || isset( $palette['custom'] ) || isset( $palette['default'] ) ) {
				foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
					if ( ! empty( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) {
						$entries = array_merge( $entries, $palette[ $origin ] );
					}
				}
			} else {
				$entries = $palette;
			}
		}

		foreach ( $entries as $entry ) {
			if ( empty( $entry['color'] ) ) {
				continue;
			}
			$hex = sanitize_hex_color( $entry['color'] );
			if ( ! $hex ) {
				continue;
			}
			$key = strtolower( $hex );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'color' => $hex,
				'name'  => isset( $entry['name'] ) ? (string) $entry['name'] : $hex,
			);
		}

		return $out;
	}

	/**
	 * Cache-busting version for a bundled asset: the file's modification time
	 * when it can be read, else the plugin version. Keeps the browser from
	 * serving a stale stylesheet after an in-place update.
	 *
	 * @param string $relative_path Path relative to the plugin directory.
	 * @return string
	 */
	private function asset_version( $relative_path ) {
		$path  = NPA_PLUGIN_DIR . $relative_path;
		$mtime = is_readable( $path ) ? filemtime( $path ) : false;
		return false !== $mtime ? (string) $mtime : NPA_VERSION;
	}

	/**
	 * Build the first-run setup checklist: computable, meaningful steps, each
	 * linking to the tab that completes it. Drives the Home tab's progress meter.
	 *
	 * @return array<int,array{label:string,done:bool,tab:string,hint:string}>
	 */
	public function get_setup_checklist() {
		$s     = $this->plugin->settings;
		$mode  = $s->get_mode();
		$embed = ( 'embed' === $mode );

		$items = array();

		$items[] = array(
			'label' => $embed ? __( 'Connect: publishable key & platform URL', 'newtide-public-agent' ) : __( 'Connect: gateway URL, credential & agent', 'newtide-public-agent' ),
			'done'  => $embed ? $s->is_embed_configured() : $s->is_configured(),
			'tab'   => 'agent',
			'hint'  => __( 'Set your connection on the Agent tab.', 'newtide-public-agent' ),
		);

		$items[] = array(
			'label' => __( 'Choose an agent', 'newtide-public-agent' ),
			'done'  => $embed ? ( '' !== $s->get_public_key() ) : ( '' !== $s->get_agent_id() ),
			'tab'   => 'agent',
			'hint'  => __( 'Pick which published agent answers visitors.', 'newtide-public-agent' ),
		);

		$items[] = array(
			'label' => __( 'Turn the widget on', 'newtide-public-agent' ),
			'done'  => (bool) $s->get( 'enabled' ),
			'tab'   => 'general',
			'hint'  => __( 'Enable the front-end widget on the General tab.', 'newtide-public-agent' ),
		);

		$items[] = array(
			'label' => __( 'Write a greeting', 'newtide-public-agent' ),
			'done'  => '' !== trim( (string) $s->get( 'greeting' ) ),
			'tab'   => 'general',
			'hint'  => __( 'The first line visitors read when the chat opens.', 'newtide-public-agent' ),
		);

		$items[] = array(
			'label' => __( 'Style the launcher', 'newtide-public-agent' ),
			'done'  => '' !== trim( (string) $s->get( 'accent' ) ),
			'tab'   => 'appearance',
			'hint'  => __( 'Match colours and pick an icon on the Appearance tab.', 'newtide-public-agent' ),
		);

		return $items;
	}

	/**
	 * Stream the current settings as a JSON download (secret excluded).
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'newtide-public-agent' ) );
		}
		check_admin_referer( 'npa_export' );

		$opts = get_option( NPA_Settings::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		unset( $opts['gateway_key'] ); // Never export the stored credential.

		$payload = array(
			'plugin'   => 'newtide-public-agent',
			'version'  => defined( 'NPA_VERSION' ) ? NPA_VERSION : '',
			'exported' => current_time( 'mysql' ),
			'settings' => $opts,
		);

		$json     = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$filename = 'newtide-agent-settings-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( (string) $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download.
		exit;
	}

	/**
	 * Import settings from an uploaded JSON export. The credential is never
	 * imported (the existing one is kept), and everything runs through sanitize().
	 *
	 * @return void
	 */
	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'newtide-public-agent' ) );
		}
		check_admin_referer( 'npa_import' );

		$redirect = add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => 'home',
			),
			admin_url( 'admin.php' )
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-provided upload path, guarded by is_uploaded_file().
		if ( empty( $_FILES['npa_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['npa_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'npa_import', 'nofile', $redirect ) );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-provided upload path, guarded above by is_uploaded_file().
		$raw  = file_get_contents( $_FILES['npa_import_file']['tmp_name'] );
		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			wp_safe_redirect( add_query_arg( 'npa_import', 'badfile', $redirect ) );
			exit;
		}

		$incoming = $data['settings'];
		unset( $incoming['gateway_key'] ); // Keep the existing credential; never import one.

		$clean = $this->plugin->settings->sanitize( $incoming );
		update_option( NPA_Settings::OPTION, $clean );

		wp_safe_redirect( add_query_arg( 'npa_import', 'ok', $redirect ) );
		exit;
	}

	/**
	 * Usage analytics markup: headline stat tiles + a single-series
	 * messages-per-day bar chart (one hue; the title names the series, so no
	 * legend) + a top-agents ranking. Fully escaped at construction.
	 *
	 * @return string
	 */
	public function analytics_html() {
		$store  = $this->plugin->store;
		$series = $store->daily_series( 14 );
		$agg    = $store->aggregates( 50 );

		$total = 0;
		$max   = 0;
		$busy  = '';
		foreach ( $series as $d ) {
			$total += $d['count'];
			if ( $d['count'] > $max ) {
				$max  = $d['count'];
				$busy = $d['date'];
			}
		}

		if ( 0 === $total && 0 === (int) $agg['count'] ) {
			return '<p class="description">' . esc_html__( 'No activity yet. Once your agent starts answering visitors, usage charts appear here.', 'newtide-public-agent' ) . '</p>';
		}

		// --- Stat tiles ------------------------------------------------------
		$err_pct = number_format_i18n( $agg['error_rate'] * 100, 1 );
		$err_ok  = $agg['error_rate'] <= 0.1;
		$tiles   = '<div class="npa-stats">';
		$tiles  .= $this->stat_tile( esc_html__( 'Messages (14 days)', 'newtide-public-agent' ), esc_html( number_format_i18n( $total ) ), '' );
		$tiles  .= $this->stat_tile( esc_html__( 'Error rate (last 50)', 'newtide-public-agent' ), esc_html( $err_pct . '%' ), $err_ok ? 'ok' : 'warn' );
		$tiles  .= $this->stat_tile( esc_html__( 'Avg latency', 'newtide-public-agent' ), esc_html( number_format_i18n( $agg['avg_latency_ms'] ) . ' ms' ), '' );
		$tiles  .= '</div>';

		// --- Bar chart (single series → one hue, no legend) ------------------
		$slot    = 34;
		$bar_w   = 18;
		$plot_h  = 120;
		$top     = 12;
		$label_h = 24;
		$days    = count( $series );
		$w       = max( 1, $days ) * $slot;
		$h       = $top + $plot_h + $label_h;
		$scale   = max( 1, $max );
		$base_y  = $top + $plot_h;

		$bars = '';
		foreach ( $series as $i => $d ) {
			$c  = (int) $d['count'];
			$bh = $c > 0 ? max( 3, (int) round( $c / $scale * $plot_h ) ) : 0;
			$x  = $i * $slot + (int) ( ( $slot - $bar_w ) / 2 );
			$y  = $base_y - $bh;
			$dm = gmdate( 'M j', strtotime( $d['date'] ) );
			/* translators: 1: date, 2: message count. */
			$tip = sprintf( _n( '%2$s message on %1$s', '%2$s messages on %1$s', $c, 'newtide-public-agent' ), $dm, number_format_i18n( $c ) );

			if ( $bh > 0 ) {
				$bars .= sprintf(
					'<rect x="%d" y="%d" width="%d" height="%d" rx="4" fill="#0090fc"><title>%s</title></rect>',
					$x,
					$y,
					$bar_w,
					$bh,
					esc_html( $tip )
				);
			} else {
				// Zero day: a faint baseline stub so the day is still legible.
				$bars .= sprintf(
					'<rect x="%d" y="%d" width="%d" height="2" rx="1" fill="#c6dbf3"><title>%s</title></rect>',
					$x,
					$base_y - 2,
					$bar_w,
					esc_html( $tip )
				);
			}

			// Sparse x labels: first, middle, last.
			$mid_i = (int) floor( $days / 2 );
			if ( 0 === $i || $i === $days - 1 || $mid_i === $i ) {
				$bars .= sprintf(
					'<text x="%d" y="%d" text-anchor="middle" font-size="11" fill="#646970">%s</text>',
					$x + (int) ( $bar_w / 2 ),
					$base_y + 16,
					esc_html( $dm )
				);
			}
		}

		$chart  = '<figure class="npa-chart">';
		$chart .= '<figcaption class="npa-chart__title">' . esc_html__( 'Messages per day', 'newtide-public-agent' );
		$chart .= ' <span class="npa-chart__peak">' . esc_html( sprintf( /* translators: %s: number. */ __( 'peak %s', 'newtide-public-agent' ), number_format_i18n( $max ) ) ) . '</span></figcaption>';
		$chart .= sprintf(
			'<svg viewBox="0 0 %d %d" role="img" preserveAspectRatio="xMinYMid meet" class="npa-chart__svg" aria-label="%s">',
			$w,
			$h,
			esc_attr__( 'Messages per day, last 14 days', 'newtide-public-agent' )
		);
		$chart .= sprintf( '<line x1="0" y1="%d" x2="%d" y2="%d" stroke="#e2e4e7" stroke-width="1" />', $base_y, $w, $base_y );
		$chart .= $bars;
		$chart .= '</svg></figure>';

		// --- Top agents ------------------------------------------------------
		$top_html = $this->top_agents_html();

		return $tiles . $chart . $top_html;
	}

	/**
	 * A single stat tile. Status ('ok'|'warn'|'') tints only the value.
	 *
	 * @param string $label Pre-escaped label.
	 * @param string $value Pre-escaped value.
	 * @param string $state '', 'ok', or 'warn'.
	 * @return string
	 */
	private function stat_tile( $label, $value, $state ) {
		$cls = 'npa-stat';
		if ( 'ok' === $state ) {
			$cls .= ' npa-stat--ok';
		} elseif ( 'warn' === $state ) {
			$cls .= ' npa-stat--warn';
		}
		return '<div class="' . esc_attr( $cls ) . '"><div class="npa-stat__value">' . $value . '</div><div class="npa-stat__label">' . $label . '</div></div>';
	}

	/**
	 * A compact "busiest agents" ranking from recent rows (magnitude per agent,
	 * one hue; identity carried by the label beside each bar).
	 *
	 * @return string
	 */
	private function top_agents_html() {
		$rows   = $this->plugin->store->recent( 200 );
		$counts = array();
		foreach ( $rows as $r ) {
			$id = (string) $r['agent_id'];
			if ( '' === $id ) {
				continue;
			}
			$counts[ $id ] = isset( $counts[ $id ] ) ? $counts[ $id ] + 1 : 1;
		}
		if ( empty( $counts ) ) {
			return '';
		}
		arsort( $counts );
		$counts = array_slice( $counts, 0, 5, true );
		$max    = max( $counts );

		$out = '<div class="npa-topagents"><h4 class="npa-topagents__title">' . esc_html__( 'Busiest agents (recent)', 'newtide-public-agent' ) . '</h4>';
		foreach ( $counts as $id => $n ) {
			$pct  = (int) round( $n / max( 1, $max ) * 100 );
			$out .= '<div class="npa-topagents__row">';
			$out .= '<span class="npa-topagents__name" title="' . esc_attr( $id ) . '">' . esc_html( $id ) . '</span>';
			$out .= '<span class="npa-topagents__track"><span class="npa-topagents__bar" style="width:' . esc_attr( (string) $pct ) . '%"></span></span>';
			$out .= '<span class="npa-topagents__count">' . esc_html( number_format_i18n( $n ) ) . '</span>';
			$out .= '</div>';
		}
		$out .= '</div>';
		return $out;
	}

	// Page rendering.

	/**
	 * The active tab slug.
	 *
	 * @return string
	 */
	public function current_tab() {
		// Reading a tab name for display only; nonce not applicable to tab nav.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'general';
	}

	/**
	 * Tab slug => label map.
	 *
	 * @return array<string,string>
	 */
	public function tabs() {
		return array(
			'home'       => __( 'Home', 'newtide-public-agent' ),
			'general'    => __( 'General', 'newtide-public-agent' ),
			'appearance' => __( 'Appearance', 'newtide-public-agent' ),
			'behavior'   => __( 'Behavior', 'newtide-public-agent' ),
			'agent'      => __( 'Agent', 'newtide-public-agent' ),
			'additional' => __( 'Additional Agents', 'newtide-public-agent' ),
			'publishing' => __( 'Publishing', 'newtide-public-agent' ),
			'status'     => __( 'Service Status', 'newtide-public-agent' ),
			'tests'      => __( 'Tests', 'newtide-public-agent' ),
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

		// Branded header — the NewTide wordmark (inline, recolorable) + product name.
		echo '<div class="npa-brand-header">';
		echo '<span class="npa-brand-logo-wrap">';
		require NPA_PLUGIN_DIR . 'admin/views/brand-logo.php';
		echo '</span>';
		echo '<span class="npa-brand-sep" aria-hidden="true"></span>';
		echo '<h1 class="npa-brand-title">' . esc_html__( 'Public Agent', 'newtide-public-agent' ) . '</h1>';
		echo '<span class="npa-brand-tagline">' . esc_html__( 'The Tide that Lifts All Boats', 'newtide-public-agent' ) . '</span>';
		echo '</div>';

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
	 * Render a tab's intro "hero" — a brand-tinted icon, the page title, and a
	 * one-line description of what the tab is for. Gives each page an identity
	 * beyond the stock WordPress form.
	 *
	 * @param string $icon  Dashicon slug (e.g. "dashicons-format-chat").
	 * @param string $title Tab title.
	 * @param string $desc  One-line description.
	 * @return void
	 */
	public function tab_intro( $icon, $title, $desc ) {
		printf(
			'<div class="npa-tab-intro"><span class="npa-tab-intro__icon dashicons %s" aria-hidden="true"></span><div><h2 class="npa-tab-intro__title">%s</h2><p class="npa-tab-intro__desc">%s</p></div></div>',
			esc_attr( $icon ),
			esc_html( $title ),
			esc_html( $desc )
		);
	}

	/**
	 * Open a settings "card" — a white panel with a brand-accent header. Pair
	 * with card_close(). Keeps related fields visually grouped.
	 *
	 * @param string $title Card title.
	 * @param string $desc  Optional sub-line.
	 * @return void
	 */
	public function card_open( $title, $desc = '' ) {
		echo '<div class="npa-card"><div class="npa-card__head">';
		echo '<h3 class="npa-card__title">' . esc_html( $title ) . '</h3>';
		if ( '' !== $desc ) {
			echo '<p class="npa-card__desc">' . esc_html( $desc ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Close a card opened with card_open().
	 *
	 * @return void
	 */
	public function card_close() {
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
