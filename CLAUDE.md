# CLAUDE.md — NewTide Public Agent

WordPress plugin that embeds **one published NewTide / Agent Harbor public agent** on a site (support chat, guided browsing, sales-inquiry response). It is a **thin client** for the agent conversation itself — the **Public Agent Gateway** owns identity, safety, rate-limiting, cost control, and guardrails — but it ships with the **full NewTide operational standard** (durable usage table, budget metering, Service Status, Tests tab, four companions). The `why` behind every rule is in `docs/ARCHITECTURE-DECISIONS.md`.

## Identity (locked)

| Thing | Value |
|---|---|
| Plugin Name | NewTide Public Agent |
| Slug / text domain | `newtide-public-agent` |
| Class / constant prefix | `NPA_` |
| Function / option prefix | `npa_` |
| Single options key | `npa_options` |
| REST namespace | `npa/v1` |
| Key constant (wp-config) | `NPA_GATEWAY_KEY` |
| Release branch | `main` (PUC deploy) |

## Non-negotiable conventions (WordPress)

- Entry file `newtide-public-agent.php` with a standard header; `Version:` header and `NPA_VERSION` constant **always in lockstep** (ADR-002).
- **One options array** `npa_options`; a Settings class owns defaults + read/write.
- **Capability + nonce on every privileged path.** `current_user_can()` before any write; nonce on every form submit and AJAX/REST mutation; REST routes have a non-empty `permission_callback`.
- **PHP→JS only via `wp_localize_script`.** Never inline `<script>`/`<style>` in shortcode output — `wpautop()` corrupts them (ADR-012/013).
- **Enqueue with the theme stylesheet as a dependency** so plugin assets load after the theme.
- Sanitize-on-input, escape-on-output, everywhere. Whitelist allowed settings keys in the sanitize callback.

## Architecture (reconciled)

Thin-client **spine = the gateway-client interface** (the best idea from the original plan): build the whole plugin against a deterministic **mock**, swap in the HTTP client last.

```
newtide-public-agent.php          # header, constants, PUC wiring, boot NPA_Plugin::instance()
includes/
  class-npa-plugin.php            # singleton bootstrap; wires subsystems; do_action('npa_booted')
  class-npa-settings.php          # single options array; defaults; sanitize (whitelist)
  class-npa-enqueue.php           # assets, theme stylesheet as dependency
  class-npa-rest.php              # npa/v1 proxy; non-empty permission_callback
  class-npa-store.php             # custom table: dual-write usage rows (+ opt-in transcripts)
  class-npa-budget.php            # per-day counter, ceiling, graceful exhaustion
  class-npa-service-status.php    # health / backoff (warn 3, disable 10) registry
  class-npa-test-runner.php       # fast, deterministic, no-live-HTTP battery
  class-npa-logger.php            # structured, opt-in; ring buffer for the panel
  gateway/
    interface-npa-gateway-client.php     # send_message / list_agents / health_check
    class-npa-gateway-client-mock.php    # deterministic; simulates 401/429/5xx/slow
    class-npa-gateway-client-http.php     # real calls (built last)
    class-npa-gateway-result.php
    class-npa-gateway-exception.php
admin/  class-npa-admin.php + views/*.php   # tabs: General / Agent / Service Status / Tests
public/ class-npa-public.php + views/*.php   # shortcode + block; HTML only
assets/ css,js                                # theme-token-first; data via wp_localize_script
languages/newtide-public-agent.pot
```

## Definition of Done — the four companions

A feature is done when it can be **operated, tuned, and trusted**, not when it renders. Build all four in the SAME increment, scaled to feature type:

1. **Manage** — a control to run/reset/flush; visible last-run + last-error; enable/disable toggle.
2. **Configure** — admin settings AND (for user-facing surfaces) presentation options.
3. **Oversee** — health/freshness state + **active** failure handling: count failures, back off, auto-disable past threshold (warn 3, disable 10), admin notice. Home = the **Service Status** tab.
4. **Verify** — register a suite into `NPA_Test_Runner`; snapshot results; surface them in the **Tests** tab with plain-language "why this matters" copy. Battery is fast + deterministic — **no live HTTP** (runs against fixtures / the mock gateway).

## Security & secrets (thin client — the gateway owns the rest)

- **`NPA_GATEWAY_KEY` in `wp-config.php` is preferred**; options-array fallback is write-only and never re-rendered. Never in the browser, a URL, a localized JS object, or a log.
- Front-end talks ONLY to our own nonce-authed `npa/v1` proxy, which calls the gateway server-side.
- The plugin does **not** implement auth, abuse defense, prompt-injection defense, or PII scrubbing — the gateway does. If you're writing security logic in PHP, stop and ask.
- In-plugin **budget meter** (per-day counter + ceiling) is a courtesy limiter / cost-visibility aid, **not** the abuse defense.

## Data & persistence

- **Dual-write:** every gateway call writes a transient (fast render) AND a durable **usage row** (metadata only: time, agent id, conversation id, latency, status, finish reason, token counts, error code). This is the Service Status / Tests / budget substrate — **zero PII**.
- **Transcript content is opt-in, default OFF**, with a retention window + purge cron, and disclosed in `readme.txt`. Off on EU sites until consent integration lands.
- Runtime/user-editable data lives under `wp-content/uploads/newtide-public-agent/`, never the plugin dir (overwritten on update). Ship a seed + auto-bootstrap.
- **Stale fallback** over blanks/fatals: degrade visibly with an "As of {date}" note.

## Front-end standards

- CSS only in the enqueued external stylesheet; bump `NPA_VERSION` to bust caches.
- Theme-token-first (`--wp--preset--*`); never hardcode sub-1rem font sizes. Dark mode = colors only, never sizes.
- BEM, plugin-prefixed: `newtide-public-agent__el`, `newtide-public-agent--mod`.
- Widget a11y is part of the grade: keyboard-operable, focus management, `aria-live` for replies, `prefers-reduced-motion`.

## Deployment (git-as-deploy, no zip)

- Prod delivery = **Plugin Update Checker wired to GitHub `main`**. Pushing to `main` IS the deploy; commit directly to it — no release feature-branches. `main` must always be releasable.
- **Bump BOTH version fields** before pushing.
- **Do NOT build production zips** (a hand-built zip once crashed prod — ADR-003). The user handles any zip.
- `deploy.bat` mirrors the repo into the Local site each cycle; test on Local, not the repo.

## Verify habit (hard-won)

- After any change, **render the real entry point** (actual shortcode/page), not just the unit. Green unit tests ≠ the page renders (ADR-017).
- Changing a shared structure? Exercise **every** consumer.

## Working agreements

- One feature at a time, shipped complete with its four companions before the next.
- Deploy to Local after each batch, then tell the user what to test.
- **Commit hygiene:** add files explicitly; never `git add -A` (it sweeps planning/scratch into releases — ADR-019). `docs/` and `*.md` are excluded from the Local mirror.
- Build against the **mock** end-to-end; the real gateway swaps in only the HTTP client. Never block on the endpoint existing.
