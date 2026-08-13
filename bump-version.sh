#!/usr/bin/env bash
# bump-version.sh — Coordinated version bump for bdus-api + bdus-app + this
#                    root repo (orchestration: bradypus.yml, compose files,
#                    helper scripts)
#
# Usage:
#   ./bump-version.sh <version>
#
# The version argument sets major.minor.patch on bdus-api/bdus-app (via their
# composer.json/package.json) and tags this root repo the same way — all
# three always carry the same version tag, even though the root has no
# manifest file of its own to bump (it's just tagged directly on HEAD).
# Use this for every coordinated release (all major and minor bumps, and any
# patch release you want to ship as a coherent trio).
#
# For hotfixes that touch only one repo, edit that repo's composer.json or
# package.json directly, commit and tag there — no need to run this script.
#
# Examples:
#   ./bump-version.sh 5.1.0    # coordinated minor release
#   ./bump-version.sh 5.1.1    # coordinated patch (repos changed)
#   ./bump-version.sh 6.0.0    # major release

set -euo pipefail

# ── Helpers ───────────────────────────────────────────────────────────────────

red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n'   "$*"; }

die() { red "ERROR: $*"; exit 1; }

# ── Validate argument ─────────────────────────────────────────────────────────

VERSION="${1:-}"
[[ -z "$VERSION" ]] && die "Usage: $0 <version>  (e.g. 5.1.0)"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Version must be in X.Y.Z format (got: $VERSION)"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$SCRIPT_DIR"
API_DIR="$SCRIPT_DIR/bdus-api"
APP_DIR="$SCRIPT_DIR/bdus-app"

[[ -d "$API_DIR" ]] || die "bdus-api directory not found: $API_DIR"
[[ -d "$APP_DIR" ]] || die "bdus-app directory not found: $APP_DIR"

# ── Read current versions ─────────────────────────────────────────────────────

cur_api=$(python3 -c "import json,sys; print(json.load(open('$API_DIR/composer.json'))['version'])")
cur_app=$(python3 -c "import json,sys; print(json.load(open('$APP_DIR/package.json'))['version'])")
cur_root=$(git -C "$ROOT_DIR" describe --tags --abbrev=0 2>/dev/null || echo "none")

bold "Current versions:"
echo "  bdus-api  $cur_api"
echo "  bdus-app  $cur_app"
echo "  root      $cur_root  (tag only, no manifest)"
echo ""
bold "Target version: $VERSION"
echo ""

[[ "$cur_api" == "$VERSION" && "$cur_app" == "$VERSION" && "$cur_root" == "v$VERSION" ]] && {
  green "All three are already at $VERSION — nothing to do."
  exit 0
}

# ── Confirm ───────────────────────────────────────────────────────────────────

read -rp "Proceed? [y/N] " answer
[[ "$(echo "$answer" | tr '[:upper:]' '[:lower:]')" == "y" ]] || { echo "Aborted."; exit 0; }

# ── Check working trees are clean ─────────────────────────────────────────────

for dir in "$API_DIR" "$APP_DIR" "$ROOT_DIR"; do
  name=$(basename "$dir")
  if ! git -C "$dir" diff --quiet || ! git -C "$dir" diff --cached --quiet; then
    die "$name has uncommitted changes — commit or stash them first"
  fi
done

# Each repo's manifest bump + commit is skipped if it's already at $VERSION —
# lets you run this to just catch up a single lagging repo (e.g. root) without
# erroring on "nothing to commit" in the ones already done.

MSG="chore: bump version to $VERSION"
TAG="v$VERSION"

# ── bdus-api ───────────────────────────────────────────────────────────────────

if [[ "$cur_api" != "$VERSION" ]]; then
  python3 - "$API_DIR/composer.json" "$VERSION" <<'PYEOF'
import json, sys
path, ver = sys.argv[1], sys.argv[2]
with open(path) as f:
    data = json.load(f)
data['version'] = ver
with open(path, 'w') as f:
    json.dump(data, f, indent=4, ensure_ascii=False)
    f.write('\n')
PYEOF
  git -C "$API_DIR" add composer.json
  git -C "$API_DIR" commit -m "$MSG"
  echo "  ✓ bdus-api/composer.json → $VERSION (committed)"
else
  echo "  · bdus-api already at $VERSION — skipping commit"
fi

# ── bdus-app ───────────────────────────────────────────────────────────────────

if [[ "$cur_app" != "$VERSION" ]]; then
  python3 - "$APP_DIR/package.json" "$VERSION" <<'PYEOF'
import json, sys
path, ver = sys.argv[1], sys.argv[2]
with open(path) as f:
    data = json.load(f)
data['version'] = ver
with open(path, 'w') as f:
    json.dump(data, f, indent=2, ensure_ascii=False)
    f.write('\n')
PYEOF
  git -C "$APP_DIR" add package.json
  git -C "$APP_DIR" commit -m "$MSG"
  echo "  ✓ bdus-app/package.json  → $VERSION (committed)"
else
  echo "  · bdus-app already at $VERSION — skipping commit"
fi

# ── Tag all three (idempotent — skip if the tag already exists locally) ────────

echo ""
bold "Tagging..."

for dir in "$API_DIR" "$APP_DIR" "$ROOT_DIR"; do
  name=$(basename "$dir")
  if git -C "$dir" rev-parse "$TAG" >/dev/null 2>&1; then
    echo "  · $name already tagged $TAG"
  else
    git -C "$dir" tag "$TAG"
    echo "  ✓ $name tagged $TAG"
  fi
done

# ── Push ──────────────────────────────────────────────────────────────────────

echo ""
bold "Pushing..."

for dir in "$API_DIR" "$APP_DIR" "$ROOT_DIR"; do
  name=$(basename "$dir")
  git -C "$dir" push && git -C "$dir" push origin "$TAG"
  echo "  ✓ $name pushed"
done

echo ""
green "Done! All three repos are now at $TAG."
echo ""
echo "  Hotfix workflow (one repo only):"
echo "    cd bdus-api  # or bdus-app"
echo "    # edit composer.json / package.json version manually"
echo "    git add <file> && git commit -m 'chore: bump version to X.Y.Z'"
echo "    git tag vX.Y.Z && git push && git push origin vX.Y.Z"
