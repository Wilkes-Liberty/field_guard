# Changelog

All notable changes to Field Guard are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-07-28

First stable release.

### Added

- **Initial release.** Config-driven per-field access control that denies with
  `AccessResult::forbidden()` — a verdict no permission, `is_admin` role or user 1
  can override, because `EntityAccessControlHandler::fieldAccess()` performs no
  admin check and folds hook results with `orIf()`, where forbidden is contagious.
- **Fails closed on definition-level checks.** JSON:API's
  `FieldResolver::getFieldAccess()` and Views' `EntityField::access()` both ask
  whether a field may be filtered or sorted on, passing no entity. Returning
  neutral there leaves the Views handler in place and permits the JSON:API filter,
  so a guarded value can be probed by an exposed filter even though it is never
  rendered. Field Guard denies instead. Guarded fields are consequently
  unfilterable and unsortable for everyone, including permission holders; that is
  the intended trade and is pinned by a test.
- **Explicit-grant checking.** Access is not resolved through
  `AccountInterface::hasPermission()`, which returns TRUE for every permission on an
  `is_admin` role or user 1 and would silently exempt exactly the accounts most
  worth constraining. Field Guard walks the account's roles, skips `is_admin` roles,
  and asks each remaining role directly — so a grant is a reviewable line in a
  configuration diff rather than an inheritance.
- **No permissions of its own.** The module names no permissions; it checks
  whichever permission the site nominates in its map. Installing it changes nothing
  until it is configured.
