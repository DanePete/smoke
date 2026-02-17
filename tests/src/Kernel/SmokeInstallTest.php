<?php

declare(strict_types=1);

namespace Drupal\Tests\smoke\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Tests module install and uninstall hooks.
 *
 * @group smoke
 */
final class SmokeInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'smoke',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['smoke']);

    // KernelTestBase only loads .module files automatically. The .install
    // file must be loaded explicitly so smoke_install() is available.
    module_load_install('smoke');

    // Run the install hook to create the smoke_bot user and role.
    smoke_install();
  }

  /**
   * Tests that the smoke_bot role is created on install.
   */
  public function testInstallCreatesRole(): void {
    $role = Role::load('smoke_bot');
    $this->assertNotNull($role, 'smoke_bot role should be created on install.');
    $this->assertTrue($role->hasPermission('access content'));
    $this->assertTrue($role->hasPermission('create page content'));
  }

  /**
   * Tests that the smoke_bot user is created on install.
   */
  public function testInstallCreatesUser(): void {
    $uid = \Drupal::state()->get('smoke.bot_uid');
    $this->assertNotNull($uid, 'smoke.bot_uid state should be set.');

    $user = User::load($uid);
    $this->assertNotNull($user, 'smoke_bot user should exist.');
    $this->assertSame('smoke_bot', $user->getAccountName());
    $this->assertTrue($user->isActive());
    $this->assertTrue($user->hasRole('smoke_bot'));
  }

  /**
   * Tests that the bot password is stored in state.
   */
  public function testInstallStoresPassword(): void {
    $password = \Drupal::state()->get('smoke.bot_password');
    $this->assertNotNull($password, 'smoke.bot_password state should be set.');
    $this->assertSame(32, strlen($password), 'Password should be 32 hex chars (16 random bytes).');
  }

  /**
   * Tests that default config is installed with expected values.
   */
  public function testDefaultConfig(): void {
    $config = \Drupal::config('smoke.settings');

    $suites = $config->get('suites');
    $this->assertIsArray($suites);
    $this->assertTrue($suites['core_pages']);
    $this->assertTrue($suites['auth']);
    $this->assertTrue($suites['health']);

    $this->assertSame(30000, $config->get('timeout'));
    $this->assertSame([], $config->get('custom_urls'));
  }

  /**
   * Tests that uninstall removes the user, role, and state.
   */
  public function testUninstallCleansUp(): void {
    $uid = \Drupal::state()->get('smoke.bot_uid');

    // Run the uninstall hook (loaded via module_load_install in setUp).
    smoke_uninstall();

    // User should be deleted.
    $user = User::load($uid);
    $this->assertNull($user, 'smoke_bot user should be deleted on uninstall.');

    // Role should be deleted.
    $role = Role::load('smoke_bot');
    $this->assertNull($role, 'smoke_bot role should be deleted on uninstall.');

    // State should be cleaned up.
    $this->assertNull(\Drupal::state()->get('smoke.bot_password'));
    $this->assertNull(\Drupal::state()->get('smoke.bot_uid'));
    $this->assertNull(\Drupal::state()->get('smoke.last_results'));
    $this->assertNull(\Drupal::state()->get('smoke.last_run'));
  }

}
