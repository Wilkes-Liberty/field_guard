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
 * Pins the cache metadata on the access verdicts.
 *
 * Cacheability on this hook is not decoration. It is consumed on exactly one
 * path, and that path is the one the module cares most about: JSON:API's
 * FieldResolver::getFieldAccess() calls fieldAccess() with
 * $return_as_object = TRUE
 * (core/modules/jsonapi/src/Context/FieldResolver.php:763)
 * and folds the result into the cacheable response. The render path calls
 * fieldAccess() with $return_as_object = FALSE and discards the metadata
 * entirely, so nothing this hook returns can protect it.
 *
 * Two properties are pinned here, both regressions that review caught:
 *
 * 1. Every verdict carries `config:field_guard.settings`. Without it a
 *    cached verdict outlives the config change that should have altered it —
 *    and because this module only ever denies, the stale direction is a field
 *    staying readable after it was protected. That is the failure that matters.
 * 2. The definition-level (NULL $items) verdict carries NO user cache context.
 *    It never consults $account, so varying it per permission set fragmented
 *    the cache for an answer identical for everyone. The value-level verdict
 *    does consult the account, so it keeps its user contexts — asserted here
 *    so a future "optimisation" cannot quietly drop them and start serving
 *    one account's verdict to another.
 */
#[Group('field_guard')]
#[RunTestsInSeparateProcesses]
#[CoversFunction('field_guard_entity_field_access')]
final class CacheabilityTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The cache tag that must invalidate every verdict.
   */
  private const SETTINGS_TAG = 'config:field_guard.settings';

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
   * The definition-level verdict is invalidated by a settings change.
   */
  public function testDefinitionLevelVerdictCarriesTheSettingsCacheTag(): void {
    $result = $this->handler()
      ->fieldAccess('view', $this->definition(), $this->createUser(), NULL, TRUE);

    $this->assertTrue($result->isForbidden(), 'Premise: the definition-level check denies.');
    $this->assertContains(
      self::SETTINGS_TAG,
      $result->getCacheTags(),
      'A verdict derived from field_guard.settings must be invalidated when that config changes, or a field stays readable after it was protected.',
    );
  }

  /**
   * The definition-level verdict does not vary per user and must not say so.
   */
  public function testDefinitionLevelVerdictHasNoUserCacheContext(): void {
    $result = $this->handler()
      ->fieldAccess('view', $this->definition(), $this->createUser(), NULL, TRUE);

    $contexts = $result->getCacheContexts();

    $this->assertNotContains(
      'user.permissions',
      $contexts,
      'The definition-level deny never consults $account, so varying it per permission set fragments the cache for an identical answer.',
    );
    $this->assertSame(
      [],
      array_values(array_filter($contexts, static fn (string $c): bool => str_starts_with($c, 'user'))),
      'The definition-level deny is the same for every account and must carry no user context at all.',
    );
  }

  /**
   * The same verdict is returned for accounts with different permissions.
   *
   * The companion to the assertion above: dropping the user context is only
   * safe because the answer genuinely does not depend on the account. If that
   * ever stops being true, this fails rather than silently serving a cached
   * verdict across accounts.
   */
  public function testDefinitionLevelVerdictIsIdenticalAcrossAccounts(): void {
    $without = $this->handler()
      ->fieldAccess('view', $this->definition(), $this->createUser(), NULL, TRUE);
    $with = $this->handler()->fieldAccess(
      'view',
      $this->definition(),
      $this->createUser(['view guarded field']),
      NULL,
      TRUE,
    );

    $this->assertTrue($without->isForbidden());
    $this->assertTrue(
      $with->isForbidden(),
      'If the permission holder is ever allowed here, the verdict is account-dependent and must regain its user cache context.',
    );
  }

  /**
   * The value-level verdict is also invalidated by a settings change.
   */
  public function testValueLevelVerdictCarriesTheSettingsCacheTag(): void {
    $entity = EntityTest::create(['name' => 'x', 'field_evidence_date' => '2026-08-01']);
    $entity->save();

    $result = $entity->get('field_evidence_date')
      ->access('view', $this->createUser(), TRUE);

    $this->assertTrue($result->isForbidden(), 'Premise: an unprivileged account is denied.');
    $this->assertContains(
      self::SETTINGS_TAG,
      $result->getCacheTags(),
      'Whether this field is protected at all comes from config, so the verdict must carry the config tag.',
    );
  }

  /**
   * The value-level verdict keeps the user contexts it genuinely needs.
   */
  public function testValueLevelVerdictKeepsItsUserCacheContexts(): void {
    $entity = EntityTest::create(['name' => 'x', 'field_evidence_date' => '2026-08-01']);
    $entity->save();

    $contexts = $entity->get('field_evidence_date')
      ->access('view', $this->createUser(), TRUE)
      ->getCacheContexts();

    $this->assertContains(
      'user.roles',
      $contexts,
      'The value-level verdict is derived from the roles the account holds; without user.roles one account\'s verdict can be served to another.',
    );
  }

}
