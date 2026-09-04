=== NewTide Public Agent ===
Contributors: newtide
Tags: agent, chat, ai, support, embed
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed a published NewTide / Agent Harbor public agent on your WordPress site via a shortcode or block. A thin, secure client for the Public Agent Gateway.

== Description ==

NewTide Public Agent puts one published agent (support chat, guided browsing, sales-inquiry response) on your site. Add it with the `[newtide_agent]` shortcode or the **NewTide Agent** block.

It is a **thin client**. The Public Agent Gateway owns identity, safety, rate-limiting, prompt-injection defense, and cost control. The plugin renders the chat UI and relays messages through a **same-origin, nonce-authenticated proxy**, so the gateway credential is used server-side only and **never reaches the browser**.

**Features**

* Shortcode and Gutenberg block, with per-placement overrides (agent, greeting, label, position, accent).
* Server-side REST proxy (`/wp-json/npa/v1/message`) — the credential never leaves your server.
* Admin screen with tabs: General, Agent, Service Status, Tests.
* Durable usage history (metadata only — no message content), powering a status panel and a courtesy daily budget cap.
* Accessible widget: keyboard-operable, focus management, screen-reader announcements, respects reduced motion.
* Built to run fully against a deterministic mock, so it works before your gateway is live.

== Installation ==

1. Upload the plugin to `wp-content/plugins/newtide-public-agent` and activate it.
2. Preferred: add your gateway credential to `wp-config.php`:
   `define( 'NPA_GATEWAY_KEY', 'your-key' );`
3. In **NewTide Agent → Agent**, set the gateway base URL and choose (or enter) your published agent ID. Click **Test connection**.
4. Place the widget with the `[newtide_agent]` shortcode or the **NewTide Agent** block.

Until a gateway URL and credential are configured, the plugin runs against a built-in mock so you can preview the experience.

== Configuration constants ==

Define these in `wp-config.php`:

* `NPA_GATEWAY_KEY` — gateway credential for Proxy mode (preferred; never stored in the database).
* `NPA_GATEWAY_BASE_URL` — overrides the Proxy-mode base URL set in the admin.
* `NPA_PUBLIC_KEY` — publishable `pk_` key for Embed mode (overrides the admin field).
* `NPA_PLATFORM_URL` — RisingTide host for Embed mode; defaults to production (`https://ai.newtide.ai`). Set to `https://uat-ai.newtide.ai` only for internal NewTide testing.
* `NPA_HTTP_TIMEOUT` — request timeout in seconds (default 15).
* `NPA_LOG_ENABLED` — force call logging on or off.
* `NPA_FORCE_MOCK` — force the mock client (useful on staging).

== External services ==

This plugin connects to the **NewTide Public Agent Gateway** to obtain agent replies. When a visitor sends a message, the plugin transmits, from your server:

* the visitor's message text;
* an opaque conversation ID used to thread the exchange;
* page context: the page URL, page title, and locale;
* metadata: a source tag, the plugin version, and your site host.

Data is sent only when a visitor interacts with the widget. The gateway credential is sent as an authorization header from your server and is never exposed to the browser. By default the plugin stores only call **metadata** (timestamps, latency, status, token counts) — never message content. **Store transcripts** is an explicit opt-in that persists what visitors type and what the agent replies; it is off unless you turn it on, bounded by a retention window, and purged daily.

Confirm the gateway's provider, terms, and privacy policy before enabling on a production or EU-facing site.

== Frequently Asked Questions ==

= Is my gateway key exposed to visitors? =
No. The browser talks only to your site's own REST proxy; the credential is added server-side. Prefer defining `NPA_GATEWAY_KEY` in `wp-config.php` so it never touches the database.

= Does it store conversations? =
Only if you ask it to. By default it stores call metadata alone — no message content. Turning on **Store transcripts** (Agent tab) persists both sides of each exchange for a retention window you set, after which a daily job deletes them. Service Status shows how much is held, how old the oldest record is, and when the next purge runs, and offers one-click purge and delete-all. Uninstalling drops the table.

Storing transcripts means holding visitor-authored content, which may be personal data. Turn it on only with a lawful basis and a privacy notice that covers it.

= Can I use it before the gateway is ready? =
Yes. Without a configured URL and credential the plugin runs against a deterministic mock, so you can build and preview the widget.

= Does the plugin prevent abuse or control cost? =
Those are enforced by the gateway. The plugin offers an optional courtesy daily cap and a per-request throttle, but the authoritative controls live gateway-side.

== Changelog ==

= 0.3.0 =
Feature — transcript storage (opt-in, off by default):
* **Store transcripts** now works. Turning it on persists the visitor's message
  and the agent's reply for each exchange; leaving it off writes no message
  content at all, which is the default and is asserted by a test that sends a
  real message through the proxy and checks nothing was written.
* Retention is enforced, not just configured. A daily WP-Cron job deletes
  anything past the window. It self-schedules on a normal page load rather than
  only on activation, because a git-based update never fires the activation
  hook — otherwise a site upgrading into this feature would store content
  forever with nothing to remove it.
