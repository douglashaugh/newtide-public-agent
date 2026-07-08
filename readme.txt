=== NewTide Public Agent ===
Contributors: newtide
Tags: agent, chat, ai, support, embed
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed a published NewTide / Agent Harbor public agent on your WordPress site.

== Description ==

NewTide Public Agent embeds one published agent (support chat, guided browsing,
sales-inquiry response) via a shortcode or block. It is a thin client: the
Public Agent Gateway owns identity, safety, rate-limiting, and cost control.
The plugin renders the UI and relays messages through a same-origin,
nonce-authenticated proxy so the gateway credential never reaches the browser.

This is an early scaffold (v0.1.0). Functionality lands milestone by milestone.

== External services ==

This plugin sends end-user chat messages and minimal page context (URL, title,
locale) to the NewTide Public Agent Gateway to obtain agent replies. Full
external-service disclosure, the data sent, and links to the privacy policy and
terms will be completed before public release.

== Changelog ==

= 0.1.0 =
* Scaffold: plugin bootstrap, structured logger, Service Status registry,
  deterministic test runner, coding-standards + deployment tooling.
