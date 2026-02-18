<?php

declare(strict_types=1);

namespace Drupal\smoke;

/**
 * Constants used throughout the Smoke module.
 */
final class SmokeConstants {

  /**
   * The username for the test bot account.
   */
  public const BOT_USERNAME = 'smoke_bot';

  /**
   * The role ID for the test bot.
   */
  public const BOT_ROLE = 'smoke_bot';

  /**
   * The config name for module settings.
   */
  public const CONFIG_NAME = 'smoke.settings';

  /**
   * State key for the bot password.
   */
  public const STATE_BOT_PASSWORD = 'smoke.bot_password';

  /**
   * State key for the bot user ID.
   */
  public const STATE_BOT_UID = 'smoke.bot_uid';

  /**
   * State key for last test results.
   */
  public const STATE_LAST_RESULTS = 'smoke.last_results';

  /**
   * State key for last run timestamp.
   */
  public const STATE_LAST_RUN = 'smoke.last_run';

  /**
   * Default test timeout in milliseconds.
   */
  public const DEFAULT_TIMEOUT = 30000;

  /**
   * Default webform ID for testing.
   */
  public const DEFAULT_WEBFORM_ID = 'smoke_test';

  /**
   * Quick mode suites - minimal tests for fast verification.
   */
  public const QUICK_MODE_SUITES = [
    'core_pages',
    'auth',
  ];

  /**
   * Exit codes for CLI.
   */
  public const EXIT_SUCCESS = 0;
  public const EXIT_FAILURE = 1;
  public const EXIT_SETUP_REQUIRED = 2;

  /**
   * Error codes for structured error handling.
   */
  public const ERROR_PLAYWRIGHT_NOT_SETUP = 'PLAYWRIGHT_NOT_SETUP';
  public const ERROR_BROWSER_LAUNCH_FAILED = 'BROWSER_LAUNCH_FAILED';
  public const ERROR_CONFIG_MISSING = 'CONFIG_MISSING';
  public const ERROR_INVALID_SUITE = 'INVALID_SUITE';
  public const ERROR_TIMEOUT = 'TIMEOUT';

  /**
   * Private constructor to prevent instantiation.
   */
  private function __construct() {}

}
