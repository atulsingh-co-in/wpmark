#!/usr/bin/env bash
#
# Build the installable plugin zip from the current commit.
#
#   bin/build-zip.sh
#
# Writes dist/wpmark-<version>.zip containing a single "wpmark" folder, ready
# for Plugins → Add New → Upload Plugin. It includes the bundled MCP Adapter
# (installed with Composer, without development tools), so users install one
# plugin only. Development files (tests, tooling) are left out by
# .gitattributes. Uncommitted changes are not included, so commit first.
#
# Needs git, Composer and zip.

set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root"

version="$(sed -n "s/^define( 'WPMARK_VERSION', '\(.*\)' );$/\1/p" wpmark.php)"
out="$repo_root/dist/wpmark-${version}.zip"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# 1. The plugin's own files, exactly as committed.
mkdir -p "$work/wpmark"
git archive HEAD | tar -x -C "$work/wpmark"

# 2. The bundled MCP Adapter and the Jetpack Autoloader that shares it safely
#    with other plugins. composer.json and composer.lock are only needed here.
git show HEAD:composer.json > "$work/wpmark/composer.json"
git show HEAD:composer.lock > "$work/wpmark/composer.lock"
#    The Jetpack Autoloader is a Composer plugin, and Composer silently skips
#    plugins when it runs as root without this setting, which would leave out
#    vendor/autoload_packages.php and give a zip that cannot start.
COMPOSER_ALLOW_SUPERUSER=1 composer install --working-dir="$work/wpmark" --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --quiet
rm "$work/wpmark/composer.json" "$work/wpmark/composer.lock"

if [ ! -f "$work/wpmark/vendor/autoload_packages.php" ] || [ ! -d "$work/wpmark/vendor/wordpress/mcp-adapter" ]; then
	echo "Build failed: the bundled MCP Adapter or its autoloader is missing from vendor/." >&2
	exit 1
fi

# 3. Drop what the bundled packages ship for their own development.
find "$work/wpmark/vendor" -mindepth 3 -maxdepth 3 \
	\( -name tests -o -name docs -o -name generator -o -name skill -o -name .github \
	-o -name 'package.json' -o -name 'package-lock.json' -o -name 'composer.lock' \
	-o -name 'phpunit.xml*' -o -name 'phpstan*' -o -name 'CLAUDE.md' -o -name 'CONTRIBUTING.md' \
	-o -name 'SECURITY.md' -o -name 'CHANGELOG.md' \) -exec rm -rf {} +
find "$work/wpmark/vendor" -name .git -prune -exec rm -rf {} +

# 4. Zip it.
mkdir -p "$repo_root/dist"
rm -f "$out"
(cd "$work" && zip -qr "$out" wpmark)

echo "dist/wpmark-${version}.zip"
