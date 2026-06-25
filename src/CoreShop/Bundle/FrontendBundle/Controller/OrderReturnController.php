<?php

declare(strict_types=1);

namespace CoreShop\Bundle\FrontendBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderReturnController extends FrontendController
{
    public function returnFormAction(Request $request): Response
    {
        $params = [];

        return $this->render(
            $this->getTemplateConfigurator()->findTemplate(
                'OrderReturn/return-form.html'
            ), $params
        );
    }

}