* Service Status gains a Transcripts card: how many messages and conversations
  are held, the oldest record, the retention window, when the next purge runs,
  a viewer for the most recent messages, and buttons to purge expired or delete
  everything. The health roll-up reports "not ok" if storage is on while no
  purge is scheduled, rather than letting an unbounded store go unnoticed.
* Stored content is stripped of markup on the way in — a transcript is a record
  of what was said, never markup to be replayed into a page.
* Uninstall drops the transcript table and clears the cron event.
* Schema version 3; the table is created automatically on upgrade.

= 0.2.4 =
Fixes:
* Installs no longer receive the project's internal files. Git-as-deploy installs
  the GitHub branch archive, so deploy.bat's exclusions -- which only ever applied
  to the local mirror -- did not apply to real sites: every install received
  CLAUDE.md, docs/ (architecture decisions, the provisional gateway contract, the
  publishing guide PDF), composer.json, .phpcs.xml.dist and deploy.bat. Because
  .md, .pdf and .bat are served as static files from wp-content/plugins/, all of
  them were readable at a public URL on the customer's own domain. A .gitattributes
  with export-ignore now keeps them in the repository but out of the archive.

= 0.2.3 =
Fixes:
* The Logging checkbox now works. It was read from nothing at all — only the
  NPA_LOG_ENABLED constant could switch logging on, so ticking the box in the
  admin changed no behaviour. Its label also described the usage table, which
  records regardless; it now describes the diagnostic log it actually controls.
* Average latency no longer reports a meaningless "0 ms". Calls served by the
  built-in mock answer in about a millisecond, and averaging them in dragged the
  figure toward zero. Mock calls are now flagged in the usage table, excluded
  from the latency average, and counted separately; a site with no live traffic
  says so instead of showing a confident zero. (Schema version 2 — the column is
  added automatically on upgrade.)
* Store transcripts is disabled rather than left looking functional. The setting
  was sanitised and stored but nothing ever read it: no transcript table, no
  write path, no retention purge. Message content has never been persisted, and
  the admin and readme now say so plainly instead of implying an opt-in exists.
* Suggested prompts helper text now states that extra lines beyond six are
  ignored, which is what the sanitiser has always done.

Tests:
* Settings: the logging setting switches the diagnostic log on and off.
* Usage store: mock calls are counted but excluded from average latency.

= 0.2.2 =
Fixes:
* Per-page agents now actually answer. In Proxy mode the widget rendered with its
  targeted agent but every message was relayed to the site-wide default, so
  Additional Agents and the shortcode/block `agent` override had no effect. The
  mount now carries a server signature for its agent id and the proxy honours it;
  an unsigned or forged id still falls back to the default, so the browser can
  never address an agent this site did not render.
* Service Status no longer reports "Configured: No" on a correctly configured
  Embed-mode site. The check read Proxy-only fields regardless of mode; both it
  and the Home checklist now share one mode-aware test.
* Usage history and the busiest-agents chart now attribute calls to the agent that
  actually answered rather than always the default.
* deploy.bat no longer strips the update checker's vendor/ directory when
  mirroring to a Local site (a bare robocopy /XD name matches at any depth).

Tests:
* Environment: the plugin header and NPA_VERSION must agree (ADR-002), the update
  checker library must be loaded, and the checker must be registered — the three
  ways a release can silently fail to ship.
* Message proxy: a signed agent id routes to that agent; a forged one does not.
* Front-end widget: the mount carries a valid signature for its agent id.

= 0.2.1 =
Admin experience and widget customization overhaul:
* Reworked admin UX with a dedicated Appearance tab and a settings dashboard.
* Multi-agent support in the admin UI.
* Page-targeting control and branded admin styling.
* Embed transport defaults to the production platform; Platform URL marked advanced.

= 0.1.0 =
Initial build (feature-complete against the mock gateway):
* Plugin scaffold, coding standards, and git-as-deploy tooling.
* Gateway client contract with mock and HTTP implementations.
* Settings storage with whitelisting sanitization and wp-config-first secret handling.
* Durable usage table (dual-write), courtesy budget meter, and Service Status.
* Admin page: General / Agent / Service Status / Tests.
* REST proxy with nonce-protected permission callback and friendly error mapping.
* Accessible front-end widget, `[newtide_agent]` shortcode, and Gutenberg block.
* Widget customization (admin-wide): four launcher positions, light/dark/auto colour scheme, header title, pill or bubble launcher, and an optional "Powered by" line; auto-open delay, hide-on-mobile, remember open state, audience gate (everyone / logged-in / logged-out), and per-page suppression by ID; custom input placeholder, clickable suggested prompts, and a custom error message. Settings split across new Appearance and Behavior tabs.
* Publishing tab: an in-plugin walkthrough of how to make a RisingTide agent public (enable public access, create a key, get the embed snippet) so the setup workflow lives next to the plugin settings.
* Embed mode (Connection mode = Embed): injects RisingTide's official agent-embed.js widget using a publishable pk_ key, honouring the enable / audience / per-page-exclusion gates. Floating (site-wide bubble) or inline (via the shortcode/block) placement. The existing Proxy mode (the plugin's own widget through a server-side gateway) remains the default.
