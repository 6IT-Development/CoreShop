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
namespace CoreShop\Component\Address\Formatter;

/**
 * Builds the translation key of a salutation value, shared by the salutation form choices and
 * the address formatter so that one salutation always uses one key.
 */
final class SalutationTranslationKey
{
    public const PREFIX = 'coreshop.form.customer.salutation.';

    public static function fromSalutation(string $salutation): string
    {
        return self::PREFIX . str_replace(' ', '_', mb_strtolower(trim($salutation)));
    }
}
