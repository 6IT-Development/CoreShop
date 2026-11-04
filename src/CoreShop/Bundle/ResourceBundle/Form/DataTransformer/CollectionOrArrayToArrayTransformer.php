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
namespace CoreShop\Bundle\ResourceBundle\Form\DataTransformer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Model transformer for multiple choice fields whose model value is either a Doctrine
 * Collection or a plain array (for example a Pimcore multiselect field).
 *
 * reverseTransform() returns the same kind of value transform() received: an array for an
 * array, otherwise (Collection or no data) an ArrayCollection like Doctrine's
 * CollectionToArrayTransformer.
 *
 * @implements DataTransformerInterface<Collection|array, array>
 */
final class CollectionOrArrayToArrayTransformer implements DataTransformerInterface
{
    private bool $modelIsArray = false;

    public function transform(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if ($value instanceof Collection) {
            $this->modelIsArray = false;

            return $value->toArray();
        }

        if (is_array($value)) {
            $this->modelIsArray = true;

            return $value;
        }

        throw new TransformationFailedException(sprintf('Expected a Doctrine Collection or an array, got "%s".', get_debug_type($value)));
    }

    public function reverseTransform(mixed $value): Collection|array
    {
        if ('' === $value || null === $value) {
            $value = [];
        } elseif ($value instanceof Collection) {
            $value = $value->toArray();
        }

        if (!is_array($value)) {
            throw new TransformationFailedException(sprintf('Expected an array, got "%s".', get_debug_type($value)));
        }

        return $this->modelIsArray ? array_values($value) : new ArrayCollection($value);
    }
}
