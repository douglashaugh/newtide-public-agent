# CLAUDE.md — {Plugin Name} (RisingTide Public Agent)

> **This is a starter `CLAUDE.md` for a new WordPress plugin, distilled from 30+ released versions of the TOE Energy Dashboard plugin.** Rename to `CLAUDE.md` at the new repo root. Replace every `{placeholder}`. The companion `ARCHITECTURE-DECISIONS.md` explains the *why* behind each rule — read it once, then this file is your day-to-day contract.

## Project

**{Plugin Name}** is a WordPress plugin that surfaces the **RisingTide public agent** on {site}. It embeds an agent UI (via shortcode/block) and exposes REST endpoints the agent and the site can call. All UI must blend with the host theme — never introduce a separate design system.

## Non-negotiable conventions (WordPress)

- **Plugin entry file:** `{plugin-slug}.php` with a standard header block (`Plugin Name`, `Version`, `Text Domain: {plugin-slug}`).
- **Text domain:** `{plugin-slug}` for every translatable string.
- **One options key:** store ALL settings under a single array option `{plugin_slug}_options`. Keeps `wp_options` clean and versioning trivial. A `Settings` class owns read/write + defaults.
- **Constants prefix:** `{PLUGIN_PREFIX}_` (e.g. version, paths, URLs).
- **REST namespace:** `{plugin-slug}/v1`.
- **Transient prefix:** `{pfx}_` on every transient key (short, collision-free).
- **Capability + nonce on every privileged path:** `current_user_can()` before any write; nonce verification on all form submits and AJAX/REST mutations.
- **Enqueue with the theme as a dependency** so plugin styles/scripts always load *after* the theme: `wp_enqueue_*` on `wp_enqueue_scripts` (front) / `admin_enqueue_scripts` (admin), listing the theme stylesheet handle as a dep.
- **Pass PHP→JS only via `wp_localize_script`** (or `wp_add_inline_script` for a data object). NEVER inline `<script>` data blocks in shortcode output.

## Recommended architecture

```
{plugin-slug}.php                 # Entry: define constants, boot singleton, wire Plugin Update Checker
includes/
  class-{pfx}-plugin.php          # Singleton bootstrap — wires all subsystems, registers hooks
  class-{pfx}-settings.php        # Read/write the single options array; defaults; access checks
  class-{pfx}-enqueue.php         # Asset enqueue (theme as dependency)
  class-{pfx}-rest.php            # REST routes under {plugin-slug}/v1 (agent + site)
  class-{pfx}-agent-client.php    # Server-side RisingTide API client (keys stay server-side)
  class-{pfx}-store.php           # Custom-table persistence (dual-write); historical/agent queries
  class-{pfx}-service-status.php  # Central health/backoff/auto-disable registry
  class-{pfx}-test-runner.php     # Shared test battery (fast, deterministic, no live HTTP)
admin/
  class-{pfx}-admin.php           # Settings API page + Tabs (General / Agent / Service Status / Tests)
  views/*.php                     # Admin templates (HTML only)
public/
  class-{pfx}-public.php          # Shortcode(s) + template loader
  views/*.php                     # Front-end markup (HTML only — no <style>/<script>)
assets/
  css/{plugin-slug}-public.css    # Theme-token-first front-end styles
  css/{plugin-slug}-admin.css     # Scoped admin styles (plugin pages only)
  js/{plugin-slug}-public.js      # Vanilla JS; receives localized object
  js/{plugin-slug}-admin.js       # Admin JS (deps: wp-api-fetch, wp-i18n)
languages/                        # .pot / .po / .mo
```

**Bootstrap flow:** entry file → `{Pfx}_Plugin::instance()` → require class files → instantiate components → register WP hooks. Extend surfaces via `do_action()` hooks, not by editing core render loops.

## Definition of Done — the four companions

