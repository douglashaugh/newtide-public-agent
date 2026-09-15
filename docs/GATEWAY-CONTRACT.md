# Public Agent Gateway — Contract (PROVISIONAL)

> ## The Agent API — documented, OpenAI-compatible, multi-turn (2026-09-15)
>
> `POST {base}/v1/chat/completions`, bearer `wbk_` key. **Supersedes everything
> below for new work.** Hosts: `https://myagents-api.newtide.ai` (production),
> `https://myagents-uat-api.newtide.ai` (UAT) — note these are a different host
> family from `ai-api.newtide.ai`, so nothing derives one from the other.
>
> Standard OpenAI request and response. `model` is ignored; the key selects the
> agent. `messages` is a real role/content array, so **conversations are native** —
> verified by planting a code in a prior turn and getting it back. Responses carry
> `usage.prompt_tokens` / `completion_tokens`, which finally populates the usage
> table's token columns.
>
> **Measured deviations from the published instructions — all cost time:**
>
> | Documented | Actual |
> |---|---|
> | `curl -H "Authorization: Bearer wbk_…"` alone | **401.** An `Origin` header is required and is not mentioned anywhere. |
> | `X-API-Key` listed in the CORS allow-headers | Rejected. Bearer only. |
> | — | Origin is matched **exactly** against the key's allow-list: `https://example.com` passes, `https://www.example.com` does not. |

**The announced origin is configurable** (0.8.2, Agent tab → Announced origin, or
`NPA_AGENT_API_ORIGIN`). It defaults to `home_url()`, which is right when the key
was issued for the site it is installed on. It has to be settable because the
match above is exact and one-sided: a key issued for the `www.` form of a host
refuses the bare form, and a site cannot change its WordPress address to suit a
key. Origin is not a credential on this path — the key is, and the server writes
the header itself — so letting the owner state which of their own origins is
registered concedes nothing that `curl` did not already have.

