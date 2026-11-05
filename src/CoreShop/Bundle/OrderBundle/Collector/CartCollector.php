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

namespace CoreShop\Bundle\OrderBundle\Collector;

use CoreShop\Component\Locale\Context\LocaleContextInterface;
use CoreShop\Component\Order\Context\CartContextInterface;
use Pimcore\Http\Request\Resolver\PimcoreContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

final class CartCollector extends DataCollector
{
    public function __construct(
        private CartContextInterface $cartContext,
        private LocaleContextInterface $localeContext,
        private PimcoreContextResolver $pimcoreContext,
    ) {
        $this->reset();
    }

    public function hasCart(): bool
    {
        return $this->data['has_cart'];
    }

    public function getCartId(): ?int
    {
        return $this->data['cart_id'];
    }

    public function getTotal(): ?int
    {
        return $this->data['total'];
    }

    public function getCurrency(): ?string
    {
        return $this->data['currency'];
    }

    public function getLocale(): string
    {
        return $this->data['locale'];
    }

    public function getAdmin(): bool
    {
        return $this->data['admin'];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        if ($this->pimcoreContext->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_ADMIN)) {
            $this->data['admin'] = true;

            return;
        }

        try {
            $cart = $this->cartContext->getCart();

            // store a scalar summary only, the profile must not carry the whole order object graph
            $this->data['has_cart'] = true;
            $this->data['cart_id'] = $cart->getId();
            $this->data['total'] = $cart->getTotal();
            $this->data['currency'] = $cart->getCurrency()?->getIsoCode();
            $this->data['locale'] = $this->localeContext->getLocaleCode();
        } catch (\Throwable) {
            //If something went wrong, we don't have any cart, which we can safely ignore
        }
    }

    public function reset(): void
    {
        $this->data = [
            'has_cart' => false,
            'cart_id' => null,
            'total' => null,
            'currency' => null,
            'locale' => 'en',
            'admin' => false,
        ];
    }

    public function getName(): string
    {
        return 'coreshop.cart_collector';
    }
}
