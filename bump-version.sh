#!/usr/bin/env bash
# bump-version.sh — cut a coordinated release of the BraDypUS monorepo.
#
# Usage:
#   ./bump-version.sh <X.Y.Z>
#
# What it does, in one commit and one tag:
#   1. sets the version in bdus-api/composer.json, bdus-app/package.json and
#      bdus-app/package-lock.json
#   2. promotes the CHANGELOG's "## [Unreleased]" section to
#      "## [X.Y.Z] - <today>"  (skipped if there is no [Unreleased] header)
#   3. commits as  "chore: release X.Y.Z"
#   4. tags  vX.Y.Z  and pushes the branch + the tag
#
# The tag triggers .github/workflows/release.yml, which builds and pushes
# ghcr.io/lad-sapienza/bdus-api and ghcr.io/lad-sapienza/bdus-app at X.Y.Z.
#
# Add your release notes under "## [Unreleased]" in CHANGELOG.md *before*
# running this.

set -euo pipefail

red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
die()   { red "ERROR: $*"; exit 1; }

VERSION="${1:-}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Usage: $0 <X.Y.Z>  (e.g. 5.4.4)"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

git diff --quiet && git diff --cached --quiet || die "working tree not clean — commit or stash first"

TAG="v$VERSION"
! git rev-parse "$TAG" >/dev/null 2>&1 || die "tag $TAG already exists"

CUR=$(python3 -c "import json; print(json.load(open('bdus-api/composer.json'))['version'])")
green "bdus-api / bdus-app: $CUR  ->  $VERSION"
read -rp "Proceed? [y/N] " a; [[ "${a,,}" == "y" ]] || { echo "Aborted."; exit 0; }

TODAY="$(date +%F)"

# ── 1. manifests ────────────────────────────────────────────────────────────
python3 - "$VERSION" <<'PY'
import json, sys
ver = sys.argv[1]
for path, indent in (("bdus-api/composer.json", 4),
                     ("bdus-app/package.json", 2),
                     ("bdus-app/package-lock.json", 2)):
    with open(path) as f:
        data = json.load(f)
    data["version"] = ver
    pkgs = data.get("packages")
    if isinstance(pkgs, dict) and "" in pkgs:
        pkgs[""]["version"] = ver
    with open(path, "w") as f:
        json.dump(data, f, indent=indent, ensure_ascii=False)
        f.write("\n")
PY

# ── 2. CHANGELOG ───────────────────────────────────────────────────────────
if grep -q '^## \[Unreleased\]$' CHANGELOG.md; then
  python3 - "$VERSION" "$TODAY" <<'PY'
import sys
ver, today = sys.argv[1], sys.argv[2]
txt = open("CHANGELOG.md").read()
needle = "## [Unreleased]\n"
i = txt.index(needle) + len(needle)
open("CHANGELOG.md", "w").write(txt[:i] + f"\n## [{ver}] - {today}\n" + txt[i:])
PY
  green "CHANGELOG: [Unreleased] -> [$VERSION] - $TODAY"
else
  red "note: no '## [Unreleased]' header in CHANGELOG.md — not promoting"
fi

# ── 3-4. commit, tag, push ─────────────────────────────────────────────────
git add bdus-api/composer.json bdus-app/package.json bdus-app/package-lock.json CHANGELOG.md
git commit -m "chore: release $VERSION"
git tag "$TAG"

green "Pushing $TAG ..."
git push && git push origin "$TAG"

green "Done. release.yml is now building the images for $TAG."
