#!/usr/bin/env bash
# bump-version.sh — Coordinated version bump for bdus-api + bdus-app
#
# Usage:
#   ./bump-version.sh <version>
#
# The version argument sets major.minor.patch on BOTH repos simultaneously.
# Use this for every coordinated release (all major and minor bumps, and any
# patch release you want to ship as a coherent pair).
#
# For hotfixes that touch only one repo, edit that repo's composer.json or
# package.json directly, commit and tag there — no need to run this script.
#
# Examples:
#   ./bump-version.sh 5.1.0    # coordinated minor release
#   ./bump-version.sh 5.1.1    # coordinated patch (both repos changed)
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
API_DIR="$SCRIPT_DIR/bdus-api"
APP_DIR="$SCRIPT_DIR/bdus-app"

[[ -d "$API_DIR" ]] || die "bdus-api directory not found: $API_DIR"
[[ -d "$APP_DIR" ]] || die "bdus-app directory not found: $APP_DIR"

# ── Read current versions ─────────────────────────────────────────────────────

cur_api=$(python3 -c "import json,sys; print(json.load(open('$API_DIR/composer.json'))['version'])")
cur_app=$(python3 -c "import json,sys; print(json.load(open('$APP_DIR/package.json'))['version'])")

bold "Current versions:"
echo "  bdus-api  $cur_api"
echo "  bdus-app  $cur_app"
echo ""
bold "Target version: $VERSION"
echo ""

[[ "$cur_api" == "$VERSION" && "$cur_app" == "$VERSION" ]] && {
  green "Both repos are already at $VERSION — nothing to do."
  exit 0
}

# ── Confirm ───────────────────────────────────────────────────────────────────

read -rp "Proceed? [y/N] " answer
[[ "$(echo "$answer" | tr '[:upper:]' '[:lower:]')" == "y" ]] || { echo "Aborted."; exit 0; }

# ── Check working trees are clean ─────────────────────────────────────────────

for dir in "$API_DIR" "$APP_DIR"; do
  name=$(basename "$dir")
  if ! git -C "$dir" diff --quiet || ! git -C "$dir" diff --cached --quiet; then
    die "$name has uncommitted changes — commit or stash them first"
  fi
done

# ── Update composer.json (bdus-api) ───────────────────────────────────────────

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
echo "  ✓ bdus-api/composer.json → $VERSION"

# ── Update package.json (bdus-app) ────────────────────────────────────────────

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
echo "  ✓ bdus-app/package.json  → $VERSION"

# ── Commit each repo ──────────────────────────────────────────────────────────

MSG="chore: bump version to $VERSION"

git -C "$API_DIR" add composer.json
git -C "$API_DIR" commit -m "$MSG"
echo "  ✓ bdus-api committed"

git -C "$APP_DIR" add package.json
git -C "$APP_DIR" commit -m "$MSG"
echo "  ✓ bdus-app committed"

# ── Tag both repos ────────────────────────────────────────────────────────────

TAG="v$VERSION"

git -C "$API_DIR" tag "$TAG"
echo "  ✓ bdus-api tagged $TAG"

git -C "$APP_DIR" tag "$TAG"
echo "  ✓ bdus-app tagged $TAG"

# ── Push ──────────────────────────────────────────────────────────────────────

echo ""
bold "Pushing..."

git -C "$API_DIR" push && git -C "$API_DIR" push origin "$TAG"
echo "  ✓ bdus-api pushed"

git -C "$APP_DIR" push && git -C "$APP_DIR" push origin "$TAG"
echo "  ✓ bdus-app pushed"

echo ""
green "Done! Both repos are now at $TAG."
echo ""
echo "  Hotfix workflow (one repo only):"
echo "    cd bdus-api  # or bdus-app"
echo "    # edit composer.json / package.json version manually"
echo "    git add <file> && git commit -m 'chore: bump version to X.Y.Z'"
echo "    git tag vX.Y.Z && git push && git push origin vX.Y.Z"
