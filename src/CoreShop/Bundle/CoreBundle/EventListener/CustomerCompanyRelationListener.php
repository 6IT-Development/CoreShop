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

use CoreShop\Component\Core\Customer\Allocator\CustomerAddressAllocatorInterface;
use CoreShop\Component\Core\Model\CompanyInterface;
use CoreShop\Component\Core\Model\CustomerInterface;
use CoreShop\Component\Customer\Repository\CustomerRepositoryInterface;
use Pimcore\Db;
use Pimcore\Event\Model\DataObjectEvent;

final class CustomerCompanyRelationListener
{
    public function __construct(
        protected CustomerRepositoryInterface $customerRepository,
    ) {
    }

    public function onCompanyDelete(DataObjectEvent $event): void
    {
        $object = $event->getObject();

        if (!$object instanceof CompanyInterface) {
            return;
        }

        $list = $this->customerRepository->getList();
        // deactivated (unpublished) customers must be reset as well
        $list->setUnpublished(true);
        $list->addConditionParam('company__id = ?', $object->getId());

        $customers = [];

        foreach ($list->getData() as $customer) {
            if (!$customer instanceof CustomerInterface) {
                continue;
            }

            $accessType = $customer->getAddressAccessType();

            if (empty($accessType) || $accessType === CustomerAddressAllocatorInterface::ADDRESS_ACCESS_TYPE_OWN_ONLY) {
                continue;
            }

            $customers[] = $customer;
        }

        if ([] === $customers) {
            return;
        }

        /**
         * All or nothing: if one customer cannot be saved, the exception aborts the company delete
         * and none of the customers is left changed.
         */
        Db::get()->transactional(static function () use ($customers): void {
            foreach ($customers as $customer) {
                $customer->setAddressAccessType(CustomerAddressAllocatorInterface::ADDRESS_ACCESS_TYPE_OWN_ONLY);
                $customer->save();
            }
        });
    }
}
