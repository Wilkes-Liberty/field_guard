<?php

declare(strict_types=1);

namespace Drupal\field_guard_mcp\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;

/**
 * Lists the fields Field Guard protects on an entity type.
 */
#[Tool(
  id: 'field_guard_list_guarded',
  label: new TranslatableMarkup('List guarded fields'),
  description: new TranslatableMarkup('List the fields Field Guard protects on an entity type, optionally one bundle. For each field: the guarded operations (view, edit), the permission name each one requires, and whether the view guard exempts the record\'s own subject. A guarded field a caller may not view is left out of JSON:API and reads as null in GraphQL, so it looks empty; this list says which fields that can happen to. Names only, never field values. A field missing from the list is not guarded by this module; other access rules may still apply. Returns at most 500 fields and sets "truncated" when there are more.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'entity_type' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity type'),
      description: new TranslatableMarkup('Entity type machine name, for example node.'),
      required: TRUE,
      constraints: ['Regex' => ['pattern' => '/^[a-z][a-z0-9_]{0,127}$/D']],
    ),
    'bundle' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Bundle'),
      description: new TranslatableMarkup('Bundle machine name. Omit for every bundle in the map.'),
      required: FALSE,
      constraints: ['Regex' => ['pattern' => '/^[a-z][a-z0-9_]{0,127}$/D']],
    ),
  ],
)]
final class ListGuardedTool extends FieldGuardToolBase {

  /**
   * Most fields one call returns.
   */
  private const MAX_FIELDS = 500;

  /**
   * {@inheritdoc}
   */
  protected function run(array $values): array {
    $entityType = $this->machineName($values['entity_type'] ?? NULL);
    $bundle = ($values['bundle'] ?? NULL) === NULL ? NULL : $this->machineName($values['bundle']);

    $fields = [];
    $total = 0;
    foreach ($this->fieldMap->guardedFields($entityType, $bundle) as $bundleId => $guarded) {
      foreach ($guarded as $fieldName => $guard) {
        // The map's keys are config. A key that is not a machine name cannot
        // name a real bundle or field, so it is not repeated.
        if (!preg_match(self::MACHINE_NAME, (string) $bundleId) || !preg_match(self::MACHINE_NAME, (string) $fieldName)) {
          continue;
        }
        $total++;
        if (count($fields) >= self::MAX_FIELDS) {
          continue;
        }
        $fields[] = [
          'bundle' => (string) $bundleId,
          'field' => (string) $fieldName,
          'operations' => array_filter([
            'view' => $guard['view'],
            'edit' => $guard['edit'],
          ], static fn (?string $permission): bool => $permission !== NULL),
          'view_exempt_own_subject' => $guard['view'] !== NULL && $guard['view_exempt_own_subject'],
        ];
      }
    }
    return [
      'entity_type' => $entityType,
      'total' => $total,
      'truncated' => $total > count($fields),
      'fields' => $fields,
    ];
  }

}
