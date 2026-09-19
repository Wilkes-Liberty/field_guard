<?php

/**
 * @file
 * Post update functions for Field Guard.
 */

declare(strict_types=1);

/**
 * Rebuild the container to register the explicit-permission checker service.
 */
function field_guard_post_update_register_explicit_permission_checker(): void {
  // Deliberately empty. Running any update rebuilds the container, and the
  // access hook needs field_guard.explicit_permission_checker to be in it.
}
