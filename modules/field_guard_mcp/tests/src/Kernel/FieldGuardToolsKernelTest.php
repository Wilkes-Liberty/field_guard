<?php

declare(strict_types=1);

namespace Drupal\Tests\field_guard_mcp\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises discovery and direct execution against source governance.
 *
 * The annotations repeat the attributes because the Drupal 10.6 leg runs
 * PHPUnit 9, which reads only annotations.
 *
 * @group field_guard
 *
 * @runTestsInSeparateProcesses
 */
#[Group('field_guard')]
#[RunTestsInSeparateProcesses]
final class FieldGuardToolsKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * A stored value of a guarded field. It must never reach a tool result.
   */
  private const VALUE = 'guarded-value-7Q';

  private const TOOLS = ['field_guard_list_guarded', 'field_guard_check_access'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'file', 'node',
    'serialization', 'jsonapi', 'tool', 'key', 'image', 'options',
    'path_alias', 'consumers', 'simple_oauth', 'encrypt', 'audit_chain',
    'mcp_sentinel', 'entity_test', 'field_guard', 'field_guard_test',
    'field_guard_mcp',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Tool API and MCP Sentinel are optional. Where a pipeline does not
    // install them, skip instead of failing on a missing module.
    if (!class_exists('Drupal\\tool\\Tool\\ToolBase') || !class_exists('Drupal\\mcp_sentinel\\Plugin\\tool\\Tool\\McpGovernedToolBase')) {
      $this->markTestSkipped('Tool API and MCP Sentinel are not installed.');
    }
    parent::setUp();
    $this->installSchema('audit_chain', ['audit_chain_log', 'audit_chain_mutex']);
    $this->container->get('database')->insert('audit_chain_mutex')
      ->fields(['id' => 1, 'locked' => 1])->execute();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['system', 'user', 'field', 'mcp_sentinel', 'field_guard']);

    foreach (['field_evidence_date', 'field_subject_note', 'field_open'] as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'entity_test',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'entity_test',
        'bundle' => 'entity_test',
      ])->save();
    }
    $this->config('field_guard.settings')->set('protected', [
      'entity_test' => [
        'entity_test' => [
          'field_evidence_date' => [
            'view' => 'view guarded field',
            'edit' => 'edit guarded field',
          ],
          'field_subject_note' => [
            'view' => 'view guarded field',
            'view_exempt_own_subject' => TRUE,
          ],
        ],
        'other_bundle' => [
          'field_rate' => ['edit' => 'edit guarded field'],
        ],
      ],
    ])->save();
    EntityTest::create([
      'name' => 'Subject record',
      'field_evidence_date' => self::VALUE,
      'field_subject_note' => self::VALUE,
    ])->save();

    // User 1 is created first and reserved, so no test account receives it.
    $this->createUser([], 'root', TRUE);
    $role = Role::load('mcp_api') ?? Role::create(['id' => 'mcp_api', 'label' => 'MCP API']);
    $role->grantPermission('access mcp sentinel context')
      ->grantPermission('use field guard mcp tools')
      ->save();
    // The guarded permission sits on its own non-admin role, so an account
    // can hold the tool role without it.
    $this->createRole(['view guarded field'], 'evidence_viewer');
    $this->config('mcp_sentinel.settings')->set('governed_role_fallback', TRUE)->save();
    $this->setUpCurrentUser(['roles' => ['mcp_api', 'evidence_viewer']]);
  }

  /**
   * Both tools run for a governed account and refuse an anonymous one.
   */
  public function testGovernedToolsAndAnonymousDenial(): void {
    $account = $this->container->get('current_user')->getAccount();
    foreach (self::TOOLS as $id) {
      $this->container->get('current_user')->setAccount($account);
      $tool = $this->tool($id, $this->inputsFor($id));
      self::assertTrue($tool->discoveryAccess($account)->isAllowed(), $id);
      self::assertTrue($tool->access(), $id);
      $tool->execute();
      self::assertTrue($tool->getResultStatus(), $id . ': ' . $tool->getResultMessage());
      self::assertNotEmpty($tool->getResult()->getContextValues(), $id);

      $this->container->get('current_user')->setAccount(new AnonymousUserSession());
      $denied = $this->tool($id, $this->inputsFor($id));
      self::assertFalse($denied->discoveryAccess(new AnonymousUserSession())->isAllowed(), $id);
      self::assertFalse($denied->access(), $id);
      $denied->execute();
      self::assertFalse($denied->getResultStatus(), $id);
      self::assertEmpty($denied->getResult()->getContextValues(), $id);
    }
  }

  /**
   * Sentinel access alone does not grant the module's tools.
   */
  public function testModulePermissionIsRequired(): void {
    Role::load('mcp_api')->revokePermission('use field guard mcp tools')->save();
    $account = $this->container->get('current_user');
    foreach (self::TOOLS as $id) {
      $tool = $this->tool($id, $this->inputsFor($id));
      self::assertFalse($tool->discoveryAccess($account)->isAllowed(), $id);
      self::assertFalse($tool->access(), $id);
      $tool->execute();
      self::assertFalse($tool->getResultStatus(), $id);
      self::assertEmpty($tool->getResult()->getContextValues(), $id);
    }
  }

  /**
   * Disabled auditing makes governance not ready, so every tool refuses.
   */
  public function testGovernanceNotReadyRefusesDirectExecution(): void {
    $this->config('mcp_sentinel.settings')->set('audit_enabled', FALSE)->save();
    foreach (self::TOOLS as $id) {
      $tool = $this->tool($id, $this->inputsFor($id));
      self::assertFalse($tool->discoveryAccess($this->container->get('current_user'))->isAllowed(), $id);
      self::assertFalse($tool->access(), $id);
      $tool->execute();
      self::assertFalse($tool->getResultStatus(), $id);
      self::assertEmpty($tool->getResult()->getContextValues(), $id);
    }
  }

  /**
   * The list names operations, permissions and the exemption, never values.
   */
  public function testListNamesGuardsAndNeverValues(): void {
    $all = $this->execute('field_guard_list_guarded', ['entity_type' => 'entity_test']);
    self::assertSame('entity_test', $all['entity_type']);
    self::assertFalse($all['truncated']);
    self::assertSame(3, $all['total']);
    self::assertSame([
      [
        'bundle' => 'entity_test',
        'field' => 'field_evidence_date',
        'operations' => ['view' => 'view guarded field', 'edit' => 'edit guarded field'],
        'view_exempt_own_subject' => FALSE,
      ],
      [
        'bundle' => 'entity_test',
        'field' => 'field_subject_note',
        'operations' => ['view' => 'view guarded field'],
        'view_exempt_own_subject' => TRUE,
      ],
      [
        'bundle' => 'other_bundle',
        'field' => 'field_rate',
        'operations' => ['edit' => 'edit guarded field'],
        'view_exempt_own_subject' => FALSE,
      ],
    ], $all['fields']);
    self::assertStringNotContainsString('7Q', json_encode($all));
    self::assertStringNotContainsString('field_open', json_encode($all));

    $one = $this->execute('field_guard_list_guarded', [
      'entity_type' => 'entity_test',
      'bundle' => 'other_bundle',
    ]);
    self::assertSame(['field_rate'], array_column($one['fields'], 'field'));

    $none = $this->execute('field_guard_list_guarded', ['entity_type' => 'node']);
    self::assertSame(0, $none['total']);
    self::assertSame([], $none['fields']);
  }

  /**
   * The check answers for the acting account with the hook's own rule.
   */
  public function testCheckAnswersForTheActingAccount(): void {
    $inputs = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['field_evidence_date', 'field_subject_note', 'field_open'],
    ];

    $view = $this->execute('field_guard_check_access', $inputs + ['operation' => 'view']);
    self::assertSame('view', $view['operation']);
    self::assertSame([
      [
        'field' => 'field_evidence_date',
        'guarded' => TRUE,
        'allowed' => TRUE,
        'permission' => 'view guarded field',
        'own_subject_exempt' => FALSE,
      ],
      [
        'field' => 'field_subject_note',
        'guarded' => TRUE,
        'allowed' => TRUE,
        'permission' => 'view guarded field',
        'own_subject_exempt' => TRUE,
      ],
      [
        'field' => 'field_open',
        'guarded' => FALSE,
        'allowed' => NULL,
        'permission' => NULL,
        'own_subject_exempt' => FALSE,
      ],
    ], $view['fields']);
    self::assertStringNotContainsString('7Q', json_encode($view));

    // The role grants view only. Edit on the same field is the guard's deny.
    $edit = $this->execute('field_guard_check_access', $inputs + ['operation' => 'edit']);
    $byField = array_column($edit['fields'], NULL, 'field');
    self::assertFalse($byField['field_evidence_date']['allowed']);
    self::assertSame('edit guarded field', $byField['field_evidence_date']['permission']);
    // Edit is not guarded on this field, and there is no edit exemption.
    self::assertFalse($byField['field_subject_note']['guarded']);
    self::assertNull($byField['field_subject_note']['allowed']);
    self::assertFalse($byField['field_subject_note']['own_subject_exempt']);

    // The tool's verdict is the hook's verdict for the same account.
    $entity = EntityTest::load(1);
    $account = $this->container->get('current_user')->getAccount();
    self::assertFalse($entity->get('field_evidence_date')->access('view', $account, TRUE)->isForbidden());
    self::assertTrue($entity->get('field_evidence_date')->access('edit', $account, TRUE)->isForbidden());
  }

  /**
   * An admin-role account is reported as denied, as the hook denies it.
   */
  public function testAdminRoleAccountIsReportedDenied(): void {
    // The tool role plus an is_admin role. Neither grants 'view guarded
    // field' explicitly.
    $admin = $this->createUser([], 'admin-ish', TRUE, ['roles' => ['mcp_api']]);
    self::assertTrue($admin->hasPermission('view guarded field'), 'Core says yes for an admin role.');
    $this->container->get('current_user')->setAccount($admin);

    $view = $this->execute('field_guard_check_access', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['field_evidence_date'],
      'operation' => 'view',
    ]);
    self::assertTrue($view['fields'][0]['guarded']);
    self::assertFalse($view['fields'][0]['allowed']);
    self::assertTrue(
      EntityTest::load(1)->get('field_evidence_date')->access('view', $admin, TRUE)->isForbidden(),
    );
  }

  /**
   * There is no way to ask about another account.
   */
  public function testCheckHasNoAccountInput(): void {
    $definitions = $this->tool('field_guard_check_access')->getInputDefinitions(TRUE);
    self::assertSame(['entity_type', 'bundle', 'fields', 'operation'], array_keys($definitions));
    $definitions = $this->tool('field_guard_list_guarded')->getInputDefinitions(TRUE);
    self::assertSame(['entity_type', 'bundle'], array_keys($definitions));

    $tool = $this->tool('field_guard_check_access');
    try {
      $tool->setInputValue('uid', 1);
      self::fail('An account input must not exist.');
    }
    catch (\Throwable $exception) {
      self::assertStringNotContainsString('fail(', $exception->getMessage());
      self::assertNotInstanceOf('PHPUnit\\Framework\\AssertionFailedError', $exception);
    }
  }

  /**
   * Malformed names and oversized lists are refused without an echo.
   */
  public function testInvalidInputIsRefusedWithoutEcho(): void {
    $good = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['field_open'],
      'operation' => 'view',
    ];
    $cases = [
      ['entity_type' => 'Entity-Test-7Q'] + $good,
      ['bundle' => 'bundle 7Q'] + $good,
      ['fields' => ['field_open', 'field.bad-7Q']] + $good,
      ['fields' => []] + $good,
      ['fields' => array_map(static fn (int $i): string => 'field_' . $i, range(1, 51))] + $good,
      ['operation' => 'delete'] + $good,
    ];
    // Tool API may reject a value when it is set. Count the cases that reach
    // execute(), so this cannot pass without ever asserting a refusal.
    $reached = 0;
    foreach ($cases as $inputs) {
      $reached += (int) $this->assertRefused('field_guard_check_access', $inputs);
    }
    $reached += (int) $this->assertRefused('field_guard_list_guarded', ['entity_type' => 'Bad Type 7Q']);
    self::assertGreaterThanOrEqual(1, $reached);
  }

  /**
   * The tools re-validate in code, behind Tool API's own constraints.
   *
   * Tool API rejects these values before doExecute() runs, so the only way to
   * reach the second check is to call doExecute() the way a PHP caller could.
   */
  public function testCodeRevalidatesBehindToolApiConstraints(): void {
    $good = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['field_open'],
      'operation' => 'view',
    ];
    $cases = [
      'field_guard_check_access' => [
        ['entity_type' => 'Entity-Test-7Q'] + $good,
        ['bundle' => 'bundle 7Q'] + $good,
        ['fields' => ['field_open', 'field.bad-7Q']] + $good,
        ['fields' => 'field_open'] + $good,
        ['fields' => []] + $good,
        ['fields' => array_map(static fn (int $i): string => 'field_' . $i, range(1, 51))] + $good,
        ['operation' => 'delete-7Q'] + $good,
      ],
      'field_guard_list_guarded' => [
        ['entity_type' => 'Bad Type 7Q'],
        ['entity_type' => 'entity_test', 'bundle' => 'Bad Bundle 7Q'],
        [],
      ],
    ];
    foreach ($cases as $id => $inputs) {
      $tool = $this->tool($id);
      $method = new \ReflectionMethod($tool, 'doExecute');
      foreach ($inputs as $values) {
        $result = $method->invoke($tool, $values);
        self::assertFalse($result->isSuccess(), $id . ' ' . json_encode($values));
        self::assertSame(
          'Field Guard operation refused. Check permissions, inputs and limits.',
          (string) $result->getMessage(),
        );
        self::assertEmpty($result->getContextValues());
      }
      // The same call path succeeds with good values, so the refusals above
      // come from validation and not from the way the method is reached.
      $ok = $method->invoke($tool, $id === 'field_guard_list_guarded' ? ['entity_type' => 'entity_test'] : $good);
      self::assertTrue($ok->isSuccess(), $id);
    }
  }

  /**
   * The list stops at 500 fields and says so.
   */
  public function testListIsBoundedAndSaysWhenTruncated(): void {
    $fields = [];
    foreach (range(1, 501) as $i) {
      $fields['field_bulk_' . $i] = ['view' => 'view guarded field'];
    }
    $this->config('field_guard.settings')
      ->set('protected', ['entity_test' => ['bulk' => $fields]])
      ->save();

    $list = $this->execute('field_guard_list_guarded', ['entity_type' => 'entity_test']);
    self::assertSame(501, $list['total']);
    self::assertTrue($list['truncated']);
    self::assertCount(500, $list['fields']);
  }

  /**
   * Creates a fresh tool instance with inputs set.
   */
  private function tool(string $id, array $inputs = []): object {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    return $tool;
  }

  /**
   * Minimal valid inputs for a tool.
   */
  private function inputsFor(string $id): array {
    return $id === 'field_guard_list_guarded'
      ? ['entity_type' => 'entity_test']
      : [
        'entity_type' => 'entity_test',
        'bundle' => 'entity_test',
        'fields' => ['field_evidence_date'],
        'operation' => 'view',
      ];
  }

  /**
   * Runs a tool that must succeed and returns its result.
   */
  private function execute(string $id, array $inputs): array {
    $tool = $this->tool($id, $inputs);
    self::assertTrue($tool->access(), $id);
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), $id . ': ' . $tool->getResultMessage());
    return $tool->getResult()->getContextValues();
  }

  /**
   * Asserts a tool refuses the inputs without echoing them.
   *
   * @return bool
   *   TRUE when execute() ran and the refusal was asserted, FALSE when Tool
   *   API rejected the value before that.
   */
  private function assertRefused(string $id, array $inputs): bool {
    try {
      $tool = $this->tool($id, $inputs);
      $tool->execute();
    }
    catch (\Throwable) {
      // A typed-data refusal at input time is as good as one at execute.
      return FALSE;
    }
    self::assertFalse($tool->getResultStatus(), json_encode($inputs));
    self::assertStringNotContainsString('7Q', (string) $tool->getResultMessage());
    self::assertEmpty($tool->getResult()->getContextValues());
    return TRUE;
  }

}
