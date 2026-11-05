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

namespace CoreShop\Component\Product\Rule\Fetcher;

/**
 * Builds the part of a cache key that depends on the rule context
 * (store, currency, country, customer, cart, …). Two contexts that can
 * lead to different valid rules must get different fingerprints.
 */
interface RuleContextFingerprinterInterface
{
    /**
     * @return string|null null if the context cannot be fingerprinted and must not be cached
     */
    public function fingerprint(array $context): ?string;
}
