<?php

declare(strict_types=1);

namespace Drupal\field_guard_mcp\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field_guard\ExplicitPermissionChecker;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\ListInputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports the guard's verdict on named fields for the acting account.
 */
#[Tool(
  id: 'field_guard_check_access',
  label: new TranslatableMarkup('Check guarded field access'),
  description: new TranslatableMarkup('For the account making this call, and no other, report Field Guard\'s verdict on up to 50 named fields of one bundle for view or edit, so a client can check before it writes. This is the guard\'s verdict, not Drupal\'s full field access result: "allowed": true means this module does not deny the account, and entity access, other modules and field permissions still apply. A field this module does not guard returns "guarded": false and "allowed": null, never "allowed": true. The guard counts only a permission granted by a non-admin role, so user 1 and an administrator role are denied unless such a role holds the permission. "own_subject_exempt": true means the record\'s own subject may view the field without the permission; the tool does not resolve who that is. Filtering or sorting on a guarded field is refused for every account. Names only, never field values.'),
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
      description: new TranslatableMarkup('Bundle machine name.'),
      required: TRUE,
      constraints: ['Regex' => ['pattern' => '/^[a-z][a-z0-9_]{0,127}$/D']],
    ),
    'fields' => new ListInputDefinition(
      label: new TranslatableMarkup('Fields'),
      description: new TranslatableMarkup('Field machine names, 1 to 50.'),
      required: TRUE,
      constraints: ['Count' => ['min' => 1, 'max' => 50]],
      item_definition: new InputDefinition(
        data_type: 'string',
        label: new TranslatableMarkup('Field'),
        description: new TranslatableMarkup('Field machine name, for example field_salary.'),
        required: TRUE,
        constraints: ['Regex' => ['pattern' => '/^[a-z][a-z0-9_]{0,127}$/D']],
      ),
    ),
    'operation' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Operation'),
      description: new TranslatableMarkup('view or edit.'),
      required: TRUE,
      constraints: ['Choice' => ['choices' => ['view', 'edit']]],
    ),
  ],
)]
final class CheckAccessTool extends FieldGuardToolBase {

  /**
   * Most field names one call accepts.
   */
  private const MAX_FIELDS = 50;

  /**
   * The rule the access hook applies.
   */
  protected ExplicitPermissionChecker $checker;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->checker = $container->get('field_guard.explicit_permission_checker');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function run(array $values): array {
    $entityType = $this->machineName($values['entity_type'] ?? NULL);
    $bundle = $this->machineName($values['bundle'] ?? NULL);
    $operation = $values['operation'] ?? NULL;
    if (!in_array($operation, ['view', 'edit'], TRUE)) {
      throw new \InvalidArgumentException('Unknown operation.');
    }
    $names = $values['fields'] ?? NULL;
    if (!is_array($names) || $names === [] || count($names) > self::MAX_FIELDS) {
      throw new \InvalidArgumentException('Between 1 and 50 fields.');
    }
    $names = array_values(array_unique(array_map([$this, 'machineName'], $names)));

    $fields = [];
    foreach ($names as $name) {
      $permission = $this->fieldMap->requiredPermission($entityType, $bundle, $name, $operation);
      // The acting account only. There is no input that names another one.
      // An unguarded field has no verdict: NULL, never TRUE.
      $allowed = $permission === NULL
        ? NULL
        : $this->checker->hasExplicitPermission($this->currentUser, $permission);
      $exempt = $permission !== NULL
        && $operation === 'view'
        && $this->fieldMap->viewExemptsOwnSubject($entityType, $bundle, $name);
      $fields[] = [
        'field' => $name,
        'guarded' => $permission !== NULL,
        'allowed' => $allowed,
        'permission' => $permission,
        'own_subject_exempt' => $exempt,
      ];
    }
    return [
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'operation' => $operation,
      'fields' => $fields,
    ];
  }

}
