<?php

namespace Drupal\selectamix\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\selectamix\Service\NetworkBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves the mix network as a page and as JSON.
 */
class NetworkController extends ControllerBase {

  public function __construct(protected NetworkBuilder $network) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('selectamix.network'));
  }

  /**
   * The explorer page.
   */
  public function page(): array {
    $network = $this->network->network();
    return [
      '#theme' => 'selectamix_network',
      '#tune_count' => count($network['tunes']),
      '#connection_count' => count($network['connections']),
      '#attached' => [
        'library' => ['selectamix/network'],
        'drupalSettings' => [
          'selectamix' => [
            'network' => $network,
            // 0 means no limit, matching PlayingTimeLimits in the Java client.
            'limits' => ['minTunes' => 0, 'maxTunes' => 0],
          ],
        ],
      ],
      '#cache' => ['tags' => NetworkBuilder::CACHE_TAGS],
    ];
  }

  /**
   * The network as JSON for other clients.
   */
  public function json(): CacheableJsonResponse {
    $response = new CacheableJsonResponse($this->network->network());
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags(NetworkBuilder::CACHE_TAGS));
    return $response;
  }

}
