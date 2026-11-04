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

namespace CoreShop\Bundle\ProductBundle\Pimcore\Repository;

use CoreShop\Bundle\ResourceBundle\Pimcore\PimcoreRepository;
use CoreShop\Component\Product\Model\CategoryInterface;
use CoreShop\Component\Product\Repository\CategoryRepositoryInterface;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Listing;

class CategoryRepository extends PimcoreRepository implements CategoryRepositoryInterface
{
    public function findFirstLevel(): array
    {
        $list = $this->getList();
        $list->setCondition('parentCategory__id is null');
        $this->applyDefaultSorting($list);

        return $list->getObjects();
    }

    public function findChildCategories(CategoryInterface $category): array
    {
        $list = $this->getList();
        $list->setCondition('parentCategory__id = ?', [$category->getId()]);

        $this->applyChildrenSorting($list, $category);

        return $list->getObjects();
    }

    /**
     * Sorts like the Pimcore tree of the category: by key or index, ascending or descending,
     * as configured on the category. Both values are whitelisted before they reach the SQL.
     */
    protected function applyChildrenSorting(Listing $list, CategoryInterface $category): void
    {
        $sortBy = AbstractObject::OBJECT_CHILDREN_SORT_BY_DEFAULT;
        $sortOrder = AbstractObject::OBJECT_CHILDREN_SORT_ORDER_DEFAULT;

        if ($category instanceof AbstractObject) {
            $sortBy = $category->getChildrenSortBy();
            $sortOrder = $category->getChildrenSortOrder();
        }

        $direction = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';

        if ($sortBy === AbstractObject::OBJECT_CHILDREN_SORT_BY_INDEX) {
            // The key breaks ties between children with the same index.
            $list->setOrderKey(sprintf('`index` %1$s, `key` %1$s', $direction), false);

            return;
        }

        $list->setOrderKey(sprintf('`key` %s', $direction), false);
    }

    /**
     * Deterministic order for listings without a parent category: index, then key.
     */
    protected function applyDefaultSorting(Listing $list): void
    {
        $list->setOrderKey('`index` ASC, `key` ASC', false);
    }
}
