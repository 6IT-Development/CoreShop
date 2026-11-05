<?php

declare(strict_types=1);

/*
 * CoreShop
 *
 * This source file is available under two different licenses:
 *  - GNU General Public License version 3 (GPLv3)
 *  - CoreShop Commercial License (CCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.com)
 * @license    https://www.coreshop.com/license     GPLv3 and CCL
 *
 */

namespace CoreShop\Bundle\FrontendBundle\DependencyInjection;

use CoreShop\Bundle\FrontendBundle\Controller\CartController;
use CoreShop\Bundle\FrontendBundle\Controller\CategoryController;
use CoreShop\Bundle\FrontendBundle\Controller\CheckoutController;
use CoreShop\Bundle\FrontendBundle\Controller\CurrencyController;
use CoreShop\Bundle\FrontendBundle\Controller\CustomerController;
use CoreShop\Bundle\FrontendBundle\Controller\IndexController;
use CoreShop\Bundle\FrontendBundle\Controller\MailController;
use CoreShop\Bundle\FrontendBundle\Controller\OrderController;
use CoreShop\Bundle\FrontendBundle\Controller\ProductController;
use CoreShop\Bundle\FrontendBundle\Controller\QuoteController;
use CoreShop\Bundle\FrontendBundle\Controller\RegisterController;
use CoreShop\Bundle\FrontendBundle\Controller\SearchController;
use CoreShop\Bundle\FrontendBundle\Controller\SecurityController;
use CoreShop\Bundle\PayumBundle\Controller\PaymentController;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * Controller key => default controller class. The class is also the
     * service id the routes use, see RegisterFrontendControllerPass.
     */
    public const DEFAULT_CONTROLLERS = [
        'index' => IndexController::class,
        'register' => RegisterController::class,
        'customer' => CustomerController::class,
        'currency' => CurrencyController::class,
        'search' => SearchController::class,
        'cart' => CartController::class,
        'checkout' => CheckoutController::class,
        'order' => OrderController::class,
        'category' => CategoryController::class,
        'product' => ProductController::class,
        'quote' => QuoteController::class,
        'security' => SecurityController::class,
        'payment' => PaymentController::class,
        'mail' => MailController::class,
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('core_shop_frontend');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('view_suffix')->defaultValue('twig')->end()
                ->scalarNode('view_bundle')->setDeprecated('coreshop/frontend-bundle', '4.1', 'Use view_prefix instead')->defaultNull()->end()
                ->scalarNode('view_prefix')->defaultNull()->end()
            ->end()
        ;

        $this->addCategorySection($rootNode);
        $this->addPimcoreResourcesSection($rootNode);
        $this->addControllerSection($rootNode);

        return $treeBuilder;
    }

    private function addCategorySection(ArrayNodeDefinition $node): void
    {
        $node->children()
            ->arrayNode('category')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('valid_sort_options')
                        ->defaultValue(['name'])
                        ->scalarPrototype()->end()
                    ->end()
                    ->scalarNode('default_sort_name')->defaultValue('name')->end()
                    ->scalarNode('default_sort_direction')
                        ->defaultValue('asc')
                        ->validate()
                            ->ifNotInArray(['asc', 'desc'])
                            ->thenInvalid('Supported values are asc, desc.')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    private function addControllerSection(ArrayNodeDefinition $node): void
    {
        $children = $node->children()
            ->arrayNode('controllers')
                ->addDefaultsIfNotSet()
                ->children()
        ;

        foreach (self::DEFAULT_CONTROLLERS as $key => $controllerClass) {
            $children->scalarNode($key)->defaultValue($controllerClass)->end();
        }
    }

    private function addPimcoreResourcesSection(ArrayNodeDefinition $node): void
    {
        $node->children()
            ->arrayNode('pimcore_admin')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('install')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->arrayNode('routes')
                                ->treatNullLike([])
                                ->scalarPrototype()->end()
                                ->defaultValue(['@CoreShopFrontendBundle/Resources/install/pimcore/staticroutes.yml'])
                            ->end()
                            ->arrayNode('documents')
                                ->treatNullLike([])
                                ->scalarPrototype()->end()
                                ->defaultValue(['@CoreShopFrontendBundle/Resources/install/pimcore/documents.yml'])
                            ->end()
                            ->arrayNode('image_thumbnails')
                                ->treatNullLike([])
                                ->scalarPrototype()->end()
                                ->defaultValue(['@CoreShopFrontendBundle/Resources/install/pimcore/image-thumbnails.yml'])
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end()
        ;
    }
}
