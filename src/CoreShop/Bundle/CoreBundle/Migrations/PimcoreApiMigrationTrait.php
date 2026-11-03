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

use CoreShop\Bundle\CoreBundle\EventListener\MigrationDryRunListener;
use Doctrine\DBAL\Schema\Schema;

/**
 * For migrations that change state through the Pimcore API instead of addSql(): Doctrine calls up() and down()
 * in dry-run mode as well, and class definition changes commit implicitly, so they cannot run in a transaction.
 */
trait PimcoreApiMigrationTrait
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function preUp(Schema $schema): void
    {
        $this->skipInDryRun();
    }

    public function preDown(Schema $schema): void
    {
        $this->skipInDryRun();
    }

    private function skipInDryRun(): void
    {
        $this->skipIf(
            \Pimcore::getContainer()->get(MigrationDryRunListener::class)->isDryRun(),
            'This migration changes state through the Pimcore API and cannot be dry-run.',
        );
    }
}