**A feature is not "done" when it renders. It is done when it can be operated, tuned, and trusted over time.** Build all four companions in the SAME increment (scaled to the feature type — don't over-build small items):

1. **Manage (admin):** a control to run/trigger/reset/flush; a visible last-run + last-error; an enable/disable toggle.
2. **Configure (admin + user):** admin settings (keys, intervals, thresholds, sources, display) AND, for user-facing surfaces, a customizer entry (visibility / order / options).
3. **Oversee (monitor/alert):** freshness/health state, run audit + provenance, and **active** failure handling — count failures, back off, **auto-disable past a threshold** (pattern: warn at 3, disable at 10), surface an admin notice. Central home = a **Service Status** admin tab every data feature registers into.
4. **Verify (tested + logged):** register a suite into the shared test runner; persist a results snapshot; surface a **dedicated "Tests" admin tab** with plain-language "why this matters" copy per suite (it's both QC and a user-facing trust artifact). Battery must be **fast + deterministic — no live HTTP** (run against stored data/fixtures).

Scale by type: external feed/import → full treatment; computation → trigger + last-run + idempotency (+ unit tests when "the math is the product"); UI card → toggle + customizer + freshness badge; admin tool → confirm + result + audit entry.

## Front-end standards

- **CSS lives ONLY in the enqueued external stylesheet.** Never emit `<style>` blocks from shortcode/template output — `wpautop()` and block sanitizers corrupt them. Bump the version constant when CSS changes so caches bust.
- **No inline `<script>` in shortcode output.** `wpautop()` wraps it in `<p>` and breaks it. If JS must accompany a template, enqueue it or hook `wp_footer` (priority 99) to a separate template; pass data via `wp_localize_script`.
- **Theme-token-first:** inherit the host theme's CSS custom properties (colors, spacing, fonts) — never hardcode a raw value when a variable exists. Define/expect the same tokens so the plugin blends in.
- **Font sizes use the theme's fluid scale** (`--wp--preset--font-size--*`). Never hardcode sub-1rem rems — repeated "compacting" makes text unreadable. Dark-theme overrides change **colors only, never font sizes**.
- **BEM class naming, plugin-prefixed:** `{plugin-slug}__element`, `{plugin-slug}--modifier`. Keep a card/component style pattern (value/units/change/byline, per-section accent, dark-theme variant) and copy it for consistency.
- **Theme-aware:** support the host's light/dark schemes; commit to both.

## Data, persistence & the agent

- **Dual-write:** every fetch writes BOTH a transient (fast render) AND a row to a custom table (durable history). API calls cost money — retain the data for analytics, charts, and **agent queries**.
- **Background cron independent of page views** for scheduled refreshes; tunable intervals + per-job enable toggles in settings.
- **Stale fallback:** when a source fails, render the last stored observation with an "As of {date}" badge — never a blank or a fatal.
- **Keys stay server-side.** The RisingTide agent key lives in the options array and is used only by the server-side client class. NEVER expose it to the browser, a URL param, or a localized JS object. Front-end talks to your own `{plugin-slug}/v1` REST proxy (nonce-authed), which calls RisingTide server-side.
- **Budget/rate control:** meter agent/API calls (per-day counter), enforce a ceiling, and degrade gracefully when exhausted. Expose usage in the Service Status tab.
- **REST-for-agents:** keep query endpoints clean and documented so RisingTide agents can call them (date ranges, aggregations). Sanitize/validate every param; rate-limit public routes.

## Deployment (git-as-deploy, no zip)

- **Prod delivery = Plugin Update Checker (PUC) wired to the GitHub `master` branch.** Pushing to `master` **is** the deploy — WP offers "Update" when the header `Version:` exceeds the installed copy. `master` is the release branch: commit directly to it, do NOT feature-branch for releases.
- **TWO version fields must match** on every release: the `* Version:` header AND the `{PLUGIN_PREFIX}_VERSION` runtime define. If the header isn't bumped, no update ever surfaces.
- **Do NOT build production zips.** (A hand-built zip once crashed prod.) The user builds/handles any zip themselves; zip is only for hosts that can't run PUC.
- **Local sandbox every cycle:** a `deploy.bat` mirrors the repo into the Local WP site's plugin dir. Run it after each batch — the user tests against Local, not the repo.
- Semver-ish: patch = fix/tweak, minor = new feature. Bump before pushing.

## Verify habit (hard-won)

- After changing anything, **render the real entry point** (the actual shortcode/page), not just the unit under test. Unit tests passing ≠ the page renders.
- **When you change a shared data structure, exercise EVERY consumer of it** — not just the feature you're building. (A ticker data-structure refactor once null-ref'd an unrelated consumer and white-screened prod despite green unit tests.)
- Prefer a fast CLI runner that boots WP and renders/asserts, so you can verify before handing off.

## Hard rules & gotchas

- **Runtime/user-editable data must survive plugin updates** → store it under `wp-content/uploads/{plugin-slug}/`, NOT in the plugin dir (plugin files are overwritten on every update). Ship a bundled seed + an auto-bootstrap that rebuilds from the seed if the uploads copy is missing. Merge user overrides on top.
- **Keep a section/feature integration checklist** and verify all touchpoints before "done." Lazy-loaded/AJAX-hydrated sections have several separate registrations (route + component map + settings default + admin toggle + sanitize key + render guard) that must stay in sync — the sneakiest miss silently 404s the hydration and leaves a skeleton forever.
- **Commit hygiene:** add files explicitly; do NOT `git add -A` (it sweeps local planning docs/scratch into releases). `.gitignore` planning/scratch `.md` and data files.
- **Scope discipline:** when a request could mean a small change or a broad one, confirm the interpretation before building the broad version. ("Several items ticking" meant items in an existing row, not new rows — building the big version first wasted a cycle.)
- **Never** put secrets in URLs, client JS, or logs. Never auto-approve consent/permission/OAuth on the user's behalf.

## Working agreements

- Deploy to Local after each batch, then tell the user what to test.
- Keep the memory/index files current; record durable lessons, not restated code.
- One feature at a time, shipped complete (with its companions) before the next.
