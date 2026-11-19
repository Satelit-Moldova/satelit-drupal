<?php

declare(strict_types=1);

namespace Drupal\satelit\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\satelit\SatelitClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings at /admin/config/services/satelit.
 *
 * The key is kept in State (never in exported configuration) and never sent
 * back to the browser: the field is always empty, an empty field keeps it.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config,
    private readonly StateInterface $state,
    private readonly SatelitClient $client,
  ) {
    parent::__construct($config_factory, $typed_config);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('state'),
      $container->get('satelit.client'),
    );
  }

  public function getFormId(): string {
    return 'satelit_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['satelit.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('satelit.settings');
    $source = $this->client->keySource();

    $form['key_state'] = [
      '#type' => 'item',
      '#title' => $this->t('API key status'),
      '#markup' => $this->keyState(),
    ];

    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Your Satelit key (sat_live_…), from the <a href=":console">console</a>. Kept on the server only (State, never in exported configuration). Leave empty to keep the saved key; type <code>-</code> to remove it. A key set in settings.php as <code>$settings[\'satelit_api_key\']</code> wins over this field.', [':console' => 'https://developer.satelit.md/en/console/']),
      '#attributes' => ['autocomplete' => 'new-password', 'spellcheck' => 'false', 'placeholder' => $source ? $this->t('A key is saved. Leave empty to keep it.') : 'sat_live_…'],
      '#disabled' => $source === 'settings',
    ];

    $form['lang'] = [
      '#type' => 'select',
      '#title' => $this->t('Data language'),
      '#description' => $this->t('Language of names and texts that come from the API (news, film titles).'),
      '#options' => [
        '' => $this->t('The page language (Romanian, Russian, Ukrainian; others get English)'),
        'ro' => 'Română',
        'ru' => 'Русский',
        'en' => 'English',
        'uk' => 'Українська',
      ],
      '#default_value' => (string) $config->get('lang'),
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#open' => (string) $config->get('base_url') !== '',
    ];
    $form['advanced']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API address'),
      '#description' => $this->t('Leave empty (@default). Only for tests against another address.', ['@default' => SatelitClient::DEFAULT_BASE]),
      '#default_value' => (string) $config->get('base_url'),
    ];

    $form['credits'] = [
      '#type' => 'item',
      '#title' => $this->t('Caching and credits'),
      '#markup' => $this->t('Answers are cached: rates 3 h, fuel 2 h, news 15 min, cinema 3 h (40 credits a call), prices 3 h. When the API cannot be reached the last saved copy is shown for up to 7 days. <a href=":pricing">Plans and credits</a>.', [':pricing' => 'https://developer.satelit.md/en/pricing/']),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $key = trim((string) $form_state->getValue('api_key'));
    if ($key !== '' && $key !== '-' && !preg_match('/^sat_[A-Za-z0-9_]{8,}$/', $key)) {
      $form_state->setErrorByName('api_key', $this->t('A Satelit key starts with sat_ and has no spaces.'));
    }
    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('satelit.settings')
      ->set('lang', (string) $form_state->getValue('lang'))
      ->set('base_url', trim((string) $form_state->getValue('base_url')))
      ->save();

    $key = trim((string) $form_state->getValue('api_key'));
    if ($key === '-') {
      $this->state->delete(SatelitClient::STATE_KEY);
    }
    elseif ($key !== '') {
      $this->state->set(SatelitClient::STATE_KEY, $key);
    }

    // New key or address: no pause left over from the old one.
    $this->client->reset();
    parent::submitForm($form, $form_state);

    if ($this->client->key() === '') {
      $this->messenger()->addWarning($this->t('No API key saved yet: Satelit blocks show nothing.'));
      return;
    }
    $me = $this->client->me();
    if ($me['ok']) {
      $this->messenger()->addStatus($this->okLine($me['data']));
    }
    else {
      $this->messenger()->addWarning($this->t('The API did not accept the key or could not be reached (@code). The site keeps working: blocks show the last saved copy or nothing.', ['@code' => $me['code']]));
    }
  }

  private function keyState(): string {
    $source = $this->client->keySource();
    if ($source === '') {
      return (string) $this->t('No API key saved yet: Satelit blocks show nothing.');
    }
    $me = $this->client->me();
    $where = $source === 'settings' ? $this->t('from settings.php') : $this->t('saved with this form');
    if (!$me['ok']) {
      return (string) $this->t('A key is saved (@where), but the API did not accept it or could not be reached (@code).', ['@where' => $where, '@code' => $me['code']]);
    }
    return $this->okLine($me['data']) . ' ' . $this->t('Key @prefix… (@where).', ['@prefix' => $me['data']['key']['prefix'] ?? '', '@where' => $where]);
  }

  private function okLine(array $me): string {
    $c = $me['credits'] ?? [];
    $left = max(0, (int) ($c['limit'] ?? 0) - (int) ($c['used'] ?? 0));
    return (string) $this->t('Key works: plan @plan, @left credits left this period.', [
      '@plan' => (string) ($me['plan'] ?? '?'),
      '@left' => number_format($left, 0, '', ' '),
    ]);
  }

}
