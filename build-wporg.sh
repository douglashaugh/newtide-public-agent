#!/usr/bin/env bash
#
# build-wporg.sh -- produce the zip to submit to the WordPress.org directory.
#
# Run from Git Bash:  ./build-wporg.sh
#
# ADR-003 says never hand-build a production zip, and this does not: the package
# comes straight out of `git archive` at HEAD. That matters. The 0.2.1 zip that
# would not install was almost certainly made with PowerShell's Compress-Archive,
# which writes BACKSLASH path separators; the ZIP spec requires forward slashes,
# so PHP read "newtide-public-agent\admin\..." as one long filename, created no
# directories, and WordPress found no plugin. `git archive` always emits correct
# separators, and this script asserts it before handing you the file.
#
# What is excluded and why:
#   - .gitattributes export-ignore already drops docs/, CLAUDE.md, deploy.bat,
#     composer.json and the tooling configs (see that file).
#   - lib/ is dropped HERE, not there, because it must ship on GitHub and must
#     NOT ship to wordpress.org: core owns updates for a hosted slug, and a
#     bundled Plugin Update Checker filtering the same update_plugins transient
#     would compete with it. The bootstrap guards on is_readable(), so its
#     absence disables the checker cleanly rather than fataling, and the
#     Environment suite asserts the .org case explicitly.

set -euo pipefail

cd "$( dirname "${BASH_SOURCE[0]}" )"

SLUG="newtide-public-agent"
OUT_DIR="dist"

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
ZIP="${OUT_DIR}/${SLUG}-${header_version}-wporg.zip"
rm -f "$ZIP"

git archive --format=zip --prefix="${SLUG}/" -o "$ZIP" HEAD -- . ':(exclude)lib'

# Verify rather than trust. Each of these has bitten this project already.
fail=0

# -F (fixed string) is required. A bare grep -q '\\' is an incomplete escape:
# grep warns "Trailing backslash" and matches nothing, so the guard silently
# passes everything -- verified against the malformed 0.2.1 zip, which -F
# catches and the escaped form does not.
if unzip -l "$ZIP" | grep -qF '\'; then
  echo "ERROR: archive contains backslash path separators -- it will not install." >&2
  fail=1
fi

for must_not in "lib/" "docs/" "CLAUDE.md" "deploy.bat" "composer.json" "build-wporg.sh"; do
  if unzip -l "$ZIP" | grep -q "${SLUG}/${must_not}"; then
    echo "ERROR: ${must_not} must not ship to wordpress.org." >&2
    fail=1
  fi
done

for must in "${SLUG}.php" "readme.txt" "uninstall.php"; do
  if ! unzip -l "$ZIP" | grep -q "${SLUG}/${must}"; then
    echo "ERROR: ${must} is missing from the archive." >&2
    fail=1
  fi
done

if [ "$fail" -ne 0 ]; then
  rm -f "$ZIP"
  echo "Build failed; the archive was removed so a bad package cannot be submitted by mistake." >&2
  exit 1
fi

echo "Built ${ZIP} (version ${header_version})"
echo "Contents:"
unzip -l "$ZIP" | tail -n +4 | head -20
echo
echo "Next: upload at https://wordpress.org/plugins/developers/add/"
