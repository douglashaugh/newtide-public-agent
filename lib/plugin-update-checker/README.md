# Vendored: Plugin Update Checker

This plugin deploys via **git-as-deploy** (ADR-001): pushing to the GitHub
`main` branch is the production release, and each installed site auto-updates
through [Plugin Update Checker (PUC)](https://github.com/YahnisElsts/plugin-update-checker).

**Drop the PUC library here** so this folder contains
`plugin-update-checker.php` and PUC's `Puc/` tree, e.g.:

```
lib/plugin-update-checker/
├── plugin-update-checker.php
├── Puc/
├── vendor/
└── ...
```

Install it with either:

- Composer: `composer require yahnis-elsts/plugin-update-checker` and copy the
  package here, **or**
- Download the release zip from the repo above and extract it into this folder.

`newtide-public-agent.php` loads it defensively (`is_readable()` guard), so the
plugin runs fine before the library is present — it simply won't offer
auto-updates until you vendor it in. The library is intentionally NOT
git-ignored; it must ship with the plugin.
