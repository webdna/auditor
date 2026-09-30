#!/bin/sh
# The leg that runs with Craft Commerce installed (TS-10, BR-23).
#
# The plugin must never depend on Commerce, so the main install (vendor/) never carries it — which
# is also what proves TN-14 there. This leg derives composer.commerce.json from composer.json plus
# craftcms/commerce, installs it into vendor-commerce/, then runs PHPStan over src/ against
# Commerce's real classes and the `commerce` suite with Commerce installed in the test site. All
# three generated paths are gitignored.
#
#   docker compose run --rm php composer test:commerce
#   PHP_VERSION=8.4 docker compose run --rm php composer test:commerce
set -eu
cd "$(dirname "$0")/../.."

php -r '
    $composer = json_decode(file_get_contents("composer.json"), true);
    $composer["require-dev"]["craftcms/commerce"] = "^5.0";
    file_put_contents("composer.commerce.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'

export COMPOSER=composer.commerce.json
export COMPOSER_VENDOR_DIR=vendor-commerce
composer update --no-interaction --no-progress --quiet

vendor-commerce/bin/phpstan analyse --memory-limit=1G --no-progress --configuration=tests/commerce/phpstan.neon
# --no-redirect: codecept otherwise switches itself to vendor/, which has no Commerce.
AUDITOR_VENDOR=vendor-commerce vendor-commerce/bin/codecept run commerce --no-redirect "$@"
