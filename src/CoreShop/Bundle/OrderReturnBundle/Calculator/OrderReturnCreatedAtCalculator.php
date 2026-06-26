<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Calculator;

use CoreShop\Component\OrderReturn\Model\OrderReturnInterface;
use Pimcore\Model\DataObject\ClassDefinition\CalculatorClassInterface;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\CalculatedValue;

class OrderReturnCreatedAtCalculator implements CalculatorClassInterface
{
    /**
     * @param Concrete $object
     * @param CalculatedValue $context
     * @return string
     */
    public function compute(Concrete $object, CalculatedValue $context): string
    {
        if ($object instanceof OrderReturnInterface) {
            if (method_exists($object, 'formatDate')) {
                return (string)$object->formatDate($object->getCreationDate());
            }
        }

        return '';
    }

    /**
     * @param Concrete $object
     * @param CalculatedValue $context
     * @return string
     */
    public function getCalculatedValueForEditMode(Concrete $object, CalculatedValue $context): string
    {
        return $this->compute($object, $context);
    }
}
