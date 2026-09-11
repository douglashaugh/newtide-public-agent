=== NewTide Public Agent ===
Contributors: newtide
Tags: agent, chat, ai, support, embed
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.0
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

This plugin relies on external services. Which ones depends on the connection mode you choose on the **Agent** tab.

**Proxy mode — the NewTide Public Agent Gateway**

The plugin connects to the Public Agent Gateway to obtain agent replies. When a visitor sends a message, the plugin transmits, from your server:

* the visitor's message text;
* an opaque conversation ID used to thread the exchange;
* page context: the page URL, page title, and locale;
* metadata: a source tag, the plugin version, and your site host.

Data is sent only when a visitor interacts with the widget. The gateway credential is sent as an authorization header from your server and is never exposed to the browser. By default the plugin stores only call **metadata** (timestamps, latency, status, token counts) — never message content. **Store transcripts** is an explicit opt-in that persists what visitors type and what the agent replies; it is off unless you turn it on, bounded by a retention window, and purged daily.

**Embed mode — RisingTide's agent widget (`agent-embed.js`)**

In Embed mode the plugin does not proxy messages. Instead it adds a `<script>` tag to your pages that loads `agent-embed.js` from the platform host you configure — by default `https://ai.newtide.ai`. That script is served by NewTide, runs in the visitor's browser, and renders the chat itself, so the conversation goes directly from the visitor to the platform without passing through your server. Your publishable `pk_` key is included in the tag; it is designed to be public and is restricted by the allowed-origins list you set when creating it.

The script is loaded on any page where the widget is enabled and permitted to appear, whether or not a visitor interacts with it. What the platform collects once the widget is in use is governed by NewTide's own terms and privacy policy, not by this plugin.

Service URLs: `https://ai.newtide.ai` (production) — or the Platform URL / Gateway base URL you configure.
Terms and privacy policy: https://newtide.ai

Confirm the provider's terms and privacy policy before enabling on a production or EU-facing site.

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

== Screenshots ==

1. Home — the setup checklist, with each step linking to the tab that satisfies it.
2. Agent — connection mode, publishable key or gateway credential, agent picker, and placement.
3. Appearance — launcher shape, size, colour and icon, with a live preview that updates as you type.
4. Additional Agents — route a different agent to specific pages, no shortcode required.
5. Service Status — usage over the last 14 days, health roll-up, and transcript retention.
6. Tests — the built-in battery, run from the admin, against fixtures and the mock gateway.
7. The chat widget on the front end.

== Changelog ==

= 0.6.0 =
**The agent can follow up.** Proxy mode now keeps short-lived conversation
context on your server, so "and who runs it?" works.

* The agent API is single-turn and ignores every threading field offered to it
  (measured across six request shapes — see the Conversation probe), so
  continuity is reconstructed here. The last **10 exchanges** are kept for **one
  hour** and replayed as context, under a hard character cap.
* History is held **server-side and never taken from the browser**. A
  browser-supplied transcript would let a visitor invent turns the agent never
  said; here the client can only add its own next message.
* Conversation ids are minted with UUID entropy and an id this site did not issue
  is never continued, so one visitor cannot reach another's conversation.
* The widget gains a **New chat** button, which clears the view and discards the
  stored conversation.
* On by default, and switchable off on the Agent tab — a chat that cannot follow
  up is the worse failure today. **Turn it off once the agent platform supports
  conversations itself**; this is a workaround, and it means earlier messages are
  replayed to the agent, so turns get longer and visitor text sits inside the
  prompt.
* Embed mode is unaffected: that widget is RisingTide's and has the same
  single-turn limitation, which only they can fix.

= 0.5.4 =
* Fixes the Conversation probe and the resolved-agent display not appearing. The
  Agent tab decided which Proxy route was in use with a looser test than the code
  that actually picks the client: a stored gateway credential on its own counted
  as a dedicated gateway and hid both sections, even though the site was using the
  public agent API regardless. The tab now asks exactly the same question the
  runtime does.
* A gateway credential that no code path can reach is now called out. A dedicated
  gateway needs all three of an API base URL, an agent ID and a credential before
  it takes over — and an earlier version of the Publishing guide wrongly told
  people to paste their pk_ key there, so a stray one is likely.
* The Agent tab no longer makes two identical calls to the agent API on every
  page load.

