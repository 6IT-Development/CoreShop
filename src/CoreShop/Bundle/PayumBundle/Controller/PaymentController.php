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

namespace CoreShop\Bundle\PayumBundle\Controller;

use CoreShop\Bundle\PayumBundle\Factory\ConfirmOrderFactoryInterface;
use CoreShop\Bundle\PayumBundle\Factory\GetStatusFactoryInterface;
use CoreShop\Bundle\PayumBundle\Factory\ResolveNextRouteFactoryInterface;
use CoreShop\Component\Core\Model\PaymentInterface;
use CoreShop\Component\Core\Model\PaymentProviderInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\OrderPaymentStates;
use CoreShop\Component\Order\OrderSaleStates;
use CoreShop\Component\Order\OrderStates;
use CoreShop\Component\Order\Payment\OrderPaymentProviderInterface;
use CoreShop\Component\Order\Repository\OrderRepositoryInterface;
use Payum\Core\Model\GatewayConfigInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Generic;
use Payum\Core\Security\TokenInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\Attribute\SubscribedService;

class PaymentController extends AbstractController
{
    public function __construct(
        \Psr\Container\ContainerInterface $container,
    ) {
        $this->container = $container;
    }

    public function prepareCaptureAction(Request $request): RedirectResponse
    {
        /*
         * Only the order token identifies the order. The old order-id lookup
         * (deprecated coreshop_payment route) would let anyone start a payment
         * for any order id and learn its token from the redirect afterwards.
         */
        $property = 'token';
        $identifier = $request->attributes->get('token');

        if (!is_string($identifier) || '' === $identifier) {
            throw new NotFoundHttpException('Missing order token.');
        }

        /**
         * @var OrderInterface|null $order
         */
        $order = $this->getOrderRepository()->findOneBy([$property => $identifier], true);

        if (null === $order) {
            throw new NotFoundHttpException(sprintf('Order with %s "%s" does not exist.', $property, $identifier));
        }

        if (!$this->isOrderPayable($order)) {
            throw new NotFoundHttpException(sprintf('Order with %s "%s" cannot be paid in its current state.', $property, $identifier));
        }

        /**
         * @var PaymentInterface $payment
         */
        $payment = $this->getOrderPaymentProvider()->provideOrderPayment($order);

        $storage = $this->getPayum()->getStorage($payment);
        $storage->update($payment);

        $token = $this->provideTokenBasedOnPayment($payment);

        return $this->redirect($token->getTargetUrl());
    }

    public function afterCaptureAction(Request $request): RedirectResponse
    {
        $token = $this->getPayum()->getHttpRequestVerifier()->verify($request);

        /** @var Generic $status */
        $status = $this->getGetStatusFactory()->createNewWithModel($token);
        $this->getPayum()->getGateway($token->getGatewayName())->execute($status);

        $confirmOrderRequest = $this->getConfirmOrderFactory()->createNewWithModel($status->getFirstModel());
        $this->getPayum()->getGateway($token->getGatewayName())->execute($confirmOrderRequest);

        $resolveNextRoute = $this->getResolveNextRouteFactory()->createNewWithModel($status->getFirstModel());
        $this->getPayum()->getGateway($token->getGatewayName())->execute($resolveNextRoute);
        $this->getPayum()->getHttpRequestVerifier()->invalidate($token);

        return $this->redirectToRoute($resolveNextRoute->getRouteName(), $resolveNextRoute->getRouteParameters());
    }

    /**
     * Only a placed order that is neither cancelled nor complete and still
     * waits for (the rest of) its payment can start a new payment. Carts and quotes have tokens too.
     */
    protected function isOrderPayable(OrderInterface $order): bool
    {
        return $order->getSaleState() === OrderSaleStates::STATE_ORDER &&
            !in_array($order->getOrderState(), [OrderStates::STATE_CANCELLED, OrderStates::STATE_COMPLETE], true) &&
            in_array($order->getPaymentState(), [OrderPaymentStates::STATE_AWAITING_PAYMENT, OrderPaymentStates::STATE_PARTIALLY_PAID], true);
    }

    protected function getPayum(): Payum
    {
        return $this->container->get('payum');
    }

    protected function getOrderPaymentProvider()
    {
        return $this->container->get(OrderPaymentProviderInterface::class);
    }

    protected function getOrderRepository()
    {
        return $this->container->get('coreshop.repository.order');
    }

    protected function getGetStatusFactory()
    {
        return $this->container->get(GetStatusFactoryInterface::class);
    }

    protected function getResolveNextRouteFactory()
    {
        return $this->container->get(ResolveNextRouteFactoryInterface::class);
    }

    protected function getConfirmOrderFactory()
    {
        return $this->container->get(ConfirmOrderFactoryInterface::class);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(
            parent::getSubscribedServices(),
            [
                OrderPaymentProviderInterface::class => OrderPaymentProviderInterface::class,
                new SubscribedService('coreshop.repository.order', OrderRepositoryInterface::class),
                GetStatusFactoryInterface::class => GetStatusFactoryInterface::class,
                ResolveNextRouteFactoryInterface::class => ResolveNextRouteFactoryInterface::class,
                ConfirmOrderFactoryInterface::class => ConfirmOrderFactoryInterface::class,
                new SubscribedService('payum', Payum::class),
            ],
        );
    }

    private function provideTokenBasedOnPayment(PaymentInterface $payment): TokenInterface
    {
        /** @var PaymentProviderInterface $paymentMethod */
        $paymentMethod = $payment->getPaymentProvider();

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $paymentMethod->getGatewayConfig();

        if (isset($gatewayConfig->getConfig()['use_authorize']) && $gatewayConfig->getConfig()['use_authorize'] === true) {
            $token = $this->getPayum()->getTokenFactory()->createAuthorizeToken(
                $gatewayConfig->getGatewayName(),
                $payment,
                'coreshop_payment_after',
                [],
            );
        } else {
            $token = $this->getPayum()->getTokenFactory()->createCaptureToken(
                $gatewayConfig->getGatewayName(),
                $payment,
                'coreshop_payment_after',
                [],
            );
        }

        return $token;
    }
}
