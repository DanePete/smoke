<?php

/**
 * @file
 * Bootstrap for Smoke module PHPUnit tests.
 *
 * Registers the module's PSR-4 namespace so unit tests can run standalone
 * without the full Drupal bootstrap.
 */

declare(strict_types=1);

// Find the Composer autoloader by walking up from the tests/ directory.
// Module typically at web/modules/contrib/smoke (5 levels up) or
// modules/contrib/smoke (4 levels up).
$candidates = [
  dirname(__DIR__, 5) . '/vendor/autoload.php',
  dirname(__DIR__, 4) . '/vendor/autoload.php',
  dirname(__DIR__, 3) . '/vendor/autoload.php',
  dirname(__DIR__) . '/vendor/autoload.php',
];

$loader = NULL;
foreach ($candidates as $candidate) {
  if (file_exists($candidate)) {
    $loader = require $candidate;
    break;
  }
}

if (!$loader) {
  fwrite(STDERR, "Could not find Composer autoloader.\n");
  exit(1);
}

// Register the module's own PSR-4 namespace.
$loader->addPsr4('Drupal\\smoke\\', dirname(__DIR__) . '/src');
$loader->addPsr4('Drupal\\Tests\\smoke\\', dirname(__DIR__) . '/tests/src');
