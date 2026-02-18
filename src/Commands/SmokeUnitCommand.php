<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\Core\Extension\ModuleExtensionList;
use Drush\Commands\DrushCommands;
use Symfony\Component\Process\Process;

/**
 * Runs the module's own PHPUnit tests.
 */
final class SmokeUnitCommand extends DrushCommands {

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container): static {
    return new static(
      $container->get('extension.list.module'),
    );
  }

  /**
   * Run Smoke module PHPUnit tests.
   *
   * @command smoke:unit
   * @aliases sunit
   * @usage drush smoke:unit
   *   Run all Smoke PHPUnit tests.
   * @usage drush smoke:unit ModuleDetectorTest
   *   Run a specific test class by name.
   */
  public function unit(string $filter = ''): void {
    $modulePath = DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath('smoke');
    $phpunit = $this->findPhpUnit();

    if (!$phpunit) {
      $this->io()->error('PHPUnit not found. Install it: composer require --dev phpunit/phpunit');
      return;
    }

    if (!file_exists($modulePath . '/phpunit.xml.dist')) {
      $this->io()->error('phpunit.xml.dist not found in the Smoke module directory.');
      return;
    }

    $args = [$phpunit, '--colors=always'];

    if ($filter !== '') {
      $args[] = '--filter';
      $args[] = $filter;
    }

    $this->io()->title('Smoke — PHPUnit');
    $this->io()->text("  Running from: <comment>$modulePath</comment>");
    $this->io()->newLine();

    $process = new Process($args, $modulePath);
    $process->setTimeout(120);
    $process->setTty(Process::isTtySupported());

    $process->run(function (string $type, string $buffer): void {
      $this->output()->write($buffer);
    });

    $this->io()->newLine();

    if ($process->isSuccessful()) {
      $this->io()->success('All tests passed.');
    }
    else {
      $this->io()->error('Some tests failed (exit code ' . $process->getExitCode() . ').');
    }
  }

  /**
   * Locates the PHPUnit binary.
   */
  private function findPhpUnit(): ?string {
    $candidates = [
      DRUPAL_ROOT . '/../vendor/bin/phpunit',
      DRUPAL_ROOT . '/vendor/bin/phpunit',
    ];

    foreach ($candidates as $path) {
      if (file_exists($path) && is_executable($path)) {
        return $path;
      }
    }

    return NULL;
  }

}
