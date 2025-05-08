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

namespace CoreShop\Bundle\IndexBundle\Worker\OpenSearchWorker\Builder;

use CoreShop\Bundle\IndexBundle\Worker\OpenSearchWorker\Mapping\LanguageAnalyzer;
use CoreShop\Component\Index\Model\IndexInterface;
use CoreShop\Component\Index\Worker\OpenSearchWorkerInterface;
use Pimcore\Tool;

class MappingBuilder
{
    private array $properties = [];

    public function build(IndexInterface $index, array $systemAttributes, array $localizedSystemAttributes): array
    {
        // Add system attributes
        foreach ($systemAttributes as $propertyName => $propertyType) {
            $this->addSimpleProperty($propertyName, $propertyType);
        }

        // Add localized system attributes
        foreach ($localizedSystemAttributes as $propertyName => $propertyType) {
            $this->addLocalizedProperty($propertyName, $propertyType);
        }

        // Add index columns
        foreach ($index->getColumns() as $column) {
            $propertyName = $column->getName();
            $propertyType = $column->getColumnType();

            if ($column->getInterpreter() === 'localeMapping') {
                $this->addLocalizedProperty($propertyName, $propertyType);
            } else {
                $this->addSimpleProperty($propertyName, $propertyType);
            }
        }

        return [
            'properties' => $this->properties,
        ];
    }

    private function addSimpleProperty(string $name, string $type): void
    {
        $this->properties[$name] = [
            'type' => $type,
        ];
    }

    private function addLocalizedProperty(string $name, string $type): void
    {
        $this->addSimpleProperty($name, OpenSearchWorkerInterface::FIELD_TYPE_OBJECT);

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

            $this->properties[$name]['properties'][$locale] = $propertySettings;
        }
    }
}
