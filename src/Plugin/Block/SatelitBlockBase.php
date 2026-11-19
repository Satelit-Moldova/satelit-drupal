<?php

declare(strict_types=1);

namespace Drupal\satelit\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\satelit\SatelitClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared parts of the Satelit blocks: the client, cache tags and max-age,
 * the attribution line every block carries.
 */
abstract class SatelitBlockBase extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Seconds a copy counts as fresh, also the block's render max-age.
   */
  protected const TTL = 3600;

  /**
   * Cache tag of this kind of data, invalidated by cron (see satelit_cron()).
   */
  protected const KIND = 'satelit';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected readonly SatelitClient $client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('satelit.client'));
  }

  /**
   * The block's render array from one API answer; empty array = no block.
   */
  abstract protected function render(array $res): array;

  /**
   * The route and query this block reads.
   *
   * @return array{0: string, 1: array}
   */
  abstract protected function route(): array;

  public function build(): array {
    [$path, $query] = $this->route();
    $res = $this->client->get($path, $query, static::TTL);
    $build = $res ? $this->render($res) : [];

    if ($build) {
      $build['#source'] = $this->source($res['meta'] ?? []);
      $build['#attached']['library'][] = 'satelit/blocks';
    }

    // An empty block is cached for a short time only, so it comes back soon
    // after the key is fixed or the API is back.
    $build['#cache'] = [
      'tags' => ['satelit:' . static::KIND],
      'max-age' => $build ? $this->getCacheMaxAge() : 300,
      'contexts' => $this->getCacheContexts(),
    ];

    return $build;
  }

  public function getCacheMaxAge(): int {
    return static::TTL;
  }

  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['satelit:' . static::KIND]);
  }

  public function getCacheContexts(): array {
    // The data language follows the page language.
    return Cache::mergeContexts(parent::getCacheContexts(), ['languages:language_content']);
  }

  /**
   * Attribution (when the API gives one) and the "Data: Satelit" link.
   */
  protected function source(array $meta): array {
    return [
      'attribution' => is_string($meta['attribution'] ?? NULL) ? $meta['attribution'] : '',
      'label' => $this->t('Data: Satelit'),
      'url' => 'https://satelit.md',
    ];
  }

  /**
   * 2026-11-19 → 19.11.2026.
   */
  protected static function date(?string $iso): string {
    return $iso && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3].$m[2].$m[1]" : '';
  }

  /**
   * Only http(s) links from the API's data reach the page.
   */
  protected static function link(?string $url): string {
    return $url && preg_match('#^https?://#i', $url) ? $url : '';
  }

  /**
   * Decimal comma for Romanian and Russian pages.
   */
  protected function num(float|int|string $v, int $decimals): string {
    $sep = in_array($this->client->lang(), ['ro', 'ru', 'uk'], TRUE) ? ',' : '.';
    return number_format((float) $v, $decimals, $sep, '');
  }

}
