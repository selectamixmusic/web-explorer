<?php

namespace Drupal\selectamix\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\selectamix\Service\NetworkBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lists mix routes and plays one.
 */
class RouteController extends ControllerBase {

  public function __construct(protected NetworkBuilder $network) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('selectamix.network'));
  }

  /**
   * All published routes with their tune chain.
   */
  public function list(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'mix_route')->condition('status', 1)
      ->sort('title')->execute();
    $routes = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $programme = $this->network->programme($node);
      $routes[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(TRUE)->getGeneratedUrl(),
        'play_url' => Url::fromRoute('selectamix.route_play', ['node' => $node->id()])->toString(TRUE)->getGeneratedUrl(),
        'tunes' => $programme ? array_map(fn($t) => trim(($t['artist'] ?? '') . ' – ' . $t['title'], ' –'), $programme['tunes']) : [],
        'playable' => $programme && !array_filter($programme['tunes'], fn($t) => empty($t['audioUrl'])),
        'broken' => $programme === NULL,
      ];
    }
    return [
      '#theme' => 'selectamix_routes',
      '#routes' => $routes,
      '#cache' => ['tags' => NetworkBuilder::CACHE_TAGS],
    ];
  }

  /**
   * The player page for one route.
   */
  public function play(NodeInterface $node): array {
    $programme = $this->programmeOr404($node);
    return [
      '#theme' => 'selectamix_route_player',
      '#route' => $node,
      '#programme' => $programme,
      '#attached' => [
        'library' => ['selectamix/route_player'],
        'drupalSettings' => ['selectamix' => ['programme' => $programme]],
      ],
      '#cache' => ['tags' => NetworkBuilder::CACHE_TAGS],
    ];
  }

  /**
   * Title callback for the player page.
   */
  public function playTitle(NodeInterface $node): string {
    return $this->t('Play @title', ['@title' => $node->label()]);
  }

  /**
   * The programme as JSON.
   */
  public function json(NodeInterface $node): CacheableJsonResponse {
    $response = new CacheableJsonResponse($this->programmeOr404($node));
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags(NetworkBuilder::CACHE_TAGS));
    return $response;
  }

  protected function programmeOr404(NodeInterface $node): array {
    if ($node->bundle() !== 'mix_route') {
      throw new NotFoundHttpException();
    }
    $programme = $this->network->programme($node);
    if ($programme === NULL) {
      throw new NotFoundHttpException('This route\'s connections do not chain from one tune to the next.');
    }
    return $programme;
  }

}
