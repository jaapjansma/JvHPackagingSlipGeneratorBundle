<?php
/**
 * Copyright (C) 2022  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace JvH\PackagingSlipGeneratorBundle\EventListener;

use Contao\CoreBundle\Event\MenuEvent;
use JvH\PackagingSlipGeneratorBundle\Controller\GeneratorController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Terminal42\ServiceAnnotationBundle\Annotation\ServiceTag;

/**
 * @ServiceTag("kernel.event_listener", event="contao.backend_menu_build", priority=-255)
 */
class BackendMenuListener {

  /**
   * @var \Symfony\Component\Routing\RouterInterface
   */
  protected $router;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  public function __construct(RouterInterface $router, RequestStack $requestStack)
  {
    $this->router = $router;
    $this->requestStack = $requestStack;
  }

  public function __invoke(MenuEvent $event): void
  {
    $factory = $event->getFactory();
    $tree = $event->getTree();

    if ('mainMenu' !== $tree->getName()) {
      return;
    }

    $contentNode = $tree->getChild('isotope');
    if ($contentNode) {

      $node = $factory
        ->createItem('jvh-packaging-slip-generate')
        ->setUri($this->router->generate(GeneratorController::class))
        ->setLabel('Generate Packaging Slip')
        ->setLinkAttribute('title', 'Generate Packaging Slip')
        ->setLinkAttribute('class', 'jvh-packaging-slip-generate')
        ->setCurrent($this->requestStack->getCurrentRequest()
            ->get('_controller') === GeneratorController::class);

      $contentNode->addChild($node);
    }
  }

}