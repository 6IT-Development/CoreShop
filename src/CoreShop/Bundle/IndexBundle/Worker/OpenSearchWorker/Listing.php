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
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;

class Listing extends AbstractListing
{
    protected const INTEGER_MAX_VALUE = 2147483647; // OpenSearch Integer.MAX_VALUE is 2^31 - 1

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

    protected array $preparedGroupByValues = [];

    protected array $preparedGroupByValuesResults = [];

    protected bool $preparedGroupByValuesLoaded = false;

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
            $params['body']['from'] = $this->getOffset();
        }

        if (null !== $this->getLimit()) {
            $params['body']['size'] = $this->getLimit();
        }

        $result = $this->getWorker()->getClient($this->index)
            ->search($params);

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
        if (!$this->preparedGroupByValuesLoaded) {
            $this->doLoadGroupByValues();
        }

        $results = $this->preparedGroupByValuesResults[$fieldName] ?? null;

        if (null === $results) {
            return [];
        }

        if (true === $countValues) {
            return $results;
        }

        return \array_column($results, 'value');
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
        $object = \current($this->getObjects());

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

    /**
     * Loads all prepared "group by" values
     */
    private function doLoadGroupByValues(): void
    {
        // Create general filters and queries
        $toExcludeFieldNames = [];

        foreach ($this->preparedGroupByValues as $fieldName => $config) {
            if (true === $config['fieldnameShouldBeExcluded']) {
                $toExcludeFieldNames[] = $fieldName;
            }
        }

        // Get base search parameters
        $params = $this->getSearchParams();

        // Reset size and remove existing aggregations if any
        $params['body']['size'] = 0;
        $params['body']['_source'] = false;
        $params['body']['from'] = $this->getOffset();

        // Initialize aggregations array
        $aggregations = [];

        // Calculate already filtered attributes to avoid duplicate filtering
        $filteredFieldNames = [];

        foreach ($this->conditions as $fieldName => $condition) {
            if (! \in_array($fieldName, $toExcludeFieldNames, true)) {
                $filteredFieldNames[$fieldName] = $fieldName;
            }
        }

        foreach ($this->relationConditions as $fieldName => $condition) {
            if (! \in_array($fieldName, $toExcludeFieldNames, true)) {
                $filteredFieldNames[$fieldName] = $fieldName;
            }
        }

        // Create aggregations for each prepared field
        foreach ($this->preparedGroupByValues as $fieldName => $config) {
            $specificFilters = [];
            // User-specific filters
            $specificFilters = $this->buildFilterConditions($specificFilters, [...$filteredFieldNames, $fieldName]);
            // Relation conditions
            $specificFilters = $this->buildRelationConditions($specificFilters, [...$filteredFieldNames, $fieldName]);

            // Define the aggregation config
            if (! empty($config['aggregationConfig'])) {
                $aggregation = $config['aggregationConfig'];
            } else {
                $aggregation = [
                    'terms' => [
                        'field' => $fieldName,
                        'size' => self::INTEGER_MAX_VALUE,
                        'order' => ['o_key' => 'asc']
                    ],
                ];
            }

            // Add filters to the aggregation if needed
            if ($specificFilters) {
                $aggregations[$fieldName] = [
                    'filter' => [
                        'bool' => [
                            'must' => $specificFilters,
                        ],
                    ],
                    'aggs' => [
                        $fieldName => $aggregation,
                    ],
                ];

                // Add special handling for variant mode
                if ($this->getVariantMode() === ListingInterface::VARIANT_MODE_INCLUDE_PARENT_OBJECT) {
                    $aggregations[$fieldName]['aggs'][$fieldName]['aggs'] = [
                        'objectCount' => ['cardinality' => ['field' => 'o_virtualProductId']],
                    ];
                }
            } else {
                $aggregations[$fieldName] = $aggregation;

                // Add special handling for variant mode
                if ($this->getVariantMode() === ListingInterface::VARIANT_MODE_INCLUDE_PARENT_OBJECT) {
                    $aggregations[$fieldName]['aggs'] = [
                        'objectCount' => ['cardinality' => ['field' => 'o_virtualProductId']],
                    ];
                }
            }
        }

        // If we have aggregations, add them to the search params and execute the query
        if ($aggregations) {
            $params['body']['aggs'] = $aggregations;

            // Modify variant mode for aggregations if needed
            $variantModeForAggregations = $this->getVariantMode();
            if ($this->getVariantMode() === ListingInterface::VARIANT_MODE_INCLUDE_PARENT_OBJECT) {
                $variantModeForAggregations = ListingInterface::VARIANT_MODE_VARIANTS_ONLY;

                // Update the query to reflect the correct variant mode
                $params = $this->updateQueryForVariantMode($params, $variantModeForAggregations);
            }

            // Send a request to OpenSearch
            $result = $this->getWorker()->getClient($this->index)
                ->search($params);

            // Process result and extract aggregation values
            $this->processAggregationResults($result);
        } else {
            $this->preparedGroupByValuesResults = [];
        }

        $this->preparedGroupByValuesLoaded = true;
    }

    /**
     * Process the aggregation results from OpenSearch
     */
    private function processAggregationResults(array $result): void
    {
        if (isset($result['aggregations'])) {
            foreach ($result['aggregations'] as $fieldName => $aggregation) {
                $groupByValueResult = [];
                $buckets = $this->extractBuckets($aggregation);

                if ($buckets) {
                    foreach ($buckets as $bucket) {
                        if ($this->getVariantMode() === ListingInterface::VARIANT_MODE_INCLUDE_PARENT_OBJECT) {
                            $groupByValueResult[] = [
                                'value' => $bucket['key'],
                                'count' => $bucket['objectCount']['value']
                            ];
                        } else {
                            $data = [
                                'value' => $bucket['key'],
                                'count' => $bucket['doc_count']
                            ];

                            // Handle sub-aggregations
                            if (isset($bucket) && \is_array($bucket)) {
                                foreach ($bucket as $key => $subAggregation) {
                                    if ($key !== 'key' && $key !== 'doc_count' && $key !== 'key_as_string') {
                                        $data[$key] = $subAggregation;
                                    }
                                }
                            }

                            $groupByValueResult[] = $data;
                        }
                    }
                }

                $this->preparedGroupByValuesResults[$fieldName] = $groupByValueResult;
            }
        }
    }

    /**
     * Extract buckets from the aggregation result
     */
    private function extractBuckets(array $aggregation): array
    {
        if (isset($aggregation['buckets'])) {
            return $aggregation['buckets'];
        }

        // Search for nested aggregations
        foreach ($aggregation as $value) {
            if (! \is_array($value)) {
                continue;
            }

            if (isset($value['buckets'])) {
                return $value['buckets'];
            }

            $nestedBuckets = $this->extractBuckets($value);

            if (! empty($nestedBuckets)) {
                return $nestedBuckets;
            }
        }

        return [];
    }

    /**
     * Update the query parameters for a specific variant mode
     */
    private function updateQueryForVariantMode(array $params, string $variantMode): array
    {
        // Extract the current bool query
        $boolQuery = $params['body']['query']['bool'] ?? [];

        // Apply the variant mode filter
        if ($variantMode === ListingInterface::VARIANT_MODE_VARIANTS_ONLY) {
            $boolQuery['filter']['bool']['must'][] = [
                'term' => ['o_type' => AbstractObject::OBJECT_TYPE_VARIANT]
            ];
        } elseif ($variantMode === ListingInterface::VARIANT_MODE_HIDE) {
            $boolQuery['filter']['bool']['must'][] = [
                'term' => ['o_type' => AbstractObject::OBJECT_TYPE_OBJECT]
            ];
        }

        // Update the query in the params
        $params['body']['query']['bool'] = $boolQuery;

        return $params;
    }

    private function getSearchParams(string $excludedFieldName = null): array
    {
        $body = [
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

        return [
            'index' => $this->getWorker()->getIndexName($this->index->getName()),
            'body' => \array_merge_recursive($body, ...$renderConditions),
        ];
    }

    /**
     * Builds filter condition of user-specific conditions
     */
    private function buildFilterConditions(array $boolFilters, array $excludedFieldNames): array
    {
        foreach ($this->conditions as $fieldName => $conditions) {
            if (\in_array($fieldName, $excludedFieldNames, true)) {
                continue;
            }

            foreach ($conditions as $condition) {
                if (\is_array($condition)) {
                    $boolFilters[] = $condition;
                } else {
                    $boolFilters[] = ['term' => [$fieldName => $condition]];
                }
            }
        }

        return $boolFilters;
    }

    /**
     * Builds relation conditions of user-specific query conditions
     */
    private function buildRelationConditions(array $boolFilters, array $excludedFieldNames): array
    {
        foreach ($this->relationConditions as $fieldName => $conditions) {
            if (\in_array($fieldName, $excludedFieldNames, true)) {
                continue;
            }

            foreach ($conditions as $condition) {
                if (\is_array($condition)) {
                    $boolFilters[] = $condition;
                } else {
                    $boolFilters[] = ['term' => [$fieldName => $condition]];
                }
            }
        }

        return $boolFilters;
    }
}
