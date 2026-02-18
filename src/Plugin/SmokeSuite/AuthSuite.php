<?php

declare(strict_types=1);

namespace Drupal\smoke\Plugin\SmokeSuite;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\smoke\Attribute\SmokeSuite;
use Drupal\smoke\Plugin\SuiteBase;

/**
 * Authentication suite - tests login functionality.
 */
#[SmokeSuite(
  id: 'auth',
  label: new TranslatableMarkup('Authentication'),
  description: new TranslatableMarkup('Login form works, invalid credentials show errors, password reset exists.'),
  icon: 'lock',
  weight: -90,
)]
class AuthSuite extends SuiteBase {

  /**
   * {@inheritdoc}
   */
  public function isDetected(): bool {
    // Auth is always available.
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
