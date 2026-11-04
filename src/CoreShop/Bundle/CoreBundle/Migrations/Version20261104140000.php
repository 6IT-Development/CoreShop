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

final class Version20261104140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make coreshop_country.isoCode NOT NULL and unique (AddressBundle B-09).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('coreshop_country')) {
            return;
        }

        $table = $schema->getTable('coreshop_country');

        // tables created by the schema tool already match the mapping
        if ($table->hasIndex('coreshop_country_iso_code')) {
            return;
        }

        $missing = $this->connection->fetchFirstColumn(
            "SELECT id FROM coreshop_country WHERE isoCode IS NULL OR isoCode = ''",
        );
        $this->abortIf(
            [] !== $missing,
            sprintf('Countries without an ISO code (ids: %s). Set their isoCode, then run the migration again.', implode(', ', $missing)),
        );

        $duplicates = $this->connection->fetchFirstColumn(
            'SELECT isoCode FROM coreshop_country GROUP BY isoCode HAVING COUNT(*) > 1',
        );
        $this->abortIf(
            [] !== $duplicates,
            sprintf('Duplicate country ISO codes: %s. Merge or fix those countries (addresses and states reference them), then run the migration again.', implode(', ', $duplicates)),
        );

        $dropOldIndex = $table->hasIndex('isoCode') ? ' DROP INDEX isoCode,' : '';

        $this->addSql(
            'ALTER TABLE coreshop_country
            CHANGE isoCode isoCode VARCHAR(2) NOT NULL,' . $dropOldIndex . '
            ADD UNIQUE INDEX coreshop_country_iso_code (isoCode);',
        );
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('coreshop_country') || !$schema->getTable('coreshop_country')->hasIndex('coreshop_country_iso_code')) {
            return;
        }

        $this->addSql(
            'ALTER TABLE coreshop_country
            CHANGE isoCode isoCode VARCHAR(2) DEFAULT NULL,
            DROP INDEX coreshop_country_iso_code,
            ADD INDEX isoCode (isoCode);',
        );
    }
}
