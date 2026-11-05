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

namespace CoreShop\Component\Product\Exception;

/**
 * Thrown by a product price rule action processor when the stored action
 * configuration cannot be used (missing or non-numeric value, deleted
 * currency). The rule calculator logs it and skips the rule.
 */
final class InvalidActionConfigurationException extends \InvalidArgumentException
{
    public static function invalidValue(string $processorClass, string $key): self
    {
        return new self(sprintf('Action processor "%s": configuration value "%s" is missing or not numeric.', $processorClass, $key));
    }

    public static function currencyNotFound(string $processorClass, mixed $currencyId): self
    {
        return new self(sprintf('Action processor "%s": currency "%s" does not exist.', $processorClass, is_scalar($currencyId) ? (string) $currencyId : get_debug_type($currencyId)));
    }
}
