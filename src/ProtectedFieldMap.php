<?php

declare(strict_types=1);

namespace Drupal\field_guard;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves which permission, if any, guards a given field operation.
 *
 * The map is configuration rather than a hardcoded array for two reasons. It is
 * diffable and reviewable in `config/sync`, and on a deploy that runs
 * `drush config:import` it is reverted to the repository on every release —
 * so a live edit that widens access does not survive. That property is the
 * reason to prefer config here even though code would be marginally faster.
 */
final class ProtectedFieldMap implements CacheableDependencyInterface {

  /**
   * The configuration object holding the map.
   */
  private const SETTINGS = 'field_guard.settings';

  /**
   * Field operations this module understands.
   *
   * Drupal's field access API only ever passes 'view' or 'edit'. Anything else
   * is a caller error and is treated as unprotected rather than silently
   * denied, so a future core operation cannot lock a site out of its own data.
   */
  private const OPERATIONS = ['view', 'edit'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the permission guarding a field operation, or NULL if unprotected.
   *
   * @param string $entityTypeId
   *   The entity type the field is attached to.
   * @param string|null $bundle
   *   The bundle, or NULL for a base field with no bundle context.
   * @param string $fieldName
   *   The field machine name.
   * @param string $operation
   *   The field operation: 'view' or 'edit'.
   *
   * @return string|null
   *   The required permission, or NULL when this field is not protected.
   */
  public function requiredPermission(
    string $entityTypeId,
    ?string $bundle,
    string $fieldName,
    string $operation,
  ): ?string {
    if ($bundle === NULL || !in_array($operation, self::OPERATIONS, TRUE)) {
      return NULL;
    }

    $protected = $this->settings()->get('protected') ?? [];

    $permission = $protected[$entityTypeId][$bundle][$fieldName][$operation] ?? NULL;

    // An empty string is a misconfiguration, not a grant. Treat it as unset
    // rather than as "a permission nobody holds", which would be an accidental
    // total denial that is very hard to diagnose from the config alone.
    return is_string($permission) && $permission !== '' ? $permission : NULL;
  }

  /**
   * Returns TRUE when a field's view guard exempts the record's own subject.
   *
   * Opt-in, per field, view only. TRUE means: when the entity the field sits
   * on ultimately hangs from the acting user's own account, the view guard
   * stands aside (returns neutral) instead of forbidding. Edit is never
   * exempted — a record its subject can rewrite is not evidence — and the
   * definition-level (filter/sort) deny is likewise untouched.
   *
   * Only a boolean TRUE enables the exemption. Any other value — including a
   * truthy string from a hand-edited YAML — is treated as absent, so a
   * malformed entry fails closed.
   */
  public function viewExemptsOwnSubject(string $entityTypeId, ?string $bundle, string $fieldName): bool {
    if ($bundle === NULL) {
      return FALSE;
    }

    $protected = $this->settings()->get('protected') ?? [];

    return ($protected[$entityTypeId][$bundle][$fieldName]['view_exempt_own_subject'] ?? NULL) === TRUE;
  }

  /**
   * Returns TRUE when the field is protected for at least one operation.
   *
   * Used by callers that need to know whether a field is in scope at all —
   * for example an audit subscriber deciding whether a read is worth recording.
   */
  public function isProtected(string $entityTypeId, ?string $bundle, string $fieldName): bool {
    foreach (self::OPERATIONS as $operation) {
      if ($this->requiredPermission($entityTypeId, $bundle, $fieldName, $operation) !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Loads the settings config object.
   */
  private function settings() {
    return $this->configFactory->get(self::SETTINGS);
  }

  /**
   * {@inheritdoc}
   *
   * The map itself varies by nothing — it is the same for every account.
   * Callers that make an ACCOUNT-dependent decision from it must add their own
   * user
   * contexts; callers whose verdict is unconditional must not, or they fragment
   * the cache per permission set for an answer that is identical for everyone.
   */
  public function getCacheContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Delegates to the config object, which carries `config:field_guard.settings`
   * (ConfigBase implements RefinableCacheableDependencyInterface). This is the
   * invalidation that was missing: without it, a cached access verdict
   * outlives the config change that should have altered it — and because this
   * module only ever denies, the stale direction is a field staying readable
   * after it was protected.
   */
  public function getCacheTags(): array {
    return $this->settings()->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

}
