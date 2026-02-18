<?php

declare(strict_types=1);

namespace Drupal\smoke\Plugin\SmokeSuite;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\smoke\Attribute\SmokeSuite;
use Drupal\smoke\Plugin\SuiteBase;

/**
 * Sitemap suite - tests XML sitemap generation.
 */
#[SmokeSuite(
  id: 'sitemap',
  label: new TranslatableMarkup('Sitemap'),
  description: new TranslatableMarkup('XML sitemap exists, returns valid XML, contains URLs.'),
  icon: 'sitemap',
  weight: -40,
)]
class SitemapSuite extends SuiteBase {

  /**
   * {@inheritdoc}
   */
  public function isDetected(): bool {
    // Requires simple_sitemap or xmlsitemap module.
    return $this->moduleHandler->moduleExists('simple_sitemap')
      || $this->moduleHandler->moduleExists('xmlsitemap');
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    $module = $this->moduleHandler->moduleExists('simple_sitemap') ? 'simple_sitemap' : 'xmlsitemap';

    return [
      'detected' => $this->isDetected(),
      'label' => $this->getLabel(),
      'description' => $this->getDescription(),
      'module' => $module,
    ];
  }

}
