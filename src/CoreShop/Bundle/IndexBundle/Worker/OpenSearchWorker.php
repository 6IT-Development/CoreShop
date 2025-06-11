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

namespace CoreShop\Bundle\IndexBundle\Worker;

use CoreShop\Bundle\IndexBundle\Worker\OpenSearchWorker\Builder\SettingsBuilder;
use CoreShop\Bundle\IndexBundle\Worker\OpenSearchWorker\Listing;
use CoreShop\Bundle\IndexBundle\Worker\OpenSearchWorker\Mapping\LanguageAnalyzer;
use CoreShop\Component\Index\Condition\ConditionInterface;
use CoreShop\Component\Index\Condition\ConditionRendererInterface;
use CoreShop\Component\Index\Extension\IndexColumnsExtensionInterface;
use CoreShop\Component\Index\Interpreter\LocalizedInterpreterInterface;
use CoreShop\Component\Index\Interpreter\RelationInterpreterInterface;
use CoreShop\Component\Index\Listing\ListingInterface;
use CoreShop\Component\Index\Model\IndexableInterface;
use CoreShop\Component\Index\Model\IndexColumnInterface;
use CoreShop\Component\Index\Model\IndexInterface;
use CoreShop\Component\Index\Order\OrderRendererInterface;
use CoreShop\Component\Index\Worker\FilterGroupHelperInterface;
use CoreShop\Component\Index\Worker\OpenSearchWorkerInterface;
use CoreShop\Component\Index\Worker\WorkerDeleteableByIdInterface;
use CoreShop\Component\Registry\ServiceRegistryInterface;
use OpenSearch\Client;
use Pimcore\Tool;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\String\Slugger\SluggerInterface;
use function Symfony\Component\String\u;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

class OpenSearchWorker extends AbstractWorker implements OpenSearchWorkerInterface, WorkerDeleteableByIdInterface
{
    public function __construct(
        ServiceRegistryInterface $extensions,
        ServiceRegistryInterface $getterServiceRegistry,
        ServiceRegistryInterface $interpreterServiceRegistry,
        FilterGroupHelperInterface $filterGroupHelper,
        ConditionRendererInterface $conditionRenderer,
        OrderRendererInterface $orderRenderer,
        private EventDispatcherInterface $eventDispatcher,
        private ServiceRegistryInterface $clientRegistry,
        private ServiceRegistryInterface $interpreterRegistry,
        private SluggerInterface $slugger,
    ) {
        parent::__construct(
            $extensions,
            $getterServiceRegistry,
            $interpreterServiceRegistry,
            $filterGroupHelper,
            $conditionRenderer,
            $orderRenderer,
        );
    }

    /**
     * @inheritDoc
     */
    public function createOrUpdateIndexStructures(IndexInterface $index): void
    {
        $client = $this->getClient($index);
        $indexName = $this->getIndexName($index->getName());

        if ($client->indices()->exists(['index' => $indexName])) {
            $this->deleteIndexStructures($index);
        }

        $config = $index->getConfiguration();
        $columns = $this->getIndexColumns($index);

        $attributes = $columns['attributes'];
        $relationalAttributes = $columns['relationalAttributes'];
        $localizedAttributes = $columns['localizedAttributes'];

        $body = [
            'settings' => [
                'index' => [
                    'number_of_shards' => $config['numberOfShards'] ?? 1,
                    'number_of_replicas' => $config['numberOfReplicas'] ?? 1,
                ]
            ],
            'mappings' => [
                'properties' => [
                    'attributes' => [
                        'type' => OpenSearchWorkerInterface::FIELD_TYPE_OBJECT,
                        'properties' => $attributes,
                    ],
                    'localizedAttributes' => [
                        'type' => OpenSearchWorkerInterface::FIELD_TYPE_OBJECT,
                        'properties' => $localizedAttributes,
                    ],
                    'relationalAttributes' => [
                        'type' => OpenSearchWorkerInterface::FIELD_TYPE_NESTED,
                        'properties' => $relationalAttributes,
                    ],
                ],
            ],
        ];

        $event = new GenericEvent($index);
        $event->setArguments([
            'index' => $indexName,
            'body' => $body,
        ]);

        $this->eventDispatcher->dispatch($event, 'coreshop.index.create_or_update_index_structures');

        $client
            ->indices()
            ->create([
                'index' => $event->getArgument('index'),
                'body' => $event->getArgument('body'),
            ])
        ;
    }

