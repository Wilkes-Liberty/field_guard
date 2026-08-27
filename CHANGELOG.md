# Changelog

All notable changes to Field Guard are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2026-08-27

### Added

- **Opt-in own-subject view exemption (`view_exempt_own_subject`).** A guarded
  field may declare that its **view** guard stands aside when the entity
  carrying the field ultimately roots at the acting user's own account — for
  records about a user stored on that user's account, directly or on a nested
  composition entity. The host chain is duck-typed on `getParentEntity()`
  (new `field_guard.host_chain_walker` service), depth-capped, cycle-guarded,
  and fail-closed: an unresolvable chain, an orphan root, or anonymous exempts
  nothing. The module still never grants — the exempt verdict is neutral, and
  it carries the `user` cache context plus every chain entity as a cacheable
  dependency, while the non-subject verdict on a flagged field keeps the
  role/permission cache contexts alongside `user`. Edit guards, the
  definition-level (filter/sort) deny, and the deny against every
  non-subject — administrators and uid 1 included — are untouched; only
  boolean `true` enables the flag.

### Changed

- **CI: the attribution check is now the shared workflow.**
  `.github/workflows/attribution.yml` becomes a thin caller pinned to
  `Wilkes-Liberty/shared-ci@v1`, and the vendored `.github/scripts/` copies are
  removed. One implementation for every repository makes copy drift structurally
  impossible instead of merely detectable.

## [1.1.0] - 2026-08-02

### Fixed
- **Access verdicts now invalidate when the map changes (#7).** `ProtectedFieldMap`
  implements `CacheableDependencyInterface`, delegating to the settings config
  object, and both verdict paths add it as a cacheable dependency — so every
  verdict carries `config:field_guard.settings`. Without it a cached verdict
  outlived the config change that produced it, and because this module only ever
  denies, the stale direction was a field staying readable after it was
  protected. This metadata is consumed on exactly the path the module exists to
  close: JSON:API's `FieldResolver::getFieldAccess()` folds it into the cacheable
  response; the render path discards it.
- **The definition-level (NULL `$items`) verdict no longer carries
  `user.permissions` (#7).** That verdict never consults the account — it is
  identical for everyone — and the context only fragmented the cache per
  permission set. The value-level verdict keeps its user contexts; both now also
  carry the config dependency.
- **A typo in the operation key is now a schema violation (#7).** The operation
  level of `field_guard.settings` is a closed `view`/`edit` mapping instead of an
  open sequence. Previously `viewed:` or `Edit:` validated silently and left the
  field unprotected while the config claimed otherwise. A `ConfigSchemaTest`
  pins typo-rejection; a 216-line `CacheabilityTest` pins the metadata above.

### Changed
- **One `loadMultiple()` instead of a `load()` per role** in the explicit-
  permission check. Field access runs once per field per entity, so listings
  reach it N×M times; the per-role loop made each pass a storage call per role.
- **README now warns against guarding workflow fields on `edit`.** Field
  edit-access is checked against the *stored* value during a JSON:API/REST
  PATCH (`EntityResource::checkPatchFieldAccess()`), so mapping
  `moderation_state` or `status` on `edit` forbids legitimate transitions
  computed against the wrong value.
- The forbidden reason on definition-level checks and an interior docblock no
  longer say "personnel" — leftovers from the codebase this module was
  extracted from.

## [1.0.1] - 2026-07-30

### Changed
- **`composer.json` now declares `"php": ">=8.1"`.** It previously specified no PHP
  constraint at all, so the effective floor came only from whatever core happened to
  require — the supported surface was implied rather than stated, and a reader had
  to trace Drupal's own requirements to find it.

  8.1 is the real floor, checked rather than assumed: PHPCompatibility reports the
  codebase clean from 8.1 upward, Drupal 10.6 requires `>=8.1.0`, and this module
  takes no runtime dependency beyond core. It is also already verified — the
  drupal.org previous-major lane runs this suite on PHP 8.1.34 and passes.

  This does not change which sites can install today: `^10.6 || ^11.3 || ^12`
  already implies the same floor. What it changes is that the claim is stated where
  Composer and a human both read it, and it stops moving silently if core's floor
  moves or this module adopts newer syntax.

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
