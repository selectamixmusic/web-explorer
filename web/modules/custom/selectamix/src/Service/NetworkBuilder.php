<?php

namespace Drupal\selectamix\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\node\NodeInterface;

/**
 * Serialises tunes, connections and routes for the explorer and the player.
 */
class NetworkBuilder {

  const CACHE_TAGS = ['node_list:tune', 'node_list:mix_connection', 'node_list:mix_route'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * All published tunes and connections.
   */
  public function network(): array {
    $storage = $this->entityTypeManager->getStorage('node');

    $tunes = [];
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'tune')->condition('status', 1)
      ->sort('title')->execute();
    foreach ($storage->loadMultiple($ids) as $node) {
      $tunes[] = $this->tune($node);
    }

    $connections = [];
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'mix_connection')->condition('status', 1)
      ->sort('nid')->execute();
    foreach ($storage->loadMultiple($ids) as $node) {
      if ($c = $this->connection($node)) {
        $connections[] = $c;
      }
    }

    return ['tunes' => $tunes, 'connections' => $connections];
  }

  /**
   * A route as a playable programme: tunes in order, connections between them.
   *
   * Returns NULL when the route's connections do not chain.
   */
  public function programme(NodeInterface $route): ?array {
    $tunes = [];
    $connections = [];
    $previous = NULL;
    foreach ($route->get('field_connections')->referencedEntities() as $connectionNode) {
      $c = $this->connection($connectionNode);
      if (!$c) {
        return NULL;
      }
      $from = $connectionNode->get('field_from_tune')->entity;
      $to = $connectionNode->get('field_to_tune')->entity;
      if ($previous === NULL) {
        $tunes[] = $this->tune($from);
      }
      elseif ($previous !== $c['from']) {
        return NULL;
      }
      $tunes[] = $this->tune($to);
      $connections[] = $c;
      $previous = $c['to'];
    }
    return [
      'id' => 'route-' . $route->id(),
      'nid' => (int) $route->id(),
      'title' => $route->label(),
      'tunes' => $tunes,
      'connections' => $connections,
    ];
  }

  public function tune(NodeInterface $node): array {
    return [
      'id' => 'tune-' . $node->id(),
      'nid' => (int) $node->id(),
      'title' => $node->label(),
      'artist' => $this->str($node, 'field_artist'),
      'version' => $this->str($node, 'field_version'),
      'bpm' => $this->num($node, 'field_bpm'),
      'key' => $this->str($node, 'field_key'),
      'durationSeconds' => $this->num($node, 'field_duration'),
      'firstDownbeatSeconds' => $this->num($node, 'field_first_downbeat'),
      'isrc' => $this->str($node, 'field_isrc'),
      'musicbrainzId' => $this->str($node, 'field_musicbrainz_id'),
      'audioUrl' => $this->fileUrl($node, 'field_audio'),
      'previewUrl' => $this->fileUrl($node, 'field_preview_clip'),
      'url' => $node->toUrl()->toString(TRUE)->getGeneratedUrl(),
    ];
  }

  public function connection(NodeInterface $node): ?array {
    $from = $node->get('field_from_tune')->target_id;
    $to = $node->get('field_to_tune')->target_id;
    if (!$from || !$to) {
      return NULL;
    }
    return [
      'id' => 'mix-' . $node->id(),
      'nid' => (int) $node->id(),
      'label' => $node->label(),
      'from' => 'tune-' . $from,
      'to' => 'tune-' . $to,
      'outCueBar' => $this->num($node, 'field_out_cue_bar'),
      'inCueBar' => $this->num($node, 'field_in_cue_bar'),
      'blendBars' => $this->num($node, 'field_blend_bars'),
      'tempoMaster' => $this->str($node, 'field_tempo_master'),
      'faderCurve' => $this->str($node, 'field_fader_curve'),
      'clipUrl' => $this->fileUrl($node, 'field_mix_clip'),
      'notes' => $this->str($node, 'field_notes'),
      'url' => $node->toUrl()->toString(TRUE)->getGeneratedUrl(),
    ];
  }

  protected function str(NodeInterface $node, string $field): ?string {
    return $node->hasField($field) ? $node->get($field)->value : NULL;
  }

  protected function num(NodeInterface $node, string $field): ?float {
    $value = $this->str($node, $field);
    return $value === NULL || $value === '' ? NULL : (float) $value;
  }

  protected function fileUrl(NodeInterface $node, string $field): ?string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    $file = $node->get($field)->entity;
    return $file ? $this->fileUrlGenerator->generateString($file->getFileUri()) : NULL;
  }

}
