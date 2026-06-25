<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class RegisterOrderReturnControllerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $controllers = $container->getParameter('coreshop.orderreturn.controllers');

        foreach ($controllers as $key => $value) {
            $controllerKey = sprintf('coreshop.orderreturn.controller.%s', $key);
            $controllerClass = (string) $container->getParameter($controllerKey);

            $serviceName = match ($key) {
                'orderReturn' => 'CoreShop\Bundle\OrderReturnBundle\Controller\OrderReturnController',
                default => $controllerClass,
            };

            if ($container->hasDefinition($controllerClass)) {
                $customController = $container->getDefinition($controllerClass);

                $customController->addTag('container.service_subscriber');
                $customController->addTag('controller.service_arguments');

                $container->setDefinition($serviceName, $customController)->setPublic(true);

                continue;
            }

            $controllerDefinition = new Definition($controllerClass);
            $controllerDefinition->setPublic(true);
            $controllerDefinition->addTag('controller.service_arguments');
            $controllerDefinition->addTag('container.service_subscriber');

            $container->setDefinition($serviceName, $controllerDefinition)->setPublic(true);

            if ($controllerClass !== $serviceName) {
                $container->setAlias($controllerClass, $serviceName)->setPublic(true);
            }
        }
    }
}