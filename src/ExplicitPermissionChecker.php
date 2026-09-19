<?php

declare(strict_types=1);

namespace Drupal\field_guard;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Decides whether an account holds a permission by explicit grant.
 *
 * The access hook and any caller that needs the guard's verdict share this one
 * implementation.
 */
final class ExplicitPermissionChecker {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks whether a permission is EXPLICITLY granted by a non-admin role.
   *
   * This deliberately does not use AccountInterface::hasPermission(). That
   * method returns TRUE for every permission when the account holds a role
   * with is_admin: TRUE, or when it is uid 1.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to test.
   * @param string $permission
   *   The permission that must be explicitly granted.
   *
   * @return bool
   *   TRUE when a non-admin role held by the account grants the permission.
   */
  public function hasExplicitPermission(AccountInterface $account, string $permission): bool {
    $roles = $this->entityTypeManager
      ->getStorage('user_role')
      ->loadMultiple($account->getRoles());

    foreach ($roles as $role) {
      /** @var \Drupal\user\RoleInterface $role */
      // Skip is_admin roles: their permission list is "everything", so
      // consulting it would reintroduce the bypass this method exists to
      // close.
      if ($role->isAdmin()) {
        continue;
      }

      if ($role->hasPermission($permission)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
