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

final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align coreshop_payment_provider_rule_translation created by Version20230829163505 with its Doctrine mapping.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('coreshop_payment_provider_rule_translation')) {
            return;
        }

        $table = $schema->getTable('coreshop_payment_provider_rule_translation');

        // tables created by the schema tool already match the mapping
        if (!$table->hasIndex('translatable_id_locale')) {
            return;
        }

        $dropRedundantIndex = $table->hasIndex('translatable_id') ? ' DROP INDEX translatable_id,' : '';

        // translations without a locale can never be loaded
        $this->addSql('DELETE FROM coreshop_payment_provider_rule_translation WHERE locale IS NULL;');
        $this->addSql('UPDATE coreshop_payment_provider_rule_translation SET label = LEFT(label, 255) WHERE CHAR_LENGTH(label) > 255;');
        $this->addSql(
            'ALTER TABLE coreshop_payment_provider_rule_translation
            CHANGE label label VARCHAR(255) DEFAULT NULL,
            CHANGE locale locale VARCHAR(5) NOT NULL,' . $dropRedundantIndex . '
            DROP INDEX translatable_id_locale,
            ADD UNIQUE INDEX coreshop_payment_provider_rule_translation_uniq_trans (translatable_id, locale);',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
    }
}
