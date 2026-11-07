<?php

/**
 * @file
 * Attaches the synthetic test tunes to the seeded tune nodes and gives every
 * connection cue points, so the six seeded routes become playable.
 *
 * Run after scripts/generate-test-audio.py:
 *   ddev drush php:script scripts/seed-test-audio.php
 */

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

$dir = 'public://selectamix/tunes';
$manifestPath = \Drupal::service('file_system')->realpath("$dir/tunes.json");
if (!$manifestPath || !file_exists($manifestPath)) {
  echo "No tunes.json in $dir. Run scripts/generate-test-audio.py first.\n";
  return;
}
$manifest = json_decode(file_get_contents($manifestPath), TRUE);
$storage = \Drupal::entityTypeManager()->getStorage('node');

foreach ($manifest as $entry) {
  $ids = $storage->getQuery()->accessCheck(FALSE)
    ->condition('type', 'tune')
    ->condition('field_artist', $entry['artist'])
    ->condition('title', $entry['title'])
    ->execute();
  if (!$ids) {
    echo "No tune node for {$entry['artist']} – {$entry['title']}, skipping.\n";
    continue;
  }
  $node = Node::load(reset($ids));
  $uri = "$dir/{$entry['file']}";
  $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  $file = $existing ? reset($existing) : File::create(['uri' => $uri, 'status' => 1, 'uid' => 1]);
  $file->setPermanent();
  $file->save();
  $node->set('field_audio', ['target_id' => $file->id()]);
  $node->set('field_bpm', $entry['bpm']);
  $node->set('field_first_downbeat', $entry['firstDownbeatSeconds']);
  $node->set('field_duration', $entry['durationSeconds']);
  $node->save();
  echo "Attached {$entry['file']} to {$node->label()}\n";
}

// Cue points: leave bars 1-8 as an intro, mix out at bar 25 so each tune
// plays about 16 bars on its own. Vary tempo master and fader curve across
// the connections so every code path in the player gets exercised.
$variants = [
  ['from', 'equal_power'],
  ['from', 'linear'],
  ['to', 'equal_power'],
  ['midpoint', 'equal_power'],
  ['from', 'cut'],
];
$ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'mix_connection')->sort('nid')->execute();
$i = 0;
foreach ($storage->loadMultiple($ids) as $node) {
  [$master, $curve] = $variants[$i++ % count($variants)];
  $node->set('field_out_cue_bar', 25);
  $node->set('field_in_cue_bar', 9);
  $node->set('field_blend_bars', $curve === 'cut' ? 0 : 8);
  $node->set('field_tempo_master', $master);
  $node->set('field_fader_curve', $curve);
  $node->save();
  echo "Cued {$node->label()}: $master / $curve\n";
}
echo "Done.\n";
