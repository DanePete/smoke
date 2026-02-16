<?php

declare(strict_types=1);

namespace Drupal\smoke\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\smoke\Service\ModuleDetector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Smoke test settings.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The module detector service.
   */
  private readonly ModuleDetector $moduleDetector;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->moduleDetector = $container->get('smoke.module_detector');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['smoke.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'smoke_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('smoke.settings');
    $enabledSuites = $config->get('suites') ?? [];
    $labels = ModuleDetector::suiteLabels();
    $detected = $this->moduleDetector->detect();

    // ── Suite toggles ──
    $form['suites'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Test Suites'),
      '#description' => $this->t('Enable or disable test suites. Auto-detected suites are enabled by default.'),
    ];

    foreach ($labels as $id => $label) {
      $isDetected = !empty($detected[$id]['detected']);
      $form['suites'][$id] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $enabledSuites[$id] ?? TRUE,
        '#description' => $isDetected
          ? $this->t('Detected on this site.')
          : $this->t('Not detected — module not installed.'),
        '#disabled' => !$isDetected,
      ];
    }

    // ── Webform (for Webform suite) ──
    $form['webform'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Webform test'),
      '#description' => $this->t('Choose which webform to use for the smoke test. Use the form machine name (e.g. <code>smoke_test</code>, <code>contact_us</code>). This lets you tailor the test to your agency or company form. Default <code>smoke_test</code> is auto-created if missing.'),
    ];
    $form['webform']['webform_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test webform ID'),
      '#default_value' => $config->get('webform_id') ?? 'smoke_test',
      '#required' => FALSE,
      '#maxlength' => 64,
      '#placeholder' => 'smoke_test',
      '#description' => $this->t('Machine name of the webform to load, fill, and submit. Use <code>smoke_test</code> for the default; or any existing webform (e.g. contact, quote request).'),
    ];

    // ── Custom URLs ──
    $form['custom_urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom URLs'),
      '#description' => $this->t('Additional URL paths to test (one per line). Each will be checked for HTTP 200 and no PHP errors. Example: <code>/about</code>'),
      '#default_value' => implode("\n", $config->get('custom_urls') ?? []),
      '#rows' => 5,
      '#placeholder' => "/about\n/pricing\n/contact",
    ];

    // ── Timeout ──
    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Test timeout (ms)'),
      '#description' => $this->t('Maximum time in milliseconds for each test. Default: 30000 (30 seconds).'),
      '#default_value' => $config->get('timeout') ?? 30000,
      '#min' => 5000,
      '#max' => 120000,
      '#step' => 1000,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $labels = ModuleDetector::suiteLabels();
    $suites = [];
    foreach (array_keys($labels) as $id) {
      $suites[$id] = (bool) $form_state->getValue($id);
    }

    // Normalize webform_id (machine name: lowercase, underscores only).
    $webformValues = $form_state->getValue('webform') ?? [];
    $webformIdRaw = (string) ($webformValues['webform_id'] ?? 'smoke_test');
    $webformId = $webformIdRaw !== '' ? strtolower(preg_replace('/[^a-z0-9_]/', '_', trim($webformIdRaw))) : 'smoke_test';
    if ($webformId === '') {
      $webformId = 'smoke_test';
    }

    // Parse custom URLs from textarea.
    $urlsRaw = (string) $form_state->getValue('custom_urls');
    $urls = array_values(array_filter(
      array_map('trim', explode("\n", $urlsRaw)),
      fn(string $url): bool => $url !== '' && str_starts_with($url, '/'),
    ));

    $this->config('smoke.settings')
      ->set('suites', $suites)
      ->set('webform_id', $webformId)
      ->set('custom_urls', $urls)
      ->set('timeout', (int) $form_state->getValue('timeout'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