= 0.5.3 =
* Adds a **conversation probe** to the Agent tab (Proxy mode, diagnostic). The
  agent API takes a single message and its own embed widget sends nothing else,
  so each turn may arrive with no memory of the last. The probe asks the agent to
  remember a random code and then asks for it back, trying several request shapes
  — chatId, conversationId, sessionId, history[], messages[] — in case the server
  supports threading its client never uses. It makes real calls to your live
  agent, one shape at a time so a slow reply cannot time out the whole run.
* Connection-mode and publishable-key descriptions corrected: the key is used by
  both modes and is what selects the agent, and Proxy no longer claims to need a
  server-side gateway credential.

= 0.5.2 =
Gateway settings brought in line with how Proxy mode now works:
* **Agent** is no longer an editable field on the public API path — the key
  selects the agent, so the panel shows the one your key resolves to and says
  there is nothing to choose. It also carries that id forward on save, which
  clears a stale value; a wrong id did not affect replies but misattributed every
  row in the usage table and the busiest-agents chart. If the stored id differs,
  the panel says so before you save.
* **Gateway base URL** is now labelled advanced and states the address actually in
  use, derived from your Platform URL. Leave it blank unless NewTide gives you a
  dedicated endpoint.
* **Gateway credential** is marked advanced and explains that the public agent API
  authenticates with the publishable key, so most sites leave it empty.
* The **daily cap** description no longer claims abuse prevention happens at "the
  gateway"; it names the agent API and its retry behaviour.
* The setup checklist asks for a publishable key rather than an agent id whenever
  the key is what identifies the agent — which is both modes now.

= 0.5.1 =
* Fixes Proxy mode failing with "Origin header is required". The API needs two
  origin headers, not one: `X-Embed-Origin` carries your site (the allowed-origins
  check), while `Origin` carries the platform — in a browser it is set
  automatically to the embed iframe's own origin, and PHP sends none at all. The
  plugin now sends both, exactly as the browser does.
* Test: requests carry the API key and both origin headers.

= 0.5.0 =
**Proxy mode works.** It now relays through the public agent API — the same
service the embedded widget itself calls — so the plugin renders its own chat
widget and the Appearance and Behavior tabs apply again.

* Set Connection mode to Proxy with your publishable key and platform URL in
  place. No separate gateway credential is needed; the API host is derived from
  the platform URL (`ai.newtide.ai` → `ai-api.newtide.ai`).
* Your server sends this site's own address as the request origin, so the site
  URL must be in the key's allowed-origins list exactly as it is for Embed mode.
* Replies arrive as a stream and are reassembled server-side, so the widget shows
  the finished answer. Rate limiting is reported as "busy" rather than a generic
  error.
* Test connection in Proxy mode now genuinely validates the key *and* the origin,
  because the API checks both.

Note: this API is not yet formally documented by NewTide. It is what the embedded
widget calls, so it is the same service — confirm with the platform team before
relying on it in production. Conversation memory across turns is not yet proven
on this path.

= 0.4.1 =
* The built-in mock can no longer answer a real visitor. Proxy mode falls back to
  the mock when no gateway is configured, which is useful for previewing but meant
  a live site could tell visitors "Mock agent reply. You said: ..." in what looks
  like the company's own support chat. Administrators still see the mock when
  previewing; everyone else gets your configured error message.
* The Agent tab states plainly that Proxy mode requires a server-to-server agent
  API, and that the published public-agent path is an embedded page rather than
  such an API — so the mode cannot be made to work by configuration alone.
* Test: a visitor is never served a canned reply from the built-in mock.

= 0.4.0 =
The admin now tells the truth about which connection mode a setting belongs to.
Embed and Proxy share almost nothing, but every screen presented both at once.

* **Test connection** tests the mode you are actually in. In Embed mode it checks
  that a publishable key is set and that this server can fetch the widget loader
  from your platform URL — and says plainly that your key is validated in the
  visitor's browser, which no server-side check can reproduce. Previously it
  health-checked the gateway, which Embed never uses; with no gateway configured
  that meant it checked the built-in mock and reported "Connected", reading as
  confirmation the widget was live when nothing had been verified.
* In **Proxy** mode, a reply from the built-in mock now says so instead of
  reporting a successful connection.
* The **Agent** tab shows only the fields the selected mode uses. Publishable key
  and Platform URL appear for Embed; the gateway card appears for Proxy. The
  server renders the correct state, so it is right without JavaScript, and the
  panels switch as you change the mode.
