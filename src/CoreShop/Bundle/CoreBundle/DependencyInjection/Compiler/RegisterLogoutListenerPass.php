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

namespace CoreShop\Bundle\CoreBundle\DependencyInjection\Compiler;

use CoreShop\Bundle\CoreBundle\EventListener\ShopUserLogoutHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Attaches the logout listener to the configured firewall's event dispatcher, but only if that
 * firewall exists, so projects with a different or no firewall still compile.
 */
final class RegisterLogoutListenerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ShopUserLogoutHandler::class) ||
            !$container->hasParameter('coreshop.security.firewall_name')
        ) {
            return;
        }

        $dispatcherId = sprintf('security.event_dispatcher.%s', (string) $container->getParameter('coreshop.security.firewall_name'));

        if (!$container->has($dispatcherId)) {
            return;
        }

        $container->getDefinition(ShopUserLogoutHandler::class)->addTag('kernel.event_listener', [
            'event' => LogoutEvent::class,
            'method' => 'onLogoutSuccess',
            'priority' => 65,
            'dispatcher' => $dispatcherId,
        ]);
    }
}
