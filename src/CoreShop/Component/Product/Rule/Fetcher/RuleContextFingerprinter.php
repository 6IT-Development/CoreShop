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

namespace CoreShop\Component\Product\Rule\Fetcher;

use CoreShop\Component\Resource\Model\ResourceInterface;

/**
 * Generic fingerprint of a rule context: scalars by value, resources by class
 * and id, dates by timestamp, arrays recursively. Objects without an id are
 * identified by their instance. State inside an object (for example the items
 * of a cart) is not covered here; decorate this service to add it.
 */
final class RuleContextFingerprinter implements RuleContextFingerprinterInterface
{
    public function fingerprint(array $context): ?string
    {
        ksort($context);

        return hash('xxh128', $this->fingerprintValue($context));
    }

    private function fingerprintValue(mixed $value): string
    {
        return match (true) {
            null === $value => 'n',
            is_bool($value) => $value ? 'b1' : 'b0',
            is_int($value) => 'i' . $value,
            is_float($value) => 'f' . var_export($value, true),
            is_string($value) => 's' . strlen($value) . ':' . $value,
            is_array($value) => $this->fingerprintArray($value),
            $value instanceof \BackedEnum => 'e' . $value::class . ':' . $value->value,
            $value instanceof \UnitEnum => 'e' . $value::class . ':' . $value->name,
            $value instanceof \DateTimeInterface => 'd' . $value->format('U.u'),
            is_object($value) => $this->fingerprintObject($value),
            default => 'x' . get_debug_type($value),
        };
    }

    private function fingerprintArray(array $value): string
    {
        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = (is_int($key) ? 'i' . $key : 's' . strlen($key) . ':' . $key) . '=' . $this->fingerprintValue($item);
        }

        return '[' . implode(',', $parts) . ']';
    }

    private function fingerprintObject(object $value): string
    {
        if ($value instanceof ResourceInterface && null !== $value->getId()) {
            return 'r' . $value::class . ':' . $value->getId();
        }

        return 'o' . $value::class . '#' . spl_object_id($value);
    }
}
