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
use CoreShop\Component\Order\Model\OrderItemInterface;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;

final class CartCreationCartItemType extends AbstractResourceType
{
    public function __construct(
        string $dataClass,
        array $validationGroups,
        private DataMapperInterface $dataMapper,
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['allow_product']) {
            $builder
                ->add('product', PurchasableSelectionType::class, [
                    'constraints' => [
                        new NotNull(groups: $this->validationGroups),
                    ],
                ])
            ;
        }

        $builder
            ->add('quantity', IntegerType::class, [
                'attr' => ['min' => 1],
                'label' => 'coreshop.ui.quantity',
            ])->setDataMapper($this->dataMapper)
        ;

        if ($options['allow_custom_price']) {
            $builder
                // percent
                ->add('customItemDiscount', NumberType::class, [
                    'required' => false,
                    'constraints' => [
                        new Range(min: 0, max: 100, groups: $this->validationGroups),
                    ],
                ])
                // minor units (decimal factor), 0 means no custom price
                ->add('customItemPrice', IntegerType::class, [
                    'required' => false,
                    'constraints' => [
                        new PositiveOrZero(groups: $this->validationGroups),
                    ],
                ])
            ;

            // a missing or empty value keeps the stored one (0 for a new item), instead of resetting it
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $e) {
                $data = $e->getData();

                if (!is_array($data)) {
                    return;
                }

                $item = $e->getForm()->getData();

                if (!isset($data['customItemPrice']) || '' === $data['customItemPrice']) {
                    $data['customItemPrice'] = $item instanceof OrderItemInterface ? ($item->getCustomItemPrice() ?? 0) : 0;
                }

                if (!isset($data['customItemDiscount']) || '' === $data['customItemDiscount']) {
                    $data['customItemDiscount'] = $item instanceof OrderItemInterface ? ($item->getCustomItemDiscount() ?? 0) : 0;
                }

                $e->setData($data);
            });
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefault('allow_product', true);
        $resolver->setDefault('allow_custom_price', true);
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_cart_item';
    }
}
