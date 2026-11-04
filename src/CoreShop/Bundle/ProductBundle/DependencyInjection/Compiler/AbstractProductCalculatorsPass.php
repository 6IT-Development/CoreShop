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

namespace CoreShop\Bundle\ProductBundle\DependencyInjection\Compiler;

use CoreShop\Component\Product\Calculator\CompositeDiscountCalculator;
use CoreShop\Component\Product\Calculator\CompositeDiscountPriceCalculator;
use CoreShop\Component\Product\Calculator\CompositeProductCustomAttributesCalculator;
use CoreShop\Component\Product\Calculator\CompositeRetailPriceCalculator;
use CoreShop\Component\Product\Calculator\ProductPriceCalculatorInterface;
use CoreShop\Component\Registry\RegisterSimpleRegistryTypePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers product calculator strategies, but never the price calculator facade,
 * the composites or decorators. Those implement the strategy interfaces as well,
 * so interface autoconfiguration tags them, and registering them into a composite
 * would make every price lookup recurse endlessly.
 */
abstract class AbstractProductCalculatorsPass extends RegisterSimpleRegistryTypePass
{
    private const EXCLUDED_CLASSES = [
        ProductPriceCalculatorInterface::class,
        CompositeRetailPriceCalculator::class,
        CompositeDiscountPriceCalculator::class,
        CompositeDiscountCalculator::class,
        CompositeProductCustomAttributesCalculator::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds($this->tag)) as $id) {
            $definition = $container->findDefinition($id);

            if ($this->isExcluded($container, $definition->getClass(), null !== $definition->getDecoratedService())) {
                $definition->clearTag($this->tag);
            }
        }

        parent::process($container);
    }

    private function isExcluded(ContainerBuilder $container, ?string $class, bool $isDecorator): bool
    {
        if ($isDecorator) {
            return true;
        }

        if (null === $class) {
            return false;
        }

        $class = $container->getParameterBag()->resolveValue($class);

        if (!is_string($class) || !class_exists($class)) {
            return false;
        }

        foreach (self::EXCLUDED_CLASSES as $excludedClass) {
            if (is_a($class, $excludedClass, true)) {
                return true;
            }
        }

        return false;
    }
}
