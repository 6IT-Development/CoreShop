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
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.org)
 * @license    https://www.coreshop.org/license     GPLv3 and CCL
 *
 */

namespace CoreShop\Bundle\IndexBundle\Worker\OpenSearchWorker;

use CoreShop\Bundle\IndexBundle\Worker\AbstractListing;
use CoreShop\Bundle\IndexBundle\Worker\OpenSearchWorker;
use CoreShop\Component\Index\Condition\ConditionInterface;
use CoreShop\Component\Index\Listing\ListingInterface;
use CoreShop\Component\Index\Worker\WorkerInterface;
use Pimcore\Model\DataObject\Concrete;

class Listing extends AbstractListing
{
    protected ?array $objects = null;

    protected string $order;

    protected string|array $orderKey;

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected string $variantMode = ListingInterface::VARIANT_MODE_HIDE;

    protected array $conditions = [];

    protected array $relationConditions = [];

    protected array $queryConditions = [];

    protected bool $enabled = true;

    protected WorkerInterface $worker;

    public function getObjects(): ?array
    {
        if ($this->objects === null) {
            $this->load();
        }

        return $this->objects;
    }

    public function addCondition(ConditionInterface $condition, $fieldName): void
    {
        $this->objects = null;
        $this->conditions[$fieldName][] = $condition;
    }

    public function addQueryCondition(ConditionInterface $condition, $fieldName): void
    {
        $this->objects = null;
        $this->queryConditions[$fieldName][] = $condition;
    }

    public function addRelationCondition(ConditionInterface $condition, $fieldName): void
    {
        $this->objects = null;
        $this->relationConditions[$fieldName][] = $condition;
    }

    public function resetCondition($fieldName): void
    {
        $this->objects = null;
        unset($this->conditions[$fieldName]);
    }

    public function resetQueryCondition($fieldName): void
    {
        $this->objects = null;
        unset($this->queryConditions[$fieldName]);
    }

    public function resetConditions(): void
    {
        $this->objects = null;
        $this->conditions = [];
        $this->relationConditions = [];
        $this->queryConditions = [];
    }

    public function setOrder($order): void
    {
        $this->objects = null;
        $this->order = $order;
    }

    public function getOrder(): string
    {
        return $this->order;
    }

    public function setOrderKey($orderKey): void
    {
        $this->objects = null;
        $this->orderKey = $orderKey;
    }

    public function getOrderKey(): array|string
    {
        return $this->orderKey;
    }

    public function setLimit($limit): void
    {
        if ($this->limit !== $limit) {
            $this->objects = null;
        }

        $this->limit = $limit;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function setOffset($offset): void
    {
        if ($this->offset !== $offset) {
            $this->objects = null;
        }

        $this->offset = $offset;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function setVariantMode($variantMode): void
    {
        $this->objects = null;
        $this->variantMode = $variantMode;
    }

    public function getVariantMode(): string
    {
        return $this->variantMode;
    }

    public function setEnabled($enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function load(array $options = []): ?array
    {
        $params = $this->getSearchParams();

        if (null !== $this->getOffset()) {
            $params['from'] = $this->getOffset();
        }

        if (null !== $this->getLimit()) {
            $params['size'] = $this->getLimit();
        }

        $result = $this->getWorker()->getClient($this->index)
            ->search([
                'index' => $this->getWorker()->getIndexName($this->index->getName()),
                'body' => $params,
            ]);

        foreach ($result['hits']['hits'] as $hit) {
            $object = Concrete::getById($hit['_source']['o_id']);

            if ($object instanceof Concrete) {
                $this->objects[] = $object;
            }
        }

        return $this->objects;
    }

    public function getGroupByValues($fieldName, $countValues = false, $fieldNameShouldBeExcluded = true): array
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function getGroupByRelationValues($fieldName, $countValues = false, $fieldNameShouldBeExcluded = true): array
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function getGroupBySystemValues($fieldName, $countValues = false, $fieldNameShouldBeExcluded = true): array
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function buildSimilarityOrderBy(array $fields, int $objectId): string
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function current(): Concrete|false
    {
        /**
         * @var Concrete|false $object
         */
        $object = $this->getObjects();

        return $object;
    }

    public function next(): void
    {
        $this->getObjects();

        \next($this->objects);
    }

    public function key(): int|null
    {
        return \key($this->getObjects());
    }

    public function valid(): bool
    {
        return false !== $this->current();
    }

    public function rewind(): void
    {
        $this->getObjects();

        \reset($this->objects);
    }

    public function count(): int
    {
        return \count($this->getObjects());
    }

    public function getItems(int $offset, int $itemCountPerPage): array
    {
        $this->setOffset($offset);
        $this->setLimit($itemCountPerPage);

        return $this->getObjects();
    }

    public function getWorker(): OpenSearchWorker
    {
        /**
         * @var OpenSearchWorker $worker
         */
        $worker = $this->worker;

        return $worker;
    }

    private function getSearchParams(string $excludedFieldName = null): array
    {
        $params = [
            'query' => [
                'bool' => [
                    'filter' => [
                        'term' => ['active' => true],
                    ],
                ],
            ],
        ];
        $renderConditions = [[]];

        foreach ($this->conditions as $fieldName => $condArray) {
            if ($fieldName === $excludedFieldName || ! \is_array($condArray)) {
                continue;
            }

            foreach ($condArray as $cond) {
                $renderConditions[] = $this->worker->renderCondition($cond);
            }
        }

        return \array_merge_recursive($params, ...$renderConditions);
    }
}
