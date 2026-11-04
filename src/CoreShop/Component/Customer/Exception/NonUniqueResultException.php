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

namespace CoreShop\Component\Customer\Exception;

/**
 * Thrown by a repository lookup that is expected to match at most one object, when it matches several.
 */
class NonUniqueResultException extends \RuntimeException
{
    public static function forField(string $class, string $field): self
    {
        return new self(sprintf('More than one %s matches the given %s, expected at most one.', $class, $field));
    }
}