* **Appearance** and **Behavior** carry a notice in Embed mode explaining that the
  embedded widget is styled in RisingTide — and, on Behavior, that the audience
  and page rules *do* still decide where it appears. This was the July review's
  main support-confusion warning.
* "Proxy-mode settings" is renamed "Gateway settings".

= 0.3.5 =
Important fix — the Tests tab could overwrite your live settings:
* Running the battery wrote fixture values into the real settings row and
  restored them at the end. If a run was cut short — a PHP timeout, a memory
  limit, a fatal, closing the tab — the restore never happened and the site was
  left running the fixture. On a production install this published the
  placeholder key `pk_embed_test_123` to real visitors, so the widget loaded and
  answered every message with "Invalid or revoked API key". Even a completed run
  left a window where a visitor could be served test configuration.
* Suites now present fixture settings through a filter and write nothing, so
  there is nothing to leave behind however the request ends. The runner clears
  the override after every suite in a `finally`, so one suite cannot leak into
  the next either.
* A new check asserts this directly: the override is visible to readers while
  the stored row underneath is byte-for-byte untouched.

If your widget is showing "Invalid or revoked API key", re-enter your publishable
key on the Agent tab and save — the stored value was replaced by the fixture.

= 0.3.4 =
Fixes to the Publishing guide, which gave instructions that could not work:
* The guide walks you through creating a publishable `pk_` key and then told you
  to put it in `NPA_GATEWAY_KEY` — the Proxy-mode server-side secret, a different
  credential entirely. Following it left Embed mode unconfigured and the widget
  silently absent. It now says to set Connection mode to Embed and paste the key
  into Publishable key (or `NPA_PUBLIC_KEY`), notes that the key selects the agent
  so no agent ID is needed, and carries a warning against the old advice.
* The connect step still assumed the widget needed a shortcode; it now explains
  that Floating placement covers every allowed page on its own.
* Allowed origins now says to match the origin exactly as browsers send it (list
  www and non-www if both resolve) and warns that a local development site is not
  a valid origin — test Embed on the real domain, or use Proxy mode locally.

= 0.3.3 =
Directory-submission readiness (no functional change to the widget):
* readme.txt: "Tested up to" raised to 7.1; External services now also documents
  Embed mode loading agent-embed.js from the platform host into the visitor's
  browser, which was previously undisclosed; Screenshots and Upgrade Notice
  sections added.
* The Environment suite understands both distributions. A GitHub build must have
  the bundled update checker present and registered; a WordPress.org build must
  NOT, because core owns updates for a hosted slug and two updaters filtering the
  same transient would compete.
* Adds build-wporg.sh, which produces the submission package from git archive.

= 0.3.2 =
* The launcher label can now be edited on the Appearance tab, beside the Pill /
  Bubble control that refers to it, and the live preview updates as you type.
  The setting already existed on the General tab as "Launcher label", but
  Appearance offered a shape called "Pill (label text)" and a preview showing
  that text with no field to change it — so the option read as missing. It is
  the same setting in both places; editing either one changes the launcher.

= 0.3.1 =
Fixes:
* The widget now actually appears in Proxy mode. Site-wide injection existed only
  for Embed mode, so a Proxy site with the widget enabled, placement set to
  "Floating bubble (site-wide)" and pages set to "All pages" rendered nothing at
  all unless a shortcode, block or additional agent placed it — while the admin
  offered an enable switch, a page scope and four launcher positions that all
  implied otherwise. Proxy + Floating now injects on every allowed page, honouring
  the same enable, audience, page-scope and exclusion gates as Embed.
* A page that already mounts the widget via shortcode or block no longer also
  gets the site-wide bubble.
* "Embed placement" is renamed "Placement" and its help text corrected: the
  setting governs both connection modes, not just Embed.

Tests:
* Front-end widget: Proxy + Floating renders site-wide with no shortcode, and
  Inline placement does not auto-inject.

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

== Upgrade Notice ==

= 0.3.1 =
Fixes a bug where the widget never appeared in Proxy mode even with the widget enabled and set to show on all pages.

= 0.3.0 =
Adds opt-in transcript storage with an enforced retention window. Off by default; no message content is stored unless you turn it on.

= 0.2.2 =
Fixes per-page agents being ignored in Proxy mode: every message was answered by the site-wide default agent.
