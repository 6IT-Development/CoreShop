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

namespace CoreShop\Component\Pimcore\DataObject;

use CoreShop\Component\Resource\Model\ResourceInterface;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Model\Element\ElementInterface;

/**
 * A hash of what saving the object would write: its tree position, published state and the values of its fields.
 * Two equal fingerprints mean a save can be skipped. Pimcore's dirty detection can not tell this, most generated
 * setters do not mark their field dirty.
 *
 * A value it can not describe gets a random part, so it is never taken for unchanged.
 */
final class ObjectFingerprint
{
    public static function of(Concrete $object): string
    {
        $values = [
            '@key' => $object->getKey(),
            '@parent' => $object->getParentId(),
            '@published' => $object->getPublished(),
        ];

        foreach ($object->getClass()->getFieldDefinitions() as $name => $definition) {
            $values[$name] = self::normalize(self::read($object, $name));
        }

        return hash('xxh128', serialize($values));
    }

    private static function read(object $owner, string $name): mixed
    {
        $getter = 'get' . ucfirst($name);

        return method_exists($owner, $getter) ? $owner->$getter() : '@unreadable:' . bin2hex(random_bytes(8));
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            null === $value, is_scalar($value) => $value,
            // stored the same way: a loaded empty collection or relation list is null, a processed one is empty
            [] === $value, $value instanceof Fieldcollection && $value->getItems() === [] => null,
            is_array($value) => array_map(self::normalize(...), $value),
            $value instanceof ElementInterface => ['@element', $value->getType(), $value->getId()],
            $value instanceof ResourceInterface => ['@resource', $value::class, $value->getId()],
            $value instanceof Localizedfield => self::normalize($value->getItems()),
            $value instanceof Fieldcollection => array_map(self::normalizeFieldcollectionItem(...), $value->getItems()),
            $value instanceof Objectbrick => self::normalizeObjectbrick($value),
            $value instanceof \DateTimeInterface => ['@date', $value->format('U.u')],
            $value instanceof \BackedEnum => ['@enum', $value::class, $value->value],
            default => self::normalizeOther($value),
        };
    }

    private static function normalizeFieldcollectionItem(Fieldcollection\Data\AbstractData $item): array
    {
        $values = ['@type' => $item->getType()];
        foreach ($item->getDefinition()->getFieldDefinitions() as $name => $definition) {
            $values[$name] = self::normalize(self::read($item, $name));
        }

        return $values;
    }

    private static function normalizeObjectbrick(Objectbrick $container): array
    {
        $values = [];
        foreach ($container->getItems() as $brick) {
            $brickValues = ['@type' => $brick->getType()];
            foreach ($brick->getDefinition()->getFieldDefinitions() as $name => $definition) {
                $brickValues[$name] = self::normalize(self::read($brick, $name));
            }
            $values[] = $brickValues;
        }

        return $values;
    }

    private static function normalizeOther(object $value): string
    {
        try {
            return $value::class . ':' . serialize($value);
        } catch (\Throwable) {
            return '@unserializable:' . bin2hex(random_bytes(8));
        }
    }
}
