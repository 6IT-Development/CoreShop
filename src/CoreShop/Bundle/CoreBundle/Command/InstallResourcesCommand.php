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

namespace CoreShop\Bundle\CoreBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallResourcesCommand extends AbstractInstallCommand
{
    protected function configure(): void
    {
        $this
            ->setName('coreshop:install:resources')
            ->setDescription('Install CoreShop Resources.')
            ->setHelp(
                <<<EOT
The <info>%command.name%</info> command creates CoreShop Resources.
EOT
            )
            ->addOption(
                'update-classes',
                null,
                InputOption::VALUE_NONE,
                'Set this option to update class definitions if they already exist',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = ['--application-name' => 'coreshop'];

        if ($input->getOption('update-classes')) {
            $params['--update-classes'] = true;
        }

        $this->runCommands(['coreshop:resources:install' => $params], $output, false, true);

        return 0;
    }
}
