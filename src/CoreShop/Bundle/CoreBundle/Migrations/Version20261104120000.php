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

final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Activate all existing tax rates and tax rule groups, because the tax calculation now skips inactive ones.';
    }

    public function up(Schema $schema): void
    {
        // the active flag was never checked before, so every existing rate and group may be in use
        foreach (['coreshop_tax_rate', 'coreshop_tax_rule_group'] as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $this->addSql(sprintf('UPDATE %s SET active = 1 WHERE active = 0 OR active IS NULL;', $table));
        }
    }

    public function down(Schema $schema): void
    {
        // the previous flags are not known anymore, and inactive rates were charged anyway
    }
}
