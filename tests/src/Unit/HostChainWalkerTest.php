<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\field_guard\HostChainWalker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the fail-closed host-chain walk.
 */
#[Group('field_guard')]
final class HostChainWalkerTest extends UnitTestCase {

  /**
   * Builds a mock entity with no parent accessor.
   *
   * @return \Drupal\Core\Entity\EntityInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The mock.
   */
  private function entity(string $type, string $id): EntityInterface {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($type);
    $entity->method('id')->willReturn($id);

    return $entity;
  }

  /**
   * Builds a mock composition entity exposing getParentEntity().
   *
   * @return \Drupal\Tests\field_guard\Unit\ParentAwareEntityInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The mock; wire its parent with ->method('getParentEntity').
   */
  private function parentAwareEntity(string $type, string $id): ParentAwareEntityInterface {
    $entity = $this->createMock(ParentAwareEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($type);
    $entity->method('id')->willReturn($id);

    return $entity;
  }

  /**
   * An entity without getParentEntity() is its own one-link chain.
   */
  public function testParentlessEntityIsItsOwnChain(): void {
    $user = $this->entity('user', '4');

    $this->assertSame([$user], (new HostChainWalker())->chain($user));
  }

  /**
   * A nested composition walks to its root.
   */
  public function testNestedChainWalksToRoot(): void {
    $user = $this->entity('user', '4');
    $inner = $this->parentAwareEntity('paragraph', '20');
    $inner->method('getParentEntity')->willReturn($user);
    $outer = $this->parentAwareEntity('paragraph', '10');
    $outer->method('getParentEntity')->willReturn($inner);

    $this->assertSame([$outer, $inner, $user], (new HostChainWalker())->chain($outer));
  }

  /**
   * An orphan ends the walk with itself as root — a chain, not a NULL.
   *
   * Callers still get cacheability coverage for the entity, and an orphan
   * root that is not a user exempts nothing.
   */
  public function testOrphanIsItsOwnRoot(): void {
    $orphan = $this->parentAwareEntity('paragraph', '10');
    $orphan->method('getParentEntity')->willReturn(NULL);

    $this->assertSame([$orphan], (new HostChainWalker())->chain($orphan));
  }

  /**
   * A parent accessor returning a non-entity fails the walk closed.
   */
  public function testNonEntityParentFailsClosed(): void {
    $broken = $this->parentAwareEntity('paragraph', '10');
    $broken->method('getParentEntity')->willReturn('not an entity');

    $this->assertNull((new HostChainWalker())->chain($broken));
  }

  /**
   * A cycle fails the walk closed.
   */
  public function testCycleFailsClosed(): void {
    $a = $this->parentAwareEntity('paragraph', '1');
    $b = $this->parentAwareEntity('paragraph', '2');
    $a->method('getParentEntity')->willReturn($b);
    $b->method('getParentEntity')->willReturn($a);

    $this->assertNull((new HostChainWalker())->chain($a));
  }

  /**
   * A chain still open at the depth cap fails closed: the root is unproven.
   */
  public function testDepthOverflowFailsClosed(): void {
    $entities = [];
    for ($i = 12; $i >= 1; $i--) {
      $entity = $this->parentAwareEntity('paragraph', (string) $i);
      if (isset($entities[$i + 1])) {
        $entity->method('getParentEntity')->willReturn($entities[$i + 1]);
      }
      $entities[$i] = $entity;
    }

    $this->assertNull((new HostChainWalker())->chain($entities[1]));
  }

}
