<?php
/**
 * Reads an OpenAI-style chat-completions stream (Server-Sent Events).
 *
 * Fed raw bytes in whatever pieces the network delivers them, so a frame or
 * even a line can arrive split across two calls; nothing is acted on until its
 * line is complete. Each `data:` frame is a JSON chunk whose
 * `choices[0].delta.content` carries the next piece of the reply, and the
 * stream ends with `data: [DONE]`.
 *
 * Tolerant by design: comments, unknown events and frames that are not JSON
 * are skipped rather than fatal, because an agent platform may interleave its
 * own events with the completion chunks.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NPA_Sse_Reader
 */
class NPA_Sse_Reader {

	/**
	 * Bytes not yet forming a complete line.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * `data:` lines of the frame being read.
	 *
	 * @var string[]
	 */
	private $data = array();

	/**
	 * Called with each new piece of reply text.
	 *
	 * @var callable|null
	 */
	private $on_text;

	/**
	 * The reply so far.
	 *
	 * @var string
	 */
	public $text = '';

	/**
	 * finish_reason from the last chunk that gave one.
	 *
	 * @var string
	 */
	public $finish = '';

	/**
	 * Token usage, when the stream reports it.
	 *
	 * @var array
	 */
	public $usage = array();

	/**
	 * Whether `[DONE]` arrived.
	 *
	 * @var bool
	 */
	public $done = false;

	/**
	 * An error the stream itself reported, or ''.
	 *
	 * @var string
	 */
	public $error = '';

	/**
	 * Frames read. Zero after a complete body means it was not a stream.
	 *
	 * @var int
	 */
	public $frames = 0;

	/**
	 * Constructor.
	 *
	 * @param callable|null $on_text Called with each new piece of reply text.
	 */
	public function __construct( $on_text = null ) {
		$this->on_text = $on_text;
	}

	/**
	 * Take the next bytes.
	 *
	 * @param string $chunk Raw bytes.
	 * @return void
	 */
	public function feed( $chunk ) {
		$this->buffer .= (string) $chunk;

		while ( false !== ( $pos = strpos( $this->buffer, "\n" ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$line         = rtrim( substr( $this->buffer, 0, $pos ), "\r" );
			$this->buffer = (string) substr( $this->buffer, $pos + 1 );
			$this->line( $line );
		}
	}

	/**
	 * The body has ended: act on whatever is left.
	 *
	 * @return void
	 */
	public function finish() {
		if ( '' !== $this->buffer ) {
			$line         = rtrim( $this->buffer, "\r" );
			$this->buffer = '';
			$this->line( $line );
		}

		$this->dispatch();
	}

	/**
	 * One complete line.
	 *
	 * @param string $line Line, without its ending.
	 * @return void
	 */
	private function line( $line ) {
		if ( '' === $line ) {
			$this->dispatch();
			return;
		}

		// A comment: keep-alives look like this.
		if ( ':' === $line[0] ) {
			return;
		}

		$colon = strpos( $line, ':' );
		$field = false === $colon ? $line : substr( $line, 0, $colon );
		$value = false === $colon ? '' : (string) substr( $line, $colon + 1 );

		if ( '' !== $value && ' ' === $value[0] ) {
			$value = (string) substr( $value, 1 );
		}

		if ( 'data' === $field ) {
			$this->data[] = $value;
		}
	}

	/**
	 * The frame is complete.
	 *
	 * @return void
	 */
	private function dispatch() {
		if ( ! $this->data ) {
			return;
		}

		$payload    = implode( "\n", $this->data );
		$this->data = array();
		++$this->frames;

		if ( '[DONE]' === trim( $payload ) ) {
			$this->done = true;
			return;
		}

		$json = json_decode( $payload, true );

		if ( ! is_array( $json ) ) {
			return;
		}

		if ( isset( $json['error'] ) ) {
			$error       = $json['error'];
			$this->error = is_string( $error ) ? $error : ( is_array( $error ) && isset( $error['message'] ) ? (string) $error['message'] : 'The agent reported an error mid-reply.' );
			return;
		}

		if ( isset( $json['usage'] ) && is_array( $json['usage'] ) ) {
			$this->usage = $json['usage'];
		}

		$choice = ( isset( $json['choices'][0] ) && is_array( $json['choices'][0] ) ) ? $json['choices'][0] : null;

		if ( null === $choice ) {
			return;
		}

		$piece = null;
		if ( isset( $choice['delta']['content'] ) && is_string( $choice['delta']['content'] ) ) {
			$piece = $choice['delta']['content'];
		} elseif ( isset( $choice['message']['content'] ) && is_string( $choice['message']['content'] ) ) {
			// A whole message in one frame — some servers send the reply that way.
			$piece = $choice['message']['content'];
		}

		if ( null !== $piece && '' !== $piece ) {
			$this->text .= $piece;
			if ( null !== $this->on_text ) {
				call_user_func( $this->on_text, $piece );
			}
		}

		if ( ! empty( $choice['finish_reason'] ) && is_string( $choice['finish_reason'] ) ) {
			$this->finish = $choice['finish_reason'];
		}
	}
}
