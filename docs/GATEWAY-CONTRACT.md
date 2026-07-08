# Public Agent Gateway — Contract (PROVISIONAL)

**Status:** provisional. The plugin is built against the **mock** (`NPA_Gateway_Client_Mock`) using these assumptions. Each field is unconfirmed until the real RisingTide / NewTide public-agent API spec lands. When it does: reconcile this doc, then implement `NPA_Gateway_Client_Http` to match — that is the only code that should need to change.

Target agent for first integration: **`37cf3d4c-e12b-485f-978d-019aa5db96be`** (RisingTide AI, `ai.newtide.ai`).

The entire contract the plugin needs is three methods (`includes/gateway/interface-npa-gateway-client.php`): `send_message`, `list_agents`, `health_check`.

## Assumed endpoints (confirm names/paths)

| Purpose | Assumed method + path | Confirm |
|---|---|---|
| Send message | `POST {base}/v1/agents/{agent_id}/messages` | Path; whether `agent_id` is in the path or the body |
| List agents | `GET {base}/v1/agents` | Existence; filtering by credential |
| Health / whoami | `GET {base}/v1/health` (or `/v1/whoami`) | Which; whether it validates the key |

`{base}` unknown — likely under `ai.newtide.ai` or a dedicated gateway host.

## Assumed auth (confirm)

Credential sent as `Authorization: Bearer <key>` **or** `X-NewTide-Key: <key>`.
**Decisive open question:** is it a *publishable* key (safe in the browser, domain-locked + CORS) or a *secret* key (server/proxy only)? This one answer sets the widget transport. Default until answered: **secret + server-side proxy.**

## Assumed request body — send_message (confirm field names)

```json
{
  "message": "string — the user's message",
  "conversation_id": "string — opaque, empty on first turn",
  "context": { "page_url": "string", "page_title": "string", "locale": "en_US" },
  "metadata": { "source": "wordpress-plugin", "plugin_version": "0.1.0", "site": "example.com" }
}
```

## Assumed response body (confirm)

```json
{
  "reply": "string — agent's reply text",
  "conversation_id": "string — echo/assigned session token",
  "finish_reason": "stop | length | filtered | error",
  "usage": { "input_tokens": 0, "output_tokens": 0 }
}
```

Mapped to `NPA_Gateway_Result { reply_text, conversation_id, finish_reason, input_tokens, output_tokens, raw }`.

## Assumed error shape (confirm)

Non-2xx with `{ "error": { "code": "...", "message": "..." } }`. The plugin surfaces a generic, friendly message to visitors and logs the detail admin-side. `NPA_Gateway_Exception` carries a stable `error_code` + `http_status`. Special-cased branches (proven in the mock suite):

- **401 / 403** → bad or revoked key: tell the admin, not the visitor.
- **429** → rate-limited: widget shows "busy, try again."
- **5xx** → gateway down: graceful fallback message.

## The 6 open questions for the gateway team

1. **Publishable vs secret key?** Domain-locking mechanism? CORS allow-list, or proxy-only?
2. Is there a **list-agents endpoint** scoped to the credential, or must the admin paste an agent ID?
3. **Streaming:** SSE, chunked, WebSocket/SignalR, or non-streaming only for public agents? (MVP is non-streaming regardless — this is for later planning.)
4. **Conversation threading:** does the gateway issue a `conversation_id`, or is each turn stateless?
5. What request `context` / `metadata` does it accept, and is any of it required?
6. **Rate-limit / error response shapes** and the 429 `Retry-After` convention.

## Prerequisite (platform-side, not plugin work)

Before M8 (real integration) can land, the target agent must be **published as a public agent** and reachable through a **public invocation API with a credential**. As of 2026-07-08 this is unconfirmed — the workbench is authenticated, not a runtime API. The plugin is fully buildable and testable against the mock until then.
