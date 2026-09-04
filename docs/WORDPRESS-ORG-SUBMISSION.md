# Publishing to the WordPress.org plugin directory

**Status as of 0.3.3 (2026-09-04):** the plugin is prepared and a submission package builds cleanly. Nothing has been submitted. Four items remain, all of which need a human with a WordPress.org account — see [§4](#4-what-only-a-human-can-do).

**Why this document exists:** the directory is the only route by which a customer *finds* this plugin rather than being sent a link. Getting there costs the release mechanism the project currently runs on, and that trade is not reversible on a whim. This records the decision, the work already done, and the parts that are easy to get wrong.

---

## 1. The decision: directory OR GitHub, not both

Publishing to wordpress.org makes **WordPress core the update source** for the slug `newtide-public-agent`. The plugin currently updates itself from GitHub via Plugin Update Checker (PUC), which filters the same `update_plugins` transient core does. Two updaters competing over one slug is unpredictable, and the directory guidelines do not permit a hosted plugin to self-update from elsewhere.

| | Discoverability | Release flow | Review risk |
|---|---|---|---|
| **wordpress.org** | Searchable; installs from wp-admin | SVN; tag every release; PUC stripped | Human review, unpredictable duration |
| **GitHub** (today) | None — you send a link | `git push` **is** the deploy | None |

Everything else built this cycle survives the move: version-lockstep discipline (ADR-002), the test battery, `.gitattributes` keeping internal files out of installs, the clean-archive verification. **Only delivery changes.**

The repository stays public either way — see the `repo-must-stay-public` note. On .org it must be public for the source to be auditable; on GitHub it must be public for PUC to work without a token.

---

## 2. What is already done (shipped in 0.3.3)

### readme.txt

- **`Tested up to: 7.1`** — was `6.7` while WordPress shipped 7.1. The directory flags plugins untested against recent releases, and staleness that visible invites scrutiny of everything else.
- **External services rewritten and split by connection mode.** The previous text documented the Proxy-mode gateway carefully but never mentioned that **Embed mode injects a `<script>` tag loading `agent-embed.js` from the platform host into every visitor's browser**. Undisclosed remote code is among the likeliest rejection reasons. The section now names the host, explains the `pk_` key is publishable and origin-locked, and states that the script loads on any permitted page whether or not a visitor interacts.
- **`== Screenshots ==`** added (seven entries — the files themselves still need taking, §4).
- **`== Upgrade Notice ==`** added for the releases worth pushing an update for.

### The test battery understands both distributions

`register_core_tests()` branches on whether `lib/plugin-update-checker/plugin-update-checker.php` is readable:

- **GitHub build** — asserts the vendored checker is loaded *and* that the checker object was constructed. An emptied `lib/` disables updates silently; this is what makes that visible.
- **.org build** — asserts the opposite: that **no** updater is bundled and `NPA_Plugin::$update_checker` is null, so nothing competes with core.

The bootstrap already guards on `is_readable()`, so stripping `lib/` degrades cleanly rather than fataling.

> **Expected counts differ by design.** GitHub build: **73 of 73**. Directory build: **72 of 72** (the two GitHub-updater checks collapse into one .org check). A 72 on a GitHub build is a real failure, not a variant.

### `build-wporg.sh`

Builds the submission package from `git archive` at `HEAD`. Run from Git Bash:

```bash
cd newtide-public-agent
./build-wporg.sh
# -> dist/newtide-public-agent-<version>-wporg.zip
```

It refuses to build when the two version fields disagree (ADR-002), warns when the working tree is dirty (the package comes from HEAD, so uncommitted work is *not* in it), and verifies the result before handing it over:

- path separators are forward slashes
- `lib/`, `docs/`, `CLAUDE.md`, `deploy.bat`, `composer.json`, `build-wporg.sh` are all absent
- the main file, `readme.txt` and `uninstall.php` are present

On any failure it deletes the archive, so a bad package cannot be submitted by mistake.

---

## 3. Hard-won details — read before touching the packaging

**Never build the zip by hand (ADR-003).** The `newtide-public-agent-0.2.1.zip` that would not install stored **backslash** path separators, almost certainly from PowerShell's `Compress-Archive`. The ZIP spec requires forward slashes; PHP read `newtide-public-agent\admin\...` as one long *filename*, created no directories, and WordPress reported no valid plugin. `git archive` cannot make this mistake. This cost real debugging time and looked like a code problem when it was a packaging problem.

**The separator guard needs `grep -F`.** `grep -q '\\'` is an incomplete escape: grep warns `Trailing backslash` and matches nothing, so the guard passes everything. Verified by pointing both forms at the real malformed 0.2.1 zip — `-F` catches it, the escaped form does not. If you touch that check, re-test it against a known-bad archive. *A verification step that cannot fail is worse than none.*

**`lib/` is excluded in the script, not in `.gitattributes`.** It must ship to GitHub and must not ship to the directory. `.gitattributes` `export-ignore` handles everything that should never ship anywhere; `lib/` is the one path that differs per destination.

**The slug is permanent.** Assigned at submission, derived from the plugin name. `newtide-public-agent` matches the text domain, which is required for language-pack support. It cannot be changed afterwards.

---

## 4. What only a human can do

| # | Item | Notes |
|---|---|---|
| 1 | **Register / confirm the WordPress.org account** | `Contributors: newtide` in readme.txt must be a real registered username. If `newtide` is unclaimed the line is invalid. Update readme.txt once known. |
| 2 | **Banner and icon assets** | `banner-772x250.png`, `banner-1544x500.png`, `icon-128x128.png`, `icon-256x256.png`. These live in the SVN `assets/` folder at the repo root, **not** inside the plugin, and are not part of what users download. |
| 3 | **Screenshots** | `screenshot-1.png` … `screenshot-7.png` in SVN `assets/`, numbered to match the `== Screenshots ==` entries in readme.txt. **Do not reuse the images in `AGENT PLUGIN INFO`** — they are 0.2.1-era and contain a live `pk_` key that should be rotated regardless. |
| 4 | **Submit** | Upload the built zip at `https://wordpress.org/plugins/developers/add/`. |

---

## 5. After approval — the SVN workflow

Approval grants an SVN repository at `https://plugins.svn.wordpress.org/newtide-public-agent/`. Subversion, not Git. Standard layout:

```
trunk/      the current development state of the plugin
tags/       one directory per released version, e.g. tags/0.3.3/
assets/     banner, icon, screenshots (directory listing only, never downloaded)
branches/   rarely used
```

Release cycle, replacing steps B4–B6 of the git-as-deploy loop:

1. Bump **both** version fields plus `Stable tag:` in readme.txt — the same lockstep rule, now with a third field.
2. Build with `build-wporg.sh` and copy its contents into `trunk/`.
3. `svn cp trunk tags/<version>` and commit.
4. **`Stable tag:` in `trunk/readme.txt` is what actually decides which version users receive.** Pointing it at a tag that does not exist ships nothing; this is the directory's equivalent of forgetting the header bump.

Keep pushing to GitHub as the source of truth and treat SVN as a publishing target. Do **not** re-add PUC to the .org build.

---

## 6. Open risks to weigh before submitting

**Neither connection mode has answered a real question from a real visitor.** A reviewer will install this and try it. Today Proxy mode answers from the built-in mock because `gateway_base_url` is unset, and the gateway contract in `GATEWAY-CONTRACT.md` is still provisional with six unanswered questions. Embed mode with a working `pk_` key is the path that does not depend on that — get it demonstrably working on a public page first, or the plugin's first genuine end-to-end test will be somebody else's.

**Remote script loading will draw a question.** Loading `agent-embed.js` from `ai.newtide.ai` is legitimate — it is the vendor's own service SDK, the same shape as Stripe.js or Google Maps — but reviewers scrutinise it. The §2 disclosure is the defence. Do not weaken it.

**Review duration is unknowable.** A volunteer queue; historically days to months. Do not plan a launch date around it.

**A service-dependent plugin is allowed but must degrade honestly.** The built-in mock helps here: the plugin does something coherent before it is configured, rather than erroring.

---

## Related

- `ARCHITECTURE-DECISIONS.md` — ADR-001 (git-as-deploy), ADR-002 (version lockstep), ADR-003 (never hand-build the prod zip)
- `GATEWAY-CONTRACT.md` — the provisional contract and its open questions
- `../CLAUDE.md` — identity table, conventions, Definition of Done
