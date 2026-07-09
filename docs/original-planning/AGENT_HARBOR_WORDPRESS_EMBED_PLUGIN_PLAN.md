# RisingTide Agent Embed — WordPress Plugin Implementation Plan

**Version:** 1.0
**Date:** July 6, 2026
**Status:** Ready for intern kickoff (gateway spec pending — see §2)
**Working name:** RisingTide Agent Embed (`risingtide-agent-embed`)
**Text domain / prefix:** `rtae` (RisingTide Agent Embed)
**Stack:** WordPress 6.x, PHP 8.1+, vanilla JS + `@wordpress/scripts` build, PHPUnit via `wp-env`
**Repo:** `NewTideAI/risingtide-agent-embed` (new)
**Branch strategy:** `feature/<slice>` → PR into `develop`; small, independently mergeable PRs
**Owner:** Intern (entry-level project) — reviewer: assign one senior on the RisingTide team
**Target:** A self-contained WordPress plugin that lets a site owner embed one **published** Agent Harbor agent on their website (support chat, guided browsing, sales-inquiry response). The plugin is a thin client. The Public Agent Gateway owns identity, safety, cost control, rate limiting, domain-locking, and guardrails.

---

## 0. Design Principles (non-negotiable)

**P1 — Thin client.** The plugin renders UI and relays messages. It does **not** implement authentication logic, rate limiting for abuse prevention, prompt-injection defense, PII scrubbing, or cost control. Those belong to the gateway. If the intern finds themselves writing security logic in PHP, that is the signal to stop and ask.

**P2 — Program against a contract, not the gateway.** Every call to the gateway goes through one interface (`RTAE_Gateway_Client`) with two implementations: a **mock** (`RTAE_Gateway_Client_Mock`, canned responses) and an **HTTP** (`RTAE_Gateway_Client_Http`, real calls). Build the whole plugin against the mock. Never block on the real endpoint existing. When the gateway spec lands, only the HTTP implementation and the contract stub in §2 change.

**P3 — Smallest vertical slice first.** MVP is: settings screen → store agent ID + key reference → shortcode/block enqueues a widget → widget does one **non-streaming** round-trip → render the reply. Everything else is a deferral (§11).

**P4 — WordPress correctness is the grade.** The plugin is judged on: capability checks on every privileged action, nonces on every state change and AJAX/REST call, sanitize-on-input / escape-on-output everywhere, correct use of the Settings API, internationalization (`__()` with the text domain), no secret key ever emitted to the browser, and no blocking of page render on a remote call.

**P5 — Standards are features, built in from PR #1.** Administration (§4), Configuration (§5), Testing (§7), and Monitoring (§8) are not a final phase. Each is present from the first slice and grows with the plugin.

**P6 — Ship in small PRs.** Each PR is independently mergeable and leaves the plugin working (against the mock). No multi-week branches.

---

## 1. Architecture & Data Flow

Two surfaces:

- **Admin surface** — a settings page under Settings (or a top-level menu) where the site owner configures which agent to embed and how it looks. Gated by `manage_options`.
- **Front-end surface** — the embed widget placed via shortcode, a Gutenberg block, or an optional site-wide auto-inject.

**Recommended request path (server-side proxy — default):**

```
Browser widget (JS)
  → POST /wp-json/rtae/v1/message   (same-origin; WP REST nonce)
    → PHP: RTAE_Gateway_Client_Http adds the gateway credential (server-side)
      → RisingTide Public Agent Gateway  (agent invocation)
    ← gateway response JSON
  ← PHP relays sanitized response
← widget renders reply
```

Why the proxy is the default: the gateway credential never reaches the browser; there is no cross-origin (CORS) problem because the browser only ever talks to its own WordPress site; and the PHP layer is a natural place for a WP nonce check and an optional per-site request throttle. The alternative — browser calling the gateway directly with a publishable, domain-locked key — is lighter but only appropriate if the gateway is explicitly designed for it (publishable key + CORS allow-list). **Confirm this against the gateway spec (§2) before locking the widget's transport.** The client abstraction (P2) is built so either path is a small change.

**The client abstraction is the load-bearing decision.** Define it once:

