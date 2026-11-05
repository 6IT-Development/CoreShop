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

namespace CoreShop\Bundle\ProductBundle\Doctrine\ORM;

use CoreShop\Component\Product\Model\ProductInterface;
use CoreShop\Component\Product\Model\ProductSpecificPriceRuleInterface;
use CoreShop\Component\Product\Repository\ProductSpecificPriceRuleRepositoryInterface;

class ProductSpecificPriceRuleRepository extends PriceRuleRepository implements ProductSpecificPriceRuleRepositoryInterface
{
    public function findForProduct(ProductInterface $product): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.product = :productId')
            ->setParameter('productId', $product->getId())
            ->addOrderBy('o.priority', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Active rules that have at least one condition of the given type, with all their conditions
     * and actions loaded.
     *
     * @return ProductSpecificPriceRuleInterface[]
     */
    public function findActiveWithConditionOfType(string $conditionType): array
    {
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('typedRule.id')
            ->from($this->getEntityName(), 'typedRule')
            ->innerJoin('typedRule.conditions', 'typedCondition')
            ->andWhere('typedCondition.type = :conditionType')
        ;

        return $this->createQueryBuilder('o')
            ->leftJoin('o.conditions', 'conditions')
            ->addSelect('conditions')
            ->leftJoin('o.actions', 'actions')
            ->addSelect('actions')
            ->andWhere('o.active = 1')
            ->andWhere(sprintf('o.id IN (%s)', $subQuery->getDQL()))
            ->setParameter('conditionType', $conditionType)
            ->addOrderBy('o.priority', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }
}
