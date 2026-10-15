<?php
/**
 * Turn diagnostics — where a slow or failed chat turn was lost.
 *
 * A turn crosses four hops: the visitor's browser to this site, this site to
 * the agent API, the agent to its model, and (when site knowledge is on) the
 * agent back into this site's MCP server. Before 0.16.1 the plugin saw only
 * the second, and only when PHP lived long enough to write it down. A host
 * that cut the connection, or killed the request, left no trace at all, and
 * every failure looked the same to the visitor.
 *
 * So each turn now carries an id from the browser through to the agent API,
 * its usage row is written before the agent is called rather than after, a
 * failure the browser sees is reported back, and MCP calls are timed. Then
 * classify() reads the evidence and names the hop.
 *
 * Metadata only — no message content — like the usage table it extends.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Diagnostics
 */
class NPA_Diagnostics {

	/**
	 * Header carrying the turn id to the agent API.
	 *
	 * @var string
	 */
	const REQUEST_HEADER = 'X-Request-Id';

	/**
	 * Response headers an upstream may name its own request by, in order.
	 *
	 * @var string[]
	 */
	const UPSTREAM_HEADERS = array( 'x-request-id', 'request-id', 'x-amzn-requestid', 'x-trace-id', 'cf-ray' );

	/**
	 * Failures the browser can report.
	 *
	 * - network: the request never got an answer (connection dropped).
	 * - timeout: the widget gave up waiting.
	 * - http:    an error status with no readable reply.
	 * - parse:   a reply that was not the plugin's JSON — typically a host's
	 *            or CDN's own 502/504 page.
	 *
	 * @var string[]
	 */
	const CLIENT_KINDS = array( 'network', 'timeout', 'http', 'parse' );

	/**
	 * A reply slower than this is reported as slow, milliseconds.
	 *
	 * @var int
	 */
	const SLOW_MS = 15000;

	/**
	 * Diagnostic events are kept this many days.
	 *
	 * @var int
	 */
	const RETENTION_DAYS = 30;

	/**
	 * Plugin instance.
	 *
	 * @var NPA_Plugin
	 */
	private $plugin;

	/**
	 * The turn in progress in this request, or null.
	 *
	 * @var array|null { id, row, start, upstream_id }
	 */
	private $turn = null;

	/**
	 * Whether the shutdown handler is registered for this request.
	 *
	 * @var bool
	 */
	private $shutdown_registered = false;

	/**
	 * Constructor.
	 *
	 * @param NPA_Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether diagnostics are switched on.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) $this->plugin->settings->get( 'diagnostics', true );
	}

	// ── Turn ids ────────────────────────────────────────────────────────────

	/**
	 * A fresh turn id: 16 lowercase hex characters.
	 *
	 * @return string
	 */
	public static function new_turn_id() {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( Exception $e ) {
			return substr( md5( uniqid( '', true ) ), 0, 16 );
		}
	}

