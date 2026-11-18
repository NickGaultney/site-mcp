#!/usr/bin/env bash
# Publishes the version in site-mcp.php as a GitHub release with site-mcp.zip attached.
# Installed sites pick it up through the plugin's update checker.
# Usage: ./release.sh   (after bumping the version and committing)
set -euo pipefail
cd "$(dirname "$0")"

version=$(sed -n 's/^ \* Version: *//p' site-mcp.php)
tag="v$version"
[ -z "$(git status --porcelain)" ] || { echo "Commit your changes first."; exit 1; }
grep -q "define('SITE_MCP_VERSION', '$version')" site-mcp.php || { echo "SITE_MCP_VERSION doesn't match Version: $version"; exit 1; }
grep -q "^Stable tag: $version$" readme.txt || { echo "readme.txt Stable tag doesn't match $version"; exit 1; }
if gh release view "$tag" >/dev/null 2>&1; then echo "$tag is already released."; exit 1; fi

find . -name '*.php' -not -path './vendor/*' -not -path './.git/*' -print0 | xargs -0 -n1 php -l > /dev/null

build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT
mkdir "$build/site-mcp"
git archive HEAD | tar -x -C "$build/site-mcp"
python3 -c 'import shutil, sys; shutil.make_archive(sys.argv[1], "zip", sys.argv[2], "site-mcp")' "$build/site-mcp-zip" "$build"
mv "$build/site-mcp-zip.zip" "$build/site-mcp.zip"

git rev-parse -q --verify "refs/tags/$tag" >/dev/null || git tag "$tag"
git push -q origin HEAD "$tag"
gh release create "$tag" "$build/site-mcp.zip" --title "$tag" --notes "$(awk -v v="$version" '$0=="= "v" =" {f=1; next} /^= / {f=0} f' readme.txt)"