```php
interface RTAE_Gateway_Client {
    /**
     * Send a user message to a published agent and return the agent reply.
     * @param string $agent_id      Published-agent identifier from Agent Harbor.
     * @param string $message       End-user message (already sanitized).
     * @param string $conversation_id  Opaque session token to thread turns (may be empty on first turn).
     * @param array  $context       Optional page context (url, title, locale) — send only what the gateway accepts.
     * @return RTAE_Gateway_Result  { reply_text, conversation_id, finish_reason, raw }
     * @throws RTAE_Gateway_Exception on transport/gateway error.
     */
    public function send_message( string $agent_id, string $message, string $conversation_id, array $context ): RTAE_Gateway_Result;

    /**
     * List the published agents available to the configured credential.
     * Used by the admin agent-picker. If the gateway has no list endpoint,
     * the HTTP impl returns an empty array and the admin UI falls back to manual entry.
     * @return RTAE_Gateway_Agent[]  each { id, name, description }
     */
    public function list_agents(): array;

    /**
     * Cheap health/credential check for the admin "Test connection" button and Site Health.
     * @return RTAE_Gateway_Health  { ok:bool, message:string, latency_ms:int }
     */
    public function health_check(): RTAE_Gateway_Health;
}
```

Everything the plugin needs from the gateway is these three methods. That is also the checklist you hand the gateway team.

---

## 2. Gateway Contract (PROVISIONAL — confirm against gateway spec)

This is the de-risking core. The intern builds against these assumptions via the **mock**; each field is provisional until the real spec lands. When it lands, reconcile this section and implement `RTAE_Gateway_Client_Http` to match.

**Assumed endpoints (confirm names/paths):**

| Purpose | Assumed method + path | Confirm |
|---|---|---|
| Send message | `POST {base}/v1/agents/{agent_id}/messages` | path, whether agent_id is in path or body |
| List agents | `GET {base}/v1/agents` | existence, filtering by credential |
| Health / whoami | `GET {base}/v1/health` or `GET {base}/v1/whoami` | which, and whether it validates the key |

**Assumed auth (confirm):** a credential sent as `Authorization: Bearer <key>` **or** `X-RisingTide-Key: <key>`. Confirm whether it is a *publishable* key (safe in browser, domain-locked) or a *secret* key (proxy-only). This single answer decides §1's transport.

**Assumed request body for send_message (confirm field names):**

```json
{
  "message": "string — the user's message",
  "conversation_id": "string — opaque, empty on first turn",
  "context": { "page_url": "string", "page_title": "string", "locale": "en_US" },
  "metadata": { "source": "wordpress-plugin", "plugin_version": "1.0.0", "site": "example.com" }
}
```

**Assumed response body (confirm):**

```json
{
  "reply": "string — agent's reply text",
  "conversation_id": "string — echo/assigned session token",
  "finish_reason": "stop | length | filtered | error",
  "usage": { "input_tokens": 0, "output_tokens": 0 }
}
```

**Assumed error shape (confirm):** non-2xx with `{ "error": { "code": "...", "message": "..." } }`. The plugin surfaces a generic, friendly message to end users and logs the detail admin-side (§8). Special-case at least: `401/403` (bad or revoked key → tell the admin, not the visitor), `429` (rate-limited by gateway → widget shows "busy, try again"), `5xx` (gateway down → graceful fallback message).

