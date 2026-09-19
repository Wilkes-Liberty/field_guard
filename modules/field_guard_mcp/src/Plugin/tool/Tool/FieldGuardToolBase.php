<?php

declare(strict_types=1);

namespace Drupal\field_guard_mcp\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field_guard\ProtectedFieldMap;
use Drupal\mcp_sentinel\McpPolicyProfileInterface;
use Drupal\mcp_sentinel\Plugin\tool\Tool\McpEntityToolTrait;
use Drupal\mcp_sentinel\Plugin\tool\Tool\McpGovernedToolBase;
use Drupal\tool\ExecutableResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shares access, rate limiting and refusal handling for Field Guard tools.
 *
 * This module's own refusals are one fixed message. Caller input, exception
 * text and field values never reach a result.
 */
abstract class FieldGuardToolBase extends McpGovernedToolBase {

  use McpEntityToolTrait;

  /**
   * Permission every tool in this module requires.
   */
  public const PERMISSION = 'use field guard mcp tools';

  /**
   * Entity type, bundle and field machine names.
   */
  public const MACHINE_NAME = '/^[a-z][a-z0-9_]{0,127}$/D';

  /**
   * Largest JSON result a tool returns, in bytes.
   *
   * The resolved profile's response-size cap applies when it is lower.
   */
  protected const MAX_RESULT_BYTES = 131072;

  /**
   * The protected field map.
   */
  protected ProtectedFieldMap $fieldMap;

  /**
   * MCP Sentinel's exfiltration guard, when the installed version has one.
   *
   * @var \Drupal\mcp_sentinel\Service\McpExfiltrationGuard|null
   */
  protected $exfiltrationGuard = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->fieldMap = $container->get('field_guard.field_map');
    $instance->exfiltrationGuard = $container->has('mcp_sentinel.exfiltration_guard')
      ? $container->get('mcp_sentinel.exfiltration_guard')
      : NULL;
    return $instance;
  }

  /**
   * Runs the operation against the module's own services.
   *
   * @param array $values
   *   Input values.
   *
   * @return array
   *   Result holding names only, never a field value.
   *
   * @throws \InvalidArgumentException
   *   When an input is not acceptable. The message is never relayed.
   */
  abstract protected function run(array $values): array;

  /**
   * {@inheritdoc}
   */
  protected function checkGovernedDiscoveryAccess(AccountInterface $account): AccessResultInterface {
    return $this->checkGovernedAccess([], $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkGovernedAccess(array $values, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, self::PERMISSION)->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      // ToolBase::execute() does not call access(). Recheck for PHP callers.
      if (!$this->checkAccess($values, $this->currentUser)) {
        return $this->refused();
      }
      $profile = $this->governancePolicyResolver?->resolve($this->currentUser);
      if ($profile === NULL) {
        return $this->refused();
      }
      if ($limited = $this->checkRateLimit($profile, $this->getPluginId())) {
        return $limited;
      }
      $result = $this->run($values);
      if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > $this->resultLimit($profile)) {
        return $this->refused();
      }
      return ExecutableResult::success($this->t('Field Guard operation completed.'), $result);
    }
    catch (\Throwable $exception) {
      // Record the failure class only. Messages can carry caller input.
      $this->logger->warning('Field Guard tool @tool failed with @type at @source:@line.', [
        '@tool' => $this->getPluginId(),
        '@type' => get_class($exception),
        '@source' => basename($exception->getFile()),
        '@line' => $exception->getLine(),
      ]);
      return $this->refused();
    }
  }

  /**
   * The smaller of this module's ceiling and the profile's response-size cap.
   */
  protected function resultLimit(McpPolicyProfileInterface $profile): int {
    $cap = $this->exfiltrationGuard !== NULL
      ? (int) $this->exfiltrationGuard->effectiveResponseSizeCap($profile)
      : 0;
    return $cap > 0 ? min(static::MAX_RESULT_BYTES, $cap) : static::MAX_RESULT_BYTES;
  }

  /**
   * The refusal this module's own code returns.
   */
  protected function refused(): ExecutableResult {
    return ExecutableResult::failure($this->t('Field Guard operation refused. Check permissions, inputs and limits.'));
  }

  /**
   * Validates a machine name.
   *
   * @throws \InvalidArgumentException
   */
  protected function machineName(mixed $value): string {
    if (!is_string($value) || !preg_match(self::MACHINE_NAME, $value)) {
      throw new \InvalidArgumentException('Invalid machine name.');
    }
    return $value;
  }

}
