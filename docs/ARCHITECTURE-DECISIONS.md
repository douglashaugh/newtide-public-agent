# Architecture Decision Record — WordPress Plugin Standards

Distilled from the TOE Energy Dashboard plugin (30+ released versions, ~4 months). Each record is a decision we actually made, why, what it cost/bought, and — where it exists — the concrete incident that taught it. Use this to seed the next plugin (the RisingTide public-agent plugin) so it starts where TOE ended, not where TOE began.

Format: **Context → Decision → Consequences → Evidence.**

---

## Deployment & release

### ADR-001 — Git-as-deploy via Plugin Update Checker on `master`
- **Context:** The standard WP flow is hand-building a zip and dropping it on prod (or FTP). It's manual, error-prone, and gives no auto-update path to other sites running the plugin.
- **Decision:** Wire the [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) to the GitHub `master` branch. Pushing to `master` **is** the deploy; WP surfaces "Update" when the header version exceeds the installed one. `master` is the release branch — commit directly to it.
- **Consequences:** Zero-zip releases; every site checking the repo gets auto-updates; the whole team can deploy by pushing. Trade-off: `master` must always be releasable — no half-finished commits on it.
- **Evidence:** Became the backbone of the workflow; documented as onboarding for a co-worker. It's "a bit non-standard but a lot cleaner once working."

### ADR-002 — Two version fields, always in lockstep
- **Context:** WP has a header `Version:` (what PUC compares) and we keep a runtime `VERSION` constant. They can drift.
- **Decision:** Bump BOTH on every release; treat a mismatch as a release bug.
- **Consequences:** If the header isn't bumped, WP never offers the update and "nothing deployed" — a silent, confusing failure. Making it a checklist item eliminates it.
- **Evidence:** Repeatedly the first thing to verify when "the update didn't show up."

### ADR-003 — Never let the assistant build the prod zip
- **Context:** One host (WordPress.com managed) can't use PUC and needs a zip.
- **Decision:** The assistant deploys to Local only and prepares files; the **user** builds/handles any production zip.
- **Consequences:** Removes a whole class of packaging errors from the automated path.
- **Evidence:** An assistant-built zip once **crashed the production site.** Hard line since.

### ADR-004 — A disposable Local mirror for every cycle
- **Context:** The repo is the source of truth but WP reads from its plugins dir.
- **Decision:** A `deploy.bat` xcopy-mirrors the repo into the Local site's plugin folder; run it after every batch. Local is disposable and not a git repo.
- **Consequences:** Tight test loop; the user always tests real rendered output before prod. Forgetting to deploy = wasted round-trips, so it's a standing habit.

---

## Data & configuration

### ADR-005 — One options key (an array), not many rows
- **Decision:** Store every setting under a single `{plugin}_options` array; a Settings class owns defaults + read/write + access checks.
- **Consequences:** Clean `wp_options`, trivial migration/versioning, one place to reason about config. New settings are one array key + one default.

### ADR-006 — Runtime/user data lives in `uploads/`, never the plugin dir
- **Context:** We shipped a bundled seed file and let the app write the full data set back into the plugin directory.
- **Decision:** Runtime + user-editable data goes to `wp-content/uploads/{plugin}/`. Ship a bundled seed for read-fallback; auto-bootstrap (transient-locked) rebuilds the full set from the seed if the uploads copy is missing. User overrides live in a separate uploads file and merge on top.
- **Consequences:** Data survives plugin updates and re-imports; no manual re-seed after deploy.
- **Evidence:** **The glossary overwrite bug.** Because runtime data sat in the plugin dir and the repo only committed a ~14-term seed, *every* deploy/update overwrote the live 495-term glossary back to the seed — forcing a manual re-import after each release. Moving to uploads + auto-bootstrap ended it permanently.

### ADR-007 — Dual-write persistence (transient + custom table)
- **Context:** API calls cost money but only produced ephemeral cache.
- **Decision:** Every fetch writes a transient (fast render) AND a row in a custom time-series table. Add REST endpoints for historical/aggregate queries.
- **Consequences:** Builds a durable, queryable history for charts, analytics, and — critically — **agent queries**. This is the substrate the RisingTide agent should read from.
- **Evidence:** Explicit 2026-03 decision; became the foundation the whole retail/pressure-index analytics layer was built on.

### ADR-008 — Stale fallback over blanks/fatals
- **Decision:** When a source fails, render the last stored observation with an "As of {date}" badge; the ticker/cards rebuild from stored data when a key is missing or quota is exhausted.
- **Consequences:** The surface never white-screens or shows empty on a transient upstream failure — it degrades visibly and honestly.

---

## The operating standard (what "done" means)

### ADR-009 — Every feature ships with its four companions
- **Context:** Retrofitting oversight after a feed silently dies or a pipeline drifts is expensive and only happens after a failure.
- **Decision:** Build **Manage / Configure / Oversee / Verify** in the same increment as the feature, scaled to feature type. A feature is done when it can be *operated, tuned, and trusted*, not merely when it renders.
- **Consequences:** More up-front work per feature, far less firefighting later. Applies plugin-wide, not to one surface.
- **Evidence:** Adopted as the explicit definition-of-done after enough "it renders but nobody can tell it broke" moments. In production, the Tests battery + Service Status correctly flagged a pre-backfill missing-data state until backfill ran — the standard caught a real gap.

### ADR-010 — Active alerting for feeds: backoff + auto-disable
- **Decision:** Feeds/connections track failure counts, back off, and **auto-disable past a threshold** (warn at 3, disable at 10), with an admin notice. Central roll-up = a **Service Status** admin tab every data feature registers into.
- **Consequences:** A dead upstream can't silently burn budget or serve stale data forever; there's one place to see health.

