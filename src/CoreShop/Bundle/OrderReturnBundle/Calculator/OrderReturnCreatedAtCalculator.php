<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Calculator;

use CoreShop\Component\OrderReturn\Model\OrderReturnInterface;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\CalculatedValue;

class OrderReturnCreatedAtCalculator
{
    /**
     * @param Concrete $object
     * @param CalculatedValue $context
     * @return string
     */
    public function compute(Concrete $object, CalculatedValue $context): string
    {
        if ($object instanceof OrderReturnInterface) {
            // We call the model's formatDate method to avoid infinite loop
            // since getCreatedAt() might be overridden by Pimcore to call this calculator.
            if (method_exists($object, 'formatDate')) {
                // Since formatDate is protected in the model, we use a trick or just use the same logic here.
                // Actually, let's make it public in the model to be usable by the calculator.
                return (string)$object->formatDate($object->getCreationDate());
            }
        }

        return '';
    }

    /**
     * @param Concrete $object
     * @param CalculatedValue $context
     * @return array|null
     */
    public function getCacheData(Concrete $object, CalculatedValue $context): ?array
    {
        return null;
    }
}
