# Contributing to Field Guard

Issues and merge requests are welcome in the drupal.org queue:
<https://www.drupal.org/project/issues/field_guard>

## Before you open a merge request

- **Every behaviour change needs a test.** This is an access-control module; a change
  without a test that fails before it is not reviewable. Kernel tests live in
  `tests/src/Kernel`, unit tests in `tests/src/Unit`.
- **Run the suite and the sniffs.**
  ```bash
  vendor/bin/phpunit -c web/core modules/contrib/field_guard/tests
  vendor/bin/phpcs --standard=phpcs.xml.dist modules/contrib/field_guard
  ```
- **Do not weaken the definition-level deny without discussion.** Returning anything
  other than forbidden when `$items` is empty reopens the JSON:API filter and Views
  handler paths this module exists to close. `NullItemsFailsClosedTest` guards it on
  purpose. If you have a case where the current behaviour is too blunt, open an issue
  first — there is a narrower option for JSON:API specifically
  (`hook_jsonapi_entity_field_filter_access`) that may fit better.
- **Do not resolve access through `AccountInterface::hasPermission()`.** It returns
  TRUE for every permission on an `is_admin` role and for user 1, which silently
  exempts the accounts most worth constraining. See
  `_field_guard_has_explicit_permission()` and the tests around it.

## Supported Drupal versions

The support floor tracks the **oldest Drupal branch still supported upstream**, not the
newest — enterprise and government users move slowly, and support breadth is an adoption
lever. The declared range is verified in CI against each supported major; a claim that CI
does not exercise is not a claim.

## Code style

Drupal coding standards, enforced by `phpcs.xml.dist`. `declare(strict_types=1)` in new
PHP files. Comments explain *why*, not *what* — particularly around the access logic,
where the non-obvious reasoning is the thing worth preserving.