**Open questions to send the gateway team (put in the PR description of PR #2):**
1. Publishable vs secret key? Domain-locking mechanism? CORS allow-list, or proxy-only?
2. Is there a list-agents endpoint scoped to the credential, or must the admin paste an agent ID?
3. Streaming: SSE, chunked, WebSocket/SignalR, or non-streaming only for public agents? (MVP is non-streaming regardless — this is for §11 planning.)
4. Conversation threading: does the gateway issue a `conversation_id`, or is each turn stateless?
5. What request `context`/`metadata` does it accept, and is any of it required?
6. Rate-limit/error response shapes and the 429 retry-after convention.

Until answered, the **mock** stands in. The mock must be good: deterministic replies, a way to simulate `401`, `429`, `5xx`, and slow responses, so tests (§7) exercise the error paths.

---

## 3. Plugin File Structure

Follows the NewTide `class-<name>.php` convention adapted from the TOE plugin, namespaced `rtae`.

```
risingtide-agent-embed/
├── risingtide-agent-embed.php        # Main plugin file: header, constants, autoload, bootstrap
├── uninstall.php                     # Clean removal of options (see §5)
├── readme.txt                        # WordPress.org-style readme (build toward, §12)
├── includes/
│   ├── class-rtae-plugin.php         # Bootstrap/singleton; wires hooks
│   ├── class-rtae-settings.php       # Settings API registration, sanitization
│   ├── class-rtae-admin.php          # Admin page render, "Test connection", agent picker
│   ├── class-rtae-rest.php           # REST proxy: /wp-json/rtae/v1/message, permission_callback
│   ├── class-rtae-shortcode.php      # [risingtide_agent] shortcode
│   ├── class-rtae-logger.php         # Structured, opt-in logging + recent-calls ring buffer
│   ├── class-rtae-health.php         # Site Health test + admin status panel data
│   ├── gateway/
│   │   ├── interface-rtae-gateway-client.php
│   │   ├── class-rtae-gateway-client-http.php
│   │   ├── class-rtae-gateway-client-mock.php
│   │   ├── class-rtae-gateway-result.php
│   │   └── class-rtae-gateway-exception.php
├── src/                              # JS/CSS source, built by @wordpress/scripts
│   ├── widget/index.js               # Front-end chat widget
│   ├── widget/widget.css
│   ├── block/index.js                # Gutenberg block (registers via block.json)
│   └── block/block.json
├── build/                            # Compiled assets (git-ignored or committed per team norm)
├── languages/                        # .pot for i18n
├── tests/
│   ├── phpunit/                      # WP_UnitTestCase tests
│   └── bootstrap.php
├── .wp-env.json                      # Local WP + test DB via Docker
├── phpunit.xml.dist
├── package.json                      # wp-scripts build/lint
└── composer.json                     # Dev deps: phpunit, wp coding standards (phpcs)
```

---

## 4. Administration (standard — first-class)

The admin experience is where an intern demonstrates WordPress fluency. Requirements:

**Settings page.** Register under Settings → "RisingTide Agent" (or a top-level menu if the team prefers). Render with the Settings API (`add_settings_section`, `add_settings_field`, `settings_fields`, `do_settings_sections`). Every field save goes through the Settings API's sanitize callback (§5). Gate the whole page with `current_user_can( 'manage_options' )`.

**Fields (MVP):**
- Gateway base URL (text; validated as URL).
- Credential source indicator (read-only) — see §5 on why the key itself is preferably a `wp-config.php` constant, not a text field.
- If the key must be entered in the UI: a password-type field, write-only (never re-render the stored value; show a "key is set" indicator instead).
- Agent selection — a dropdown populated by `list_agents()` if the gateway supports it; otherwise a plain text field for the agent ID. Always allow manual override.
- Widget presentation options: launcher label, greeting text, position (bottom-right/bottom-left), accent color, enable/disable.

**"Test connection" button.** Fires an admin-ajax or REST call (nonce-protected, capability-checked) that runs `health_check()` and shows ok/latency or a specific error (bad key vs unreachable vs agent-not-found). This is the intern's first proof the gateway wiring works — against the mock initially.

**Agent picker.** If `list_agents()` returns results, render a select with name + description. If it returns empty (gateway has no list endpoint, or key not yet valid), degrade to manual ID entry with helper text. Never hard-fail the settings page because the gateway is unreachable.

**Status panel.** A small panel on the settings page showing: connection status (from last health check), configured agent, plugin version, and a "recent activity" summary from the logger (§8) — last N calls, success rate, average latency.

**Admin-side guardrails to check in review:**
- No privileged action without `current_user_can`.
- No state change without a verified nonce (`check_admin_referer` / `wp_verify_nonce`).
- No stored secret ever echoed back into an input value or the page source.

---

## 5. Configuration (standard — first-class)

**Storage.** Use a single serialized option array `rtae_settings` (via `register_setting` with a sanitize callback) for non-secret config. One option, one autoload entry, clean uninstall.

**Secret handling — the one nuance to get right.** WordPress has no built-in encrypted secret store. Options live in `wp_options` in plaintext and are visible to any admin and to database backups. Therefore:
- **Preferred:** the gateway credential is defined as a constant in `wp-config.php`, e.g. `define( 'RTAE_GATEWAY_KEY', '...' );`. The plugin reads it with `defined('RTAE_GATEWAY_KEY') ? RTAE_GATEWAY_KEY : ''`. It never touches the database and never appears in the admin UI. The settings page shows only "key configured via wp-config" or "not set."
- **Fallback (if a UI field is required):** store in the option array, mark the field password-type and write-only, and document the tradeoff in `readme.txt`. Still never render the value back to the browser.

Provide a filter (`rtae_gateway_key`) so hosting/enterprise setups can inject the key programmatically.

**Sanitization (input) — every field:**
- URLs: `esc_url_raw()` on save.
- Text (labels, agent ID): `sanitize_text_field()`.
- Color: validate against a hex pattern or `sanitize_hex_color()`.
- Booleans: cast explicitly.
- Reject unknown keys in the settings array (whitelist the allowed keys in the sanitize callback).

**Escaping (output) — every render:** `esc_attr()`, `esc_html()`, `esc_url()` at the point of output; `wp_kses()` for any admin-authored rich text (e.g. greeting). Never trust stored data on the way out.

**Defaults.** Ship sensible defaults so the plugin renders something the moment an agent ID is set. Provide a `rtae_default_settings()` helper used by both first-run and the sanitize fallback.

**Per-widget configuration.** Shortcode and block attributes override global defaults for a single placement (e.g. a different agent or greeting on the pricing page). Attributes: `agent`, `greeting`, `label`, `position`, `accent`. Sanitize attributes the same way as settings.

**Configuration constants (developer-facing), documented in readme:**

```php
RTAE_GATEWAY_KEY        // string — credential (preferred injection point)
RTAE_GATEWAY_BASE_URL   // string — overrides the settings URL if defined
RTAE_LOG_ENABLED        // bool   — force logging on/off regardless of UI toggle
RTAE_HTTP_TIMEOUT       // int    — seconds; default 15
```

**Uninstall.** `uninstall.php` deletes `rtae_settings` and any transients. Do not delete a `wp-config.php` constant (not ours to touch). Leave a documented note.

---

## 6. The Embed & Front-End Widget

**Placement (MVP: shortcode + block).**
- Shortcode `[risingtide_agent agent="..." greeting="..." ...]` → enqueues the widget and prints a mount node.
- Gutenberg block registered via `block.json` + `register_block_type`, same attributes, same mount.
- Optional site-wide auto-inject (footer hook) is a **deferral** (§11) — do not build it in MVP; it complicates every-page performance and consent handling.

**Asset loading.** Enqueue the built widget script/style only on pages where the shortcode/block is present (or, for auto-inject later, everywhere with a guard). Never inline the key. Pass only non-secret config and the REST nonce to the browser via `wp_localize_script` / `wp_add_inline_script`:

```php
wp_localize_script( 'rtae-widget', 'RTAE', array(
    'restUrl'   => esc_url_raw( rest_url( 'rtae/v1/message' ) ),
    'nonce'     => wp_create_nonce( 'wp_rest' ),
    'agentId'   => $agent_id,          // published agent id is not a secret
    'greeting'  => $greeting,
    'label'     => $label,
    'position'  => $position,
    'accent'    => $accent,
) );
```

**REST proxy endpoint.** `register_rest_route( 'rtae/v1', '/message', ... )` with a **non-empty `permission_callback`** (verify the `wp_rest` nonce; public visitors are allowed to *chat*, but the nonce ties the call to a page load and lets you throttle). The handler: sanitize the incoming message, call `RTAE_Gateway_Client::send_message()`, catch exceptions, return a clean JSON envelope. Add an optional per-IP or per-session transient throttle here as a courtesy limiter — **note this is not the abuse defense (the gateway owns that); it is a cheap first line and a teachable use of transients.**

**Widget behavior (MVP, non-streaming):**
- Launcher button → opens a panel with the greeting.
- User types → POST to the REST proxy → show a typing indicator → render the reply.
- Thread turns with the `conversation_id` returned by the gateway (stored in memory for the session; no PII persisted client-side).
- Errors: show a friendly, generic message; never surface raw gateway errors or the key. Log detail server-side.

**Accessibility (part of the grade):** keyboard-operable launcher and input, focus management when the panel opens/closes, `aria-live` region for incoming messages, respects `prefers-reduced-motion`, sufficient contrast on the accent color. This is a realistic, checkable bar for an entry-level project.

**Streaming is deferred (§11).** Non-streaming first because it is simpler, fully testable against the mock, and streaming through a PHP proxy is genuinely fiddly (output buffering, `flush()`, server-level buffering). Revisit once the gateway's streaming model is known.

---

## 7. Testing (standard — first-class)

Mirror the NewTide testing ethos (golden fixtures, isolation-as-P0) adapted to a plugin. The **mock gateway is the backbone** — it makes the whole plugin testable without a live endpoint.

**Local environment.** `wp-env` (Docker) for a disposable WordPress + test DB. `@wordpress/scripts` for JS build/lint. `phpcs` with WordPress Coding Standards in CI.

**7.1 Unit tests (PHPUnit / `WP_UnitTestCase`):**
- Sanitization: every settings field — valid input passes, malicious input is neutralized, unknown keys are dropped.
- Escaping: rendered admin/front-end output is escaped (assert no raw stored value leaks).
- Options: save/read/round-trip of `rtae_settings`; defaults applied on first run; uninstall removes options.
- Capability + nonce: privileged actions reject when capability missing or nonce invalid (this is the security-behavior equivalent of the platform's tenant-isolation P0 tests — **make these required, not optional**).
- Gateway result mapping: given a mock response, `send_message()` returns the correct `RTAE_Gateway_Result`; given mock `401/429/5xx`, the correct exception/branch fires.

**7.2 Integration tests:**
- REST proxy: a request with a valid nonce and a message returns the mapped reply; a request with a bad/missing nonce is rejected; a message that triggers a mock error returns the friendly envelope, not the raw error.
- Shortcode/block render: emits the mount node and enqueues assets only when present; the key is never in the localized data or page source.

**7.3 Manual QA checklist (documented in the PR):**
- Fresh install → configure agent → send a message end-to-end (against mock, then against real gateway once available).
- Bad key → admin sees a specific error; visitor sees a generic one.
- Gateway down → widget degrades gracefully; no PHP warnings; no console errors leaking internals.
- Multisite sanity check (does it activate network-wide without fatal errors). Full multisite support is a deferral, but "doesn't crash" is MVP.
- Accessibility pass (keyboard-only, screen-reader announcement of replies, reduced-motion).

**7.4 Security self-review (checklist, every PR):** capability on every privileged path; nonce on every state change and REST call; sanitize-in/escape-out; key never in browser; no direct `$_POST`/`$_GET` use without unslash + sanitize; `permission_callback` present and non-empty.

**Definition of "tested" for MVP:** all of 7.1 and 7.2 green against the mock, 7.3 walked manually, 7.4 clean.

---

## 8. Monitoring & Observability (standard — first-class)

The plugin can only observe what passes through it; deep usage/cost/abuse metrics live in the gateway. Build the plugin-side visibility the intern *can* own.

**8.1 Structured, opt-in logging (`RTAE_Logger`).** Log each gateway call with: timestamp, agent ID, latency, HTTP status/finish reason, error code (if any), and a truncated, PII-conscious note. Never log the key. Never log full message bodies by default (privacy). Gate logging behind a setting and the `RTAE_LOG_ENABLED` constant. Keep a bounded "recent calls" ring buffer (last ~50, in a transient/option) to power the admin status panel without unbounded growth.

**8.2 Admin status panel (from §4).** Surface, at a glance: connection status, last N calls, success rate, average and p95 latency (computed from the ring buffer), and last error with its code.

**8.3 WP Site Health integration.** Register a custom test via the `site_status_tests` filter that runs `health_check()` and reports "RisingTide Agent: connected / not configured / unreachable / credential rejected." This is the idiomatic WordPress place for operational status and a strong teachable for the intern.

**8.4 Plugin-observable metrics and thresholds** (adapted from the NewTide monitoring-table convention; these drive the status panel and, later, admin notices):

| Metric | Alert threshold | Meaning / action |
|---|---|---|
| Gateway health check | Fails 2× consecutively | Key revoked, URL wrong, or gateway down → admin notice |
| Message call error rate | > 10% of last 50 calls | Gateway degradation or misconfig → surface in status panel |
| Message call p95 latency | > 8 s | Gateway slow or timeout risk → widget UX degradation |
| `401/403` from gateway | Any occurrence | Credential invalid/revoked → prominent admin notice; do not expose to visitors |
| `429` from gateway | Sustained | Gateway rate-limiting the site → widget shows "busy"; consider raising the courtesy limiter |
| Timeouts (`RTAE_HTTP_TIMEOUT`) | > 5% of calls | Network/gateway issue → log + status panel |

**8.5 Optional periodic health sync (deferral-lite).** A `wp_schedule_event` daily health check that flips the admin notice proactively. Note WP-Cron is request-triggered, not real cron, so it is best-effort — fine for a status hint, not for anything time-critical.

---

## 9. Build Sequence (milestones with PR gates)

Milestones, not rigid days — intern velocity varies. Each milestone is one or a few small PRs, each mergeable, each leaving the plugin working against the mock.

**M1 — Scaffold + standards skeleton.** Plugin header, bootstrap singleton, `.wp-env.json`, `phpunit.xml.dist`, `phpcs` config, empty `RTAE_Logger`. Gate: plugin activates cleanly; one trivial PHPUnit test runs green; phpcs passes.
*PR:* "Plugin scaffold, coding standards, test harness."

**M2 — Gateway contract + mock + interface.** Define `RTAE_Gateway_Client`, `RTAE_Gateway_Result`, exception, and the mock with deterministic replies and simulable `401/429/5xx`/slow. Write §2's contract stub into the code as docblocks. Gate: unit tests for the mock's error simulation pass. **This PR's description carries the six open questions for the gateway team.**
*PR:* "Gateway client interface + mock + provisional contract."

**M3 — Configuration + secret handling.** `rtae_settings` option, Settings API registration, sanitize callback (whitelist keys), defaults, `wp-config.php` constant path + `rtae_gateway_key` filter, `uninstall.php`. Gate: sanitization and round-trip unit tests pass; key is never persisted to options when the constant is used.
*PR:* "Settings storage, sanitization, secret handling."

**M4 — Admin page + Test connection + agent picker.** Render the settings page (capability-gated), the nonce-protected "Test connection" (runs mock `health_check()`), and the agent picker with manual-entry fallback. Gate: capability/nonce rejection tests pass; test-connection shows mock health.
*PR:* "Admin settings UI, connection test, agent picker."

**M5 — REST proxy.** `rtae/v1/message` with non-empty `permission_callback` (nonce verify), input sanitization, mock call, clean JSON envelope, error mapping, optional transient courtesy throttle. Gate: integration tests for valid/invalid nonce and error mapping pass; key absent from all responses.
*PR:* "REST proxy endpoint with mock wiring."

**M6 — Widget + shortcode + block (non-streaming).** Built widget (launcher, panel, send/receive, typing indicator, conversation threading, a11y), shortcode, block via `block.json`. Enqueue only when present; localize non-secret config + nonce. Gate: manual E2E against mock; a11y pass; no key in page source.
*PR:* "Front-end widget, shortcode, and block."

**M7 — Monitoring surfaces.** `RTAE_Logger` ring buffer, admin status panel, Site Health test, metric/threshold notices. Gate: status panel reflects mock call history; Site Health test appears and reports correctly.
*PR:* "Logging, status panel, Site Health integration."

**M8 — Real gateway swap.** Implement `RTAE_Gateway_Client_Http` against the now-delivered gateway spec; reconcile §2. Flip a config switch (or filter) from mock to HTTP. Gate: manual E2E against the real gateway in a staging site; all prior tests still green (mock remains the test double).
*PR:* "HTTP gateway client + real-endpoint integration."

**M9 — Hardening + readme + i18n.** Full escaping/sanitization sweep, `.pot` generation, `readme.txt`, WordPress.org readiness checklist (§12). Gate: phpcs clean, security self-review clean, docs complete.
*PR:* "Hardening, i18n, readme, release prep."

---

## 10. Acceptance Criteria (MVP)

The plugin is MVP-complete when:

1. A site owner can install, set the gateway URL and credential (constant preferred), and select or enter a published agent ID.
2. "Test connection" reports connection status accurately (mock, then real).
3. A shortcode and a block each render a working chat widget on a page; assets load only where used.
4. A visitor can hold a multi-turn conversation with the agent through the REST proxy; turns thread via `conversation_id`.
5. The gateway credential never appears in the browser, page source, localized script data, or logs.
6. Every privileged admin action enforces capability + nonce; the REST route has a non-empty `permission_callback`.
7. All input is sanitized on save; all output is escaped on render; unknown settings keys are rejected.
8. Bad-key, rate-limited, and gateway-down conditions each produce a graceful visitor message and an accurate admin signal — no raw errors leak.
9. The admin status panel and Site Health test reflect real recent activity and health.
10. Unit + integration tests are green against the mock; phpcs (WordPress standards) passes; the manual QA and security checklists are walked and documented.
11. The plugin builds against the **mock** end-to-end with the gateway absent, and swaps to the real gateway by changing only the HTTP client + §2 reconciliation.
12. Accessibility: keyboard-operable, screen-reader-announced replies, reduced-motion respected.

---

## 11. Deferrals (explicitly out of MVP scope)

Name these so they don't creep in:

- **Streaming responses** (SSE/WebSocket/SignalR) — pending gateway streaming model; PHP-proxy streaming is fiddly.
- **Site-wide auto-inject** (every page) — performance and consent implications.
- **Multiple agents / per-page routing beyond shortcode attributes.**
- **Conversation persistence / history / transcripts** — introduces PII storage and retention obligations; keep MVP stateless client-side.
- **Cookie-consent / GDPR banner integration** — required before public production on EU sites; design once the data flow is fixed.
- **Analytics dashboard** (volumes, deflection, CSAT) — most of this data lives gateway-side; surface it there, not in the plugin.
- **Theming system / full CSS customization framework** — MVP ships a few presentation options, not a theme engine.
- **Multisite network-admin configuration** — MVP only guarantees "activates without crashing."
- **WordPress.org public submission** — build toward it (§12) but treat submission as a separate, later effort.

---

## 12. WordPress.org Readiness Checklist (build toward; submission deferred)

Even though public submission is deferred, building to these avoids rework. **Verify the exact current guideline text at submission time — the .org rules are revised periodically.**

- GPLv2-or-later license header; all bundled code GPL-compatible.
- Unique, prefixed function/class/option names (`rtae_` / `RTAE_`); no global-namespace collisions.
- Proper text domain and translation-ready strings; `.pot` in `languages/`.
- No obfuscated or minified-without-source JavaScript; ship `src/` alongside `build/`.
- External-service disclosure: because the plugin calls the RisingTide gateway, `readme.txt` must disclose the external service, link a privacy policy and terms, and state what data is sent. Required, not optional.
- No credential or secret rendered to the browser; sanitize-in/escape-out throughout.
- Complete `readme.txt` (stable tag, tested-up-to, description, installation, FAQ, changelog).
- No "phone home" or tracking without explicit opt-in.
- Passes Plugin Check (the official pre-submission scanner) and WordPress Coding Standards (`phpcs`).

---

## Appendix A — What the plugin explicitly does NOT do (owned by the gateway)

State this in the readme and the code so scope is unambiguous:

- Abuse/denial-of-wallet prevention, rate limiting for cost control.
- Publishable/secret key issuance, revocation, and domain-locking.
- Prompt-injection defense, output guardrails, system-prompt protection.
- PII scrubbing and conversation-content governance.
- Per-tenant budget caps and usage billing.

The plugin assumes the gateway enforces all of the above and fails closed if the gateway rejects a call.
