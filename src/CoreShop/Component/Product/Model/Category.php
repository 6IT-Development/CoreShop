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

namespace CoreShop\Component\Product\Model;

use CoreShop\Component\Resource\Pimcore\Model\AbstractPimcoreModel;
use Pimcore\Model\DataObject\Listing;

abstract class Category extends AbstractPimcoreModel implements CategoryInterface
{
    /**
     * The published categories whose parent category is this one (the `parentCategory` relation
     * that `findChildCategories()` and the first-level lookup use), sorted like the Pimcore tree
     * of this category. Folders, variants and objects of other classes are not included.
     */
    public function getChildCategories(): Listing
    {
        if (null === $this->getId()) {
            $list = new Listing();
            $list->setObjects([]);

            return $list;
        }

        $list = static::getList();
        $list->setCondition('parentCategory__id = ?', [$this->getId()]);
        $list->setOrderKey($this->getChildrenSortBy());
        $list->setOrder($this->getChildrenSortOrder());

        return $list;
    }

    public function hasChildCategories(): bool
    {
        return $this->getChildCategories()->getTotalCount() > 0;
    }

    public function getNameForSlug(?string $language = null): ?string
    {
        return $this->getName($language);
    }

    /**
     * This category and its published parent categories (by `parentCategory`), root first.
     * Unpublished parents are skipped; the walk stops at a cycle.
     */
    public function getHierarchy(): array
    {
        $hierarchy = [$this];
        $visited = [self::visitKey($this) => true];

        $category = $this->getParentCategory();

        while ($category instanceof CategoryInterface && !isset($visited[self::visitKey($category)])) {
            $visited[self::visitKey($category)] = true;

            if ($category->isPublished()) {
                $hierarchy[] = $category;
            }

            $category = $category->getParentCategory();
        }

        /**
         * @var CategoryInterface[] $hierarchy
         */
        $hierarchy = array_reverse($hierarchy);

        return $hierarchy;
    }

    private static function visitKey(CategoryInterface $category): string
    {
        return null !== $category->getId() ? 'id:' . $category->getId() : 'obj:' . spl_object_id($category);
    }
}
