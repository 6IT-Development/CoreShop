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
use Pimcore\Bundle\WebToPrintBundle\Installer;

final class Version20240718133201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $container = \Pimcore::getContainer();

        if (!class_exists(Installer::class) || !$container->has(Installer::class)) {
            return;
        }

        /**
         * Which optional Pimcore bundles are installed is the decision of the project.
         */
        $this->warnIf(
            $container->get(Installer::class)->canBeInstalled(),
            'Pimcore WebToPrintBundle is enabled but not installed. If you need it, run: bin/console pimcore:bundle:install PimcoreWebToPrintBundle',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
    }
}
