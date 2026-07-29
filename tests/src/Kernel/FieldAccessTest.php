<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the deny holds for every principal it is supposed to hold against.
 */
#[Group('field_guard')]
#[RunTestsInSeparateProcesses]
#[CoversFunction('field_guard_entity_field_access')]
final class FieldAccessTest extends KernelTestBase {

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
   * The entity under test.
   */
  private EntityTest $entity;

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
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_evidence_date',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Evidence date',
    ])->save();

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

    $this->config('field_guard.settings')
      ->set('protected', [
        'entity_test' => [
          'entity_test' => [
            'field_evidence_date' => [
              'view' => 'view guarded field',
              'edit' => 'edit guarded field',
            ],
          ],
        ],
      ])
      ->save();

    // User 1 is created first and reserved: SuperUserAccessPolicy keys on it,
    // so it must not be handed to a test account by accident.
    $this->createUser([], 'root', TRUE);

    $this->entity = EntityTest::create([
      'name' => 'Subject record',
      'field_evidence_date' => '2026-08-01',
      'field_open' => 'public value',
    ]);
    $this->entity->save();
  }

  /**
   * An account without the permission is forbidden, not merely unlisted.
   */
  public function testUnprivilegedIsForbidden(): void {
    $account = $this->createUser();

    $result = $this->entity->get('field_evidence_date')->access('view', $account, TRUE);
    $this->assertTrue($result->isForbidden(), 'A user without the permission is forbidden.');
  }

  /**
   * An account holding the permission is not blocked by this module.
   */
  public function testPrivilegedIsNotForbidden(): void {
    $account = $this->createUser(['view guarded field']);

    $result = $this->entity->get('field_evidence_date')->access('view', $account, TRUE);
    $this->assertFalse($result->isForbidden(), 'The permission holder is not forbidden.');
  }

  /**
   * View and edit are separate grants.
   *
   * Reading an attestation about yourself and authoring one are different acts,
   * and the permission split is what makes the record trustworthy as evidence.
   */
  public function testViewPermissionDoesNotGrantEdit(): void {
    $account = $this->createUser(['view guarded field']);

    $this->assertFalse(
      $this->entity->get('field_evidence_date')->access('view', $account, TRUE)->isForbidden(),
      'The viewer may read.',
    );
    $this->assertTrue(
      $this->entity->get('field_evidence_date')->access('edit', $account, TRUE)->isForbidden(),
      'The viewer may not write: edit needs its own grant.',
    );
  }

  /**
   * An unprotected field on the same bundle is untouched.
   *
   * Guards against the module over-reaching: it must deny only what the map
   * names.
   */
  public function testUnprotectedFieldIsUnaffected(): void {
    $account = $this->createUser();

    $this->assertFalse(
      $this->entity->get('field_open')->access('view', $account, TRUE)->isForbidden(),
      'A field absent from the map is not denied.',
    );
  }

  /**
   * An is_admin role does not bypass the deny. Intended.
   *
   * This works because the module tests for an EXPLICIT grant rather than
   * calling AccountInterface::hasPermission(), which returns TRUE for every
   * permission on an is_admin role. Gating on hasPermission() would let admins
   * through silently -- an earlier draft did exactly that, and this test is
   * what caught it.
   */
  public function testAdminRoleIsForbidden(): void {
    $account = $this->createUser([], 'admin-ish', TRUE);

    $result = $this->entity->get('field_evidence_date')->access('view', $account, TRUE);
    $this->assertTrue($result->isForbidden(), 'An is_admin role does not bypass the deny.');
  }

  /**
   * User 1 does not bypass the deny. Intended.
   *
   * SuperUserAccessPolicy grants uid 1 every permission, so any hasPermission()
   * check would pass. The explicit-grant test is what makes the deny real: uid
   * 1 holds no non-admin role naming the permission, so it is forbidden like
   * anyone else -- and can be granted access deliberately, on a named role, if
   * wanted.
   */
  public function testUidOneIsForbidden(): void {
    $root = $this->container->get('entity_type.manager')
      ->getStorage('user')
      ->load(1);
    $this->assertNotNull($root, 'uid 1 exists.');

    $result = $this->entity->get('field_evidence_date')->access('view', $root, TRUE);
    $this->assertTrue($result->isForbidden(), 'uid 1 does not bypass the deny.');
  }

  /**
   * An explicit grant on a non-admin role does let an admin account through.
   *
   * This is the escape hatch, and it is deliberate: an operator can grant
   * 'edit guarded field' to their own account on a named, non-admin role,
   * and that grant is a visible line in a config diff. What they cannot do is
   * inherit the access simply by being an administrator.
   */
  public function testExplicitGrantOnNonAdminRoleAllowsAdminAccount(): void {
    $account = $this->createUser([], 'admin-with-grant', TRUE);
    $role_id = $this->createRole(['view guarded field'], 'evidence_viewer');
    $account->addRole($role_id);
    $account->save();

    $result = $this->entity->get('field_evidence_date')->access('view', $account, TRUE);
    $this->assertFalse($result->isForbidden(), 'An explicit non-admin grant is honoured.');
  }

  /**
   * The verdict varies by permission, so it must not be cached across users.
   */
  public function testResultIsCachedPerPermissions(): void {
    $account = $this->createUser();

    $result = $this->entity->get('field_evidence_date')->access('view', $account, TRUE);
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

}
