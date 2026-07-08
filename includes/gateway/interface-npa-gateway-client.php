<?php
/**
 * The gateway client contract — the load-bearing abstraction (plan P2).
 *
 * The whole plugin programs against THIS interface, never the gateway directly.
 * Two implementations exist: a deterministic mock (build + test against it) and
 * an HTTP client (real calls, built last). Everything the plugin needs from the
 * Public Agent Gateway is these three methods — this is also the checklist we
 * hand the gateway team.
 *
 * @package NewTide\PublicAgent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface NPA_Gateway_Client
 */
interface NPA_Gateway_Client {

	/**
	 * Send a user message to a published agent and return the agent reply.
	 *
	 * @param string $agent_id        Published-agent identifier (e.g. a RisingTide agent UUID).
	 * @param string $message         End-user message (already sanitized by the caller).
	 * @param string $conversation_id Opaque session token threading turns; empty on the first turn.
	 * @param array  $context         Optional page context (page_url, page_title, locale); send only accepted keys.
	 *
	 * @return NPA_Gateway_Result Reply text, conversation id, finish reason, token usage, raw payload.
	 *
	 * @throws NPA_Gateway_Exception On transport or gateway error (carries error code + HTTP status).
	 */
	public function send_message( string $agent_id, string $message, string $conversation_id, array $context ): NPA_Gateway_Result;

	/**
	 * List the published agents available to the configured credential.
	 *
	 * Powers the admin agent-picker. If the gateway has no list endpoint, the
	 * HTTP implementation returns an empty array and the admin UI falls back to
	 * manual agent-ID entry.
	 *
	 * @return NPA_Gateway_Agent[] Each { id, name, description }.
	 *
	 * @throws NPA_Gateway_Exception On a credential/transport error.
	 */
	public function list_agents(): array;

	/**
	 * Cheap health / credential check for the admin "Test connection" button
	 * and the Site Health integration.
	 *
	 * Never throws — an unreachable or rejected gateway is reported as an
	 * unhealthy result, not an exception.
	 *
	 * @return NPA_Gateway_Health { ok, message, latency_ms }.
	 */
	public function health_check(): NPA_Gateway_Health;
}
