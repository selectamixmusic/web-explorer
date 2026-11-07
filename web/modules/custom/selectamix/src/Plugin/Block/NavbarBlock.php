<?php

namespace Drupal\selectamix\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Site branding and the main menu in Mercury's navbar component.
 *
 * Mercury expects Drupal Canvas to fill its header; without Canvas, plain
 * blocks render unstyled. This block feeds the branding block and the main
 * menu into the theme's own `navbar` single-directory component. If Mercury
 * is not installed it falls back to the two plain render arrays.
 */
#[Block(
  id: 'selectamix_navbar',
  admin_label: new TranslatableMarkup('Selectamix navbar'),
  category: new TranslatableMarkup('Selectamix'),
)]
class NavbarBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected BlockManagerInterface $blockManager,
    protected MenuLinkTreeInterface $menuTree,
    protected ThemeHandlerInterface $themeHandler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.block'),
      $container->get('menu.link_tree'),
      $container->get('theme_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $brandingPlugin = $this->blockManager->createInstance('system_branding_block', [
      'label_display' => '0',
      'use_site_logo' => TRUE,
      'use_site_name' => FALSE,
      'use_site_slogan' => FALSE,
    ]);
    // Wrap the plugin output as a block so Mercury's
    // block--system-branding-block template (and core's preprocess that sets
    // site_logo / site_name) apply, exactly as for a placed block.
    $branding = [
      '#theme' => 'block',
      '#attributes' => [],
      '#configuration' => $brandingPlugin->getConfiguration(),
      '#plugin_id' => 'system_branding_block',
      '#base_plugin_id' => 'system_branding_block',
      '#derivative_plugin_id' => NULL,
      '#id' => 'selectamix_navbar_branding',
      'content' => $brandingPlugin->build(),
    ];

    $parameters = (new MenuTreeParameters())->setMaxDepth(2)->onlyEnabledLinks();
    $tree = $this->menuTree->load('main', $parameters);
    $tree = $this->menuTree->transform($tree, [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);
    $menu = $this->menuTree->build($tree);

    $build = [
      '#cache' => [
        'contexts' => ['route.menu_active_trails:main', 'user.permissions'],
        'tags' => ['config:system.menu.main', 'config:system.site'],
      ],
    ];

    if ($this->themeHandler->themeExists('mercury')) {
      $build['navbar'] = [
        '#type' => 'component',
        '#component' => 'mercury:navbar',
        '#props' => ['menu_align' => 'right'],
        '#slots' => [
          'logo' => $branding,
          'navigation' => $menu,
          'links' => [
            '#type' => 'component',
            '#component' => 'mercury:button',
            '#props' => [
              'variant' => 'primary',
              'label' => $this->t('Play a mix'),
              'href' => '/mix-routes',
              'size' => 'medium',
              'icon' => 'arrow-right',
            ],
          ],
        ],
      ];
    }
    else {
      $build['branding'] = $branding;
      $build['menu'] = $menu;
    }
    return $build;
  }

}
