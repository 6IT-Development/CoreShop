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

namespace CoreShop\Bundle\CoreBundle\Checkout\Step;

use CoreShop\Bundle\CoreBundle\Form\Type\Checkout\GuestAddressType;
use CoreShop\Component\Address\Model\AddressInterface;
use CoreShop\Component\Core\Model\CustomerInterface;
use CoreShop\Component\Order\Checkout\CheckoutException;
use CoreShop\Component\Order\Checkout\CheckoutStepInterface;
use CoreShop\Component\Order\Manager\CartManagerInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use Pimcore\Model\DataObject\Service;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Webmozart\Assert\Assert;

class GuestAddressCheckoutStep implements CheckoutStepInterface
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private CartManagerInterface $cartManager,
    ) {
    }

    public function getIdentifier(): string
    {
        return 'guest_address';
    }

    public function doAutoForward(OrderInterface $cart): bool
    {
        return false;
    }

    public function commitStep(OrderInterface $cart, Request $request): bool
    {
        Assert::isInstanceOf($cart, \CoreShop\Component\Core\Model\OrderInterface::class);

        $customer = $cart->getCustomer();

        if (!$customer instanceof CustomerInterface) {
            throw new CheckoutException('Customer not set', 'coreshop.ui.error.coreshop_checkout_internal_error');
        }

        if ($cart->hasShippableItems() && $cart->getShippingAddress() && $cart->getInvoiceAddress() &&
            $cart->getInvoiceAddress()->getId() === $cart->getShippingAddress()->getId()) {
            /**
             * @var AddressInterface $shippingAddress
             */
            $shippingAddress = Service::cloneMe($cart->getInvoiceAddress());

            $cart->setShippingAddress($shippingAddress);
        }

        $form = $this->createForm($request, $cart, $customer);

        if ($form->isSubmitted() && $form->isValid()) {
            $cart = $form->getData();

            if (!$cart->hasShippableItems()) {
                $this->saveAddress($cart, $cart->getInvoiceAddress());
            } elseif ($form->get('useInvoiceAsShipping')->getData()) {
                $this->saveAddress($cart, $cart->getInvoiceAddress());

                $cart->setShippingAddress($cart->getInvoiceAddress());
            } else {
                $this->saveAddress($cart, $cart->getInvoiceAddress());
                $this->saveAddress($cart, $cart->getShippingAddress());
            }

            $this->cartManager->persistCart($cart);

            return true;
        }

        return false;
    }

    public function prepareStep(OrderInterface $cart, Request $request): array
    {
        Assert::isInstanceOf($cart, \CoreShop\Component\Core\Model\OrderInterface::class);

        $customer = $cart->getCustomer();

        if (!$customer instanceof CustomerInterface) {
            throw new CheckoutException('Customer not set', 'coreshop.ui.error.coreshop_checkout_internal_error');
        }

        return [
            'form' => $this->createForm($request, $cart, $customer)->createView(),
            'hasShippableItems' => $cart->hasShippableItems(),
        ];
    }

    private function saveAddress(OrderInterface $cart, ?AddressInterface $address): void
    {
        if (null === $address) {
            return;
        }

        // an address that already exists stays where it is
        if (!$address->getId()) {
            $address->setParent(Service::createFolderByPath($cart->getFullPath() . '/addresses'));
            $address->setKey(uniqid());
        }

        $address->setPublished(true);
        $address->save();
    }

    private function createForm(Request $request, OrderInterface $cart, CustomerInterface $customer): FormInterface
    {
        Assert::isInstanceOf($cart, \CoreShop\Component\Core\Model\OrderInterface::class);

        $form = $this->formFactory->createNamed('coreshop', GuestAddressType::class, $cart);

        if ($cart->hasShippableItems() === false) {
            $form->remove('shippingAddress');
            $form->remove('useInvoiceAsShipping');
        }

        if ($request->isMethod('post')) {
            $form = $form->handleRequest($request);
        }

        return $form;
    }
}
