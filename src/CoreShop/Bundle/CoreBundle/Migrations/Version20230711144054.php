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

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230711144054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $productClass = \Pimcore::getContainer()->getParameter('coreshop.model.product.class');
        $tmpProduct = new $productClass();
        $productClassId = $tmpProduct->getClassId();

        $productTable = 'object_query_' . $productClassId;

        // the current product class has no taxRule field anymore, there is nothing to take over in that case
        if (!$this->connection->createSchemaManager()->introspectTable($productTable)->hasColumn('taxRule')) {
            return;
        }

        // the join skips products that reference a tax rule group that no longer exists
        $this->addSql(
            'UPDATE coreshop_product_store_values AS storeValues
            INNER JOIN ' . $productTable . ' AS products ON products.oo_id = storeValues.product
            INNER JOIN coreshop_tax_rule_group AS taxRuleGroup ON taxRuleGroup.id = products.taxRule
            SET storeValues.taxRuleId = products.taxRule',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
    }
}
