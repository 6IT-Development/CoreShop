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

namespace CoreShop\Component\Customer\Context\RequestBased;

use CoreShop\Component\Customer\Context\CustomerContextInterface;
use CoreShop\Component\Customer\Context\CustomerNotFoundException;
use CoreShop\Component\Customer\Model\CustomerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CustomerContext implements CustomerContextInterface
{
    public function __construct(
        private RequestResolverInterface $requestResolver,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Only a missing main request or a CustomerNotFoundException from the resolver means "not found";
     * any other exception of a resolver is a real error and is not hidden.
     */
    public function getCustomer(): CustomerInterface
    {
        return $this->getCustomerForRequest($this->getMainRequest());
    }

    private function getCustomerForRequest(Request $request): CustomerInterface
    {
        return $this->requestResolver->findCustomer($request);
    }

    private function getMainRequest(): Request
    {
        $masterRequest = $this->requestStack->getMainRequest();
        if (null === $masterRequest) {
            throw new CustomerNotFoundException(new \UnexpectedValueException('There are not any requests on request stack'));
        }

        return $masterRequest;
    }
}
