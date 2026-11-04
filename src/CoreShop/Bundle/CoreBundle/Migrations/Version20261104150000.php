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
namespace CoreShop\Bundle\CoreBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make coreshop_address_identifier.name unique (AddressBundle B-10).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('coreshop_address_identifier')) {
            return;
        }

        // tables created by the schema tool already match the mapping
        if ($schema->getTable('coreshop_address_identifier')->hasIndex('coreshop_address_identifier_name')) {
            return;
        }

        $duplicates = $this->connection->fetchFirstColumn(
            'SELECT name FROM coreshop_address_identifier GROUP BY name HAVING COUNT(*) > 1',
        );
        $this->abortIf(
            [] !== $duplicates,
            sprintf('Duplicate address identifier names: %s. Remove or rename the duplicates (Pimcore addresses reference them by id), then run the migration again.', implode(', ', $duplicates)),
        );

        $this->addSql('ALTER TABLE coreshop_address_identifier ADD UNIQUE INDEX coreshop_address_identifier_name (name);');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('coreshop_address_identifier') || !$schema->getTable('coreshop_address_identifier')->hasIndex('coreshop_address_identifier_name')) {
            return;
        }

        $this->addSql('ALTER TABLE coreshop_address_identifier DROP INDEX coreshop_address_identifier_name;');
    }
}
