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

use CoreShop\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;

final class ProductUnitDefinitionType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('unit', ProductUnitChoiceType::class, [
                'constraints' => [
                    new NotNull(groups: $this->getConstraintGroups($options)),
                ],
            ])
            ->add('conversionRate', NumberType::class)
            ->add('precision', IntegerType::class)
        ;
    }

    /**
     * The constraint must be in the groups the form is validated with ("coreshop" by default).
     *
     * @return string[]|null
     */
    private function getConstraintGroups(array $options): ?array
    {
        $groups = $options['validation_groups'] ?? null;

        if (is_array($groups) && [] !== $groups && array_filter($groups, 'is_string') === $groups) {
            return $groups;
        }

        return null;
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_product_unit_definition';
    }
}
