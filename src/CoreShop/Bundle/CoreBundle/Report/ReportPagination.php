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
final class ReportPagination
{
    private const MAX_LIMIT = 1000;

    public static function limit(ParameterBag $parameterBag, int $default): int
    {
        return max(1, min(self::MAX_LIMIT, (int) $parameterBag->get('limit', $default)));
    }

    /**
     * Reads "start" of the Ext paging proxy, or derives the offset from "page".
     */
    public static function offset(ParameterBag $parameterBag, int $limit): int
    {
        if ($parameterBag->has('start')) {
            return max(0, (int) $parameterBag->get('start'));
        }

        return (max(1, (int) $parameterBag->get('page', 1)) - 1) * $limit;
    }
}
