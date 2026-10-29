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

namespace CoreShop\Bundle\CoreBundle\Customer;

use CoreShop\Component\Core\Model\UserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class CustomerLoginService implements CustomerLoginServiceInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private string $firewallName = 'coreshop_frontend',
        private string $authenticatorName = 'form_login',
    ) {
    }

    public function loginCustomer(UserInterface $user): void
    {
        if (null === $this->requestStack->getCurrentRequest()) {
            return;
        }

        /**
         * Use Symfony's login flow, so the user checker runs, the session id gets migrated
         * (no session fixation) and the LoginSuccessEvent is dispatched.
         */
        $this->security->login($user, $this->authenticatorName, $this->firewallName);
    }
}