**A revoked key returns 401 for every origin, with the same body** —
`{"error":"Unauthorized"}` — as a wrong origin does. Measured 2026-09-15 against
a rotated key: identical responses for the allowed origin, the `www.` variant and
no `Origin` header at all. So a 401 alone does not distinguish the two, and the
cheapest way to tell them apart is to try a key known to be live. The plugin's
health check says both causes for this reason.
>
> **The key is a secret despite the origin check.** Origin scoping stops another
> website using the key from a browser; it stops nothing for anyone holding it,
> because a server sets the header itself — which is how all of this was measured.
> Keep it in `wp-config.php` (`NPA_AGENT_API_KEY`) and never in page output.
>
> Per-agent toggles gate two features the plugin does not use: *Accept
> instructions* (a `system` message) and *Allow caller-provided tools*. If tools
> are enabled and the agent asks to call one, the reply comes back empty with
> `finish_reason: tool_calls`; the client reports that specifically rather than as
> an empty response.

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
>   origin**, i.e. the platform host. A server must send it explicitly.
> - `X-Embed-Origin: <parent page origin>` — the parent page the widget is on.
>
> **Which of the two is measured against the key's allowed-origins list is not
> known, and behaves differently between keys.** One key worked with the platform
> value in `Origin` and the site in `X-Embed-Origin`; another, whose allowed list
> held only the customer's site, was refused with
> `Origin not permitted for this API key.` until `Origin` carried the site value
> instead. The plugin therefore tries the site origin first, falls back to the
> platform origin on an origin-specific refusal, and caches whichever the key
> accepts (`NPA_Gateway_Client_Public::dispatch()`).
>
> **Question for the platform team:** which header is authoritative, and should a
> server-side caller send its own site as `Origin`? An answer removes the
> negotiation entirely. The iframe derives
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
> **UAT and PROD share one contract** (verified 2026-09-13). `agent-embed.js` is
> byte-identical between hosts, and the two `PublicChatEmbed` chunks differ by
> four bytes — the length of the hostname string. Same endpoints, same
> `{message}` body, same headers. So an agent that works in UAT and fails in PROD
> is an agent or permissions difference, never a protocol one; do not go looking
> for a second contract.
>
> **Errors arrive inside a 200.** An agent-side failure is reported as a frame in
> the stream, not as an HTTP status:
>
> ```
> event: error
> data: {"error":"Internal error."}
> ```
>
> So a 200 does not mean the agent answered, and a client that only parses text
> frames sees an empty stream and misreports the cause. `collect_stream_error()`
> exists for this. Observed 2026-09-12 on a newly created TOE agent whose
> `/public/agent/info` succeeded — metadata can be readable while the chat itself
> fails, which is why Test connection passing proves the key, origin and
> environment but says nothing about whether the agent will answer.
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
> ### Newly created agents return "Internal error." — RESOLVED 2026-09-14
>
> Only a pre-existing agent answers. Every agent created during this
> investigation fails on first message, in both environments.
>
> | Agent | Created | Env | Result |
> |---|---|---|---|
> | TEI Info Agent | before 2026-09 | UAT | answers |
> | Thinking On Energy Public Agent | 2026-09 | UAT | `Internal error.` |
> | TOE agent | 2026-09 | PROD | `Internal error.` |
>
> **Ruled out, each by measurement rather than reasoning:**
>
> - *Environment* — UAT and PROD are byte-identical (see above).
> - *Key, origin, publishing* — `/public/agent/info` returns `success: true` for
>   the failing agents, so the key resolves and the agent is registered public.
> - *The plugin* — the same key fails through RisingTide's own `agent-embed.js`
>   iframe. Their client, their key, their agent.
> - *Agent metadata* — the public payloads are structurally identical between a
>   working and a failing agent. A populated `nickName` was the only difference;
>   clearing it changed nothing.
>
> **Cause: permissions in RisingTide.** A publishable key runs as the non-admin
> user it is bound to. That user needs the agent's **"use"** permission plus
> access to every knowledge or data source the agent reads. A newly created agent
> does not inherit these, so registration and metadata succeed while execution
> fails — `Internal error.` is what a permissions gap looks like from outside.
> The Publishing tab has warned about this since 0.1.0: *"If the widget appears
> but the agent errors out, check this first."* It was right.
>
> **A hypothesis that was wrong, recorded so it is not revisited:** that agents
> built with the new agent tooling (production, week of 2026-09-08) could not be
> executed by the legacy public-agent runtime, TEI predating it and every failing
> agent postdating it. It fitted every observation and was still false. The
> correlation was real — the new agents were new, and nobody had granted their
> permissions — but the cause was setup, not a migration boundary.
>
> **Not answerable from WordPress.** The public API exposes five display fields and
> no execution detail. The only actionable signal in the whole investigation was an
> error string inside a 200 response. Worth raising on its own: an integrator has
> no supported way to see why an agent is failing.
>
> **The test that would have found it fastest** was the Playground on a failing
> agent: it runs as a super admin while the key runs as its bound non-admin user,
> so a Playground that answers while the key does not isolates permissions in one
> click. Reach for that before comparing metadata — the public payload cannot show
> the cause, and two structurally identical payloads say nothing about it.
>
> Since 0.6.8 the plugin says this itself: an upstream "Internal error." is
> reported in Service Status with permissions named as the likely cause.

> ### Conversation continuity: none, and not for want of asking
>
> Measured 2026-09-11 against the live UAT agent with the plugin's conversation
> probe (Agent tab, Proxy mode). Each shape planted a random code in turn one and
> asked for it back in turn two:
>
> | Request body | Retained? |
> |---|---|
> | `{message}` (what the embed client sends) | no |
> | `{message, chatId}` | no |
> | `{message, conversationId}` | no |
> | `{message, sessionId}` | no |
> | `{message, history:[…]}` | no |
> | `{message, messages:[…]}` | no |
>
> Every reply was a cold start — *"Each conversation with me starts fresh — I have
> no memory…"*. **`POST /public/chat/stream` is single-turn**, and the server
> ignores every threading field offered to it.
>
> This is a platform limitation, not a plugin one. The embed client sends
> `{message}` alone and never forwards the `chatId` that `agent-embed.js`
> generates, so **RisingTide's own iframe widget behaves identically on every
> public agent**. Anyone deploying one today has a chat that cannot follow up.
>
> **For the platform team:** can `/public/chat/stream` carry conversation
> continuity — a server-side thread keyed by an id the caller supplies, or an
> accepted history array? Until then any multi-turn experience has to be
> reconstructed by each client, which duplicates the work and puts visitor text
> into prompt-composition code that sits outside the platform's guardrails.

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
