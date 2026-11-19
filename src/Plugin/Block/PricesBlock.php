<?php

declare(strict_types=1);

namespace Drupal\satelit\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\satelit\SatelitClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shop prices: products matching some words, each with its cheapest offer
 * and the shop's name (GET /prices/search 3 credits, /prices/items/{id}
 * 2 credits each; cached 3 h).
 *
 * Optionally visitors may search (?satelit_q=…), limited per IP by Drupal's
 * flood control so nobody can spend the site's credits.
 */
#[Block(
  id: 'satelit_prices',
  admin_label: new TranslatableMarkup('Satelit: shop prices'),
  category: new TranslatableMarkup('Satelit'),
)]
final class PricesBlock extends SatelitBlockBase {

  protected const TTL = 10800;
  protected const KIND = 'prices';

  // Visitor searches that reach the API, per IP per hour.
  private const FLOOD_LIMIT = 20;

  private ?string $query = NULL;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, SatelitClient $client, private readonly FloodInterface $flood, private readonly RequestStack $requests) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $client);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('satelit.client'), $container->get('flood'), $container->get('request_stack'));
  }

  public function defaultConfiguration(): array {
    return ['query' => 'lapte', 'count' => 3, 'visitors' => FALSE] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form['query'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search words'),
      '#description' => $this->t('Products whose shop title holds every word, e.g. "lapte 2.5%".'),
      '#default_value' => $this->configuration['query'],
      '#maxlength' => 100,
      '#required' => TRUE,
    ];
    $form['count'] = [
      '#type' => 'number',
      '#title' => $this->t('Products shown'),
      '#description' => $this->t('Each product costs 2 credits per refresh for its offers.'),
      '#min' => 1,
      '#max' => 5,
      '#default_value' => $this->configuration['count'],
    ];
    $form['visitors'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Visitors may search'),
      '#description' => $this->t('Shows a search field. At most @n new searches per visitor per hour reach the API.', ['@n' => self::FLOOD_LIMIT]),
      '#default_value' => $this->configuration['visitors'],
    ];
    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['query'] = trim((string) $form_state->getValue('query'));
    $this->configuration['count'] = max(1, min(5, (int) $form_state->getValue('count')));
    $this->configuration['visitors'] = (bool) $form_state->getValue('visitors');
  }

  public function getCacheContexts(): array {
    $contexts = parent::getCacheContexts();
    return $this->configuration['visitors'] ? Cache::mergeContexts($contexts, ['url.query_args:satelit_q']) : $contexts;
  }

  protected function route(): array {
    return ['/prices/search', ['q' => $this->query()]];
  }

  public function build(): array {
    $build = parent::build();
    if ($this->configuration['visitors']) {
      // The search field shows even when nothing was found.
      $build['#unavailable'] = empty($build['#theme']);
      $build['#theme'] ??= 'satelit_prices';
      $build['#items'] ??= [];
      $build['#source'] ??= $this->source([]);
      $build['#attached']['library'][] = 'satelit/blocks';
      $build['#search'] = ['name' => 'satelit_q', 'value' => $this->query(), 'label' => $this->t('Search prices')];
    }
    return $build;
  }

  protected function render(array $res): array {
    $items = [];
    foreach (array_slice((array) $res['data'], 0, (int) $this->configuration['count']) as $p) {
      if (empty($p['id']) || empty($p['name'])) {
        continue;
      }
      $item = $this->client->get('/prices/items/' . (int) $p['id'], [], static::TTL);
      // Cheapest offer in stock; when none is in stock, the cheapest seen.
      $offers = array_values(array_filter((array) ($item['data']['offers'] ?? []), fn($o) => isset($o['price'], $o['store']['name'])));
      usort($offers, fn($a, $b) => [empty($a['in_stock']), $a['price']] <=> [empty($b['in_stock']), $b['price']]);
      $best = $offers[0] ?? NULL;
      $items[] = [
        'name' => (string) $p['name'],
        'min' => $this->num($best['price'] ?? $p['min_price'], 2),
        'shop' => $best ? (string) $best['store']['name'] : '',
        'url' => $best ? self::link($best['url'] ?? NULL) : '',
        'shops' => $this->formatPlural((int) ($p['stores'] ?? 0), '1 shop', '@count shops'),
      ];
    }
    return $items ? ['#theme' => 'satelit_prices', '#items' => $items, '#query' => $this->query()] : [];
  }

  /**
   * The words searched: the block's, or a visitor's within the flood limit.
   */
  private function query(): string {
    return $this->query ??= $this->pickQuery();
  }

  private function pickQuery(): string {
    $default = (string) $this->configuration['query'];
    if (!$this->configuration['visitors']) {
      return $default;
    }
    $asked = trim((string) $this->requests->getCurrentRequest()?->query->get('satelit_q', ''));
    $asked = mb_substr(preg_replace('/\s+/u', ' ', $asked) ?? '', 0, 100);
    if (mb_strlen($asked) < 2 || $asked === $default) {
      return $default;
    }
    if (!$this->flood->isAllowed('satelit.prices_search', self::FLOOD_LIMIT, 3600)) {
      return $default;
    }
    $this->flood->register('satelit.prices_search', 3600);
    return $asked;
  }

}
