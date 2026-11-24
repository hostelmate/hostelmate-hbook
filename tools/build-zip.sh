#!/bin/sh
# Builds dist/hostelmate-hbook-<version>.zip from a commit (default HEAD) with git archive, so the same
# commit always gives the same zip, and writes dist/SHA256SUMS. Dev files are left out by .gitattributes.
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; cd "$ROOT"
REF="${1:-HEAD}"
git diff --quiet "$REF" -- 2>/dev/null || { echo "Commit your changes first (the zip is built from $REF)."; exit 1; }
VERSION="$(git show "$REF:src/Config.php" | sed -n "s/.*const VERSION *= *'\([^']*\)'.*/\1/p")"
HEADER="$(git show "$REF:hostelmate-hbook.php" | sed -n 's/^ \* Version: *//p' | tr -d ' ')"
[ "$VERSION" = "$HEADER" ] || { echo "Version mismatch: Config $VERSION, plugin header $HEADER."; exit 1; }
mkdir -p dist
OUT="dist/hostelmate-hbook-$VERSION.zip"
git archive --format=zip --prefix=hostelmate-hbook/ -o "$OUT" "$REF"
( cd dist && shasum -a 256 "$(basename "$OUT")" > SHA256SUMS )
echo "built $OUT"; cat dist/SHA256SUMS
unzip -l "$OUT" | awk 'NR>3 {print $4}' | grep -E '^hostelmate-hbook/(tests|tools)/|phpcs|phpstan|phpunit' && { echo "DEV FILES IN ZIP"; exit 1; } || echo "no dev files in the zip"
