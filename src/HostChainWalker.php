<?php

declare(strict_types=1);

namespace Drupal\field_guard;

use Drupal\Core\Entity\EntityInterface;

/**
 * Resolves the host-entity chain a guarded field's entity hangs from.
 */
final class HostChainWalker {

  /**
   * Upper bound on chain length.
   */
  private const MAX_DEPTH = 8;

  /**
   * Returns the chain from the given entity to its root host, or NULL.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the guarded field sits on.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]|null
   *   The chain starting with $entity and ending with the root host, or NULL
   *   when the root could not be established (cycle, depth cap, or a parent
   *   accessor returning a non-entity).
   */
  public function chain(EntityInterface $entity): ?array {
    $chain = [$entity];
    $seen = [$entity->getEntityTypeId() . ':' . $entity->id() => TRUE];
    $current = $entity;

    for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
      if (!method_exists($current, 'getParentEntity')) {
        return $chain;
      }

      $parent = $current->getParentEntity();
      if ($parent === NULL) {
        // A parent accessor exists but reports no parent. For a composition
        // entity that is an orphan, and an orphan has no subject: the caller
        // sees a chain whose root is not a user and exempts nothing. Return
        // the chain rather than NULL so cacheability metadata still covers
        // every entity consulted.
        return $chain;
      }
      if (!$parent instanceof EntityInterface) {
        return NULL;
      }

      $key = $parent->getEntityTypeId() . ':' . $parent->id();
      if (isset($seen[$key])) {
        return NULL;
      }
      $seen[$key] = TRUE;

      $chain[] = $parent;
      $current = $parent;
    }

    return NULL;
  }

}
