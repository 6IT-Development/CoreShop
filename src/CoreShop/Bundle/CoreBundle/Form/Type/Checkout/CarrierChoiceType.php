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

namespace CoreShop\Bundle\CoreBundle\Form\Type\Checkout;

use CoreShop\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use CoreShop\Component\Address\Model\AddressInterface;
use CoreShop\Component\Core\Model\CarrierInterface;
use CoreShop\Component\Core\Model\OrderInterface;
use CoreShop\Component\Core\Repository\CarrierRepositoryInterface;
use CoreShop\Component\Core\Taxation\TaxationDisplayProviderInterface;
use CoreShop\Component\Order\Cart\CartContextResolverInterface;
use CoreShop\Component\Shipping\Calculator\TaxedShippingCalculatorInterface;
use CoreShop\Component\Shipping\Resolver\CarriersResolverInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CarrierChoiceType extends AbstractResourceType
{
    public function __construct(
        $dataClass,
        array $validationGroups,
        private CarrierRepositoryInterface $repository,
        private CarriersResolverInterface $carriersResolver,
        private TaxedShippingCalculatorInterface $taxedShippingCalculator,
        private CartContextResolverInterface $cartContextResolver,
        private TaxationDisplayProviderInterface $taxationDisplayProvider,
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'choices' => function (Options $options) {
                    $cart = $options['cart'];
                    if ($cart instanceof OrderInterface) {
                        $address = $cart->getShippingAddress();

                        return $address instanceof AddressInterface ? $this->carriersResolver->resolveCarriers($cart, $address) : [];
                    }

                    return $this->repository->findAll();
                },
                'choice_value' => 'id',
                'choice_label' => 'title',
                'choice_translation_domain' => false,
                'cart' => null,
            ])
            ->setDefault('show_carrier_price', true)
            ->setAllowedTypes('cart', ['null', OrderInterface::class])
        ;
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['prices'] = [];
        $view->vars['show_carrier_price'] = $options['show_carrier_price'];

        /**
         * @var OrderInterface|null $cart
         */
        $cart = $options['cart'];
        $address = $cart?->getShippingAddress();

        if (!$address instanceof AddressInterface) {
            return;
        }

        $prices = [];
        $context = $this->cartContextResolver->resolveCartContext($cart);
        $withTax = $this->taxationDisplayProvider->displayWithTax($context);

        foreach ($view->vars['choices'] as $choice) {
            $carrier = $choice->data;

            if (!$carrier instanceof CarrierInterface) {
                continue;
            }

            $prices[$choice->value] = $this->taxedShippingCalculator->getPrice(
                $carrier,
                $cart,
                $address,
                $withTax,
                $context,
            );
        }

        $view->vars['prices'] = $prices;
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_checkout_carrier_choice';
    }
}
