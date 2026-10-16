<?php
/**
 * A gateway client that can hand over the reply as it is written.
 *
 * Separate from NPA_Gateway_Client rather than added to it, so the transports
 * that cannot stream (the legacy public API, the provisional HTTP gateway)
 * need no stub method: the proxy asks `instanceof` and falls back to
 * send_message() for anything that does not implement this.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface NPA_Gateway_Streaming
 */
interface NPA_Gateway_Streaming {

	/**
	 * Whether this instance will actually stream.
	 *
	 * @return bool
	 */
	public function supports_streaming(): bool;

	/**
	 * Send a message and report the reply as it arrives.
	 *
	 * Same contract as NPA_Gateway_Client::send_message() — the finished result
	 * is still returned, and errors still throw — with two callbacks on the way.
	 *
	 * @param string        $agent_id        Agent id.
	 * @param string        $message         User message.
	 * @param string        $conversation_id Conversation token.
	 * @param array         $context         Page context.
	 * @param array         $history         Prior exchanges, oldest first.
	 * @param callable      $on_text         Called with each new piece of reply text.
	 * @param callable|null $on_tick         Called often while waiting, with nothing
	 *                                       arriving — the caller's chance to keep its
	 *                                       own connection alive.
	 * @return NPA_Gateway_Result
	 * @throws NPA_Gateway_Exception On transport or API error.
	 */
	public function stream_message( string $agent_id, string $message, string $conversation_id, array $context, array $history, callable $on_text, ?callable $on_tick = null ): NPA_Gateway_Result;
}
