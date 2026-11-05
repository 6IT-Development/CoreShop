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

namespace CoreShop\Bundle\FrontendBundle\DependencyInjection\CompilerPass;

use CoreShop\Bundle\FrontendBundle\DependencyInjection\Configuration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Registers each configured frontend controller under the service id of its
 * default class (Configuration::DEFAULT_CONTROLLERS), which is what the
 * routes reference.
 */
class RegisterFrontendControllerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /**
         * @var array<string, string> $controllers
         */
        $controllers = $container->getParameter('coreshop.frontend.controllers');

        foreach ($controllers as $key => $controllerClass) {
            $serviceName = Configuration::DEFAULT_CONTROLLERS[$key] ?? null;

            if (null === $serviceName) {
                throw new \LogicException(sprintf('No default controller service is known for the frontend controller key "%s".', $key));
            }

            $controllerClass = (string) $controllerClass;

            if ($container->hasDefinition($controllerClass)) {
                // The app already defines the controller (usually an autowired
                // App\Controller service): tag that definition and point the
                // default id at it, so there is only one instance.
                $definition = $container->getDefinition($controllerClass);
                $definition->setPublic(true);
                $this->addControllerTags($definition);

                if ($controllerClass !== $serviceName) {
                    $container->setAlias($serviceName, $controllerClass)->setPublic(true);
                }

                continue;
            }

            $definition = new Definition($controllerClass);
            $definition->setPublic(true);
            $this->addControllerTags($definition);

            $container->setDefinition($serviceName, $definition);

            if ($controllerClass !== $serviceName) {
                $container->setAlias($controllerClass, $serviceName)->setPublic(true);
            }
        }
    }

    private function addControllerTags(Definition $definition): void
    {
        foreach (['controller.service_arguments', 'container.service_subscriber'] as $tag) {
            if (!$definition->hasTag($tag)) {
                $definition->addTag($tag);
            }
        }
    }
}
