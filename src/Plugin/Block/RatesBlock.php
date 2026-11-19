<?php

declare(strict_types=1);

namespace Drupal\satelit\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * BNM official exchange rates (GET /rates, 1 credit, cached 3 h).
 */
#[Block(
  id: 'satelit_rates',
  admin_label: new TranslatableMarkup('Satelit: BNM exchange rates'),
  category: new TranslatableMarkup('Satelit'),
)]
final class RatesBlock extends SatelitBlockBase {

  protected const TTL = 10800;
  protected const KIND = 'rates';

  public function defaultConfiguration(): array {
    return ['codes' => 'EUR,USD,RON'] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form['codes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currencies'),
      '#description' => $this->t('Codes in order, separated by commas: EUR,USD,RON,UAH,RUB…'),
      '#default_value' => $this->configuration['codes'],
      '#maxlength' => 120,
    ];
    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['codes'] = strtoupper((string) preg_replace('/[^A-Za-z,]/', '', (string) $form_state->getValue('codes')));
  }

  protected function route(): array {
    return ['/rates', []];
  }

  protected function render(array $res): array {
    $all = [];
    foreach ((array) $res['data'] as $r) {
      if (isset($r['code'], $r['value'])) {
        $all[strtoupper($r['code'])] = $r;
      }
    }

    $rows = [];
    $date = '';
    $codes = array_filter(explode(',', (string) $this->configuration['codes'])) ?: array_keys($all);
    foreach ($codes as $code) {
      $r = $all[strtoupper(trim($code))] ?? NULL;
      if (!$r) {
        continue;
      }
      $change = isset($r['change_7d_pct']) ? (float) $r['change_7d_pct'] : NULL;
      $rows[] = [
        'code' => ((int) ($r['nominal'] ?? 1) > 1 ? (int) $r['nominal'] . ' ' : '') . $r['code'],
        'name' => (string) ($r['name'] ?? ''),
        'value' => $this->num($r['value'], 4),
        'change' => $change === NULL ? '–' : ($change > 0 ? '+' : ($change < 0 ? '−' : '')) . $this->num(abs($change), 1) . '%',
        'trend' => $change === NULL || $change == 0 ? 'same' : ($change > 0 ? 'up' : 'down'),
      ];
      $date = $r['date'] ?? $date;
    }

    return $rows ? ['#theme' => 'satelit_rates', '#rows' => $rows, '#date' => self::date($date)] : [];
  }

}
