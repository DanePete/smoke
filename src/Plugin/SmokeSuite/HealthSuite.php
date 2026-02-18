<?php

declare(strict_types=1);

namespace Drupal\smoke\Plugin\SmokeSuite;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\smoke\Attribute\SmokeSuite;
use Drupal\smoke\Plugin\SuiteBase;

/**
 * Health suite - tests admin status, cron, asset loading.
 */
#[SmokeSuite(
  id: 'health',
  label: new TranslatableMarkup('Health'),
  description: new TranslatableMarkup('Admin status page, cron runs, CSS/JS assets load correctly.'),
  icon: 'medical',
  weight: -50,
)]
class HealthSuite extends SuiteBase {

  /**
   * {@inheritdoc}
   */
  public function isDetected(): bool {
    // Health checks are always available.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    return [
      'detected' => TRUE,
      'label' => $this->getLabel(),
      'description' => $this->getDescription(),
    ];
  }

}
