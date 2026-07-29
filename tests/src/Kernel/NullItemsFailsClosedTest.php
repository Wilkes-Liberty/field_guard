<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard\Kernel;

use Drupal\Core\Entity\EntityAccessControlHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the definition-level (NULL $items) behaviour.
 *
 * This is the regression test for the reason this module exists rather than
 * drupal/field_permissions. That module's guard clause
 * (field_permissions.module:35-38) returns AccessResult::neutral() whenever
 * $items is NULL, which silently downgrades its deny on exactly the paths that
 * ask the definition-level question:
 *
 *   - JSON:API  FieldResolver::getFieldAccess()  — is this field filterable/sortable?
 *   - Views     EntityField::access()            — should this handler be removed?
 *
 * Neutral there means the filter is permitted and the Views handler survives, so a
 * protected value can be probed by an exposed filter even though it is never
 * rendered. Upstream issue #3003914, open since 2021.
 *
 * If this test ever fails, the module has acquired that same hole.
 */
#[Group('field_guard')]
#[RunTestsInSeparateProcesses]
#[CoversFunction('field_guard_entity_field_access')]
final class NullItemsFailsClosedTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'field_guard',
    'field_guard_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['field_guard']);

    FieldStorageConfig::create([
      'field_name' => 'field_evidence_date',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_evidence_date',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Evidence date',
    ])->save();

    $this->config('field_guard.settings')
      ->set('protected', [
        'entity_test' => [
          'entity_test' => [
            'field_evidence_date' => ['view' => 'view guarded field'],
          ],
        ],
      ])
      ->save();

    $this->createUser([], 'root', TRUE);
  }

  /**
   * Returns the field definition under test.
   */
  private function definition(): FieldDefinitionInterface {
    $definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test');

    return $definitions['field_evidence_date'];
  }

  /**
   * Returns the access handler for entity_test.
   */
  private function handler(): EntityAccessControlHandlerInterface {
    return $this->container->get('entity_type.manager')
      ->getAccessControlHandler('entity_test');
  }

  /**
   * With no entity context, an unprivileged account is forbidden — not neutral.
   */
  public function testDefinitionLevelCheckDeniesWithoutPermission(): void {
    $account = $this->createUser();

    $result = $this->handler()->fieldAccess('view', $this->definition(), $account, NULL, TRUE);

    $this->assertTrue(
      $result->isForbidden(),
      'A NULL-$items check must fail closed, or filter and sort paths leak.',
    );
  }

  /**
   * The deny is unconditional at definition level, including for the permission holder.
   *
   * There is no entity in scope, so there is no way to tell a legitimate filter
   * from a probe. Denying both is the intended trade, and pinning it here stops a
   * well-meaning future change from "fixing" it into a hole.
   */
  public function testDefinitionLevelCheckDeniesEvenWithPermission(): void {
    $account = $this->createUser(['view guarded field']);

    $result = $this->handler()->fieldAccess('view', $this->definition(), $account, NULL, TRUE);

    $this->assertTrue(
      $result->isForbidden(),
      'Protected fields are not filterable or sortable by anyone.',
    );
  }

  /**
   * An unprotected field is still filterable — the deny is scoped, not global.
   */
  public function testUnprotectedFieldStillAllowsDefinitionLevelAccess(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_open',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_open',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Not protected',
    ])->save();

    $definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test');
    $account = $this->createUser();

    $result = $this->handler()
      ->fieldAccess('view', $definitions['field_open'], $account, NULL, TRUE);

    $this->assertFalse($result->isForbidden(), 'Unprotected fields remain filterable.');
  }

  /**
   * Sanity check on the premise: with an entity in scope the permission decides.
   *
   * Without this, the two tests above would pass equally well if the module
   * denied everything unconditionally.
   */
  public function testWithEntityContextThePermissionStillDecides(): void {
    $entity = EntityTest::create(['name' => 'x', 'field_evidence_date' => '2026-08-01']);
    $entity->save();

    $this->assertFalse(
      $entity->get('field_evidence_date')
        ->access('view', $this->createUser(['view guarded field']), TRUE)
        ->isForbidden(),
      'With an entity in scope, the permission holder reads the value.',
    );
  }

}
