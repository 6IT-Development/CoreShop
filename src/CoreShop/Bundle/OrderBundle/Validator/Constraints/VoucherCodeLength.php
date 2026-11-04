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

namespace CoreShop\Bundle\OrderBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

final class VoucherCodeLength extends Constraint
{
    public string $message = 'coreshop.cart_price_rule_voucher_generator.code_length';

    /**
     * Length of the voucher code column.
     */
    public int $max = 190;

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
