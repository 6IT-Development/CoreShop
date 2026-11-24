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

namespace CoreShop\Component\Core\Cart\Notice;

/**
 * The values are persisted in the cartNotices field of the cart.
 */
enum CartNoticeType: string
{
    /** a product left the cart because it can no longer be bought */
    case Removed = 'removed';

    /** the gross unit price of a product changed when the cart was recalculated */
    case PriceChanged = 'price_changed';

    /** a cart price rule (a discount) no longer applies after the cart was recalculated */
    case RuleRemoved = 'rule_removed';

    public function isAboutProduct(): bool
    {
        return $this !== self::RuleRemoved;
    }
}
