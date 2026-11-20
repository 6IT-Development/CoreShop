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

namespace CoreShop\Bundle\CoreBundle\DependencyInjection;

use CoreShop\Bundle\CoreBundle\Doctrine\ORM\ProductStoreValuesRepository;
use CoreShop\Component\Core\Model\ProductStoreValues;
use CoreShop\Component\Core\Model\ProductStoreValuesInterface;
use CoreShop\Component\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('core_shop_core');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('send_usage_log')->defaultValue(true)->end()
                ->scalarNode('checkout_manager_factory')->cannotBeEmpty()->end()
                ->scalarNode('after_logout_redirect_route')->defaultValue('coreshop_index')->cannotBeEmpty()->end()
                ->scalarNode('autoconfigure_with_attributes')->defaultFalse()->end()
            ->end()
        ;
        $this->addModelsSection($rootNode);
        $this->addPimcoreResourcesSection($rootNode);
        $this->addCheckoutConfigurationSection($rootNode);
        $this->addCartRecalculationSection($rootNode);

        return $treeBuilder;
    }

    private function addCartRecalculationSection(ArrayNodeDefinition $node): void
    {
        $node->children()
            ->arrayNode('cart_recalculation')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('lock')
                        ->info('One request per cart recalculates, a parallel one waits for it (Symfony Lock, lock.factory).')
                        ->defaultFalse()
                    ->end()
                    ->arrayNode('product_fields')
                        ->info('Product fields (with an object_store column, or relations) whose change makes every cart recalculate, besides the published state, the store values and the Doctrine pricing (price rules, unit prices). Fields the product class does not have are skipped.')
                        ->scalarPrototype()->end()
                        ->defaultValue(['taxRule', 'weight', 'itemQuantityFactor', 'digitalProduct', 'wholesaleBuyingPrice', 'categories', 'manufacturer'])
                    ->end()
                ->end()
            ->end()
            ->arrayNode('cart_availability')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('product_fields')
                        ->info('Product fields (with an object_store column) whose change asks CartProductAvailabilityCheckerInterface again, besides the published state.')
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                ->end()
            ->end()
        ->end();
    }

    private function addModelsSection(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('product_store_values')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('graphql')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                    ->end()
                                ->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(ProductStoreValues::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(ProductStoreValuesInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(ProductStoreValuesRepository::class)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    private function addPimcoreResourcesSection(ArrayNodeDefinition $node): void
    {
        $node->children()
            ->arrayNode('pimcore_admin')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('js')
                        ->useAttributeAsKey('name')
                        ->prototype('scalar')->end()
                    ->end()
                    ->arrayNode('css')
                        ->addDefaultsIfNotSet()
                        ->ignoreExtraKeys(false)
                        ->children()
                            ->scalarNode('core')->defaultValue('/bundles/coreshopcore/pimcore/css/core.css')->end()
                        ->end()
                    ->end()
                    ->scalarNode('permissions')
                        ->cannotBeOverwritten()
                        ->defaultValue(['settings', 'ctc_assign_to_new', 'ctc_assign_to_existing'])
                    ->end()
                    ->arrayNode('editmode_js')
                        ->useAttributeAsKey('name')
                        ->prototype('scalar')->end()
                    ->end()
                    ->arrayNode('editmode_css')
                        ->useAttributeAsKey('name')
                        ->prototype('scalar')->end()
                    ->end()
                ->end()
            ->end()
        ->end()
        ;
    }

    private function addCheckoutConfigurationSection(ArrayNodeDefinition $node): void
    {
        $node->children()
            ->arrayNode('checkout')
                ->isRequired()
                ->useAttributeAsKey('name')
                ->requiresAtLeastOneElement()
                ->arrayPrototype()
                    ->children()
                        ->arrayNode('steps')
                        ->useAttributeAsKey('identifier')
                            ->arrayPrototype()
                                ->canBeUnset(true)
                                ->children()
                                    ->scalarNode('step')->isRequired()->end()
                                    ->integerNode('priority')->isRequired()->end()
                                ->end()
                            ->end()
                            ->validate()
                                ->ifTrue(function (array $array) {
                                    $notValid = false;
                                    foreach ($array as $key => $value) {
                                        if ($key === 'cart') {
                                            $notValid = true;

                                            break;
                                        }
                                    }

                                    return $notValid;
                                })
                                ->thenInvalid('"cart" is a coreshop reserved checkout step. please use another name.')
                            ->end()
                        ->end()

                    ->end()
                ->end()
            ->end()
        ->end()
        ;
    }
}
