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

namespace CoreShop\Bundle\CoreBundle\EventListener;

use CoreShop\Component\Core\Model\CustomerInterface;
use CoreShop\Component\Customer\Repository\CustomerRepositoryInterface;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Http\Request\Resolver\PimcoreContextResolver;
use Pimcore\Http\RequestHelper;
use Pimcore\Model\Element\ValidationException;

/**
 * Keeps the e-mail of registered customers unique when a customer is saved outside the storefront:
 * in the Pimcore admin (object editor, preview, edit mode) and without a request (CLI, Messenger).
 * Storefront requests are skipped because the registration and profile forms validate the e-mail there.
 */
final class CustomerSecurityValidationListener
{
    public function __construct(
        protected RequestHelper $requestHelper,
        protected CustomerRepositoryInterface $customerRepository,
        protected PimcoreContextResolver $pimcoreContextResolver,
    ) {
    }

    public function checkCustomerSecurityDataBeforeUpdate(DataObjectEvent $event): void
    {
        if ($this->isStorefrontRequest()) {
            return;
        }

        $object = $event->getObject();

        if (!$object instanceof CustomerInterface) {
            return;
        }

        if ($object->getUser() === null) {
            return;
        }

        $identifierValue = $object->getEmail();

        if (null === $identifierValue || '' === $identifierValue) {
            return;
        }

        /** @var \Pimcore\Model\DataObject\Listing $listing */
        $listing = $this->customerRepository->getList();
        $listing->setUnpublished(true);
        $listing->addConditionParam('email = ?', $identifierValue);
        // a new object has no id yet (preAdd); "id != NULL" would never match
        if (null !== $object->getId()) {
            $listing->addConditionParam('id != ?', $object->getId());
        }
        $listing->addConditionParam('user__id IS NOT NULL');
        $listing->setLimit(1);

        if (count($listing->getObjects()) === 0) {
            return;
        }

        throw new ValidationException(sprintf('Email "%s" is already used. Please use another one.', $identifierValue));
    }

    private function isStorefrontRequest(): bool
    {
        if (!$this->requestHelper->hasCurrentRequest()) {
            return false;
        }

        if ($this->requestHelper->isFrontendRequestByAdmin()) {
            return false;
        }

        $request = $this->requestHelper->getCurrentRequest();

        return !$this->pimcoreContextResolver->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_ADMIN);
    }
}
