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

namespace CoreShop\Bundle\OrderBundle\Form\Type;

use CoreShop\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Positive;

final class OrderItemType extends AbstractResourceType
{
    public function __construct(
        string $dataClass,
        array $validationGroups,
        private DataMapperInterface $dataMapper,
        private DataMapperInterface $zeroQuantityDataMapper,
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('quantity', QuantityType::class, [
                'attr' => ['min' => $options['allow_zero_quantity'] ? 0 : 1],
                'label' => 'coreshop.ui.quantity',
                'constraints' => [
                    $options['allow_zero_quantity']
                        ? new GreaterThanOrEqual(value: 0, groups: $this->validationGroups ?: ['Default'])
                        : new Positive(groups: $this->validationGroups ?: ['Default']),
                ],
            ])->setDataMapper($options['allow_zero_quantity'] ? $this->zeroQuantityDataMapper : $this->dataMapper)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'allow_zero_quantity' => false,
        ]);
    }
}
