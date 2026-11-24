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

namespace CoreShop\Bundle\CoreBundle\Migrations;

use CoreShop\Component\Pimcore\DataObject\ClassUpdate;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds CoreShopOrder.persistVersion: moved by every CartManager::persistCart(), so a copy of a cart that is older
 * than the stored one is refused instead of undoing a newer save (CartPersistConflictException). Until it exists,
 * CartManager does not check versions.
 */
final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CoreShopOrder.persistVersion for the conflict check of concurrent cart saves';
    }

    public function up(Schema $schema): void
    {
        $classUpdater = new ClassUpdate(
            \Pimcore::getContainer()->getParameter('coreshop.model.order.pimcore_class_name'),
        );

        if ($classUpdater->hasField('persistVersion')) {
            return;
        }

        $classUpdater->insertFieldAfter($classUpdater->hasField('cartNotices') ? 'cartNotices' : 'needsRecalculation', [
            'fieldtype' => 'numeric',
            'width' => '',
            'defaultValue' => null,
            'queryColumnType' => 'bigint(20)',
            'columnType' => 'bigint(20)',
            'phpdocType' => 'int',
            'integer' => true,
            'unsigned' => true,
            'minValue' => null,
            'maxValue' => null,
            'unique' => false,
            'decimalSize' => null,
            'decimalPrecision' => null,
            'name' => 'persistVersion',
            'title' => 'coreshop.order.persist_version',
            'tooltip' => '',
            'mandatory' => false,
            'noteditable' => true,
            'index' => false,
            'locked' => false,
            'style' => '',
            'permissions' => null,
            'datatype' => 'data',
            'relationType' => false,
            'invisible' => true,
            'visibleGridView' => false,
            'visibleSearch' => false,
            'defaultValueGenerator' => '',
        ]);

        $classUpdater->save();
    }

    public function down(Schema $schema): void
    {
        $classUpdater = new ClassUpdate(
            \Pimcore::getContainer()->getParameter('coreshop.model.order.pimcore_class_name'),
        );

        if (!$classUpdater->hasField('persistVersion')) {
            return;
        }

        $classUpdater->removeField('persistVersion');
        $classUpdater->save();
    }
}
