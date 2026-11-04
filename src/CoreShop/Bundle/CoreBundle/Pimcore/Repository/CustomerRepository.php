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

namespace CoreShop\Bundle\CoreBundle\Pimcore\Repository;

use CoreShop\Bundle\CustomerBundle\Pimcore\Repository\CustomerRepository as BaseCustomerRepository;
use CoreShop\Component\Core\Model\CustomerInterface;
use CoreShop\Component\Customer\Model\CustomerInterface as BaseCustomerInterface;
use CoreShop\Component\Core\Repository\CustomerRepositoryInterface;

class CustomerRepository extends BaseCustomerRepository implements CustomerRepositoryInterface
{
    /**
     * Returns the most recently created matching customer (highest id). Guests (no user) are not
     * de-duplicated, so several guests can share an address.
     */
    public function findUniqueByEmail(string $email, bool $isGuest): ?BaseCustomerInterface
    {
        $list = $this->getList();
        $list->setCondition(
            $isGuest ? 'email = ? AND user__id IS NULL' : 'email = ? AND user__id IS NOT NULL',
            [$email],
        );
        $list->setOrderKey('id');
        $list->setOrder('DESC');
        $list->setLimit(1);

        $customer = $list->load()[0] ?? null;

        return $customer instanceof BaseCustomerInterface ? $customer : null;
    }

    public function findOneByEmailWithoutUser(string $email): ?CustomerInterface
    {
        $list = $this->getList();

        $list->setCondition('email = ? AND user__id IS NULL', [$email]);
        $list->load();

        $users = $list->getObjects();

        if (count($users) > 0) {
            return $users[0];
        }

        return null;
    }
}
