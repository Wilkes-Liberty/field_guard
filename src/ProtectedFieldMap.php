<?php

declare(strict_types=1);

namespace Drupal\field_guard;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves which permission, if any, guards a given field operation.
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
   * Lists the guarded fields of an entity type, names only.
   *
   * Applies the same rules as requiredPermission(): an operation counts only
   * when its permission is a non-empty string, and a field with no guarded
   * operation is left out even when it carries the own-subject flag.
   *
   * @param string $entityTypeId
   *   The entity type.
   * @param string|null $bundle
   *   Restrict the list to one bundle, or NULL for every bundle in the map.
   *
   * @return array<string, array<string, array{view: string|null, edit: string|null, view_exempt_own_subject: bool}>>
   *   Per bundle and field: the permission each operation requires (NULL when
   *   that operation is not guarded) and whether the view guard exempts the
   *   record's own subject.
   */
  public function guardedFields(string $entityTypeId, ?string $bundle = NULL): array {
    $protected = $this->settings()->get('protected') ?? [];
    $bundles = $protected[$entityTypeId] ?? [];
    if (!is_array($bundles)) {
      return [];
    }
    if ($bundle !== NULL) {
      $bundles = array_intersect_key($bundles, [$bundle => TRUE]);
    }

    $guarded = [];
    foreach ($bundles as $bundleId => $fields) {
      foreach (is_array($fields) ? array_keys($fields) : [] as $fieldName) {
        $bundleId = (string) $bundleId;
        $fieldName = (string) $fieldName;
        if (!$this->isProtected($entityTypeId, $bundleId, $fieldName)) {
          continue;
        }
        $guarded[$bundleId][$fieldName] = [
          'view' => $this->requiredPermission($entityTypeId, $bundleId, $fieldName, 'view'),
          'edit' => $this->requiredPermission($entityTypeId, $bundleId, $fieldName, 'edit'),
          'view_exempt_own_subject' => $this->viewExemptsOwnSubject($entityTypeId, $bundleId, $fieldName),
        ];
      }
    }
    return $guarded;
  }

  /**
   * Loads the settings config object.
   */
  private function settings() {
    return $this->configFactory->get(self::SETTINGS);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
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
