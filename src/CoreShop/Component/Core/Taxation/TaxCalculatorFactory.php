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

namespace CoreShop\Component\Core\Taxation;

use CoreShop\Component\Address\Model\AddressInterface;
use CoreShop\Component\Core\Model\TaxRuleInterface;
use CoreShop\Component\Core\Repository\TaxRuleRepositoryInterface;
use CoreShop\Component\Taxation\Calculator\TaxCalculatorInterface;
use CoreShop\Component\Taxation\Calculator\TaxRulesTaxCalculator;
use CoreShop\Component\Taxation\Model\TaxRateInterface;
use CoreShop\Component\Taxation\Model\TaxRuleGroupInterface;

class TaxCalculatorFactory implements TaxCalculatorFactoryInterface
{
    public function __construct(
        private TaxRuleRepositoryInterface $taxRuleRepository,
    ) {
    }

    public function getTaxCalculatorForAddress(
        TaxRuleGroupInterface $taxRuleGroup,
        AddressInterface $address,
        array $context = [],
    ): TaxCalculatorInterface {
        // A deactivated tax rule group charges no tax
        if (!$taxRuleGroup->getActive()) {
            return new TaxRulesTaxCalculator([], TaxRulesTaxCalculator::COMBINE_METHOD);
        }

        $taxRules = $this->taxRuleRepository->findForCountryAndState(
            $taxRuleGroup,
            $address->getCountry(),
            $address->getState(),
        );

        // Most specific rules first (state, then country, then wildcard), then by id, so the
        // behaviour taken from the first rule and the DISABLE_METHOD cut-off do not depend on row order.
        usort($taxRules, static fn (TaxRuleInterface $a, TaxRuleInterface $b): int => [
            null === $a->getState(),
            null === $a->getCountry(),
            $a->getId() ?? \PHP_INT_MAX,
        ] <=> [
            null === $b->getState(),
            null === $b->getCountry(),
            $b->getId() ?? \PHP_INT_MAX,
        ]);

        $taxRates = [];
        $firstRow = true;
        $behavior = TaxRulesTaxCalculator::COMBINE_METHOD;

        foreach ($taxRules as $taxRule) {
            $taxRate = $taxRule->getTaxRate();

            // A rule with a deactivated tax rate is skipped completely, including its behaviour
            if (!$taxRate instanceof TaxRateInterface || !$taxRate->getActive()) {
                continue;
            }

            $taxRates[] = $taxRate;

            if ($firstRow) {
                $behavior = $taxRule->getBehavior();

                $firstRow = false;
            }

            if ($taxRule->getBehavior() === TaxCalculatorInterface::DISABLE_METHOD) {
                break;
            }
        }

        return new TaxRulesTaxCalculator($taxRates, $behavior);
    }
}
