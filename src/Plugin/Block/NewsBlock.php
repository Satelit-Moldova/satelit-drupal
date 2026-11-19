<?php

declare(strict_types=1);

namespace Drupal\satelit\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Latest news (GET /news, 2 credits, cached 15 min).
 */
#[Block(
  id: 'satelit_news',
  admin_label: new TranslatableMarkup('Satelit: latest news'),
  category: new TranslatableMarkup('Satelit'),
)]
final class NewsBlock extends SatelitBlockBase {

  protected const TTL = 900;
  protected const KIND = 'news';

  private const CATEGORIES = ['politics', 'economy', 'society', 'justice', 'health', 'education', 'infrastructure', 'incidents', 'tech_ai', 'world'];

  public function defaultConfiguration(): array {
    return ['count' => 5, 'category' => ''] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form['count'] = [
      '#type' => 'number',
      '#title' => $this->t('Stories'),
      '#min' => 1,
      '#max' => 20,
      '#default_value' => $this->configuration['count'],
    ];
    $form['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#options' => ['' => $this->t('All')] + array_combine(self::CATEGORIES, [
        $this->t('Politics'),
        $this->t('Economy and prices'),
        $this->t('Society'),
        $this->t('Justice and corruption'),
        $this->t('Health'),
        $this->t('Education'),
        $this->t('Roads and utilities'),
        $this->t('Incidents'),
        $this->t('Technology'),
        $this->t('World'),
      ]),
      '#default_value' => $this->configuration['category'],
    ];
    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['count'] = max(1, min(20, (int) $form_state->getValue('count')));
    $cat = (string) $form_state->getValue('category');
    $this->configuration['category'] = in_array($cat, self::CATEGORIES, TRUE) ? $cat : '';
  }

  protected function route(): array {
    $q = ['per' => max(1, min(20, (int) $this->configuration['count']))];
    if (in_array($this->configuration['category'], self::CATEGORIES, TRUE)) {
      $q['category'] = $this->configuration['category'];
    }
    return ['/news', $q];
  }

  protected function render(array $res): array {
    $items = [];
    foreach ((array) $res['data'] as $n) {
      if (empty($n['title'])) {
        continue;
      }
      $src = $n['sources'][0] ?? [];
      $items[] = [
        'title' => (string) $n['title'],
        'url' => self::link($src['url'] ?? NULL),
        'outlet' => (string) ($src['name'] ?? ''),
        'time' => isset($n['published_at']) ? substr((string) $n['published_at'], 11, 5) : '',
      ];
    }
    return $items ? ['#theme' => 'satelit_news', '#items' => $items] : [];
  }

}
