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

namespace CoreShop\Bundle\OrderBundle\Doctrine\ORM;

use CoreShop\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use CoreShop\Component\Order\Model\CartPriceRuleInterface;
use CoreShop\Component\Order\Model\CartPriceRuleVoucherCodeInterface;
use CoreShop\Component\Order\Repository\CartPriceRuleVoucherRepositoryInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

class CartPriceRuleVoucherRepository extends EntityRepository implements CartPriceRuleVoucherRepositoryInterface
{
    public function findAllPaginator(CartPriceRuleInterface $cartPriceRule, int $offset, int $limit, array $filters = [], array $orderBy = []): Paginator
    {
        $queryBuilder = $this->createQueryBuilder('o')
            ->where('o.cartPriceRule = :cartPriceRule')
            ->setParameter('cartPriceRule', $cartPriceRule)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach (array_values($filters) as $index => $filter) {
            $field = 'o.' . $filter['field'];
            $parameter = 'filter' . $index;

            $expression = match ($filter['operator']) {
                'like' => $queryBuilder->expr()->like($field, ':' . $parameter),
                'lt' => $queryBuilder->expr()->lt($field, ':' . $parameter),
                'gt' => $queryBuilder->expr()->gt($field, ':' . $parameter),
                default => $queryBuilder->expr()->eq($field, ':' . $parameter),
            };

            $queryBuilder
                ->andWhere($expression)
                ->setParameter($parameter, $filter['operator'] === 'like' ? '%' . addcslashes((string) $filter['value'], '%_\\') . '%' : $filter['value']);
        }

        foreach ($orderBy as $field => $direction) {
            $queryBuilder->addOrderBy('o.' . $field, $direction);
        }

        $queryBuilder->addOrderBy('o.id', 'ASC');

        return new Paginator($queryBuilder);
    }

    public function findByCode(string $code): ?CartPriceRuleVoucherCodeInterface
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function countCodes(int $length, ?string $prefix = null, ?string $suffix = null): int
    {
        if ($prefix !== null) {
            $length += strlen($prefix);
        }

        if ($suffix !== null) {
            $length += strlen($suffix);
        }

        $code = $prefix . '%' . $suffix;

        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('LENGTH(o.code) = :length')
            ->andWhere('o.code LIKE :code')
            ->setParameter('length', $length)
            ->setParameter('code', $code)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }
}
