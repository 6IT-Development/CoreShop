<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Calculator;

use CoreShop\Component\OrderReturn\Model\OrderReturnInterface;
use Pimcore\Model\DataObject\ClassDefinition\CalculatorInterface;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\CalculatedValue;

class OrderReturnCreatedAtCalculator implements CalculatorInterface
{
    /**
     * @param Concrete $object
     * @param CalculatedValue $context
     * @return mixed
     */
    public function compute(Concrete $object, CalculatedValue $context): mixed
    {
        if ($object instanceof OrderReturnInterface) {
            return $object->getCreatedAt();
        }

        $creationDate = $object->getCreationDate();

        if (is_numeric($creationDate) && $creationDate > 0) {
            return date('Y-m-d H:i:s', (int) $creationDate);
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
