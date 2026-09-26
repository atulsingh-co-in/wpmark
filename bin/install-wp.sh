#!/usr/bin/env bash
#
# Download WordPress core for the test suite.
#
#   bin/install-wp.sh [version] [directory]
#
#   version    "latest" (default), a minor version such as "6.9" (picks its
#              newest patch release), or an exact version such as "6.9.1".
#   directory  Where to put it. Default: .wp/wordpress in the repo.
#
# Prints the exact version it installed on the last line, so CI can install
# the matching wp-phpunit test framework.
#
# Uses GitHub's official WordPress mirror, which needs only git.

set -euo pipefail

version="${1:-latest}"
repo_root="$(cd "$(dirname "$0")/.." && pwd)"
dest="${2:-$repo_root/.wp/wordpress}"
mirror="https://github.com/WordPress/WordPress.git"

# Every release tag, e.g. 6.9, 6.9.1, 7.0 (skips betas and release candidates).
tags="$(git ls-remote --tags --refs "$mirror" | sed 's#.*refs/tags/##' | grep -E '^[0-9]+\.[0-9]+(\.[0-9]+)?$' | sort -V)"

case "$version" in
	latest)
		resolved="$(tail -n 1 <<< "$tags")"
		;;
	*.*.*)
		resolved="$(grep -Fx "$version" <<< "$tags" || true)"
		;;
	*)
		resolved="$(grep -E "^${version//./\\.}(\.[0-9]+)?$" <<< "$tags" | tail -n 1 || true)"
		;;
esac

if [ -z "$resolved" ]; then
	echo "No WordPress release found for \"$version\"." >&2
	exit 1
fi

if [ -f "$dest/wp-includes/version.php" ] && grep -q "wp_version = '$resolved'" "$dest/wp-includes/version.php"; then
	echo "WordPress $resolved is already in $dest." >&2
else
	echo "Downloading WordPress $resolved into $dest ..." >&2
	rm -rf "$dest"
	mkdir -p "$(dirname "$dest")"
	git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$resolved" "$mirror" "$dest"
	rm -rf "$dest/.git"
fi

echo "$resolved"
