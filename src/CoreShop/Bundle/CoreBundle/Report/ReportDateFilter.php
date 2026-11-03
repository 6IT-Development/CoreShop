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

use Carbon\Carbon;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * @internal
 */
final class ReportDateFilter
{
    public static function from(ParameterBag $parameterBag): Carbon
    {
        return self::fromTimestamp($parameterBag->get('from', strtotime(date('01-m-Y'))));
    }

    /**
     * The filter holds the start of the last selected day. The result is the exclusive
     * upper bound after that day, so the whole day is part of the report.
     */
    public static function to(ParameterBag $parameterBag): Carbon
    {
        return self::fromTimestamp($parameterBag->get('to', strtotime(date('t-m-Y'))))->addDay();
    }

    /**
     * Carbon 3 uses UTC for timestamps unless it is told otherwise.
     */
    public static function fromTimestamp(int|string|float $timestamp): Carbon
    {
        return Carbon::createFromTimestamp((int) $timestamp, date_default_timezone_get());
    }
}
