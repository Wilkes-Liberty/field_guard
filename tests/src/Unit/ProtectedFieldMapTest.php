<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\field_guard\ProtectedFieldMap;
use PHPUnit\Framework\Attributes\CoversClass;
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
   *
   * Deliberately a loop rather than a data provider. This module supports
   * Drupal 10.6, which ships PHPUnit 9; that version honours only the docblock
   * annotation form and ignores the attribute, so an attribute-driven provider
   * passes zero arguments and the test dies with ArgumentCountError. Carrying
   * both forms works but invites a double-provide once the annotation form is
   * removed. A loop behaves identically on every PHPUnit version, and the
   * per-case message keeps a failure just as readable.
   *
   * Note the annotation name is spelled out nowhere in this docblock on
   * purpose: PHPUnit scans comments for it and would treat a mention as a real
   * declaration, failing with "Method ::() does not exist".
   */
  public function testMissesAreUnprotected(): void {
    $map = $this->mapWith([
      'profile' => ['compliance_record' => ['field_evidence_date' => ['view' => 'view guarded field']]],
    ]);

    $misses = [
      'other entity type' => ['node', 'compliance_record', 'field_evidence_date', 'view'],
      'other bundle' => ['profile', 'engagement', 'field_evidence_date', 'view'],
      'other field' => ['profile', 'compliance_record', 'field_other', 'view'],
      'unconfigured operation' => ['profile', 'compliance_record', 'field_evidence_date', 'edit'],
      'null bundle (base field)' => ['profile', NULL, 'field_evidence_date', 'view'],
      'unknown operation' => ['profile', 'compliance_record', 'field_evidence_date', 'delete'],
    ];

    foreach ($misses as $case => [$entity_type, $bundle, $field, $operation]) {
      $this->assertNull(
        $map->requiredPermission($entity_type, $bundle, $field, $operation),
        sprintf('Unprotected for the "%s" case.', $case),
      );
    }
  }

  /**
   * An empty permission string is a misconfiguration, not a total lockout.
   *
   * Treating '' as "a permission nobody holds" would deny everyone with no way
   * to tell from the config that anything was wrong.
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
