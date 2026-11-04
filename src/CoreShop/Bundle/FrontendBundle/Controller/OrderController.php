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

namespace CoreShop\Bundle\FrontendBundle\Controller;

use CoreShop\Bundle\CoreBundle\Form\Type\Order\PaymentType;
use CoreShop\Component\Core\Model\OrderInterface;
use CoreShop\Component\Core\Model\PaymentInterface as CorePaymentInterface;
use CoreShop\Component\Order\OrderPaymentStates;
use CoreShop\Component\Order\OrderSaleStates;
use CoreShop\Component\Order\OrderStates;
use CoreShop\Component\Order\Repository\OrderRepositoryInterface;
use CoreShop\Component\Payment\Model\PaymentInterface;
use CoreShop\Component\Payment\Repository\PaymentRepositoryInterface;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends FrontendController
{
    public function reviseAction(Request $request): Response
    {
        $token = $this->getParameterFromRequest($request, 'token');
        $payment = null;

        $order = is_string($token) && '' !== $token ? $this->getOrderRepository()->findOneBy(['token' => $token]) : null;

        if (!$order instanceof OrderInterface) {
            throw $this->createNotFoundException();
        }

        if (!$this->isOrderPayable($order)) {
            $this->addFlash('error', $this->container->get('translator')->trans('coreshop.ui.error.order_not_payable'));

            return $this->redirectToRoute('coreshop_index');
        }

        if ($request->query->has('paymentId')) {
            $paymentObject = $this->getPaymentRepository()->find($request->query->getInt('paymentId'));

            // only show a payment of this very order, payment ids are sequential
            if ($paymentObject instanceof CorePaymentInterface &&
                $paymentObject->getOrder()?->getId() === $order->getId()
            ) {
                $payment = $paymentObject;
            }
        }

        foreach ($this->getPaymentRepository()->findForPayable($order) as $orderPayment) {
            if ($orderPayment->getState() === PaymentInterface::STATE_COMPLETED) {
                $this->addFlash('error', $this->container->get('translator')->trans('coreshop.ui.error.order_already_paid'));

                return $this->redirectToRoute('coreshop_index');
            }
        }

        $form = $this->getFormFactory()->createNamed('coreshop', PaymentType::class, $order, [
            'payment_subject' => $order,
        ]);

        if ($request->isMethod('post')) {
            $form = $form->handleRequest($request);

            $cancelButton = $form->get('cancel');

            if ($cancelButton instanceof ClickableInterface && $form->isSubmitted() && $cancelButton->isClicked()) {
                throw new \Exception('fix me');
            }

            if ($form->isValid()) {
                $order = $form->getData();
                $order->save();

                return $this->redirectToRoute('coreshop_order_revise_pay', ['token' => $token]);
            }
        }

        $args = [
            'order' => $order,
            'payment' => $payment,
            'form' => $form->createView(),
        ];

        return $this->render($this->getTemplateConfigurator()->findTemplate('Order/revise.html'), $args);
    }

    /**
     * Only a placed order that is neither cancelled nor complete and still
     * waits for (the rest of) its payment may change its payment provider and
     * be paid again. Carts and quotes have tokens too.
     */
    protected function isOrderPayable(OrderInterface $order): bool
    {
        return $order->getSaleState() === OrderSaleStates::STATE_ORDER &&
            !in_array($order->getOrderState(), [OrderStates::STATE_CANCELLED, OrderStates::STATE_COMPLETE], true) &&
            in_array($order->getPaymentState(), [OrderPaymentStates::STATE_AWAITING_PAYMENT, OrderPaymentStates::STATE_PARTIALLY_PAID], true);
    }

    protected function getOrderRepository(): OrderRepositoryInterface
    {
        return $this->container->get('coreshop.repository.order');
    }

    private function getPaymentRepository(): PaymentRepositoryInterface
    {
        return $this->container->get('coreshop.repository.payment');
    }

    protected function getFormFactory(): FormFactoryInterface
    {
        return $this->container->get('form.factory');
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(
            parent::getSubscribedServices(),
            [
                'coreshop.repository.order' => OrderRepositoryInterface::class,
                'coreshop.repository.payment' => PaymentRepositoryInterface::class,
            ],
        );
    }
}
