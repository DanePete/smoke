<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\smoke\Service\TestRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Auto-fix common issues found by smoke tests.
 */
final class SmokeFixCommand extends DrushCommands {

  /**
   * Constructs the SmokeFixCommand.
   *
   * @param \Drupal\smoke\Service\TestRunner $testRunner
   *   The test runner service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public function __construct(
    private readonly TestRunner $testRunner,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ContainerInterface $container,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('smoke.test_runner'),
      $container->get('module_handler'),
      $container,
    );
  }

  #[CLI\Command(name: 'smoke:fix', aliases: ['sfix'])]
  #[CLI\Help(description: 'Auto-fix common issues found by smoke tests.')]
  #[CLI\Usage(name: 'drush smoke:fix', description: 'Review and fix failed tests.')]
  #[CLI\Usage(name: 'drush smoke:fix --sitemap', description: 'Regenerate the XML sitemap.')]
  #[CLI\Option(name: 'sitemap', description: 'Regenerate the XML sitemap.')]
  #[CLI\Option(name: 'all', description: 'Fix all detected issues.')]
  /**
   * Analyzes last results and applies fixes (e.g. regenerate sitemap).
   *
   * @param array $options
   *   Options: 'sitemap', 'all'.
   */
  public function fix(array $options = ['sitemap' => FALSE, 'all' => FALSE]): void {
    $this->io()->newLine();
    $this->io()->text('  <options=bold>Smoke — Fix</>');
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();

    $fixAll = (bool) $options['all'];
    $fixSitemap = (bool) $options['sitemap'];
    $didSomething = FALSE;

    // If no specific flag, analyze last results for failures.
    if (!$fixSitemap && !$fixAll) {
      $lastResults = $this->testRunner->getLastResults();
      if (!$lastResults || empty($lastResults['suites'])) {
        $this->io()->text('  No test results found. Run tests first:');
        $this->io()->text('    <options=bold>ddev drush smoke --run</>');
        $this->io()->newLine();
        return;
      }

      $this->io()->text('  Analyzing last test results...');
      $this->io()->newLine();

      // Check for sitemap failure.
      $sitemapSuite = $lastResults['suites']['sitemap'] ?? NULL;
      if ($sitemapSuite && ($sitemapSuite['failed'] ?? 0) > 0) {
        $fixSitemap = TRUE;
      }

      if (!$fixSitemap) {
        $this->io()->text('  <fg=green>✓</> No auto-fixable issues found.');
        $this->io()->newLine();
        return;
      }
    }

    // Fix: Regenerate sitemap.
    if ($fixSitemap || $fixAll) {
      $didSomething = TRUE;
      $this->fixSitemap();
    }

    if ($didSomething) {
      $this->io()->newLine();
      $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
      $this->io()->text('  <fg=green;options=bold>Done.</> Re-run tests to verify:');
      $this->io()->text('    <options=bold>ddev drush smoke --run</>');
      $this->io()->newLine();
    }
  }

  /**
   * Regenerates the XML sitemap.
   */
  private function fixSitemap(): void {
    $this->io()->text('  <fg=blue>▸</> Regenerating XML sitemap...');

    if ($this->moduleHandler->moduleExists('simple_sitemap') && $this->container->has('simple_sitemap.generator')) {
      try {
        /** @var \Drupal\simple_sitemap\Manager\Generator $generator */
        $generator = $this->container->get('simple_sitemap.generator');
        $generator->generate();
        $this->io()->text('    <fg=green>✓</> Sitemap regenerated (simple_sitemap).');
      }
      catch (\Exception $e) {
        $this->io()->text('    <fg=red>✕</> Failed: ' . $e->getMessage());
      }
    }
    elseif ($this->moduleHandler->moduleExists('xmlsitemap') && $this->container->has('xmlsitemap.generator')) {
      try {
        $this->container->get('xmlsitemap.generator')->regenerate();
        $this->io()->text('    <fg=green>✓</> Sitemap regenerated (xmlsitemap).');
      }
      catch (\Exception $e) {
        $this->io()->text('    <fg=red>✕</> Failed: ' . $e->getMessage());
      }
    }
    else {
      $this->io()->text('    <fg=yellow>⚠</> No sitemap module detected (simple_sitemap or xmlsitemap).');
    }
  }

}
