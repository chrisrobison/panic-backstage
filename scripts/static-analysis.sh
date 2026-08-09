#!/usr/bin/env bash
# Fast, hermetic quality gate used locally and in CI.

set -euo pipefail

cd "$(dirname "$0")/.."

find src public scripts database tests -type f -name '*.php' -print0 \
  | xargs -0 -r -n1 -P4 php -l >/dev/null

# public/sw.js is listed explicitly: the push service worker has to sit at the
# application root to own the right scope, so it falls outside public/assets.
find public/assets public/sw.js tests/ui -type f \( -name '*.js' -o -name '*.mjs' \) -print0 \
  | xargs -0 -r -n1 -P4 node --check

find scripts tests -type f -name '*.sh' -print0 \
  | xargs -0 -r -n1 -P4 bash -n

php scripts/check-openapi-routes.php

if [ ! -x vendor/bin/phpstan ]; then
  echo "vendor/bin/phpstan is missing; run composer install" >&2
  exit 1
fi
vendor/bin/phpstan analyse --memory-limit=1G --no-progress

for deny_file in src/.htaccess scripts/.htaccess database/.htaccess tests/.htaccess; do
  grep -Eq 'Require all denied' "$deny_file" \
    || { echo "$deny_file does not deny web access" >&2; exit 1; }
done

grep -Eq 'vendor' .htaccess \
  && grep -Eq 'composer' .htaccess \
  && grep -Eq 'phpstan' .htaccess \
  || { echo "Root .htaccess does not block development-only files" >&2; exit 1; }

grep -Eq 'php[0-9]*|phtml|phar|cgi|pl|py|sh' public/uploads/.htaccess \
  || { echo "public/uploads/.htaccess does not block executable uploads" >&2; exit 1; }

if find public/uploads -type f \
  \( -iname '*.php' -o -iname '*.php[0-9]' -o -iname '*.phtml' -o -iname '*.phar' -o -iname '*.cgi' \) \
  | grep -q .; then
  echo "Executable source file found under public/uploads" >&2
  exit 1
fi

git diff --check
echo "Static analysis and contract checks passed."
