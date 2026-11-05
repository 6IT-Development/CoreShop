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

use CoreShop\Component\Customer\Model\CustomerInterface;
use CoreShop\Component\Resource\Model\ResourceInterface;

/**
 * Shared matching for the "customers" and "customerGroups" rule conditions: the configured ids are
 * normalised to positive integers and compared strictly, a missing configuration key matches nothing.
 *
 * @internal
 */
final class CustomerConditionMatcher
{
    public static function matchesCustomer(?CustomerInterface $customer, array $configuration): bool
    {
        $id = self::normalizeId($customer?->getId());

        if (null === $id) {
            return false;
        }

        return in_array($id, self::normalizeIds($configuration['customers'] ?? []), true);
    }

    public static function matchesCustomerGroups(?CustomerInterface $customer, array $configuration): bool
    {
        if (null === $customer) {
            return false;
        }

        $groupIds = self::normalizeIds($configuration['customerGroups'] ?? []);

        if ([] === $groupIds) {
            return false;
        }

        foreach ($customer->getCustomerGroups() ?? [] as $group) {
            if (!$group instanceof ResourceInterface) {
                continue;
            }

            $id = self::normalizeId($group->getId());

            if (null !== $id && in_array($id, $groupIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return int[]
     */
    private static function normalizeIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(self::normalizeId(...), $ids), static fn (?int $id): bool => null !== $id));
    }

    private static function normalizeId(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        if (is_string($id) && 1 === preg_match('/^[1-9][0-9]*$/', $id)) {
            return (int) $id;
        }

        return null;
    }
}
