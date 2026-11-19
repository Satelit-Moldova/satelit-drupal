<?php

declare(strict_types=1);

namespace Drupal\Tests\satelit\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\satelit\SatelitClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;

/**
 * Offline tests of the client: caching, saved copies, pauses, the key.
 */
#[CoversClass(SatelitClient::class)]
#[Group('satelit')]
final class SatelitClientTest extends UnitTestCase {

  private int $now = 1795000000;
  private array $state = [];
  private array $history = [];
  public array $logs = [];
  private MockHandler $mock;

  private function client(array $settings = [], string $pageLang = 'ro'): SatelitClient {
    new Settings($settings);

    $this->mock = new MockHandler();
    $stack = HandlerStack::create($this->mock);
    $this->history = [];
    $stack->push(Middleware::history($this->history));

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn() => $this->now);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn($k, $d = NULL) => $this->state[$k] ?? $d);
    $state->method('set')->willReturnCallback(function ($k, $v) {
      $this->state[$k] = $v;
    });

    $lm = $this->createMock(LanguageManagerInterface::class);
    $lm->method('getCurrentLanguage')->willReturn(new Language(['id' => $pageLang]));

    $test = $this;
    $logger = new class($test) extends AbstractLogger {

      public function __construct(private SatelitClientTest $t) {}

      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->t->logs[] = strtr((string) $message, $context);
      }

    };

    return new SatelitClient(
      new Client(['handler' => $stack]),
      new MemoryBackend($time),
      $state,
      $this->getConfigFactoryStub(['satelit.settings' => ['lang' => '', 'base_url' => '']]),
      $logger,
      $lm,
      $time,
    );
  }

  private static function fixture(string $name): string {
    return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/' . $name . '.json');
  }

  public function testNoKeyMakesNoCall(): void {
    $c = $this->client();
    $this->assertNull($c->get('/rates'));
    $this->assertCount(0, $this->history);
    $this->assertSame('no_key', $c->lastStatus()['code']);
  }

  public function testCachesAndSendsTheKeyInAHeaderOnly(): void {
    $this->state[SatelitClient::STATE_KEY] = 'sat_live_test1234';
    $c = $this->client();
    $this->mock->append(new Response(200, [], self::fixture('rates')));

    $a = $c->get('/rates', [], 3600);
    $b = $c->get('/rates', [], 3600);

    $this->assertSame('EUR', $a['data'][0]['code']);
    $this->assertSame($a, $b);
    $this->assertCount(1, $this->history);
    $req = $this->history[0]['request'];
    $this->assertSame('Bearer sat_live_test1234', $req->getHeaderLine('Authorization'));
    $this->assertStringNotContainsString('sat_live', (string) $req->getUri());
    $this->assertStringContainsString('lang=ro', (string) $req->getUri());
    $this->assertLessThanOrEqual(10, $this->history[0]['options']['timeout']);
  }

  public function testSettingsKeyWinsOverTheForm(): void {
    $this->state[SatelitClient::STATE_KEY] = 'sat_live_form0000';
    $c = $this->client(['satelit_api_key' => 'sat_live_settings']);
    $this->assertSame('sat_live_settings', $c->key());
    $this->assertSame('settings', $c->keySource());
  }

  public function testBadKeyServesTheSavedCopyThenPauses(): void {
    $this->state[SatelitClient::STATE_KEY] = 'sat_live_test1234';
    $c = $this->client();
    $this->mock->append(new Response(200, [], self::fixture('fuel')));
    $c->get('/fuel', [], 60);

    $this->now += 120;
    $this->mock->append(new Response(401, [], '{"error":{"code":"key_invalid","message":"x","docs":"y"}}'));
    $res = $c->get('/fuel', [], 60);

    $this->assertArrayHasKey('range', $res['data'], 'the last good copy is served');
    $this->assertSame('key_invalid', $c->lastStatus()['code']);
    $this->assertStringContainsString('key_invalid', $this->logs[0]);
    $this->assertStringNotContainsString('sat_live', implode(' ', $this->logs));

    $c->get('/fuel', [], 60);
    $this->assertCount(2, $this->history, 'paused: no call');
  }

  public function testDeadApiNeverThrows(): void {
    $this->state[SatelitClient::STATE_KEY] = 'sat_live_test1234';
    $c = $this->client();
    $this->mock->append(new ConnectException('refused', new Request('GET', 'https://api.satelit.md/v1/news')));
    $this->assertNull($c->get('/news', ['per' => 5]));
    $this->assertSame('network', $c->lastStatus()['code']);
  }

  public function testLanguageFollowsThePage(): void {
    $this->state[SatelitClient::STATE_KEY] = 'sat_live_test1234';
    $this->assertSame('ru', $this->client([], 'ru')->lang());
    $this->assertSame('en', $this->client([], 'de')->lang());
  }

  public function testMeReportsTheErrorCode(): void {
    $c = $this->client();
    $this->mock->append(new Response(401, [], '{"error":{"code":"key_invalid"}}'));
    $this->assertSame(['ok' => FALSE, 'code' => 'key_invalid', 'data' => NULL], $c->me('sat_live_bad00000'));
  }

}
