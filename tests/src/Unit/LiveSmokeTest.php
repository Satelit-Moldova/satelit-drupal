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
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Opt-in live smoke test (2 credits), skipped without SATELIT_API_KEY:
 *   SATELIT_API_KEY=sat_live_… vendor/bin/phpunit -c web/core web/modules/contrib/satelit/tests/src/Unit/LiveSmokeTest.php
 */
#[Group('satelit_live')]
final class LiveSmokeTest extends UnitTestCase {

  public function testRatesAndFuel(): void {
    $key = getenv('SATELIT_API_KEY');
    if (!$key) {
      $this->markTestSkipped('SATELIT_API_KEY not set');
    }
    new Settings(['satelit_api_key' => $key]);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(time());
    $time->method('getCurrentTime')->willReturn(time());
    $lm = $this->createMock(LanguageManagerInterface::class);
    $lm->method('getCurrentLanguage')->willReturn(new Language(['id' => 'ro']));

    $client = new SatelitClient(
      new Client(),
      new MemoryBackend($time),
      $this->createMock(StateInterface::class),
      $this->getConfigFactoryStub(['satelit.settings' => ['lang' => '', 'base_url' => '']]),
      new NullLogger(),
      $lm,
      $time,
    );

    $rates = $client->get('/rates', [], 60);
    $this->assertNotNull($rates);
    $this->assertSame('Sursa: Banca Națională a Moldovei, bnm.md, CC BY 4.0', $rates['meta']['attribution']);
    $this->assertArrayHasKey('a95', $client->get('/fuel', [], 60)['data']['range']);
  }

}
