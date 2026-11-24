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

namespace CoreShop\Component\Core\Cart;

use CoreShop\Component\Order\Model\PurchasableInterface;
use Pimcore\Model\DataObject\Concrete;

/**
 * The default rule: a product stays in the carts while it is published.
 */
final class PublishedCartProductAvailabilityChecker implements CartProductAvailabilityCheckerInterface
{
    public function isAvailable(PurchasableInterface $product): bool
    {
        return !$product instanceof Concrete || $product->isPublished();
    }
}
