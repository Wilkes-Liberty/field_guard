<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard\Kernel;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the opt-in own-subject view exemption and everything it must NOT relax.
 *
 * The feature exists for records that are ABOUT a user and stored ON that
 * user's account (directly, or on a composition entity hanging from it): the
 * site may decide the subject reading their own record is fine while keeping
 * every other property of the guard — the write protection, the
 * definition-level deny, and the deny against everyone else, administrators
 * included.
 */
#[Group('field_guard')]
#[RunTestsInSeparateProcesses]
#[CoversFunction('field_guard_entity_field_access')]
final class OwnSubjectViewExemptionTest extends KernelTestBase {

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
   * The subject: the user the guarded record is about.
   */
  private UserInterface $subject;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['field_guard']);

    FieldStorageConfig::create([
      'field_name' => 'field_attestation',
      'entity_type' => 'user',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_attestation',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Attestation',
    ])->save();

    $this->config('field_guard.settings')
      ->set('protected', [
        'user' => [
          'user' => [
            'field_attestation' => [
              'view' => 'view guarded field',
              'edit' => 'edit guarded field',
              'view_exempt_own_subject' => TRUE,
            ],
          ],
        ],
      ])
      ->save();

    // User 1 is created first and reserved: SuperUserAccessPolicy keys on it.
    $this->createUser([], 'root', TRUE);

    $this->subject = $this->createUser();
    $this->subject->set('field_attestation', 'about the subject')->save();
  }

  /**
   * The subject may view the record about themselves, without any permission.
   */
  public function testSubjectMayViewOwnField(): void {
    $result = $this->subject->get('field_attestation')
      ->access('view', $this->subject, TRUE);

    $this->assertFalse(
      $result->isForbidden(),
      'The flagged view guard stands aside for the record subject.',
    );
  }

  /**
   * The exempt verdict varies per user, not merely per permission set.
   *
   * Two accounts with identical roles get different answers on the same field
   * value, so anything caching the verdict must key on the user.
   */
  public function testExemptVerdictCarriesUserCacheContext(): void {
    $result = $this->subject->get('field_attestation')
      ->access('view', $this->subject, TRUE);

    $this->assertInstanceOf(CacheableDependencyInterface::class, $result);
    $this->assertContains('user', $result->getCacheContexts());
  }

  /**
   * Any other unprivileged account stays forbidden.
   */
  public function testOtherAccountRemainsForbidden(): void {
    $other = $this->createUser();

    $result = $this->subject->get('field_attestation')
      ->access('view', $other, TRUE);

    $this->assertTrue(
      $result->isForbidden(),
      'The exemption is for the subject alone.',
    );
  }

  /**
   * An is_admin role viewing someone else's record stays forbidden.
   */
  public function testAdminViewingOtherSubjectRemainsForbidden(): void {
    $admin = $this->createUser([], 'admin-ish', TRUE);

    $result = $this->subject->get('field_attestation')
      ->access('view', $admin, TRUE);

    $this->assertTrue(
      $result->isForbidden(),
      'The flag does not reopen the admin bypass this module exists to close.',
    );
  }

  /**
   * An explicit permission grant still works on a flagged field.
   */
  public function testExplicitGrantStillHonouredOnFlaggedField(): void {
    $reviewer = $this->createUser(['view guarded field']);

    $result = $this->subject->get('field_attestation')
      ->access('view', $reviewer, TRUE);

    $this->assertFalse(
      $result->isForbidden(),
      'Flagging a field must not lock out the named permission holders.',
    );
  }

  /**
   * The subject may NOT edit the record about themselves.
   *
   * This is the property the whole feature is scoped around: a record its
   * subject can rewrite is not evidence. There is no edit exemption.
   */
  public function testSubjectMayNotEditOwnField(): void {
    $result = $this->subject->get('field_attestation')
      ->access('edit', $this->subject, TRUE);

    $this->assertTrue(
      $result->isForbidden(),
      'The exemption is view-only; edit keeps its guard.',
    );
  }

  /**
   * The definition-level (NULL $items) deny is untouched by the flag.
   *
   * Without an entity there is no subject, so there is nothing to exempt —
   * and relaxing this path would let an exposed filter binary-search other
   * subjects' values.
   */
  public function testDefinitionLevelDenyIsUntouched(): void {
    $definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('user', 'user');
    $definition = $definitions['field_attestation'];

    $handler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler('user');

    $result = $handler->fieldAccess('view', $definition, $this->subject, NULL, TRUE);

    $this->assertTrue(
      $result->isForbidden(),
      'Filter/sort probing stays closed even for the subject.',
    );
  }

  /**
   * The anonymous account is never a subject.
   */
  public function testAnonymousRemainsForbidden(): void {
    $anonymous = User::getAnonymousUser();

    $result = $this->subject->get('field_attestation')
      ->access('view', $anonymous, TRUE);

    $this->assertTrue(
      $result->isForbidden(),
      'Anonymous owns nothing.',
    );
  }

  /**
   * Without the flag, the subject is forbidden like anyone else.
   *
   * Guards the opt-in property: the exemption must never become implicit
   * behaviour for user-hosted fields.
   */
  public function testWithoutFlagSubjectRemainsForbidden(): void {
    $this->config('field_guard.settings')
      ->set('protected', [
        'user' => [
          'user' => [
            'field_attestation' => [
              'view' => 'view guarded field',
              'edit' => 'edit guarded field',
            ],
          ],
        ],
      ])
      ->save();

    $result = $this->subject->get('field_attestation')
      ->access('view', $this->subject, TRUE);

    $this->assertTrue(
      $result->isForbidden(),
      'Own-subject view is exempt only where the map says so.',
    );
  }

}
