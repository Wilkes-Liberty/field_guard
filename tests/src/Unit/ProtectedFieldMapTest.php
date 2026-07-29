<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\field_guard\ProtectedFieldMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the lookup rules, including the ones that are easy to get wrong.
 */
#[Group('field_guard')]
#[CoversClass(ProtectedFieldMap::class)]
final class ProtectedFieldMapTest extends UnitTestCase {

  /**
   * Builds a map service over a fixed 'protected' structure.
   */
  private function mapWith(array $protected): ProtectedFieldMap {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('protected')->willReturn($protected);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('field_guard.settings')->willReturn($config);

    return new ProtectedFieldMap($factory);
  }

  /**
   * The happy path: an exact match returns the configured permission.
   */
  public function testExactMatchReturnsPermission(): void {
    $map = $this->mapWith([
      'profile' => ['compliance_record' => ['field_evidence_date' => ['view' => 'view guarded field']]],
    ]);

    $this->assertSame(
      'view guarded field',
      $map->requiredPermission('profile', 'compliance_record', 'field_evidence_date', 'view'),
    );
  }

  /**
   * A miss on any axis is unprotected — protection is never inherited.
   */
  #[DataProvider('missProvider')]
  public function testMissesAreUnprotected(string $entityType, ?string $bundle, string $field, string $operation): void {
    $map = $this->mapWith([
      'profile' => ['compliance_record' => ['field_evidence_date' => ['view' => 'view guarded field']]],
    ]);

    $this->assertNull($map->requiredPermission($entityType, $bundle, $field, $operation));
  }

  /**
   * Cases that must not match.
   */
  public static function missProvider(): array {
    return [
      'other entity type' => ['node', 'compliance_record', 'field_evidence_date', 'view'],
      'other bundle' => ['profile', 'engagement', 'field_evidence_date', 'view'],
      'other field' => ['profile', 'compliance_record', 'field_other', 'view'],
      'unconfigured operation' => ['profile', 'compliance_record', 'field_evidence_date', 'edit'],
      'null bundle (base field)' => ['profile', NULL, 'field_evidence_date', 'view'],
      'unknown operation' => ['profile', 'compliance_record', 'field_evidence_date', 'delete'],
    ];
  }

  /**
   * An empty permission string is a misconfiguration, not a total lockout.
   *
   * Treating '' as "a permission nobody holds" would deny everyone with no way to
   * tell from the config that anything was wrong.
   */
  public function testEmptyPermissionIsTreatedAsUnset(): void {
    $map = $this->mapWith([
      'profile' => ['compliance_record' => ['field_evidence_date' => ['view' => '']]],
    ]);

    $this->assertNull($map->requiredPermission('profile', 'compliance_record', 'field_evidence_date', 'view'));
  }

  /**
   * An empty map protects nothing — the shipped default must be inert.
   */
  public function testEmptyMapProtectsNothing(): void {
    $map = $this->mapWith([]);

    $this->assertNull($map->requiredPermission('profile', 'compliance_record', 'field_evidence_date', 'view'));
    $this->assertFalse($map->isProtected('profile', 'compliance_record', 'field_evidence_date'));
  }

  /**
   * The isProtected() helper is true when any single operation is configured.
   */
  public function testIsProtectedMatchesAnyOperation(): void {
    $map = $this->mapWith([
      'profile' => ['compliance_record' => ['field_evidence_date' => ['edit' => 'edit guarded field']]],
    ]);

    $this->assertTrue($map->isProtected('profile', 'compliance_record', 'field_evidence_date'));
    $this->assertNull($map->requiredPermission('profile', 'compliance_record', 'field_evidence_date', 'view'));
  }

}
