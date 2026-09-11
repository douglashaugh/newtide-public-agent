# Public Agent Gateway — Contract (PROVISIONAL)

> ## Finding, 2026-09-11 — the assumed API is not what the public path uses
>
> Confirmed by reading the live loader at `https://uat-ai.newtide.ai/agent-embed.js`
> after getting Embed mode answering on a real site.
>
> **`agent-embed.js` does not call a message API. It creates an iframe:**
>
> ```js
> var src = apiKey
>   ? platformUrl.replace(/\/$/,"") + "/embed/public-chat?chatId=" + chatId + "#apiKey=" + apiKey
>   : platformUrl.replace(/\/$/,"") + "/agent-workbench?agentId=" + agentId + "&embed=true&chatId=" + chatId;
> ```
>
> The conversation UI is a RisingTide-hosted page inside that frame. The key is
> passed in the URL **fragment**, deliberately, so it never reaches a server log
> or a `Referer` header. The loader reads only five attributes —
> `data-api-key`, `data-agent-id`, `data-chat-id`, `data-container`,
> `data-platform-url` — and exposes **no theming hooks at all**.
>
> Three consequences, all load-bearing:
>
> 1. **`POST /v1/agents/{id}/messages` is not part of the public-agent path.** The
>    endpoints below remain unconfirmed, and the one transport we have proven does
>    not resemble them.
> 2. **Proxy mode cannot be enabled by configuration.** You cannot proxy an
>    iframe. It needs a genuine server-to-server API that has not been shown to
>    exist for public agents.
> 3. **The plugin's Appearance and Behavior settings cannot reach the embedded
>    widget** either — there is nothing to pass them to. Customisation depends
>    entirely on question 0 below.
>
> ### Question 0 — the one that decides whether Proxy mode has a future
>
> *Is there a server-to-server HTTP API for published public agents — send a
> message, receive a reply as JSON, authenticated by a secret credential held on
> the server?*
>
> - **Yes** → reconcile this document against it and implement
>   `NPA_Gateway_Client_Http` to match. Proxy mode and the plugin's own styled
>   widget become possible.
> - **No** → Proxy mode should be removed rather than shipped as a selectable
>   option that silently falls back to the mock, and the plugin is an
>   Embed-only client whose Appearance tab should go with it.
>
> ### Update, same day — the API was found, and it is real
>
> Recovered by reading the SPA chunk the embed page lazy-loads
> (`/assets/PublicChatEmbed-*.js`). **This supersedes every assumed endpoint below.**
>
> **Hosts** — a separate API domain from the platform:
>
> | Platform | API |
> |---|---|
> | `https://ai.newtide.ai` | `https://ai-api.newtide.ai` |
> | `https://uat-ai.newtide.ai` | `https://uat-ai-api.newtide.ai` |
>
> Both respond. An unauthenticated probe returns a clean envelope, so the surface
> is real and deliberate, not an accident:
>
> ```
> HTTP 401  {"success":false,"message":"Missing X-Api-Key header.","data":null,"errors":null}
> ```
>
> **Auth** — `X-Api-Key: <pk_ key>`. Not `Authorization: Bearer`. The credential is
> the *publishable* key; there is no separate server secret on this path.
>
> **Origin headers — BOTH are required.** A call with a valid key but no `Origin`
> is rejected with `Origin header is required.`, which reads like a credential
> problem and is not. Key validation runs first: a bad key returns
> `Invalid or revoked API key.` regardless of origin headers, so reaching the
> origin error means the key is good.
>
> - `Origin:` — in the browser this is set automatically to the **iframe's own
>   origin**, i.e. the platform host. It is the same value for every customer, so
>   it cannot be the allowed-origins check. A server must send it explicitly.
> - `X-Embed-Origin: <parent page origin>` — the per-key allowed-origins check. The iframe derives
> it from `window.location.ancestorOrigins[0]`, falling back to the `document.referrer`
> origin. **It is an ordinary request header the caller sets**, which is why a server
> can call this API too: WordPress would send its own site origin.
>
> **Endpoints**
>
> ```
> GET  {api}/public/agent/info     headers: X-Api-Key, X-Embed-Origin
>                                  -> { success, data, message, errors }
>
> POST {api}/public/chat/stream    headers: X-Api-Key, X-Embed-Origin, Content-Type: application/json
>                                  body:    { "message": "..." }
>                                  -> text/event-stream
> ```
>
> **Response shape** — `/public/chat/stream` is **Server-Sent Events**, not JSON.
> Frames are separated by a blank line; text arrives as events of
> `Event: "TextDelta"` carrying `Data.Text`. `NPA_Gateway_Client_Http` expects a
> JSON body and would need to accumulate the stream instead. The plugin's widget
> is non-streaming, so accumulating server-side and returning the finished reply
> is enough — no streaming transport to the browser is required.
>
> **Rate limiting** — HTTP 429 with a `Retry-After` header in seconds. The embed UI
> turns that into escalating copy (seconds / minutes / "daily limit").
>
> ### What this means for Proxy mode
>
> **It can work.** Point `NPA_Gateway_Client_Http` at the API host, send
> `X-Api-Key` plus an `X-Embed-Origin` of the site's own origin, and accumulate the
> SSE body. The plugin then renders its own widget and the Appearance and Behavior
> tabs become meaningful again.
>
> Four things to settle before depending on it:
>
> 1. **It is undocumented.** Found by reading a minified bundle. The platform team
>    should confirm it is supported and will not change without notice. Question 0
>    above still needs asking — the answer now looks like "yes, this is it".
> 2. **The credential is the publishable key**, so "the secret never reaches the
>    browser" is no longer the argument for Proxy mode. The real benefits become:
>    the plugin's own UI and customisation, the key not sitting in page HTML, and
>    origin enforcement moving server-side.
> 3. **No conversation id is sent.** The body is `{message}` alone, so multi-turn
>    memory may not survive between requests on this path. Confirm before promising
>    a threaded conversation.
> 4. **Untested with a real key.** The 401 proves the endpoint and its header
>    contract; nothing here proves a successful exchange.


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
