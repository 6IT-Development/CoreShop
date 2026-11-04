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

namespace CoreShop\Bundle\OrderBundle\Pimcore\GridColumnConfig\Operator;

use CoreShop\Component\Currency\Formatter\MoneyFormatterInterface;
use CoreShop\Component\Currency\Model\CurrencyAwareInterface;
use CoreShop\Component\Locale\Context\LocaleContextInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\AbstractOperator;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\ResultContainer;
use Pimcore\Model\Element\ElementInterface;

class PriceFormatter extends AbstractOperator
{
    public function __construct(
        private MoneyFormatterInterface $moneyFormatter,
        private LocaleContextInterface $localeService,
        \stdClass $config,
        $context = null,
    ) {
        parent::__construct($config, $context);
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $result = new \stdClass();
        $result->label = $this->label;
        $children = $this->getChildren();

        if (!$children) {
            return $result;
        }

        $c = $children[0];
        $result = $c->getLabeledValue($element);

        if (!is_int($result->value ?? null)) {
            return $result;
        }

        $currency = null;

        if ($element instanceof OrderInterface) {
            // Only the converted* fields are in the shopper's currency, the others are in the base currency.
            $fieldName = $result->def->name ?? null;
            $currency = is_string($fieldName) && str_starts_with($fieldName, 'converted')
                ? $element->getCurrency()
                : $element->getBaseCurrency();
        } elseif ($element instanceof CurrencyAwareInterface) {
            $currency = $element->getCurrency();
        }

        if (null !== $currency) {
            $result->value = $this->moneyFormatter->format($result->value, $currency->getIsoCode(), $this->localeService->getLocaleCode());
        }

        return $result;
    }
}
