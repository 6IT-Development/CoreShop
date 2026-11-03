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

namespace CoreShop\Bundle\CoreBundle;

use Doctrine\Migrations\Metadata\AvailableMigration;
use Pimcore\Console\Application;
use Pimcore\Extension\Bundle\Installer\Exception\InstallationException;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use Pimcore\Model\Tool\SettingsStore;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\HttpKernel\KernelInterface;

class Installer extends SettingsStoreAwareInstaller
{
    public function __construct(
        protected KernelInterface $kernel,
    ) {
        parent::__construct($this->kernel->getBundle('CoreShopCoreBundle'));
    }

    public function getLastMigrationVersionClassName(): ?string
    {
        /**
         * @psalm-suppress InternalMethod
         */
        $this->migrationRepository->setPrefix($this->bundle->getNamespace());

        /**
         * @psalm-suppress InternalMethod
         */
        $this->tableMetadataStorage->setPrefix($this->bundle->getNamespace());

        $migrations = $this->dependencyFactory->getMigrationRepository()->getMigrations();

        if (count($migrations) > 0) {
            /**
             * @var AvailableMigration $lastMigration
             */
            $lastMigration = $migrations->getItems()[count($migrations) - 1];

            return get_class($lastMigration->getMigration());
        }

        return null;
    }

    public function markAllMigrationsInstalled(): void
    {
        $this->markInstalled();
    }

    public function isSchemaPresent(): bool
    {
        return in_array('coreshop_store', $this->dependencyFactory->getConnection()->createSchemaManager()->listTableNames(), true);
    }

    public function install(): void
    {
        /** @psalm-suppress InternalClass, InternalMethod */
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        // the installer output is shown by bundle:install and the admin UI
        $exitCode = $application->run(
            new ArrayInput(['command' => 'coreshop:install', '--no-interaction' => true]),
            $this->getOutput(),
        );

        if (0 !== $exitCode) {
            // the reason is in getOutput(), which the caller prints
            throw new InstallationException(sprintf('The command "coreshop:install" failed with the exit code %d.', $exitCode));
        }

        /**
         * Do not call parent::install(): it would mark every migration as executed, also on an existing
         * installation. coreshop:install already marked them on a fresh install.
         */
        SettingsStore::set($this->getSettingsStoreInstallationId(), true, SettingsStore::TYPE_BOOLEAN, 'pimcore');
    }
}
