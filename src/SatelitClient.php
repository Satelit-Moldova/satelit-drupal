<?php

declare(strict_types=1);

namespace Drupal\satelit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Cached GET calls to the Satelit API that never throw.
 *
 * - A fresh copy is served without a call (TTL per route, set by the caller).
 * - On any failure the last good copy (kept 7 days) is served and calls
 *   pause for 5 minutes (or the API's Retry-After), so a dead API never
 *   slows every page by the timeout.
 * - The key comes from settings.php ($settings['satelit_api_key']) or from
 *   State (the settings form). Neither is exported with configuration.
 */
final class SatelitClient {

  public const VERSION = '1.0.0';
  public const DEFAULT_BASE = 'https://api.satelit.md/v1';
  public const TIMEOUT = 6;
  public const KEEP = 604800;
  public const PAUSE = 300;
  public const STATE_KEY = 'satelit.api_key';
  public const STATE_STATUS = 'satelit.last_status';

  public function __construct(
    private readonly ClientInterface $http,
    private readonly CacheBackendInterface $cache,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly LanguageManagerInterface $languageManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The key in use: settings.php wins over the form.
   */
  public function key(): string {
    $fromSettings = (string) Settings::get('satelit_api_key', '');
    return trim($fromSettings !== '' ? $fromSettings : (string) $this->state->get(self::STATE_KEY, ''));
  }

  /**
   * Where the key comes from: 'settings', 'form' or ''.
   */
  public function keySource(): string {
    if ((string) Settings::get('satelit_api_key', '') !== '') {
      return 'settings';
    }
    return (string) $this->state->get(self::STATE_KEY, '') !== '' ? 'form' : '';
  }

  /**
   * GET a route. Returns ['data' => ..., 'meta' => [...]] or NULL.
   */
  public function get(string $path, array $query = [], int $ttl = 3600): ?array {
    $now = $this->time->getRequestTime();
    $query['lang'] = $this->lang();
    ksort($query);
    $rel = $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $cid = 'r:' . hash('sha256', $rel);

    $hit = $this->cache->get($cid);
    $hit = ($hit && is_array($hit->data) && isset($hit->data['exp'], $hit->data['v'])) ? $hit->data : NULL;

    if ($hit && $hit['exp'] > $now) {
      return $hit['v'];
    }

    $old = $hit['v'] ?? NULL;
    $key = $this->key();

    if ($key === '') {
      $this->status('no_key', 0, $now);
      return $old;
    }

    $pause = $this->cache->get('pause');
    if ($pause && (int) $pause->data > $now) {
      return $old;
    }

    $code = 0;
    $body = '';
    $retry = '';

    try {
      $res = $this->http->request('GET', $this->base() . $rel, [
        'headers' => $this->headers($key),
        'timeout' => self::TIMEOUT,
        'connect_timeout' => 3,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
      $code = $res->getStatusCode();
      $body = (string) $res->getBody();
      $retry = $res->getHeaderLine('Retry-After');
    }
    catch (\Throwable $e) {
      $code = 0;
    }

    $json = json_decode($body, TRUE);

    if ($code === 200 && is_array($json) && array_key_exists('data', $json)) {
      $value = ['data' => $json['data'], 'meta' => is_array($json['meta'] ?? NULL) ? $json['meta'] : []];
      $this->cache->set($cid, ['exp' => $now + $ttl, 'v' => $value], $now + self::KEEP, ['satelit']);
      $this->status('ok', 200, $now);
      return $value;
    }

    $error = is_string($json['error']['code'] ?? NULL) ? $json['error']['code'] : ($code ? 'http_' . $code : 'network');
    $wait = is_numeric($retry) ? max(30, min(3600, (int) $retry)) : self::PAUSE;
    $this->cache->set('pause', $now + $wait, $now + $wait);
    $this->status($error, $code, $now);
    $this->logger->warning('Satelit @path: @error (HTTP @code)@old', [
      '@path' => $path,
      '@error' => $error,
      '@code' => $code,
      '@old' => $old ? ', saved copy shown' : '',
    ]);

    return $old;
  }

  /**
   * The key's plan and credits (GET /me, 0 credits). Admin only, not cached.
   *
   * @return array{ok: bool, code: string, data: ?array}
   */
  public function me(?string $key = NULL): array {
    $key = trim($key ?? $this->key());
    if ($key === '') {
      return ['ok' => FALSE, 'code' => 'no_key', 'data' => NULL];
    }
    try {
      $res = $this->http->request('GET', $this->base() . '/me', [
        'headers' => $this->headers($key),
        'timeout' => self::TIMEOUT,
        'connect_timeout' => 3,
        'http_errors' => FALSE,
      ]);
      $json = json_decode((string) $res->getBody(), TRUE);
      if ($res->getStatusCode() === 200 && isset($json['data'])) {
        return ['ok' => TRUE, 'code' => 'ok', 'data' => $json['data']];
      }
      return ['ok' => FALSE, 'code' => (string) ($json['error']['code'] ?? 'http_' . $res->getStatusCode()), 'data' => NULL];
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'code' => 'network', 'data' => NULL];
    }
  }

  /**
   * After the key or address changes: no pause left over, blocks rebuilt.
   * Saved copies stay, so they can still stand in if the new setting fails.
   */
  public function reset(): void {
    $this->cache->delete('pause');
    Cache::invalidateTags(['satelit:rates', 'satelit:fuel', 'satelit:news', 'satelit:cinema', 'satelit:prices']);
  }

  /**
   * The last call's outcome: ['code', 'http', 'at'] or NULL.
   */
  public function lastStatus(): ?array {
    $s = $this->state->get(self::STATE_STATUS);
    return is_array($s) ? $s : NULL;
  }

  /**
   * Data language: the saved choice, else the page language (ro, ru, uk), else en.
   */
  public function lang(): string {
    $saved = (string) $this->configFactory->get('satelit.settings')->get('lang');
    if (in_array($saved, ['ro', 'ru', 'en', 'uk'], TRUE)) {
      return $saved;
    }
    $page = substr($this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId(), 0, 2);
    return in_array($page, ['ro', 'ru', 'uk'], TRUE) ? $page : 'en';
  }

  /**
   * The API address: https only (http only for localhost tests).
   */
  public function base(): string {
    $saved = trim((string) $this->configFactory->get('satelit.settings')->get('base_url'));
    if ($saved !== '' && preg_match('#^(https://\S+|http://(127\.0\.0\.1|localhost)(:\d+)?/\S*)$#i', $saved)) {
      return rtrim($saved, '/');
    }
    return self::DEFAULT_BASE;
  }

  private function headers(string $key): array {
    return [
      'Authorization' => 'Bearer ' . $key,
      'Accept' => 'application/json',
      'User-Agent' => 'satelit-drupal/' . self::VERSION,
    ];
  }

  private function status(string $code, int $http, int $now): void {
    $last = $this->state->get(self::STATE_STATUS);
    // Same outcome written less than a minute ago: skip the write.
    if (is_array($last) && ($last['code'] ?? '') === $code && $now - (int) ($last['at'] ?? 0) < 60) {
      return;
    }
    $this->state->set(self::STATE_STATUS, ['code' => $code, 'http' => $http, 'at' => $now]);
  }

}
