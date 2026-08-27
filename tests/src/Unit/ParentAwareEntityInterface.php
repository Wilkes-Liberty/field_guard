<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard\Unit;

use Drupal\Core\Entity\EntityInterface;

/**
 * Mockable stand-in for composition entities exposing getParentEntity().
 *
 * The walker duck-types on the method, so production code never references
 * this interface — it exists only so the unit suite can mock parent-aware
 * entities without depending on a composition module. The accessor is
 * deliberately untyped, matching e.g. Paragraphs, so a mock can return a
 * non-entity to exercise the fail-closed branch.
 */
interface ParentAwareEntityInterface extends EntityInterface {

  /**
   * Returns the parent entity, or NULL for an orphan.
   *
   * @return mixed
   *   The parent. Untyped so the fail-closed non-entity branch is mockable.
   */
  public function getParentEntity();

}
