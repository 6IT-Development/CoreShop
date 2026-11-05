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
namespace CoreShop\Component\Core\Rule\Condition;

use CoreShop\Component\Core\Model\CustomerInterface;

/**
 * Shared semantics of the "guest" rule condition for cart, product, payment and shipping rules:
 * no customer yet (anonymous visitor) or a customer without a user counts as a guest.
 * The optional "guest" configuration (default true) selects guests; false selects registered customers.
 *
 * Note: customers created in the back office have no user either and therefore count as guests
 * until the customer model gets an explicit guest flag.
 *
 * @internal
 */
final class GuestConditionMatcher
{
    public static function isGuest(mixed $customer): bool
    {
        if (!$customer instanceof CustomerInterface) {
            return true;
        }

        return null === $customer->getUser();
    }

    public static function matches(mixed $customer, array $configuration): bool
    {
        $expectGuest = $configuration['guest'] ?? true;

        return self::isGuest($customer) === (bool) $expectGuest;
    }
}
