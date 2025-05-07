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
use Pimcore\Tool;

class MappingBuilder
{
    public function build(IndexInterface $index): array
    {
        $properties = [];

        foreach ($index->getColumns() as $column) {
            $propertyName = $column->getName();
            $propertySettings = [
                'type' => $column->getColumnType(),
            ];

            // Localized properties
            if ($column->getInterpreter() === 'localeMapping') {
                foreach (Tool::getValidLanguages() as $locale) {
                    $localizedPropertyName = $propertyName . '.' . $locale;
                    $analyzer = LanguageAnalyzer::fromLocale($locale);

                    if ($analyzer instanceof LanguageAnalyzer) {
                        $propertySettings['analyzer'] = $analyzer->value;
                    }

                    $properties[$localizedPropertyName] = $propertySettings;
                }

                continue;
            }

            // Simple properties
            $properties[$propertyName] = $propertySettings;
        }

        return [
            'properties' => $properties,
        ];
    }
}