### ADR-011 — A shared test runner + a public-facing "Tests" tab
- **Context:** For a methodology-driven product, tests are also a *trust artifact*, not just internal QC.
- **Decision:** Features register suites into one runner; results persist to a snapshot and render in a dedicated **Tests** admin tab with plain-language "why this matters" copy per suite. Battery is **fast + deterministic — no live HTTP** (runs against stored data/fixtures). Auto-run on version change + a manual "Run tests" button. Two tiers: a fast build battery (every build) and heavier validation (e.g., a back-test, when methodology changes).
- **Consequences:** QC that non-engineers can read; designed so the summary can later be exposed on a public quality/methodology page.

---

## Front-end & theming

### ADR-012 — CSS only in the enqueued external file
- **Decision:** No `<style>` blocks in shortcode/template output; all styles in the enqueued stylesheet; bump the version constant on CSS changes to bust caches.
- **Evidence:** Inline `<style>`/`<script>` inside shortcode `ob_get_clean()` output gets mangled by `wpautop()` (it inserts `<p>` tags mid-block). **Spread tooltips were completely dead** — no hover, no click — until the JS moved out of the template to a `wp_footer` hook. Same failure mode killed inline styles.

### ADR-013 — No inline scripts either; data via `wp_localize_script`
- **Decision:** JS that accompanies a template is enqueued or emitted via a `wp_footer`-hooked separate file; PHP→JS data always goes through `wp_localize_script` (restUrl, nonce, i18n, config).
- **Consequences:** Survives page builders, block sanitization, and arbitrary placement of the shortcode.

### ADR-014 — Theme-token-first, fluid font scale, dark-mode = color only
- **Decision:** Inherit host-theme CSS variables (color/spacing/fonts); never hardcode a raw value where a token exists. Use the theme's fluid `--wp--preset--font-size--*` scale; never hardcode sub-1rem rems. Dark-theme overrides change **colors only, never sizes**.
- **Evidence:** Multiple compacting rounds hardcoded tiny rems and made ticker/card text nearly unreadable. Fluid tokens are already tuned for the site.

### ADR-015 — BEM prefix + a copyable component pattern
- **Decision:** Prefix all classes (`{plugin}__el`, `{plugin}--mod`). Maintain one reference card/component pattern (value/units/change/byline, per-section accent, dark variant) and copy it for every new one.
- **Evidence:** Without section-specific classes, values rendered as unstyled body text. A style guide + "copy the most complete existing section" keeps new surfaces consistent.

---

## Extensibility & correctness

### ADR-016 — A written integration checklist for multi-touchpoint features
- **Context:** Lazy-loaded/AJAX-hydrated sections have several *separate* registrations that must stay in sync (AJAX/REST route, component map, settings default, admin toggle, sanitize key, render guard).
- **Decision:** Keep an explicit N-touchpoint checklist; verify all before "done."
- **Evidence:** The `component_map` entry was missed for a section → everything *looked* wired but AJAX hydration 404'd → **skeleton cards pulsed forever.** The sneakiest bugs are the ones where the happy path looks complete.

### ADR-017 — Verify shared-structure changes against EVERY consumer + render the real page
- **Context:** Shared data structures (e.g. a ticker `LAYERS` map) have multiple consumers; changing the shape can break a consumer you weren't looking at.
- **Decision:** When you change a shared structure, exercise every consumer, and always render the actual entry-point shortcode/page — not just the unit you changed.
- **Evidence:** A `LAYERS` refactor (stored-only rows switched from `codes` to `items`) null-ref'd an *unrelated* sparklines consumer → TypeError → **the entire dashboard shortcode fataled (white screen on local AND prod).** The ticker's own unit tests were green; nothing had rendered the full page. Hence the "/verify by rendering the real thing" habit.

### ADR-018 — Confirm scope before building the broad version
- **Context:** Ambiguous requests can mean a small in-place change or a large new structure.
- **Decision:** When interpretation forks, confirm before building the expensive branch.
- **Evidence:** "Add several items ticking" was built as two new ticker rows + extra components; the intent was items inside the *existing* row, composite-only. A quick confirm would have saved the rebuild.

### ADR-019 — Commit hygiene: explicit adds, gitignore scratch
- **Decision:** Add files explicitly; never `git add -A` on a release. `.gitignore` planning docs, scratch, and local data.
- **Evidence:** A `git add -A` swept ~15 local planning/spec `.md` files and a data dump into a release commit; they had to be `git rm --cached` back out afterward.

---

## Security (elevated for an agent-facing plugin)

### ADR-020 — Secrets stay server-side; the browser talks to your own proxy
- **Context:** The plugin holds a RisingTide agent API key and calls a paid agent endpoint.
- **Decision:** The key lives in the options array and is used only by a server-side client class. The front-end never sees it — it calls your own nonce-authed `{plugin}/v1` REST route, which calls RisingTide server-side. Meter calls (per-day counter), enforce a ceiling, degrade gracefully when exhausted, and show usage in Service Status.
- **Consequences:** No key leakage via JS/URL/logs; a runaway agent can't silently drain budget; abuse of public routes is rate-limited. Treat every REST param as untrusted — sanitize and validate.

---

## How to use this record

1. Seed the new repo's `CLAUDE.md` from `PLUGIN-CLAUDE.md` (its rules are the enforced summary of these ADRs).
2. Keep this file in the new repo (e.g. `docs/ARCHITECTURE-DECISIONS.md`) and **append** new ADRs as the new plugin teaches its own lessons — same format. The value compounds.
3. When a rule feels like overhead, re-read its Evidence line. Most of these are scar tissue, not preference.
