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
 * @group field_guard
 *
 * @runTestsInSeparateProcesses
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
   * A typo'd operation key must violate schema.
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
