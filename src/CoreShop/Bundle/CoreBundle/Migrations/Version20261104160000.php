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
use Doctrine\DBAL\Types\TextType;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store coreshop_country.addressFormat as TEXT instead of VARCHAR(255) (AddressBundle B-11).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->isVarchar($schema)) {
            return;
        }

        $this->addSql('ALTER TABLE coreshop_country CHANGE addressFormat addressFormat LONGTEXT NOT NULL;');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('coreshop_country') || $this->isVarchar($schema)) {
            return;
        }

        $tooLong = $this->connection->fetchFirstColumn(
            'SELECT id FROM coreshop_country WHERE CHAR_LENGTH(addressFormat) > 255',
        );
        $this->abortIf(
            [] !== $tooLong,
            sprintf('The address format of these countries is longer than 255 characters (ids: %s). Shorten it, then run the migration again.', implode(', ', $tooLong)),
        );

        $this->addSql('ALTER TABLE coreshop_country CHANGE addressFormat addressFormat VARCHAR(255) NOT NULL;');
    }

    private function isVarchar(Schema $schema): bool
    {
        if (!$schema->hasTable('coreshop_country')) {
            return false;
        }

        $table = $schema->getTable('coreshop_country');

        // tables created by the schema tool already match the mapping
        return $table->hasColumn('addressFormat') && !$table->getColumn('addressFormat')->getType() instanceof TextType;
    }
}
