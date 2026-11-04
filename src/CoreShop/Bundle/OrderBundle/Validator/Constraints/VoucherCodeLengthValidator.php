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

use CoreShop\Component\Order\Model\CartPriceRuleVoucherGeneratorInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class VoucherCodeLengthValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        /** @var CartPriceRuleVoucherGeneratorInterface $value */
        Assert::isInstanceOf($value, CartPriceRuleVoucherGeneratorInterface::class);

        /** @var VoucherCodeLength $constraint */
        Assert::isInstanceOf($constraint, VoucherCodeLength::class);

        if (!is_numeric($value->getLength())) {
            return;
        }

        $codeLength = mb_strlen((string) $value->getPrefix()) + (int) $value->getLength() + mb_strlen((string) $value->getSuffix());
        $hyphensOn = (int) $value->getHyphensOn();

        // the generator inserts a hyphen after every $hyphensOn characters of the whole code
        if ($hyphensOn > 0 && $codeLength > 0) {
            $codeLength += intdiv($codeLength - 1, $hyphensOn);
        }

        if ($codeLength > $constraint->max) {
            $this->context->addViolation(
                $constraint->message,
                [
                    '%codeLength%' => $codeLength,
                    '%max%' => $constraint->max,
                ],
            );
        }
    }
}
