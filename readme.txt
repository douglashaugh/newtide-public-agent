=== NewTide Public Agent ===
Contributors: newtide
Tags: agent, chat, ai, support, embed
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.2.1
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

Data is sent only when a visitor interacts with the widget. The gateway credential is sent as an authorization header from your server and is never exposed to the browser. By default the plugin stores only call **metadata** (timestamps, latency, status, token counts) — never message content. Storing transcript content is an explicit, off-by-default option.

Confirm the gateway's provider, terms, and privacy policy before enabling on a production or EU-facing site.

== Frequently Asked Questions ==

= Is my gateway key exposed to visitors? =
No. The browser talks only to your site's own REST proxy; the credential is added server-side. Prefer defining `NPA_GATEWAY_KEY` in `wp-config.php` so it never touches the database.

= Does it store conversations? =
By default it stores only call metadata (no message content). Transcript storage is an explicit opt-in with a retention window and is off by default.

= Can I use it before the gateway is ready? =
Yes. Without a configured URL and credential the plugin runs against a deterministic mock, so you can build and preview the widget.

= Does the plugin prevent abuse or control cost? =
Those are enforced by the gateway. The plugin offers an optional courtesy daily cap and a per-request throttle, but the authoritative controls live gateway-side.

== Changelog ==

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
