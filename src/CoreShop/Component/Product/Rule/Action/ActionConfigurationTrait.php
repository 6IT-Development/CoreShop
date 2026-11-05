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

namespace CoreShop\Component\Product\Rule\Action;

use CoreShop\Component\Currency\Model\CurrencyInterface;
use CoreShop\Component\Currency\Repository\CurrencyRepositoryInterface;
use CoreShop\Component\Product\Exception\InvalidActionConfigurationException;
use CoreShop\Component\Product\Model\ProductInterface;
use CoreShop\Component\Product\Model\ProductUnitDefinitionInterface;

/**
 * Reads action configuration values that come from the admin form, the API,
 * imports, fixtures or migrations, and may therefore be strings, floats or
 * missing.
 *
 * @internal
 */
trait ActionConfigurationTrait
{
    private function getIntConfiguration(array $configuration, string $key): int
    {
        $value = $configuration[$key] ?? null;

        if (!is_numeric($value)) {
            throw InvalidActionConfigurationException::invalidValue(static::class, $key);
        }

        return (int) round((float) $value);
    }

    private function getFloatConfiguration(array $configuration, string $key): float
    {
        $value = $configuration[$key] ?? null;

        if (!is_numeric($value)) {
            throw InvalidActionConfigurationException::invalidValue(static::class, $key);
        }

        return (float) $value;
    }

    /**
     * Fixed prices and amounts of the price rules are per default unit: they apply when there is no unit in
     * the context, when the context unit is the product's default unit, or when the product has no default
     * unit definition (the context unit is then the base unit). For any other unit (a "box of 12") the
     * unit's own price applies; a percent discount applies to every unit.
     */
    private function isDefaultUnitContext(mixed $subject, array $context): bool
    {
        $unitDefinition = $context['unitDefinition'] ?? null;

        if (!$unitDefinition instanceof ProductUnitDefinitionInterface || !$subject instanceof ProductInterface) {
            return true;
        }

        $defaultUnitDefinition = $subject->getUnitDefinitions()?->getDefaultUnitDefinition();

        if (!$defaultUnitDefinition instanceof ProductUnitDefinitionInterface) {
            return true;
        }

        if ($unitDefinition === $defaultUnitDefinition) {
            return true;
        }

        return null !== $unitDefinition->getId() && $unitDefinition->getId() === $defaultUnitDefinition->getId();
    }

    private function getConfiguredCurrency(CurrencyRepositoryInterface $currencyRepository, array $configuration): CurrencyInterface
    {
        $currencyId = $configuration['currency'] ?? null;
        $currency = null === $currencyId ? null : $currencyRepository->find($currencyId);

        if (!$currency instanceof CurrencyInterface) {
            throw InvalidActionConfigurationException::currencyNotFound(static::class, $currencyId);
        }

        return $currency;
    }
}
