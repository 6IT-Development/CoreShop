<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Controller\Admin;

use CoreShop\Bundle\ResourceBundle\Controller\AdminController;
use CoreShop\Component\Order\Repository\OrderRepositoryInterface;
use CoreShop\Component\OrderReturn\Model\OrderReturnInterface;
use CoreShop\Component\Resource\Repository\PimcoreRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\SubscribedService;

class OrderReturnController extends AdminController
{
    public function listAction(Request $request): JsonResponse
    {
        $orderId = $this->getParameterFromRequest($request, 'id');
        $order = $this->getOrderRepository()->find($orderId);

        if (!$order) {
            return $this->viewHandler->handle(['success' => false, 'message' => 'Order not found']);
        }

        $returns = $this->getOrderReturnRepository()->findBy(['order__id' => $order->getId()], ['creationDate' => 'DESC']);

        $parsedData = [];
        /** @var OrderReturnInterface $return */
        foreach ($returns as $return) {
            $parsedData[] = [
                'id' => $return->getId(),
                'creationDate' => $return->getCreationDate(),
            ];
        }

        return $this->viewHandler->handle(['success' => true, 'returns' => $parsedData]);
    }

    private function getOrderRepository(): PimcoreRepositoryInterface
    {
        return $this->container->get('coreshop.repository.order');
    }

    private function getOrderReturnRepository(): PimcoreRepositoryInterface
    {
        return $this->container->get('coreshop.repository.order_return');
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            new SubscribedService('coreshop.repository.order', OrderRepositoryInterface::class),
            new SubscribedService('coreshop.repository.order_return', PimcoreRepositoryInterface::class),
        ]);
    }
}
