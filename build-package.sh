#!/usr/bin/env bash
#
# build-package.sh -- produce an installable plugin zip. Never zip by hand.
#
#   ./build-package.sh            # pilot / direct install (default)
#   ./build-package.sh pilot
#   ./build-package.sh wporg      # wordpress.org directory submission
#
# WHY THIS EXISTS (ADR-003). The 0.2.1 zip that would not install was hand-built,
# almost certainly with PowerShell's Compress-Archive, which writes BACKSLASH
# path separators. The ZIP spec requires forward slashes, so PHP read
# "newtide-public-agent\admin\..." as one long filename, created no directories,
# and WordPress reported no valid plugin. `git archive` cannot make that mistake.
#
# It also gets the FOLDER NAME right. GitHub's own branch download unpacks to
# "newtide-public-agent-main/", so a site installing from it ends up with the
# wrong plugin directory -- permanently, because updates install into whatever
# folder already exists, and PUC's fixDirectoryName only runs during a
# PUC-driven update. A wrong folder also means wordpress.org would later treat
# the plugin as a different one entirely, leaving the customer with two copies.
#
# TARGETS
#   pilot -- everything, including lib/ (Plugin Update Checker), so the site
#            auto-updates from GitHub afterwards. This is what you send someone.
#   wporg -- lib/ stripped: core owns updates for a hosted slug, and a bundled
#            updater filtering the same update_plugins transient would compete
#            with it. The bootstrap guards on is_readable(), so its absence
#            disables the checker cleanly, and the Environment suite asserts
#            that case explicitly (72 checks there vs 73 on a pilot build).
#
# docs/, CLAUDE.md, deploy.bat and the tooling configs are excluded from both by
# .gitattributes export-ignore -- see that file.

set -euo pipefail

cd "$( dirname "${BASH_SOURCE[0]}" )"

SLUG="newtide-public-agent"
OUT_DIR="dist"
TARGET="${1:-pilot}"

case "$TARGET" in
  pilot|wporg) ;;
  *) echo "Usage: $0 [pilot|wporg]" >&2; exit 2 ;;
esac

header_version="$( grep -m1 -E '^\s*\*\s*Version:' "${SLUG}.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]' )"
const_version="$( grep -m1 -E "define\( 'NPA_VERSION'" "${SLUG}.php" | sed -E "s/.*'NPA_VERSION',[[:space:]]*'([^']+)'.*/\1/" )"

# ADR-002 -- refuse to build a package whose two version fields disagree.
if [ "$header_version" != "$const_version" ]; then
  echo "ERROR: version mismatch -- header '${header_version}' vs NPA_VERSION '${const_version}'." >&2
  exit 1
fi

if [ -n "$( git status --porcelain )" ]; then
  echo "WARNING: working tree is dirty. The package is built from HEAD, so uncommitted changes are NOT included." >&2
fi

mkdir -p "$OUT_DIR"
ZIP="${OUT_DIR}/${SLUG}-${header_version}-${TARGET}.zip"
rm -f "$ZIP"

if [ "$TARGET" = "wporg" ]; then
  git archive --format=zip --prefix="${SLUG}/" -o "$ZIP" HEAD -- . ':(exclude)lib'
else
  git archive --format=zip --prefix="${SLUG}/" -o "$ZIP" HEAD
fi

# Verify rather than trust. Every check below corresponds to a failure this
# project has actually shipped.
fail=0

# -F (fixed string) is required. A bare grep -q '\\' is an incomplete escape:
# grep warns "Trailing backslash" and matches nothing, so the guard silently
# passes everything -- verified against the malformed 0.2.1 zip, which -F
# catches and the escaped form does not.
if unzip -l "$ZIP" | grep -qF '\'; then
  echo "ERROR: archive contains backslash path separators -- it will not install." >&2
  fail=1
fi

# The plugin must sit in a directory named exactly for the slug.
if ! unzip -l "$ZIP" | grep -q " ${SLUG}/${SLUG}.php\$"; then
  echo "ERROR: main file is not at ${SLUG}/${SLUG}.php -- wrong folder name." >&2
  fail=1
fi

for must_not in "docs/" "CLAUDE.md" "deploy.bat" "build-package.sh" "composer.json"; do
  if unzip -l "$ZIP" | grep -q "${SLUG}/${must_not}"; then
    echo "ERROR: ${must_not} must not ship." >&2
    fail=1
  fi
done

for must in "readme.txt" "uninstall.php"; do
  if ! unzip -l "$ZIP" | grep -q "${SLUG}/${must}"; then
    echo "ERROR: ${must} is missing from the archive." >&2
    fail=1
  fi
done

# The updater is the one file set that differs by target, in both directions.
puc_count="$( unzip -l "$ZIP" | grep -c "${SLUG}/lib/plugin-update-checker/.*\.php" || true )"
if [ "$TARGET" = "pilot" ] && [ "$puc_count" -eq 0 ]; then
  echo "ERROR: pilot build has no update checker -- installs would never receive updates." >&2
  fail=1
fi
if [ "$TARGET" = "wporg" ] && [ "$puc_count" -ne 0 ]; then
  echo "ERROR: wporg build bundles an updater that would compete with WordPress.org." >&2
  fail=1
fi

if [ "$fail" -ne 0 ]; then
  rm -f "$ZIP"
  echo "Build failed; the archive was removed so a bad package cannot be shipped by mistake." >&2
  exit 1
fi

echo "Built ${ZIP}"
echo "  version         ${header_version}"
echo "  target          ${TARGET}"
echo "  plugin folder   ${SLUG}/"
echo "  update checker  $( [ "$puc_count" -gt 0 ] && echo "included (${puc_count} files)" || echo "stripped (WordPress.org owns updates)" )"
echo

if [ "$TARGET" = "wporg" ]; then
  echo "Next: upload at https://wordpress.org/plugins/developers/add/"
else
  echo "Next: send this file. Install with Plugins -> Add New -> Upload Plugin."
  echo "The site auto-updates from GitHub from then on."
fi
