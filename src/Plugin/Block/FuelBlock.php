<?php

declare(strict_types=1);

namespace Drupal\satelit\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Fuel prices: range, ANRE cap, cheapest networks (GET /fuel, 1 credit, 2 h).
 */
#[Block(
  id: 'satelit_fuel',
  admin_label: new TranslatableMarkup('Satelit: fuel prices'),
  category: new TranslatableMarkup('Satelit'),
)]
final class FuelBlock extends SatelitBlockBase {

  protected const TTL = 7200;
  protected const KIND = 'fuel';

  public function defaultConfiguration(): array {
    return ['networks' => 5] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form['networks'] = [
      '#type' => 'number',
      '#title' => $this->t('Cheapest networks listed'),
      '#min' => 0,
      '#max' => 20,
      '#default_value' => $this->configuration['networks'],
    ];
    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['networks'] = max(0, min(20, (int) $form_state->getValue('networks')));
  }

  protected function route(): array {
    return ['/fuel', []];
  }

  protected function render(array $res): array {
    $d = (array) $res['data'];
    if (empty($d['range'])) {
      return [];
    }

    $labels = ['a95' => $this->t('Petrol A95'), 'diesel' => $this->t('Diesel'), 'lpg' => $this->t('LPG')];
    $rows = [];
    foreach ($labels as $fuel => $label) {
      if (isset($d['range'][$fuel]['min'])) {
        $rows[] = [
          'label' => $label,
          'min' => $this->num($d['range'][$fuel]['min'], 2),
          'max' => $this->num($d['range'][$fuel]['max'], 2),
          'cap' => isset($d['cap'][$fuel]) ? $this->num($d['cap'][$fuel], 2) : '–',
        ];
      }
    }

    // Cheapest A95 among networks with fresh prices.
    $nets = array_values(array_filter((array) ($d['networks'] ?? []), fn($n) => empty($n['stale']) && isset($n['prices']['a95'], $n['name'])));
    usort($nets, fn($a, $b) => $a['prices']['a95'] <=> $b['prices']['a95']);
    $cheapest = array_map(
      fn($n) => ['name' => (string) $n['name'], 'price' => $this->num($n['prices']['a95'], 2)],
      array_slice($nets, 0, (int) $this->configuration['networks'])
    );

    return [
      '#theme' => 'satelit_fuel',
      '#rows' => $rows,
      '#cheapest' => $cheapest,
      '#date' => self::date($d['date'] ?? ''),
    ];
  }

}
