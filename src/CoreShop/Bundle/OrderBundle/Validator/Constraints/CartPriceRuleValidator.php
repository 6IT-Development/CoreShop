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

use CoreShop\Component\Order\Cart\Rule\CartPriceRuleValidationProcessorInterface;
use CoreShop\Component\Order\Model\CartPriceRuleInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\PriceRuleItemInterface;
use CoreShop\Component\Order\Repository\CartPriceRuleVoucherRepositoryInterface;
use Pimcore\Model\DataObject\Fieldcollection;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class CartPriceRuleValidator extends ConstraintValidator
{
    public function __construct(
        private CartPriceRuleValidationProcessorInterface $ruleValidationProcessor,
        private CartPriceRuleVoucherRepositoryInterface $voucherCodeRepository,
    ) {
    }

    public function validate($value, Constraint $constraint): void
    {
        /** @var CartPriceRule $constraint */
        Assert::isInstanceOf($constraint, CartPriceRule::class);

        if (!$value instanceof OrderInterface) {
            return;
        }

        $ruleItems = $value->getPriceRuleItems();

        if (!$ruleItems instanceof Fieldcollection) {
            return;
        }

        foreach ($ruleItems as $ruleItem) {
            if (!$ruleItem instanceof PriceRuleItemInterface) {
                continue;
            }

            $code = $ruleItem->getVoucherCode();
            $cartRule = $ruleItem->getCartPriceRule();

            if (!$cartRule instanceof CartPriceRuleInterface) {
                // an automatic rule that was deleted is removed by the cart processor, a voucher stays in the cart
                if (null !== $code) {
                    $this->context->addViolation($constraint->unknownVoucherMessage, ['%code%' => $code]);
                }

                continue;
            }

            if (!$cartRule->getIsVoucherRule()) {
                continue;
            }

            $voucherCode = null !== $code ? $this->voucherCodeRepository->findByCode($code) : null;

            if (null === $voucherCode) {
                $this->context->addViolation($constraint->unknownVoucherMessage, ['%code%' => (string) $code]);

                continue;
            }

            if (!$this->ruleValidationProcessor->isValidCartRule($value, $cartRule, $voucherCode)) {
                $this->context->addViolation(
                    $constraint->message,
                    ['%rule%' => $cartRule->getName()],
                );
            }
        }
    }
}
