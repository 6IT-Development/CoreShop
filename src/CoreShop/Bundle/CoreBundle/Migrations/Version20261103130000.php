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

final class Version20261103130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an index on coreshop_payment.orderId';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('coreshop_payment') && !$schema->getTable('coreshop_payment')->hasIndex('payment_order')) {
            $this->addSql('CREATE INDEX payment_order ON coreshop_payment (orderId)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('coreshop_payment') && $schema->getTable('coreshop_payment')->hasIndex('payment_order')) {
            $this->addSql('DROP INDEX payment_order ON coreshop_payment');
        }
    }
}
