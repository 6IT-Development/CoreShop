<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Controller;

use CoreShop\Bundle\FrontendBundle\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderReturnController extends FrontendController
{
    public function returnFormAction(Request $request): Response
    {
        $params = [];

        return $this->render(
            '@CoreShopOrderReturn/OrderReturn/return-form.html.twig', $params
        );
    }

}