	/**
	 * Whether a string is a well-formed turn id.
	 *
	 * The browser mints the id so that it can still name the turn when the
	 * server never answers, which makes it visitor input: it is only ever
	 * accepted in exactly the shape new_turn_id() produces.
	 *
	 * @param mixed $id Candidate.
	 * @return bool
	 */
	public static function is_valid_turn_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $id );
	}

	/**
	 * The short form a visitor is shown and an administrator searches for.
	 *
	 * @param string $id Turn id.
	 * @return string
	 */
	public static function reference( $id ) {
		return substr( (string) $id, 0, 8 );
	}

	// ── Server side of a turn ───────────────────────────────────────────────

	/**
	 * Milliseconds since PHP began this request.
	 *
	 * @return int
	 */
	public static function request_elapsed_ms() {
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );

		return max( 0, (int) round( ( microtime( true ) - $start ) * 1000 ) );
	}

	/**
	 * How long the request waited in front of PHP, when the host says.
	 *
	 * Some hosts' proxies stamp X-Request-Start (`t=<seconds or µs>`) when the
	 * request arrives. The gap between that and PHP starting is time spent
	 * waiting for a free worker — the cost of a site too busy to answer.
	 * Most hosts do not send it; 0 means unknown, not none.
	 *
	 * @param string|null $header Header value; read from the request when null.
	 * @param float|null  $now    PHP start, seconds; REQUEST_TIME_FLOAT when null.
	 * @return int Milliseconds.
	 */
	public static function queue_ms( $header = null, $now = null ) {
		if ( null === $header ) {
			$header = isset( $_SERVER['HTTP_X_REQUEST_START'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUEST_START'] ) ) : '';
		}

		if ( ! preg_match( '/(\d+(?:\.\d+)?)/', (string) $header, $m ) ) {
			return 0;
		}

		$stamp = (float) $m[1];

		// Seconds, milliseconds or microseconds, judged by magnitude.
		if ( $stamp > 1e14 ) {
			$stamp /= 1e6;
		} elseif ( $stamp > 1e11 ) {
			$stamp /= 1e3;
		}

		if ( null === $now ) {
			$now = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		}

		$gap = (int) round( ( (float) $now - $stamp ) * 1000 );

		// A clock disagreement shows up as nonsense; report nothing rather than it.
		return ( $gap > 0 && $gap < 600000 ) ? $gap : 0;
	}

	/**
	 * Open a turn: write its row as pending and watch the request.
	 *
	 * @param string $turn_id Turn id.
	 * @param array  $row     Usage fields known before the agent is called.
	 * @return int Row id, or 0 when diagnostics are off (the caller then
	 *             records at the end, as before 0.16.1).
	 */
	public function begin( $turn_id, array $row ) {
		if ( ! $this->enabled() ) {
			return 0;
		}

		$id = $this->plugin->store->record(
			array_merge(
				$row,
				array(
					'turn_id'       => $turn_id,
					'status'        => 0,
					'finish_reason' => NPA_Store::PENDING,
					'queue_ms'      => self::queue_ms(),
				)
			)
		);

		$this->turn = array(
			'id'          => $turn_id,
			'row'         => (int) $id,
			'start'       => microtime( true ),
			'upstream_id' => '',
		);

		add_filter( 'http_request_args', array( $this, 'tag_request' ), 10, 2 );
		add_action( 'http_api_debug', array( $this, 'capture_response' ), 10, 5 );

		if ( ! $this->shutdown_registered ) {
			register_shutdown_function( array( $this, 'on_shutdown' ) );
			$this->shutdown_registered = true;
		}

		return (int) $id;
	}

	/**
	 * Close the turn: fill in its row.
	 *
	 * @param array $outcome Usage fields for the finished turn.
	 * @return void
	 */
	public function end( array $outcome ) {
		if ( null === $this->turn ) {
			return;
		}

		$outcome['server_ms']   = self::request_elapsed_ms();
		$outcome['upstream_id'] = $this->turn['upstream_id'];

		if ( $this->turn['row'] > 0 ) {
			$this->plugin->store->complete( $this->turn['row'], $outcome );
		}

		$this->release();
	}

	/**
	 * The turn in progress, or null.
	 *
	 * @return array|null
	 */
	public function current() {
		return $this->turn;
	}

	/**
	 * Stop watching outbound requests.
	 *
	 * @return void
	 */
	private function release() {
		remove_filter( 'http_request_args', array( $this, 'tag_request' ), 10 );
		remove_action( 'http_api_debug', array( $this, 'capture_response' ), 10 );
		$this->turn = null;
	}

	/**
	 * Tag the outbound call with the turn id.
	 *
	 * Only between begin() and end(), when the one request PHP makes is the
	 * call to the agent. With the same id in the agent platform's logs, a
	 * slow turn here can be found there.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function tag_request( $args, $url ) {
		unset( $url );

		if ( null === $this->turn ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers'][ self::REQUEST_HEADER ] = $this->turn['id'];

		return $args;
	}

	/**
	 * Keep the upstream's own name for the request, if it gives one.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param string         $context  Always 'response'.
	 * @param string         $class    Transport class.
	 * @param array          $args     Request arguments.
	 * @param string         $url      Request URL.
	 * @return void
	 */
	public function capture_response( $response, $context = '', $class = '', $args = array(), $url = '' ) {
		unset( $context, $class, $args, $url );

		if ( null === $this->turn || ! is_array( $response ) ) {
			return;
		}

		$this->turn['upstream_id'] = self::upstream_id( wp_remote_retrieve_headers( $response ) );
	}

	/**
	 * The upstream request id from a set of response headers.
	 *
	 * @param mixed $headers Headers (array or WpOrg\Requests dictionary).
	 * @return string
	 */
	public static function upstream_id( $headers ) {
		foreach ( self::UPSTREAM_HEADERS as $name ) {
			$value = '';

			if ( is_array( $headers ) ) {
				$lower = array_change_key_case( $headers, CASE_LOWER );
				$value = isset( $lower[ $name ] ) ? $lower[ $name ] : '';
			} elseif ( is_object( $headers ) && isset( $headers[ $name ] ) ) {
				$value = $headers[ $name ];
			}

			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			$value = trim( (string) $value );

			if ( '' !== $value ) {
				return substr( sanitize_text_field( $value ), 0, 64 );
			}
		}

		return '';
	}

	/**
	 * Say what killed the request, if a turn was still open when PHP stopped.
	 *
	 * Runs after a fatal error, including PHP's own time limit. It does not run
	 * when the host kills the worker outright — that turn stays pending and
	 * sweep() names it abandoned later.
	 *
	 * @param array|null $last error_get_last(), or a fixture.
	 * @return string The code recorded, or '' if nothing was open.
	 */
	public function on_shutdown( $last = null ) {
		if ( null === $this->turn ) {
			return '';
		}

		if ( null === $last ) {
			$last = error_get_last();
		}

		$code = 'php_exit';

		if ( is_array( $last ) && isset( $last['type'] ) && in_array( (int) $last['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			$message = isset( $last['message'] ) ? (string) $last['message'] : '';
			$code    = false !== stripos( $message, 'maximum execution time' ) ? 'php_timeout'
				: ( false !== stripos( $message, 'allowed memory size' ) ? 'php_memory' : 'php_fatal' );
		}

		$this->end(
			array(
				'status'        => 500,
				'finish_reason' => 'error',
				'error_code'    => $code,
				'latency_ms'    => (int) round( ( microtime( true ) - $this->turn['start'] ) * 1000 ),
			)
		);

		return $code;
	}

	/**
	 * Seconds after which a turn still pending is taken to be dead.
	 *
	 * Comfortably past the longest the request could legitimately run: the
	 * agent timeout, plus PHP's own work either side.
	 *
	 * @return int
	 */
	public function abandon_after() {
		return max( 300, ( 2 * $this->plugin->settings->agent_timeout() ) + 60 );
	}

	/**
	 * Name abandoned turns. Cheap; run before anything reads the turns.
	 *
	 * @return int Rows marked.
	 */
	public function sweep() {
		return $this->plugin->store->sweep_abandoned( $this->abandon_after() );
	}

	// ── Browser side of a turn ──────────────────────────────────────────────

	/**
	 * Record what the browser saw.
	 *
	 * A report can only describe a turn — it never creates a usage row, so it
	 * cannot inflate message counts or spend the daily budget. A report for a
	 * turn the server has no row for is kept as an event: that is the turn
	 * that never reached the plugin at all.
	 *
	 * @param array $report turn_id, kind, status, elapsed_ms, ok.
	 * @return string 'turn' when it described a known turn, 'orphan' when
	 *                the server never saw it, '' when rejected.
	 */
	public function record_client( array $report ) {
		$turn_id = isset( $report['turn_id'] ) ? (string) $report['turn_id'] : '';

		if ( ! $this->enabled() || ! self::is_valid_turn_id( $turn_id ) ) {
			return '';
		}

		$ok      = ! empty( $report['ok'] );
		$kind    = isset( $report['kind'] ) ? (string) $report['kind'] : '';
		$status  = isset( $report['status'] ) ? max( 0, min( 999, (int) $report['status'] ) ) : 0;
		$elapsed = isset( $report['elapsed_ms'] ) ? max( 0, min( 3600000, (int) $report['elapsed_ms'] ) ) : 0;

		if ( ! $ok && ! in_array( $kind, self::CLIENT_KINDS, true ) ) {
			return '';
		}

		$row = $this->plugin->store->find_by_turn( $turn_id );

		// Only ever annotate a recent turn, and only once per field.
		if ( $row && strtotime( $row['created_at'] ) < (int) current_time( 'timestamp' ) - HOUR_IN_SECONDS ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- created_at is local time.
			return '';
		}

		if ( $row ) {
			$this->plugin->store->complete(
				(int) $row['id'],
				array(
					'client_ms'     => $elapsed,
					'client_status' => $ok ? 200 : $status,
				)
			);
		}

		if ( ! $ok ) {
			$this->plugin->store->record_event(
				array(
					'turn_id'    => $turn_id,
					'kind'       => 'client',
					'name'       => $kind,
					'status'     => $status,
					'latency_ms' => $elapsed,
				)
			);
		}

		return $row ? 'turn' : 'orphan';
	}

	// ── MCP calls back into this site ───────────────────────────────────────

	/**
	 * Record one MCP request.
	 *
	 * The agent calls back into this site while the visitor's own request is
	 * still holding a PHP worker, so on a host with few workers these calls
	 * queue behind the very chat that is waiting for them. boot_ms (WordPress
	 * starting) and queue_ms (waiting for a worker, where the host reports it)
	 * are what show that happening.
	 *
	 * @param string $name       Method, or the tool for tools/call.
	 * @param bool   $ok         Whether it succeeded.
	 * @param int    $latency_ms Handler time.
	 * @return void
	 */
	public function record_mcp( $name, $ok, $latency_ms ) {
		if ( ! $this->enabled() ) {
			return;
		}

		$elapsed = self::request_elapsed_ms();

		$this->plugin->store->record_event(
			array(
				'kind'       => 'mcp',
				'name'       => $name,
				'status'     => $ok ? 200 : 500,
				'latency_ms' => $latency_ms,
				'boot_ms'    => max( 0, $elapsed - (int) $latency_ms ),
				'queue_ms'   => self::queue_ms(),
			)
		);
	}

	// ── Reading it back ─────────────────────────────────────────────────────

	/**
	 * Name what happened to a turn, and which hop it happened at.
	 *
	 * @param array $row     Usage row.
	 * @param int   $timeout Agent timeout, seconds.
	 * @param array $mcp     MCP events attributed to the turn.
	 * @return array{code:string,hop:string,ok:bool,label:string}
	 */
	public static function classify( array $row, $timeout, array $mcp = array() ) {
		$status  = (int) $row['status'];
		$error   = (string) $row['error_code'];
		$latency = (int) $row['latency_ms'];
		$server  = (int) $row['server_ms'];
		$client  = (int) $row['client_status'];
		$mcp_ms  = 0;

		foreach ( $mcp as $event ) {
			$mcp_ms += (int) $event['latency_ms'] + (int) $event['boot_ms'] + (int) $event['queue_ms'];
		}

		if ( NPA_Store::PENDING === (string) $row['finish_reason'] ) {
			return self::verdict( 'in_flight', 'wordpress', true, __( 'Still being answered', 'newtide-public-agent' ) );
		}

		// An administrator previewing without a connection: nothing was sent.
		if ( ! empty( $row['is_mock'] ) && '' === $error ) {
			return self::verdict( 'preview', '', true, __( 'Answered by the preview agent (no connection configured)', 'newtide-public-agent' ) );
		}

		if ( NPA_Store::ABANDONED === $error ) {
			return self::verdict( 'abandoned', 'wordpress', false, __( 'Dropped: the host stopped WordPress before it finished (no reply was ever sent)', 'newtide-public-agent' ) );
		}

		if ( 0 === strpos( $error, 'php_' ) ) {
			$labels = array(
				'php_timeout' => __( 'Dropped: PHP hit its time limit', 'newtide-public-agent' ),
				'php_memory'  => __( 'Dropped: PHP ran out of memory', 'newtide-public-agent' ),
				'php_fatal'   => __( 'Dropped: a PHP error stopped the request', 'newtide-public-agent' ),
				'php_exit'    => __( 'Dropped: the request ended before the reply was sent', 'newtide-public-agent' ),
			);

			return self::verdict( $error, 'wordpress', false, isset( $labels[ $error ] ) ? $labels[ $error ] : $labels['php_fatal'] );
		}

		if ( 'transport' === $error ) {
			if ( $latency >= ( (int) $timeout * 1000 ) - 1500 ) {
				return self::verdict(
					'agent_timeout',
					$mcp_ms > $latency / 2 ? 'mcp' : 'agent',
					false,
					sprintf(
						/* translators: %d: timeout in seconds. */
						__( 'The agent took longer than the %d-second timeout', 'newtide-public-agent' ),
						(int) $timeout
					)
				);
			}

			return self::verdict( 'network', 'network', false, __( 'Could not reach the agent API', 'newtide-public-agent' ) );
		}

		if ( '' !== $error || $status >= 400 ) {
			return self::verdict(
				'agent_error',
				'agent',
				false,
				sprintf(
					/* translators: %s: error code. */
					__( 'The agent API returned an error (%s)', 'newtide-public-agent' ),
					'' !== $error ? $error : (string) $status
				)
			);
		}

		// The server answered. Did the browser get it?
		if ( $client >= 400 || ( 0 === $client && (int) $row['client_ms'] > 0 ) ) {
			return self::verdict( 'host_cut', 'host', false, __( 'The agent answered, but the visitor never got it — the host or CDN closed the connection first', 'newtide-public-agent' ) );
		}

		if ( $latency >= self::SLOW_MS ) {
			return self::verdict( 'slow', $mcp_ms > $latency / 2 ? 'mcp' : 'agent', true, __( 'Answered, slowly', 'newtide-public-agent' ) );
		}

		if ( $server - $latency >= 5000 ) {
			return self::verdict( 'slow', 'wordpress', true, __( 'Answered, but WordPress itself was slow', 'newtide-public-agent' ) );
		}

		return self::verdict( 'ok', '', true, __( 'Answered', 'newtide-public-agent' ) );
	}

	/**
	 * A classify() result.
	 *
	 * @param string $code  Machine code.
	 * @param string $hop   wordpress | network | agent | mcp | host | ''.
	 * @param bool   $ok    Whether the visitor got an answer.
	 * @param string $label Plain-language description.
	 * @return array
	 */
	private static function verdict( $code, $hop, $ok, $label ) {
		return array(
			'code'  => $code,
			'hop'   => $hop,
			'ok'    => $ok,
			'label' => $label,
		);
	}

	/**
	 * Plain names for the hops.
	 *
	 * @return array<string,string>
	 */
	public static function hop_labels() {
		return array(
			'host'      => __( 'Host or CDN (between the browser and WordPress)', 'newtide-public-agent' ),
			'wordpress' => __( 'WordPress / PHP on this site', 'newtide-public-agent' ),
			'network'   => __( 'Network between this site and the agent API', 'newtide-public-agent' ),
			'agent'     => __( 'The agent (My Agents / RisingTide and its model)', 'newtide-public-agent' ),
			'mcp'       => __( 'Site knowledge calls back into this site (MCP)', 'newtide-public-agent' ),
			'browser'   => __( 'Never reached WordPress', 'newtide-public-agent' ),
		);
	}

	/**
	 * MCP events that fall inside a turn's window.
	 *
	 * Attribution is by time: the agent platform does not pass the turn id
	 * back. On a busy site two overlapping turns share their MCP calls.
	 *
	 * @param array $row    Usage row.
	 * @param array $events MCP events, oldest first.
	 * @return array
	 */
	public static function mcp_for_turn( array $row, array $events ) {
		$start = strtotime( $row['created_at'] );
		$end   = $start + (int) ceil( max( (int) $row['latency_ms'], (int) $row['server_ms'] ) / 1000 ) + 1;
		$out   = array();

		foreach ( $events as $event ) {
			$at = strtotime( $event['created_at'] );

			if ( $at >= $start && $at <= $end ) {
				$out[] = $event;
			}
		}

		return $out;
	}

	/**
	 * Recent turns, classified, newest first.
	 *
	 * @param int $limit Max turns.
	 * @param int $days  Window.
	 * @return array<int,array>
	 */
	public function recent_turns( $limit = 25, $days = 7 ) {
		$this->sweep();

		$since   = $this->since( $days );
		$timeout = $this->plugin->settings->agent_timeout();
		$mcp     = $this->plugin->store->events_since( 'mcp', $since );
		$rows    = $this->plugin->store->turns_since( $since, max( 1, (int) $limit ) );
		$out     = array();

		$known = array();

		foreach ( $rows as $row ) {
			$known[ $row['turn_id'] ] = true;
			$calls                    = self::mcp_for_turn( $row, $mcp );
			$row['mcp']               = $calls;
			$row['class']             = self::classify( $row, $timeout, $calls );
			$out[]                    = $row;
		}

		/*
		 * Turns only the browser knows about. The visitor was shown their
		 * reference too, so it has to be findable here even though WordPress
		 * never recorded the message.
		 */
		foreach ( $this->plugin->store->events_since( 'client', $since ) as $event ) {
			if ( isset( $known[ $event['turn_id'] ] ) ) {
				continue;
			}

			$known[ $event['turn_id'] ] = true;

			$out[] = array(
				'created_at'    => $event['created_at'],
				'turn_id'       => $event['turn_id'],
				'latency_ms'    => 0,
				'server_ms'     => 0,
				'client_ms'     => (int) $event['latency_ms'],
				'client_status' => (int) $event['status'],
				'page_path'     => '',
				'upstream_id'   => '',
				'mcp'           => array(),
				'class'         => self::verdict( 'orphaned', 'browser', false, __( 'Never reached WordPress — rejected or dropped in front of it', 'newtide-public-agent' ) ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);

		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	/**
	 * The diagnostic summary for a window.
	 *
	 * @param int $days Window in days.
	 * @return array
	 */
	public function summary( $days = 7 ) {
		$this->sweep();

		$since   = $this->since( $days );
		$timeout = $this->plugin->settings->agent_timeout();
		$mcp     = $this->plugin->store->events_since( 'mcp', $since );
		$rows    = $this->plugin->store->turns_since( $since );
		$client  = $this->plugin->store->events_since( 'client', $since );

		$by_hop    = array();
		$by_code   = array();
		$agent_ms  = array();
		$server_ms = array();
		$browser   = array();
		$near      = 0;
		$known     = array();

		foreach ( $rows as $row ) {
			$known[ $row['turn_id'] ] = true;

			if ( ! empty( $row['is_mock'] ) ) {
				continue;
			}

			$class = self::classify( $row, $timeout, self::mcp_for_turn( $row, $mcp ) );

			if ( 'in_flight' === $class['code'] ) {
				continue;
			}

			$by_code[ $class['code'] ] = isset( $by_code[ $class['code'] ] ) ? $by_code[ $class['code'] ] + 1 : 1;

			if ( ! $class['ok'] ) {
				$by_hop[ $class['hop'] ] = isset( $by_hop[ $class['hop'] ] ) ? $by_hop[ $class['hop'] ] + 1 : 1;
			}

			if ( 200 === (int) $row['status'] ) {
				$agent_ms[]  = (int) $row['latency_ms'];
				$server_ms[] = max( 0, (int) $row['server_ms'] - (int) $row['latency_ms'] );

				if ( (int) $row['latency_ms'] >= $timeout * 800 ) {
					++$near;
				}
			}

			if ( (int) $row['client_ms'] > 0 && (int) $row['client_status'] < 400 ) {
				$browser[] = (int) $row['client_ms'];
			}
		}

		// Browser reports for turns the server has no row for.
		$orphans = 0;
		foreach ( $client as $event ) {
			if ( ! isset( $known[ $event['turn_id'] ] ) ) {
				++$orphans;
			}
		}

		if ( $orphans > 0 ) {
			$by_hop['browser']   = $orphans;
			$by_code['orphaned'] = $orphans;
		}

		$mcp_ms   = array();
		$mcp_boot = array();
		$mcp_err  = 0;
		foreach ( $mcp as $event ) {
			$mcp_ms[]   = (int) $event['latency_ms'];
			$mcp_boot[] = (int) $event['boot_ms'] + (int) $event['queue_ms'];
			if ( (int) $event['status'] >= 400 ) {
				++$mcp_err;
			}
		}

		$turns   = array_sum( $by_code );
		$dropped = array_sum( $by_hop );

		arsort( $by_hop );

		return array(
			'days'      => (int) $days,
			'turns'     => $turns,
			'dropped'   => $dropped,
			'by_hop'    => $by_hop,
			'by_code'   => $by_code,
			'near'      => $near,
			'agent'     => self::percentiles( $agent_ms ),
			'wordpress' => self::percentiles( $server_ms ),
			'browser'   => self::percentiles( $browser ),
			'mcp'       => array_merge( self::percentiles( $mcp_ms ), array( 'errors' => $mcp_err ) ),
			'mcp_boot'  => self::percentiles( $mcp_boot ),
			'timeout'   => $timeout,
		);
	}

	/**
	 * The single sentence to lead with: where turns are being lost.
	 *
	 * @param array $summary summary().
	 * @return string
	 */
	public static function verdict_text( array $summary ) {
		if ( 0 === (int) $summary['turns'] ) {
			return __( 'No turns recorded yet. Ask the agent something on the site, then come back.', 'newtide-public-agent' );
		}

		if ( 0 === (int) $summary['dropped'] ) {
			if ( $summary['near'] > 0 ) {
				return sprintf(
					/* translators: 1: count of turns, 2: timeout seconds. */
					_n( 'Nothing was dropped, but %1$d turn came within 20%% of the %2$d-second timeout. Raise the timeout before it starts failing.', 'Nothing was dropped, but %1$d turns came within 20%% of the %2$d-second timeout. Raise the timeout before they start failing.', (int) $summary['near'], 'newtide-public-agent' ),
					(int) $summary['near'],
					(int) $summary['timeout']
				);
			}

			return __( 'No turns were dropped in this window.', 'newtide-public-agent' );
		}

		$hops  = self::hop_labels();
		$worst = (string) key( $summary['by_hop'] );

		$advice = array(
			'host'      => __( 'The agent is answering, but the host or CDN closes the connection before the reply reaches the visitor. Raise the proxy/gateway timeout at the host (Cloudflare allows 100 seconds; many hosts default to 60), or lower the agent timeout below it so the visitor at least gets an error.', 'newtide-public-agent' ),
			'wordpress' => __( 'PHP is stopping before the reply is sent. Ask the host for the PHP-FPM request_terminate_timeout and max_execution_time, and whether PHP workers are running out under load.', 'newtide-public-agent' ),
			'network'   => __( 'This server cannot reliably reach the agent API. Check outbound firewall rules and DNS at the host.', 'newtide-public-agent' ),
			'agent'     => __( 'The agent is slow or failing on the platform side. Send the Ref. ids below to the agent platform team, and consider raising the agent timeout.', 'newtide-public-agent' ),
			'mcp'       => __( 'Most of the wait is the agent calling back into this site for site knowledge. Those calls compete with the waiting chat for PHP workers — ask the host for more workers, or reduce how much the agent searches.', 'newtide-public-agent' ),
			'browser'   => __( 'The browser reported failures for turns WordPress never recorded: something in front of WordPress (a firewall, security plugin, or page cache serving a stale nonce) rejected them.', 'newtide-public-agent' ),
		);

		return sprintf(
			/* translators: 1: dropped count, 2: turn count, 3: where, 4: advice. */
			__( '%1$d of %2$d turns failed. Most were lost at: %3$s. %4$s', 'newtide-public-agent' ),
			(int) $summary['dropped'],
			(int) $summary['turns'],
			isset( $hops[ $worst ] ) ? $hops[ $worst ] : $worst,
			isset( $advice[ $worst ] ) ? $advice[ $worst ] : ''
		);
	}

	/**
	 * Median and 95th percentile of a list.
	 *
	 * @param int[] $values Values.
	 * @return array{count:int,p50:int,p95:int,max:int}
	 */
	public static function percentiles( array $values ) {
		$count = count( $values );

		if ( 0 === $count ) {
			return array(
				'count' => 0,
				'p50'   => 0,
				'p95'   => 0,
				'max'   => 0,
			);
		}

		sort( $values );

		$at = static function ( $p ) use ( $values, $count ) {
			return (int) $values[ (int) min( $count - 1, max( 0, (int) ceil( $p * $count ) - 1 ) ) ];
		};

		return array(
			'count' => $count,
			'p50'   => $at( 0.50 ),
			'p95'   => $at( 0.95 ),
			'max'   => (int) $values[ $count - 1 ],
		);
	}

	/**
	 * Health line for the Service Status roll-up.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function status() {
		if ( ! $this->enabled() ) {
			return array(
				'ok'      => true,
				'message' => __( 'Off — turns are not being traced. Switch it on under Behavior to find where slow or failed turns are lost.', 'newtide-public-agent' ),
			);
		}

		$summary = $this->summary( 1 );

		if ( $summary['dropped'] >= 3 ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %d: number of failed turns. */
					__( '%d turns failed in the last 24 hours. See Turn diagnostics below for where.', 'newtide-public-agent' ),
					(int) $summary['dropped']
				),
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %d: number of turns traced. */
				_n( '%d turn traced in the last 24 hours.', '%d turns traced in the last 24 hours.', (int) $summary['turns'], 'newtide-public-agent' ),
				(int) $summary['turns']
			),
		);
	}

	/**
	 * Daily housekeeping: name abandoned turns, drop old events.
	 *
	 * @return void
	 */
	public function purge() {
		$this->sweep();
		$this->plugin->store->purge_events( self::RETENTION_DAYS );
	}

	/**
	 * The local datetime N days ago (created_at is local time).
	 *
	 * @param int $days Days.
	 * @return string
	 */
	private function since( $days ) {
		return gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- created_at is local time.
	}
}
