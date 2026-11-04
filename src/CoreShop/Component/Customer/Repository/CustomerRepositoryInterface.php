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

namespace CoreShop\Component\Customer\Repository;

use CoreShop\Component\Customer\Model\CustomerInterface;
use CoreShop\Component\Resource\Repository\PimcoreRepositoryInterface;

interface CustomerRepositoryInterface extends PimcoreRepositoryInterface
{
    public function findByNewsletterToken(string $newsletterToken): ?CustomerInterface;

    /**
     * Looks up a customer by e-mail address: a registered one (with a user) or a guest (without one).
     *
     * This is a lookup only. A typed or submitted e-mail address is no proof that the caller owns it,
     * so never hand the result to an anonymous form, attach it to a user, or merge it into a new
     * account based on the address alone. Linking guest data needs proof of ownership first, such as
     * a signed, expiring link sent to that address.
     *
     * Guests are not de-duplicated, so several guests can share one address; which one is returned
     * is not defined.
     */
    public function findUniqueByEmail(string $email, bool $isGuest): ?CustomerInterface;

    /**
     * Looks up a guest customer (without a user) by e-mail address.
     *
     * Lookup only, not proof of ownership: see findUniqueByEmail(). Do not reuse, overwrite or
     * claim the returned guest because someone typed its address (registration, guest checkout).
     */
    public function findGuestByEmail(string $email): ?CustomerInterface;

    /**
     * Looks up a registered customer (with a user) by e-mail address.
     *
     * Lookup only, not proof of ownership: see findUniqueByEmail(). Suitable for checks such as
     * "this address is already registered", not for acting on the returned customer's data.
     */
    public function findCustomerByEmail(string $email): ?CustomerInterface;
}
