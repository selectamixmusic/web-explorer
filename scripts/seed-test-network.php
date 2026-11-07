<?php

/**
 * @file
 * Seeds the six-tune, eight-connection test network from the 2001 Java client
 * (MixNetworkDatabase::build) and the six routes from AudioPlayerViaURL.
 *
 * Run with: ddev drush php:script scripts/seed-test-network.php
 * Idempotent: looks tunes up by artist and title before creating.
 *
 * BPM values are placeholders to be corrected by ear; the Java client stored
 * none. Cue bars are left empty, as the Java test data had them all at zero.
 */

use Drupal\node\Entity\Node;

$tunes = [
  ['Pete Heller', 'Big Love', 128],
  ['Big Time Charlie', 'On The Run', 130],
  ['Masters At Work', 'To Be In Love', 126],
  ['M J Cole', 'Crazy Love', 134],
  ['Shanks And Bigfoot', 'Sweet Like Chocolate', 136],
  ['Doolally', 'Straight From The Heart', 134],
];

// [from, to, legacy clip id] — direction is outgoing tune -> incoming tune.
$mixes = [
  ['Pete Heller', 'Big Time Charlie', 1],
  ['Big Time Charlie', 'Masters At Work', 7],
  ['Masters At Work', 'M J Cole', 4],
  ['M J Cole', 'Shanks And Bigfoot', 3],
  ['Shanks And Bigfoot', 'Doolally', 6],
  ['Doolally', 'M J Cole', 5],
  ['Shanks And Bigfoot', 'Masters At Work', 2],
  ['Masters At Work', 'Pete Heller', 8],
];

// The pre-rendered full mixes the Java client could play, by artist order.
$routes = [
  ['Pete Heller', 'Big Time Charlie', 'Masters At Work', 'M J Cole'],
  ['Big Time Charlie', 'Masters At Work', 'M J Cole', 'Shanks And Bigfoot'],
  ['M J Cole', 'Shanks And Bigfoot', 'Masters At Work', 'Pete Heller'],
  ['Masters At Work', 'M J Cole', 'Shanks And Bigfoot', 'Doolally'],
  ['Shanks And Bigfoot', 'Masters At Work', 'Pete Heller', 'Big Time Charlie'],
  ['Doolally', 'M J Cole', 'Shanks And Bigfoot', 'Masters At Work'],
];

$storage = \Drupal::entityTypeManager()->getStorage('node');

function sm_find(string $type, array $conditions) {
  $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('type', $type);
  foreach ($conditions as $field => $value) {
    $query->condition($field, $value);
  }
  $ids = $query->execute();
  return $ids ? Node::load(reset($ids)) : NULL;
}

$tuneByArtist = [];
foreach ($tunes as [$artist, $title, $bpm]) {
  $node = sm_find('tune', ['field_artist' => $artist, 'title' => $title]);
  if (!$node) {
    $node = Node::create([
      'type' => 'tune',
      'title' => $title,
      'field_artist' => $artist,
      'field_bpm' => $bpm,
      'status' => 1,
    ]);
    $node->save();
    echo "Created tune: $artist – $title\n";
  }
  $tuneByArtist[$artist] = $node;
}

$mixByPair = [];
foreach ($mixes as [$from, $to, $clipId]) {
  $fromNode = $tuneByArtist[$from];
  $toNode = $tuneByArtist[$to];
  $node = sm_find('mix_connection', ['field_from_tune' => $fromNode->id(), 'field_to_tune' => $toNode->id()]);
  if (!$node) {
    $node = Node::create([
      'type' => 'mix_connection',
      'title' => 'pending',
      'field_from_tune' => $fromNode->id(),
      'field_to_tune' => $toNode->id(),
      'field_blend_bars' => 8,
      'field_tempo_master' => 'from',
      'field_fader_curve' => 'equal_power',
      'field_notes' => ['value' => "Legacy mix clip id $clipId from the 2001 Java client.", 'format' => 'plain_text'],
      'status' => 1,
    ]);
    $node->save();
    echo "Created connection: {$node->label()}\n";
  }
  $mixByPair["$from|$to"] = $node;
}

foreach ($routes as $i => $artists) {
  $n = $i + 1;
  $title = "Mix $n";
  if (sm_find('mix_route', ['title' => $title])) {
    continue;
  }
  $connections = [];
  for ($j = 0; $j < count($artists) - 1; $j++) {
    $key = $artists[$j] . '|' . $artists[$j + 1];
    if (!isset($mixByPair[$key])) {
      echo "Route $n: no connection for $key, skipping route\n";
      continue 2;
    }
    $connections[] = $mixByPair[$key]->id();
  }
  Node::create([
    'type' => 'mix_route',
    'title' => $title,
    'field_connections' => $connections,
    'field_notes' => ['value' => "Pre-rendered as realaudio/mix$n.rm in the 2001 Java client.", 'format' => 'plain_text'],
    'status' => 1,
  ])->save();
  echo "Created route: $title (" . implode(' → ', $artists) . ")\n";
}

echo "Seed complete.\n";
