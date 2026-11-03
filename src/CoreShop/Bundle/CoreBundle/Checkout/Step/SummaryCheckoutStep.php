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

use CoreShop\Bundle\CoreBundle\Form\Type\Checkout\SummaryType;
use CoreShop\Component\Order\Checkout\CheckoutException;
use CoreShop\Component\Order\Checkout\CheckoutStepInterface;
use CoreShop\Component\Order\Checkout\RedirectCheckoutStepInterface;
use CoreShop\Component\Order\Checkout\ValidationCheckoutStepInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;

class SummaryCheckoutStep implements CheckoutStepInterface, RedirectCheckoutStepInterface, ValidationCheckoutStepInterface
{
    private const TERMS_ACCEPTED_SESSION_KEY = 'coreshop.checkout.terms_accepted';

    public function __construct(
        private FormFactoryInterface $formFactory,
        private RouterInterface $router,
        private RequestStack $requestStack,
    ) {
    }

    public function getIdentifier(): string
    {
        return 'summary';
    }

    public function doAutoForward(OrderInterface $cart): bool
    {
        return false;
    }

    public function getResponse(OrderInterface $cart, Request $request): RedirectResponse
    {
        $form = $this->createForm($request, $cart);

        $submitQuote = $form->get('submitQuote');

        $nextAction = $submitQuote instanceof ClickableInterface && $submitQuote->isClicked()
            ? 'coreshop_cart_create_quote'
            : 'coreshop_checkout_do';

        return new RedirectResponse($this->router->generate($nextAction));
    }

    public function validate(OrderInterface $cart): bool
    {
        $session = $this->getSession();

        return null === $session || $session->get(self::TERMS_ACCEPTED_SESSION_KEY) === $this->getCartFingerprint($cart);
    }

    public function commitStep(OrderInterface $cart, Request $request): bool
    {
        $form = $this->createForm($request, $cart);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $this->getSession()?->set(self::TERMS_ACCEPTED_SESSION_KEY, $this->getCartFingerprint($cart));

                return true;
            }

            throw new CheckoutException('Summary Form is invalid', 'coreshop.ui.error.coreshop_checkout_summary_form_invalid');
        }

        return false;
    }

    public function prepareStep(OrderInterface $cart, Request $request): array
    {
        return ['form' => $this->createForm($request, $cart)->createView()];
    }

    private function getSession(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->hasSession() ? $request->getSession() : null;
    }

    private function getCartFingerprint(OrderInterface $cart): string
    {
        $items = array_map(
            static fn (OrderItemInterface $item): array => [$item->getId(), $item->getQuantity()],
            $cart->getItems() ?? [],
        );

        return hash('sha256', json_encode([$cart->getId(), $cart->getTotal(), $items], \JSON_THROW_ON_ERROR));
    }

    private function createForm(Request $request, OrderInterface $cart): FormInterface
    {
        $form = $this->formFactory->createNamed('coreshop', SummaryType::class, $cart);

        if ($request->isMethod('post')) {
            $form = $form->handleRequest($request);
        }

        return $form;
    }
}
