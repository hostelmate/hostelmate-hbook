#!/bin/sh
# One command for every check: coding/security standard, static analysis, unit tests.
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; cd "$ROOT"
PHP="${PHP:-php}"
"$PHP" tools/bin/phpcs.phar --standard=phpcs.xml.dist
"$PHP" -d memory_limit=1G tools/bin/phpstan.phar analyse -c phpstan.neon.dist --no-progress
"$PHP" tools/bin/phpunit.phar -c phpunit.xml.dist
