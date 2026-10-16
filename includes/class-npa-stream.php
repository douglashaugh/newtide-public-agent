<?php
/**
 * Sends a reply to the widget as it is written (Server-Sent Events).
 *
 * The browser gets three kinds of event:
 *
 * - `html`  — the reply so far, rendered. Sent at most every HTML_EVERY
 *             seconds. Rendering stays in PHP, as it always has, so the
 *             widget never turns model output into markup itself.
 * - `done`  — the same envelope /message returns without streaming.
 * - `error` — the same error envelope, with the turn id.
 *
 * and a comment line every PING_EVERY seconds while the agent is silent. Those
 * keep the connection visibly alive to the host and any proxy in front of it,
 * whose idle timeouts would otherwise cut a long tool call off.
 *
 * In capture mode nothing is sent and nothing exits: frames are collected for
 * the test battery.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Stream
 */
class NPA_Stream {

	/**
	 * Seconds of silence before a keep-alive comment.
	 *
	 * @var int
	 */
	const PING_EVERY = 5;

	/**
	 * Shortest gap between two `html` events, seconds.
	 *
	 * @var float
	 */
	const HTML_EVERY = 0.35;

	/**
	 * Collect frames instead of sending them.
	 *
	 * @var bool
	 */
	private $capture;

	/**
	 * Captured frames, in capture mode.
	 *
	 * @var string[]
	 */
	public $frames = array();

	/**
	 * When the stream opened.
	 *
	 * @var float
	 */
	private $start = 0.0;

	/**
	 * Last time anything was sent.
	 *
	 * @var float
	 */
	private $last_write = 0.0;

	/**
	 * Last time an `html` event was sent.
	 *
	 * @var float
	 */
	private $last_html = 0.0;

	/**
	 * The reply so far.
	 *
	 * @var string
	 */
	private $text = '';

	/**
	 * Whether text has arrived since the last `html` event.
	 *
	 * @var bool
	 */
	private $dirty = false;

	/**
	 * Milliseconds from opening to the first piece of reply text; 0 until then.
	 *
	 * @var int
	 */
	private $first_ms = 0;

	/**
	 * Constructor.
	 *
	 * @param bool $capture Collect frames rather than send them.
	 */
	public function __construct( $capture = false ) {
		$this->capture = (bool) $capture;
	}

	/**
	 * Start the response.
	 *
	 * @param int $limit Seconds the stream may run.
	 * @return void
	 */
	public function open( $limit = 0 ) {
		$this->start      = microtime( true );
		$this->last_write = $this->start;

		if ( $this->capture ) {
			return;
		}

		// Finish the turn's bookkeeping even if the visitor closes the page.
		ignore_user_abort( true );

		if ( $limit > 0 && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) $limit + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts.
		}

		// Compression and output buffers both hold a stream back until the end.
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky

		/*
		 * Stop at a buffer that refuses to close (one started without the
		 * removable flag): ob_end_clean() then fails and the level never
		 * drops, and a loop on the level alone would spin forever.
		 */
		while ( ob_get_level() > 0 && @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.CodeAnalysis.EmptyStatement.DetectedWhile
		}

		http_response_code( 200 );
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, no-transform' );
		// nginx (and hosts built on it) buffer proxied responses unless told not to.
		header( 'X-Accel-Buffering: no' );
		header( 'X-Content-Type-Options: nosniff' );

		// Some proxies hold the first couple of kilobytes; get past that at once.
		$this->write( ':' . str_repeat( ' ', 2048 ) . "\n\n" );
	}

	/**
	 * A new piece of the reply.
	 *
	 * @param string $piece Text.
	 * @return void
	 */
	public function text( $piece ) {
		if ( '' === (string) $piece ) {
			return;
		}

		if ( 0 === $this->first_ms ) {
			$this->first_ms = max( 1, (int) round( ( microtime( true ) - $this->start ) * 1000 ) );
		}

		$this->text .= (string) $piece;
		$this->dirty = true;
		$this->maybe_html();
	}

	/**
	 * Called while waiting: send what is pending, or a keep-alive.
	 *
	 * @return void
	 */
	public function tick() {
		$this->maybe_html();

		if ( microtime( true ) - $this->last_write >= self::PING_EVERY ) {
			$this->write( ": ping\n\n" );
		}
	}

	/**
	 * Send the reply so far, unless one went out very recently.
	 *
	 * @return void
	 */
	private function maybe_html() {
		if ( ! $this->dirty || microtime( true ) - $this->last_html < self::HTML_EVERY ) {
			return;
		}

		$this->dirty     = false;
		$this->last_html = microtime( true );
		$this->send( 'html', array( 'html' => self::partial_html( $this->text ) ) );
	}

	/**
	 * Send one event.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Payload.
	 * @return void
	 */
	public function send( $event, array $data ) {
		$this->write( 'event: ' . $event . "\ndata: " . wp_json_encode( $data ) . "\n\n" );
	}

	/**
	 * Milliseconds until the first piece of the reply; 0 if none came.
	 *
	 * @return int
	 */
	public function first_ms() {
		return $this->first_ms;
	}

	/**
	 * End the response. Exits, except in capture mode.
	 *
	 * @return void
	 */
	public function close() {
		if ( $this->capture ) {
			return;
		}

		exit;
	}

	/**
	 * Write and push out.
	 *
	 * @param string $bytes Bytes.
	 * @return void
	 */
	private function write( $bytes ) {
		$this->last_write = microtime( true );

		if ( $this->capture ) {
			$this->frames[] = $bytes;
			return;
		}

		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an event-stream frame of JSON-encoded data.
		flush();
	}

	/**
	 * Render a reply that is still being written.
	 *
	 * An unclosed code fence is held back until it closes: a chart's JSON
	 * would otherwise spill out as text while it streams, then vanish.
	 *
	 * @param string $text Reply so far.
	 * @return string
	 */
	public static function partial_html( $text ) {
		$text = (string) $text;

		if ( 1 === substr_count( $text, '```' ) % 2 ) {
			$text = substr( $text, 0, strrpos( $text, '```' ) );
		}

		return NPA_Markdown::to_html( $text );
	}

	/**
	 * Decode captured frames, for the test battery.
	 *
	 * @param string[] $frames Frames.
	 * @return array<int,array{event:string,data:array}> Events; comments skipped.
	 */
	public static function decode( array $frames ) {
		$out = array();

		foreach ( $frames as $frame ) {
			if ( ! preg_match( '/^event: (\w+)\ndata: (.*)\n\n$/s', $frame, $m ) ) {
				continue;
			}

			$data  = json_decode( $m[2], true );
			$out[] = array(
				'event' => $m[1],
				'data'  => is_array( $data ) ? $data : array(),
			);
		}

		return $out;
	}
}
