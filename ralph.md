# phpMyAdmin

PHP web application (MySQL/MariaDB administration) with Composer-managed PHP dependencies (Twig templates, PHPUnit tests, PHPStan/Psalm/PHPCS analysis) and a Yarn-managed frontend (jQuery, Bootstrap, Sass themes, Babel-compiled JS, Jest tests, ESLint/Stylelint).

## Setup

Install dependencies:

    composer install && yarn install --non-interactive

## Verification

Type-check/lint with PHPCS, PHPStan, Psalm, ESLint and Stylelint. Build frontend assets (CSS and JS) via the yarn `build` script. Run PHP unit tests with PHPUnit and JS tests with Jest.

<!-- RALPH_CHECK_COMMANDS
composer phpcs
composer phpstan -- --memory-limit 2G
composer psalm
yarn run js-lint --quiet
yarn run css-lint
RALPH_CHECK_COMMANDS_END -->

<!-- RALPH_BUILD_COMMANDS
yarn run build
RALPH_BUILD_COMMANDS_END -->

<!-- RALPH_REGRESSION_COMMANDS
composer run phpunit -- --testsuite unit
yarn test
RALPH_REGRESSION_COMMANDS_END -->
