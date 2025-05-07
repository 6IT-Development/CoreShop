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

namespace CoreShop\Bundle\IndexBundle\Worker;

use CoreShop\Bundle\IndexBundle\Worker\OpenSearchWorker\Builder\MappingBuilder;
use CoreShop\Bundle\IndexBundle\Worker\OpenSearchWorker\Builder\SettingsBuilder;
use CoreShop\Component\Index\Condition\ConditionRendererInterface;
use CoreShop\Component\Index\Listing\ListingInterface;
use CoreShop\Component\Index\Model\IndexableInterface;
use CoreShop\Component\Index\Model\IndexColumnInterface;
use CoreShop\Component\Index\Model\IndexInterface;
use CoreShop\Component\Index\Order\OrderRendererInterface;
use CoreShop\Component\Index\Worker\FilterGroupHelperInterface;
use CoreShop\Component\Index\Worker\WorkerDeleteableByIdInterface;
use CoreShop\Component\Registry\ServiceRegistryInterface;
use OpenSearch\Client;
use Pimcore\Tool;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use function Symfony\Component\String\u;

class OpenSearchWorker extends AbstractWorker implements WorkerDeleteableByIdInterface
{
    private readonly string $defaultLocale;

    public function __construct(
        ServiceRegistryInterface $extensions,
        ServiceRegistryInterface $getterServiceRegistry,
        ServiceRegistryInterface $interpreterServiceRegistry,
        FilterGroupHelperInterface $filterGroupHelper,
        ConditionRendererInterface $conditionRenderer,
        OrderRendererInterface $orderRenderer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ServiceRegistryInterface $clientRegistry,
        private readonly MappingBuilder $mappingBuilder,
        private readonly SettingsBuilder $settingsBuilder,
        private readonly SluggerInterface $slugger
    ) {
        parent::__construct(
            $extensions,
            $getterServiceRegistry,
            $interpreterServiceRegistry,
            $filterGroupHelper,
            $conditionRenderer,
            $orderRenderer
        );

        $this->defaultLocale = Tool::getDefaultLanguage();
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

        $body = [
            'settings' => $this->settingsBuilder->build($index),
            'mappings' => $this->mappingBuilder->build($index),
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
            ]);
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
            ]);
    }

    public function renameIndexStructures(IndexInterface $index, string $oldName, string $newName): void
    {
        $client = $this->getClient($index);
        $oldIndex = $this->getIndexName($oldName);
        $newIndex = $this->getIndexName($newName);

        // First, check if the old index exists
        if (! $client->indices()->exists(['index' => $oldIndex])) {
            return;
        }

        // Then, reindex the data from the old index to the new one
        $client
            ->reindex([
                'body' => [
                    'source' => [
                        'index' => $oldIndex,
                    ],
                    'dest' => [
                        'index' => $newIndex,
                    ],
                ],
            ]);

        // Finally, delete the old index
        $client
            ->indices()
            ->delete([
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
            ]);
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
            ]);
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
        if (! $object->getIndexable($index)) {
            $client
                ->delete([
                    'index' => $indexName,
                    'id' => $objectId,
                ]);

            return;
        }

        $client
            ->index([
                'index' => $indexName,
                'id' => $objectId,
                'body' => $this->prepareDataForOpenSearch($index, $object),
            ]);
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
                    ->toString()
            )
        );
    }

    public function getClient(IndexInterface $index): Client
    {
        $config = $index->getConfiguration();

        if (empty($config['client'])) {
            throw new \RuntimeException('Missing client in index configuration.');
        }

        $client = $this->clientRegistry->get($config['client']);

        if (! $client instanceof Client) {
            throw new \RuntimeException(
                \sprintf(
                    'OpenSearch client "%s" could not be found. Available clients: "%s"',
                    $config['client'],
                    \implode(", ", \array_keys($this->clientRegistry->all()))
                )
            );
        }

        return $client;
    }

    protected function typeCastValues(IndexColumnInterface $column, $value)
    {
        return $value;
    }

    protected function handleArrayValues(IndexInterface $index, array $value): array
    {
        return $value;
    }

    /**
     * @throws \Exception
     */
    private function prepareDataForOpenSearch(IndexInterface $index, IndexableInterface $object): array
    {
        $preparedData = $this->prepareData($index, $object);

        if (! \is_array($preparedData['localizedData']['values'])) {
            return $preparedData;
        }

        // Prepare localized data for OpenSearch
        foreach ($preparedData['localizedData']['values'] as $locale => $localizedValues) {
            foreach ($localizedValues as $fieldName => $value) {
                // Load data and make sure to always pass data
                $localizedValue = $value;

                if (empty($localizedValue)) {
                    $localizedValue = $preparedData['localizedData']['values'][$this->defaultLocale][$fieldName];
                }

                $preparedData['data'][$fieldName][$locale] = $localizedValue;
            }
        }

        return $preparedData['data'];
    }
}
