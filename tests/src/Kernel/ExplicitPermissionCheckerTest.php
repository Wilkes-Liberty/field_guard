<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field_guard\ExplicitPermissionChecker;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the service applies the rule the access hook applies.
 *
 * @group field_guard
 *
 * @runTestsInSeparateProcesses
 */
#[Group('field_guard')]
#[RunTestsInSeparateProcesses]
#[CoversClass(ExplicitPermissionChecker::class)]
final class ExplicitPermissionCheckerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'field_guard',
    'field_guard_test',
  ];

  /**
   * The service under test.
   */
  private ExplicitPermissionChecker $checker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // User 1 is created first and reserved, so no test account receives it.
    $this->createUser([], 'root', TRUE);
    $this->checker = $this->container->get('field_guard.explicit_permission_checker');
  }

  /**
   * Only a grant on a non-admin role counts.
   */
  public function testOnlyNonAdminRoleGrantCounts(): void {
    $permission = 'view guarded field';

    $holder = $this->createUser([$permission]);
    $this->assertTrue($this->checker->hasExplicitPermission($holder, $permission));
    $this->assertFalse($this->checker->hasExplicitPermission($holder, 'edit guarded field'));

    $this->assertFalse($this->checker->hasExplicitPermission($this->createUser(), $permission));
    $this->assertFalse($this->checker->hasExplicitPermission(new AnonymousUserSession(), $permission));

    $admin = $this->createUser([], 'admin-ish', TRUE);
    $this->assertTrue($admin->hasPermission($permission), 'Core says yes for an admin role.');
    $this->assertFalse($this->checker->hasExplicitPermission($admin, $permission));

    $root = $this->container->get('entity_type.manager')->getStorage('user')->load(1);
    $this->assertFalse($this->checker->hasExplicitPermission($root, $permission));

    $admin->addRole($this->createRole([$permission], 'evidence_viewer'));
    $admin->save();
    $this->assertTrue($this->checker->hasExplicitPermission($admin, $permission));
  }

}
