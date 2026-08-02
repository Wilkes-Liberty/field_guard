<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Pins the shape of the protected-field map's config schema.
 *
 * The map is four levels deep: entity type > bundle > field name > operation.
 * The three outer levels have genuinely arbitrary keys and are `sequence`,
 * which is how Drupal models a variable-keyed map — see
 * `filter.format.*.filters`, a
 * sequence keyed by filter plugin ID. There is no "wildcard mapping" type, and
 * these levels must stay sequences or the module stops accepting new entity
 * types, bundles and fields.
 *
 * The innermost level is different: `ProtectedFieldMap::OPERATIONS` honours
 * only 'view' and 'edit', and `requiredPermission()` returns NULL for anything
 * else. So an unrecognised operation key does not error — it silently leaves
 * the field UNPROTECTED while the config claims otherwise. Modelling that
 * level as a closed `mapping` turns the typo into a schema violation.
 *
 * Measured before the change: `['viewed' => '…']` produced **zero** violations
 * and saved without complaint. After: one violation, "'viewed' is not a
 * supported key."
 *
 * How far this protection reaches, stated honestly: `ConfigSchemaChecker`
 * throws
 * on save only when `strictConfigSchema` is on, which is kernel tests and
 * therefore CI. `drush config:import` on a live site does **not** run schema
 * validation, so this is a development-and-CI gate plus executable
 * documentation of the closed set — not a runtime guard.
 */
#[Group('field_guard')]
#[RunTestsInSeparateProcesses]
final class ConfigSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'field_guard'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['field_guard']);
  }

  /**
   * Validates a data array against the module's config schema.
   *
   * @param array $data
   *   The configuration data to validate.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   The violations found.
   */
  private function violations(array $data): ConstraintViolationListInterface {
    return $this->container->get('config.typed')
      ->createFromNameAndData('field_guard.settings', $data)
      ->validate();
  }

  /**
   * Builds a map with the given operations for one field.
   *
   * @param array $operations
   *   The operation => permission pairs to place on the field.
   *
   * @return array
   *   A full settings data array.
   */
  private function map(array $operations): array {
    return ['protected' => ['profile' => ['employee' => ['field_training_date' => $operations]]]];
  }

  /**
   * A well-formed map validates and saves cleanly.
   */
  public function testWellFormedMapIsValid(): void {
    $data = $this->map([
      'view' => 'view guarded field',
      'edit' => 'edit guarded field',
    ]);

    $this->assertCount(0, $this->violations($data), 'A well-formed map must validate.');

    // Saving also exercises ConfigSchemaChecker, which throws on any mismatch
    // because KernelTestBase sets strictConfigSchema.
    $this->config('field_guard.settings')->setData($data)->save();
    $this->assertSame(
      'view guarded field',
      $this->config('field_guard.settings')
        ->get('protected.profile.employee.field_training_date.view'),
    );
  }

  /**
   * One operation alone is valid — omission is how you leave the other open.
   */
  public function testSingleOperationIsValid(): void {
    $this->assertCount(
      0,
      $this->violations($this->map(['view' => 'view guarded field'])),
      'Protecting view without protecting edit is a supported configuration.',
    );
  }

  /**
   * An unrecognised operation key is rejected instead of silently ignored.
   *
   * This is the regression this test exists for. `requiredPermission()` returns
   * NULL for an operation outside OPERATIONS, so before the schema was
   * tightened a typo left the field readable while the config claimed it was
   * protected —
   * and nothing anywhere reported it.
   */
  public function testUnrecognisedOperationKeyIsRejected(): void {
    foreach (['viewed', 'Edit', 'delete', 'update'] as $typo) {
      $violations = $this->violations($this->map([$typo => 'view guarded field']));

      $this->assertGreaterThan(
        0,
        count($violations),
        sprintf('Operation key "%s" is not honoured by ProtectedFieldMap and must not validate — a silently ignored key leaves the field unprotected.', $typo),
      );
      $this->assertStringContainsString(
        $typo,
        (string) $violations->get(0)->getPropertyPath() . ' ' . (string) $violations->get(0)->getMessage(),
        'The violation should name the offending key so the misconfiguration is diagnosable.',
      );
    }
  }

  /**
   * The three outer levels still accept arbitrary keys.
   *
   * Guards against someone "correcting" them into mappings too, which would
   * stop the module accepting entity types, bundles and fields it was not
   * shipped
   * knowing about — the whole point of it being config.
   */
  public function testOuterLevelsAcceptArbitraryKeys(): void {
    $data = [
      'protected' => [
        'node' => [
          'some_future_bundle' => [
            'field_invented_tomorrow' => ['view' => 'view guarded field'],
          ],
        ],
        'profile' => [
          'contractor' => [
            'field_agreement_doc' => ['edit' => 'edit guarded field'],
          ],
        ],
        'entirely_new_entity_type' => [
          'x' => ['field_y' => ['view' => 'view guarded field']],
        ],
      ],
    ];

    $this->assertCount(
      0,
      $this->violations($data),
      'Entity type, bundle and field keys are arbitrary by design and must remain sequences.',
    );
  }

}