    /**
     * @inheritDoc
     */
    public function deleteIndexStructures(IndexInterface $index): void
    {
        $this->getClient($index)
            ->indices()
            ->delete([
                'index' => $this->getIndexName($index->getName()),
            ])
        ;
    }

    public function renameIndexStructures(IndexInterface $index, string $oldName, string $newName): void
    {
        $indices = $this->getClient($index)->indices();
        $oldIndex = $this->getIndexName($oldName);
        $newIndex = $this->getIndexName($newName);

        // First, check if the old index exists
        if (!$indices->exists(['index' => $oldIndex])) {
            return;
        }

        // Then, clone the whole index with the new name
        $indices->clone([
            'index' => $oldIndex,
            'target' => $newIndex,
            'wait_for_completion' => true,
        ]);

        // Finally, delete the old index
        $indices->delete([
            'index' => $oldIndex,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function deleteFromIndexById(IndexInterface $index, int $id): void
    {
        $this->getClient($index)
            ->delete([
                'index' => $this->getIndexName($index->getName()),
                'id' => (string) $id,
            ])
        ;
    }

    /**
     * @inheritDoc
     */
    public function deleteFromIndex(IndexInterface $index, IndexableInterface $object): void
    {
        $this->getClient($index)
            ->delete([
                'index' => $this->getIndexName($index->getName()),
                'id' => (string) $object->getId(),
            ])
        ;
    }

    /**
     * @inheritDoc
     *
     * @throws \Exception
     */
    public function updateIndex(IndexInterface $index, IndexableInterface $object): void
    {
        $client = $this->getClient($index);
        $indexName = $this->getIndexName($index->getName());
        $objectId = (string) $object->getId();

        // Delete the document from the index if it is not indexable
        if (!$object->getIndexable($index)) {
            $client
                ->delete([
                    'index' => $indexName,
                    'id' => $objectId,
                ])
            ;

            return;
        }

        $client
            ->index([
                'index' => $indexName,
                'id' => $objectId,
                'body' => $this->prepareDataForOpenSearch($index, $object),
            ])
        ;
    }

    /**
     * @inheritDoc
     */
    public function renderFieldType($type): string
    {
        return $type;
    }

    /**
     * @inheritDoc
     */
    public function getList(IndexInterface $index): ListingInterface
    {
        return new Listing($index, $this);
    }

    public function getIndexName(string $indexName): string
    {
        return \sprintf(
            'coreshop_index_os_%s',
            $this->slugger->slug(
                u($indexName)
                    ->trim()
                    ->lower()
                    ->toString(),
            ),
        );
    }

    public function getClient(IndexInterface $index): Client
    {
        $config = $index->getConfiguration();

        if (empty($config['client'])) {
            throw new \RuntimeException('Missing client in index configuration.');
        }

        $client = $this->clientRegistry->get($config['client']);

        if (!$client instanceof Client) {
            throw new \RuntimeException(
                \sprintf(
                    'OpenSearch client "%s" could not be found. Available clients: "%s"',
                    $config['client'],
                    \implode(', ', \array_keys($this->clientRegistry->all())),
                ),
            );
        }

        return $client;
    }

    public function renderCondition(ConditionInterface $condition, array|string $params = [])
    {
        $index = $params['index'] ?? null;

        Assert::isInstanceOf($index, IndexInterface::class);

        if ($params['relation'] ?? false) {
            $params['mappedFieldName'] = 'relationalAttributes.' . $condition->getFieldName();
        } else {
            $params['mappedFieldName'] = $this->getMappedFieldName($index, $condition->getFieldName());
        }

        return parent::renderCondition($condition, $params);
    }

    /**
     * @throws \RuntimeException
     */
    public function getMappedFieldName(IndexInterface $index, string $fieldName): string
    {
        $columns = $this->getIndexColumns($index);

        $attributes = $columns['attributes'];
        $relationalAttributes = $columns['relationalAttributes'];
        $localizedAttributes = $columns['localizedAttributes'];

        if (array_key_exists($fieldName, $attributes)) {
            $prefix = 'attributes';
        } elseif (array_key_exists($fieldName, $relationalAttributes)) {
            $prefix = 'relationalAttributes';
        } elseif (array_key_exists($fieldName, $localizedAttributes)) {
            $prefix = 'localizedAttributes';
        } else {
            throw new \RuntimeException(sprintf('Field name %s not found in index', $fieldName));
        }

        return sprintf('%s.%s', $prefix, $fieldName);
    }

    protected function typeCastValues(IndexColumnInterface $column, $value)
    {
        return $value;
    }

    protected function handleArrayValues(IndexInterface $index, array $value): array
    {
        return $value;
    }

    protected function getSystemAttributes(): array
    {
        return [
            'o_id' => OpenSearchWorkerInterface::FIELD_TYPE_INTEGER,
            'oo_id' => OpenSearchWorkerInterface::FIELD_TYPE_INTEGER,
            'o_key' => OpenSearchWorkerInterface::FIELD_TYPE_KEYWORD,
            'o_classId' => OpenSearchWorkerInterface::FIELD_TYPE_KEYWORD,
            'o_className' => OpenSearchWorkerInterface::FIELD_TYPE_KEYWORD,
            'o_virtualObjectId' => OpenSearchWorkerInterface::FIELD_TYPE_INTEGER,
            'o_virtualObjectActive' => OpenSearchWorkerInterface::FIELD_TYPE_BOOLEAN,
            'o_type' => OpenSearchWorkerInterface::FIELD_TYPE_KEYWORD,
            'active' => OpenSearchWorkerInterface::FIELD_TYPE_BOOLEAN,
        ];
    }

    protected function getLocalizedSystemAttributes(): array
    {
        return [
            'name' => OpenSearchWorkerInterface::FIELD_TYPE_KEYWORD,
        ];
    }

    /**
     * @return array<string, array>
     */
    private function getIndexColumns(IndexInterface $index): array
    {
        $systemColumns = $this->getSystemAttributes();
        $localizedSystemColumns = $this->getLocalizedSystemAttributes();

        foreach ($this->getExtensions($index) as $extension) {
            if ($extension instanceof IndexColumnsExtensionInterface) {
                foreach ($extension->getSystemColumns() as $name => $type) {
                    $systemColumns[$name] = $type;
                }

                foreach ($extension->getLocalizedSystemColumns() as $name => $type) {
                    $localizedSystemColumns[$name] = $type;
                }
            }
        }

        $attributes = [];
        $relationalAttributes = [];
        $localizedAttributes = [];

        foreach ($systemColumns as $name => $type) {
            $attributes[$name] = [
                'type' => $type,
            ];
        }

        foreach ($localizedSystemColumns as $name => $type) {
            $localizedAttributes[$name] = $this->createLocalizedAttribute($type);
        }

        foreach ($index->getColumns() as $column) {
            $propertyName = $column->getName();
            $propertyType = $column->getColumnType();

            if ($column->getInterpreter()) {
                $interpreter = $this->interpreterRegistry->get($column->getInterpreter());

                if ($interpreter instanceof LocalizedInterpreterInterface) {
                    $localizedAttributes[$propertyName] = $this->createLocalizedAttribute($propertyType);
                } elseif ($interpreter instanceof RelationInterpreterInterface) {
                    $relationalAttributes[$propertyName] = [
                        'type' => $propertyType,
                    ];
                } else {
                    $attributes[$propertyName] = [
                        'type' => $propertyType,
                    ];
                }
            } else {
                $attributes[$propertyName] = [
                    'type' => $propertyType,
                ];
            }
        }

        return [
            'attributes' => $attributes,
            'localizedAttributes' => $localizedAttributes,
            'relationalAttributes' => $relationalAttributes,
        ];
    }

    /**
     * @throws \Exception
     */
    private function prepareDataForOpenSearch(IndexInterface $index, IndexableInterface $object): array
    {
        $openSearchData = [];
        $preparedData = $this->prepareData($index, $object);

        $openSearchData['attributes'] = $preparedData['data'];
        $openSearchData['relationalAttributes'] = $preparedData['relation'];

        foreach ($preparedData['localizedData']['values'] as $language => $localizedValues) {
            foreach ($localizedValues as $name => $value) {
                $openSearchData['localizedAttributes'][$name][$language] = $value;
            }
        }

        return $openSearchData;
    }

    private function createLocalizedAttribute(string $type): array
    {
        $attribute = [
            'type' => OpenSearchWorkerInterface::FIELD_TYPE_OBJECT,
            'properties' => [],
        ];

        foreach (Tool::getValidLanguages() as $locale) {
            $propertySettings = [
                'type' => $type,
            ];

            // Language analyzers are only supported for text fields
            if ($type === OpenSearchWorkerInterface::FIELD_TYPE_TEXT) {
                $analyzer = LanguageAnalyzer::fromLocale($locale);

                if ($analyzer instanceof LanguageAnalyzer) {
                    $propertySettings['analyzer'] = $analyzer->value;
                }
            }

            $attribute['properties'][$locale] = $propertySettings;
        }

        return $attribute;
    }
}
