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

namespace CoreShop\Bundle\CoreBundle\Report;

use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * @internal
 */
final class OrderStateFilter
{
    /**
     * Normalises the JSON encoded "orderState" report filter into a plain list of strings.
     * Keys of the decoded input are discarded, so they can never end up in the SQL.
     *
     * @return list<string>|null
     */
    public static function fromParameterBag(ParameterBag $parameterBag): ?array
    {
        $orderStateFilter = $parameterBag->get('orderState');

        if (!is_string($orderStateFilter) || $orderStateFilter === '') {
            return null;
        }

        $decoded = \json_decode($orderStateFilter, true);

        if (!is_array($decoded)) {
            return null;
        }

        $states = array_values(array_filter($decoded, 'is_string'));

        if ($states === [] || $states === ['all']) {
            return null;
        }

        return $states;
    }
}
