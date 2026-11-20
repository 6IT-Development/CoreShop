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

namespace CoreShop\Bundle\CoreBundle\Product;

use CoreShop\Component\Core\Model\ProductInterface;
use CoreShop\Component\Core\Model\ProductStoreValuesInterface;
use CoreShop\Component\Pimcore\DataObject\InheritanceHelper;
use Doctrine\DBAL\Connection;
use Pimcore\Model\DataObject\ClassDefinition\Data\ResourcePersistenceAwareInterface;
use Pimcore\Model\DataObject\Concrete;

/**
 * Compares a product that is being saved (call it from preUpdate) with what is stored. Pimcore's dirty detection
 * can not be used: most generated setters (store values, tax rule, money fields) never mark their field dirty, and
 * a freshly loaded copy shares the Doctrine store value entities with the changed one.
 */
final class StoredProductComparator
{
    private const string STORE_VALUES_TABLE = 'coreshop_product_store_values';

    private const string STORE_VALUES_FIELD = 'storeValues';

    public function __construct(
        private Connection $db,
    ) {
    }

    public function hasPublishedChanged(Concrete $product): bool
    {
        $stored = $this->db->createQueryBuilder()
            ->select('published')
            ->from('objects')
            ->where('id = :id')
            ->setParameter('id', $product->getId())
            ->fetchOne();

        return $stored !== false && (bool) $stored !== $product->getPublished();
    }

    /**
     * The own store values only (price and tax rule per store), an inherited value is stored at the parent.
     */
    public function haveStoreValuesChanged(Concrete $product): bool
    {
        if (!$product instanceof ProductInterface || !$product->getClass()->getFieldDefinition(self::STORE_VALUES_FIELD)) {
            return false;
        }

        $stored = $this->db->createQueryBuilder()
            ->select('store', 'price', 'taxRuleId')
            ->from(self::STORE_VALUES_TABLE)
            ->where('product = :product')
            ->andWhere('fieldName = :fieldName')
            ->setParameter('product', $product->getId())
            ->setParameter('fieldName', self::STORE_VALUES_FIELD)
            ->fetchAllAssociative();

        $storedPrices = [];
        foreach ($stored as $row) {
            $storedPrices[(int) $row['store']] = [(int) $row['price'], $row['taxRuleId'] === null ? null : (int) $row['taxRuleId']];
        }

        $currentPrices = [];
        $storeValues = InheritanceHelper::useInheritedValues(static fn () => $product->getStoreValues(), false);
        foreach ($storeValues as $storeValue) {
            if (!$storeValue instanceof ProductStoreValuesInterface || null === $storeValue->getStore()) {
                continue;
            }

            $currentPrices[(int) $storeValue->getStore()->getId()] = [$storeValue->getPrice(), $storeValue->getTaxRule()?->getId()];
        }

        ksort($storedPrices);
        ksort($currentPrices);

        return $storedPrices !== $currentPrices;
    }

    /**
     * Fields that are stored in a column of the object_store table (input, numeric, checkbox, select, money, single
     * relations of CoreShop...). Other field types are ignored, list them in a configuration only if they have one.
     *
     * @param list<string> $fieldNames
     */
    public function haveFieldsChanged(Concrete $product, array $fieldNames): bool
    {
        $current = InheritanceHelper::useInheritedValues(fn () => $this->columnValues($product, $fieldNames), false);
        if ($current === []) {
            return false;
        }

        $queryBuilder = $this->db->createQueryBuilder()
            ->from('object_store_' . $product->getClassId())
            ->where('oo_id = :id')
            ->setParameter('id', $product->getId());
        foreach (array_keys($current) as $column) {
            $queryBuilder->addSelect($this->db->quoteIdentifier($column));
        }

        $stored = $queryBuilder->fetchAssociative();
        if ($stored === false) {
            return false;
        }

        foreach ($current as $column => $value) {
            if (self::comparable($stored[$column] ?? null) !== self::comparable($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $fieldNames
     *
     * @return array<string, mixed> by column
     */
    private function columnValues(Concrete $product, array $fieldNames): array
    {
        $columns = [];
        foreach ($fieldNames as $fieldName) {
            $definition = $product->getClass()->getFieldDefinition($fieldName);
            if (!$definition instanceof ResourcePersistenceAwareInterface || !method_exists($product, 'get' . ucfirst($fieldName))) {
                continue;
            }

            $value = $definition->getDataForResource(
                $product->{'get' . ucfirst($fieldName)}(),
                $product,
                ['owner' => $product, 'fieldname' => $fieldName],
            );

            // multi-column types (quantity value, ...) return their columns by name
            foreach (is_array($value) ? $value : [$fieldName => $value] as $column => $columnValue) {
                $columns[$column] = $columnValue;
            }
        }

        return $columns;
    }

    private static function comparable(mixed $value): ?string
    {
        return match (true) {
            null === $value => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => serialize($value),
        };
    }
}
