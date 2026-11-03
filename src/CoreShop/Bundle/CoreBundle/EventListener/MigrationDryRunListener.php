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
namespace CoreShop\Bundle\CoreBundle\EventListener;

use Doctrine\Migrations\Event\MigrationsEventArgs;

final class MigrationDryRunListener
{
    private bool $dryRun = false;

    public function onMigrationsMigrating(MigrationsEventArgs $args): void
    {
        $this->dryRun = $args->getMigratorConfiguration()->isDryRun();
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
