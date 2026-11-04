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

namespace CoreShop\Bundle\ProductBundle\Form\Type\Unit;

use CoreShop\Component\Product\Model\ProductInterface;
use CoreShop\Component\Product\Model\ProductUnitDefinitionInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductUnitDefinitionsChoiceType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setRequired('product');
        $resolver->setAllowedTypes('product', ProductInterface::class);

        $resolver->setDefaults([
            'placeholder' => false,
            'choices' => static function (Options $options): iterable {
                /** @var ProductInterface $product */
                $product = $options['product'];
                if ($product->hasUnitDefinitions() === false) {
                    return [];
                }

                return $product->getUnitDefinitions()->getUnitDefinitions();
            },
            'choice_value' => 'id',
            'choice_label' => static function (ProductUnitDefinitionInterface $definition): string {
                return $definition->getUnit()?->getFullLabel() ?? (string) $definition->getId();
            },
            'choice_attr' => static function (ProductUnitDefinitionInterface $definition): array {
                return ['data-cs-unit-precision' => $definition->getPrecision()];
            },
            'choice_translation_domain' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_product_unit_definitions_choice';
    }
}
