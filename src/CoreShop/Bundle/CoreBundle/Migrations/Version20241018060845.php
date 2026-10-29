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

use CoreShop\Bundle\FrontendBundle\CoreShopFrontendBundle;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241018060845 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Check that the CoreShop FrontendBundle is enabled in bundles.php';
    }

    public function up(Schema $schema): void
    {
        /**
         * Project configuration is not edited from a migration: rewriting config/bundles.php could leave the
         * application unbootable, dropped comments and expressions, and failed on read-only deployments.
         */
        $this->warnIf(
            !$this->isFrontendBundleRegistered(),
            sprintf('CoreShop FrontendBundle is not enabled, add it to config/bundles.php: \\%s::class => [\'all\' => true]', CoreShopFrontendBundle::class),
        );
    }

    public function down(Schema $schema): void
    {
        // up() does not change the project configuration, so there is nothing to revert
    }

    private function isFrontendBundleRegistered(): bool
    {
        /** @var array<string, class-string> $bundles */
        $bundles = \Pimcore::getContainer()->getParameter('kernel.bundles');

        return in_array(CoreShopFrontendBundle::class, $bundles, true);
    }
}
