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

namespace CoreShop\Bundle\CustomerBundle\Pimcore\Repository;

use CoreShop\Bundle\ResourceBundle\Pimcore\PimcoreRepository;
use CoreShop\Component\Customer\Exception\NonUniqueResultException;
use CoreShop\Component\Customer\Model\CustomerInterface;
use CoreShop\Component\Customer\Repository\CustomerRepositoryInterface;

class CustomerRepository extends PimcoreRepository implements CustomerRepositoryInterface
{
    public function findByNewsletterToken(string $newsletterToken): ?CustomerInterface
    {
        $list = $this->getList();
        $list->setCondition('newsletterToken = ?', [$newsletterToken]);
        $list->setLimit(2);
        $objects = $list->load();

        if (count($objects) > 1) {
            throw NonUniqueResultException::forField('customer', 'newsletter token');
        }

        $customer = $objects[0] ?? null;

        return $customer instanceof CustomerInterface ? $customer : null;
    }

    /**
     * The customer bundle's own class definition has no user relation, so it cannot tell registered
     * customers from guests. CoreBundle's repository implements this on its `user` relation; a
     * standalone setup with its own user relation must override it the same way.
     *
     * @throws \LogicException always
     */
    public function findUniqueByEmail(string $email, bool $isGuest): ?CustomerInterface
    {
        throw new \LogicException(sprintf(
            '%s() needs a customer class with a user relation; use %s or override it in your own repository.',
            __METHOD__,
            'CoreShop\\Bundle\\CoreBundle\\Pimcore\\Repository\\CustomerRepository',
        ));
    }

    public function findGuestByEmail(string $email): ?CustomerInterface
    {
        return $this->findUniqueByEmail($email, true);
    }

    public function findCustomerByEmail(string $email): ?CustomerInterface
    {
        return $this->findUniqueByEmail($email, false);
    }
}
