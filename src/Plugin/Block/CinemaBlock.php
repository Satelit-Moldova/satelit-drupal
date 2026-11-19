<?php

declare(strict_types=1);

namespace Drupal\satelit\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Cinema today: films and session times (GET /movies/cinemas/today).
 *
 * A call costs 40 credits, so the answer is kept 3 h; the rendered block
 * lives 15 min, so sessions already started drop off during the day.
 */
#[Block(
  id: 'satelit_cinema',
  admin_label: new TranslatableMarkup('Satelit: cinema today'),
  category: new TranslatableMarkup('Satelit'),
)]
final class CinemaBlock extends SatelitBlockBase {

  protected const TTL = 10800;
  protected const KIND = 'cinema';

  public function defaultConfiguration(): array {
    return ['city' => 'Chișinău', 'posters' => TRUE] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form['city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#description' => $this->t('As written in Romanian: Chișinău, Bălți. Empty = the whole country.'),
      '#default_value' => $this->configuration['city'],
      '#maxlength' => 60,
    ];
    $form['posters'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show posters'),
      '#default_value' => $this->configuration['posters'],
    ];
    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['city'] = trim((string) $form_state->getValue('city'));
    $this->configuration['posters'] = (bool) $form_state->getValue('posters');
  }

  public function getCacheMaxAge(): int {
    return 900;
  }

  protected function route(): array {
    $q = ['country' => 'MD'];
    if (trim((string) $this->configuration['city']) !== '') {
      $q['city'] = mb_substr(trim((string) $this->configuration['city']), 0, 60);
    }
    return ['/movies/cinemas/today', $q];
  }

  protected function render(array $res): array {
    $d = (array) $res['data'];
    $now = new \DateTime('now', new \DateTimeZone('Europe/Chisinau'));
    // Sessions are in Chișinău time; hide those already started today.
    $cut = ($d['day'] ?? '') === $now->format('Y-m-d') ? $now->format('H:i') : '';

    $cinemas = [];
    foreach ((array) ($d['cinemas'] ?? []) as $c) {
      $films = [];
      foreach ((array) ($c['films'] ?? []) as $f) {
        $times = [];
        foreach ((array) ($f['sessions'] ?? []) as $s) {
          if (empty($s['time']) || ($cut !== '' && $s['time'] < $cut) || !empty($s['sold_out'])) {
            continue;
          }
          $formats = (array) ($s['formats'] ?? []);
          $times[] = [
            'time' => (string) $s['time'],
            'format' => $formats && $formats !== ['2D'] ? implode(' ', $formats) : '',
            'url' => self::link($s['booking_url'] ?? NULL),
          ];
        }
        if ($times && !empty($f['title'])) {
          $films[] = [
            'title' => (string) $f['title'],
            'poster' => $this->configuration['posters'] ? self::link($f['pictures']['poster']['s'] ?? NULL) : '',
            'times' => $times,
          ];
        }
      }
      if ($films && !empty($c['cinema']['name'])) {
        $cinemas[] = ['name' => (string) $c['cinema']['name'], 'films' => $films];
      }
    }

    return ['#theme' => 'satelit_cinema', '#cinemas' => $cinemas, '#date' => self::date($d['day'] ?? '')];
  }

}
