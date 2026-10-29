---
section: Changed
---
Skip Drupal.org GitLab eslint, stylelint, twigcsfixer, and Nightwatch jobs.
This module is PHP-only, and Nightwatch would otherwise autorun on every
opted-in core variant. phpcs, phpstan, cspell, composer-lint, and phpunit
stay on. Module behavior is unchanged.
