#!/bin/sh
# Dev-only tools for checking the plugin. Never shipped in the plugin zip.
# Every download is pinned: phars by SHA-256 (from each project's own release page),
# git sources by exact commit. A mismatch stops the script.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN="$ROOT/tools/bin"
VENDOR="$ROOT/tools/vendor"
mkdir -p "$BIN" "$VENDOR"

phar() { # name url sha256
	out="$BIN/$1"
	if [ ! -f "$out" ] || ! echo "$3  $out" | shasum -a 256 -c --status; then
		curl -sSfL "$2" -o "$out.tmp"
		echo "$3  $out.tmp" | shasum -a 256 -c --status || { echo "CHECKSUM MISMATCH: $1"; rm -f "$out.tmp"; exit 1; }
		mv "$out.tmp" "$out"
	fi
	echo "ok  $1"
}

src() { # dir repo tag commit
	dir="$VENDOR/$1"
	if [ ! -d "$dir/.git" ]; then
		git -c advice.detachedHead=false clone -q --depth 1 --branch "$3" "https://github.com/$2.git" "$dir"
	fi
	[ "$(git -C "$dir" rev-parse HEAD)" = "$4" ] || { echo "COMMIT MISMATCH: $1"; exit 1; }
	echo "ok  $1 $3"
}

# PHPUnit 11 supports PHP 8.2. SHA-256 from https://phar.phpunit.de/all-releases.html
phar phpunit.phar https://phar.phpunit.de/phpunit-11.5.57.phar c704fdd37c6c6ab7adb386f31b4e186cd51156801fa9028b5ea8099a2cd7f106
# PHPCS 3.x (WPCS 3.4.1 needs ^3.13.5). SHA-256 from the GitHub release asset digest.
phar phpcs.phar https://github.com/PHPCSStandards/PHP_CodeSniffer/releases/download/3.13.6/phpcs.phar aed4148cee26285a73e694a98d4675b84f81ef86b2581f811d3c402c0d3d7d23
phar phpcbf.phar https://github.com/PHPCSStandards/PHP_CodeSniffer/releases/download/3.13.6/phpcbf.phar 1ae3e93556cabb88b56f59b1facf3a607b8a2bef5ecc9002be8774deb021a791
# PHPStan 2.3.5. SHA-256 from the GitHub release asset digest.
phar phpstan.phar https://github.com/phpstan/phpstan/releases/download/2.3.5/phpstan.phar 4b3ed7850a20b092331bd1040e4f54a1d3c0559b4cf006431073997ebcb4324b

src wpcs        WordPress/WordPress-Coding-Standards 3.4.1  ec2ff942335f33683a5957a85d138753876a05cf
src phpcsutils  PHPCSStandards/PHPCSUtils            1.2.3  5f35d9408c54d7b529501f3c688b6eae562aea1f
src phpcsextra  PHPCSStandards/PHPCSExtra            1.5.1  39467533fdb742446d68c1d10ac33d625ee0311c
src wp-stubs    php-stubs/wordpress-stubs            v7.1.2 6926c5156431a3ff909d43faa16a92a0bc0c2428
# Tests only: the same libsodium polyfill WordPress ships, for PHP builds without ext-sodium.
src sodium      paragonie/sodium_compat              v1.24.2 4524c98a826776c9a32d6ce06932563cb10f3995

echo "dev tools ready in tools/bin and tools/vendor"
