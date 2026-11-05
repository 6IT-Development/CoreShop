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

/**
 * Lets used voucher codes and cart price rules with voucher codes be deleted (OrderBundle B-44).
 */
final class Version20261105120000 extends AbstractMigration
{
    private const FOREIGN_KEYS = [
        ['coreshop_cart_price_rule_voucher_code_customer', 'voucherCodeId', 'CASCADE'],
        ['coreshop_cart_price_rule_voucher_code', 'cartPriceRuleId', 'CASCADE'],
    ];

    public function getDescription(): string
    {
        return 'Add ON DELETE CASCADE to coreshop_cart_price_rule_voucher_code_customer.voucherCodeId and coreshop_cart_price_rule_voucher_code.cartPriceRuleId (OrderBundle B-44).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $column, $onDelete]) {
            $this->replaceForeignKey($schema, $table, $column, $onDelete);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $column]) {
            $this->replaceForeignKey($schema, $table, $column, null);
        }
    }

    private function replaceForeignKey(Schema $schema, string $table, string $column, ?string $onDelete): void
    {
        if (!$schema->hasTable($table)) {
            return;
        }

        foreach ($schema->getTable($table)->getForeignKeys() as $foreignKey) {
            if ($foreignKey->getLocalColumns() !== [$column] || $foreignKey->onDelete() === $onDelete) {
                continue;
            }

            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $foreignKey->getName()));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)%s',
                $table,
                $foreignKey->getName(),
                $column,
                $foreignKey->getForeignTableName(),
                implode(', ', $foreignKey->getForeignColumns()),
                null === $onDelete ? '' : ' ON DELETE ' . $onDelete,
            ));
        }
    }
}
