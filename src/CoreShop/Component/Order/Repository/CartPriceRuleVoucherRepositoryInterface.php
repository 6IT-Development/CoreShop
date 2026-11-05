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

namespace CoreShop\Component\Order\Repository;

use CoreShop\Component\Order\Model\CartPriceRuleInterface;
use CoreShop\Component\Order\Model\CartPriceRuleVoucherCodeInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

interface CartPriceRuleVoucherRepositoryInterface extends RepositoryInterface
{
    /**
     * @param list<array{field: 'code'|'used'|'uses'|'creationDate', operator: 'like'|'eq'|'lt'|'gt', value: mixed}> $filters
     * @param array<'code'|'used'|'uses'|'creationDate', 'ASC'|'DESC'>                                            $orderBy
     */
    public function findAllPaginator(CartPriceRuleInterface $cartPriceRule, int $offset, int $limit, array $filters = [], array $orderBy = []): Paginator;

    public function findByCode(string $code): ?CartPriceRuleVoucherCodeInterface;

    /**
     * Counts the codes in the format the generator stores: prefix, $length
     * random characters and suffix, with a hyphen after every $hyphensOn
     * characters when $hyphensOn > 0.
     */
    public function countCodes(int $length, ?string $prefix = null, ?string $suffix = null, int $hyphensOn = 0): int;
}
