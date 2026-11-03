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

use CoreShop\Bundle\CoreBundle\Installer;
use CoreShop\Bundle\CoreBundle\Installer\Checker\CommandDirectoryChecker;
use CoreShop\Bundle\CoreBundle\Installer\Executor\CommandFailedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

final class InstallCommand extends AbstractInstallCommand
{
    /**
     * @var array<int, array>
     */
    private array $commands = [
        [
            'command' => 'resources',
            'message' => 'Install Pimcore Classes.',
        ],
        [
            'command' => 'database',
            'message' => 'Setting up the database.',
        ],
        [
            'command' => 'folders',
            'message' => 'Install CoreShop Object Folders.',
        ],
    ];

    public function __construct(
        KernelInterface $kernel,
        CommandDirectoryChecker $directoryChecker,
        protected Installer $installer,
    ) {
        parent::__construct($kernel, $directoryChecker);
    }

    protected function configure(): void
    {
        $this
            ->setName('coreshop:install')
            ->setDescription('Installs CoreShop.')
            ->setHelp(
                <<<EOT
The <info>%command.name%</info> command installs CoreShop.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);

        $outputStyle = new SymfonyStyle($input, $output);
        $outputStyle->writeln('<info>Installing CoreShop...</info>');
        $outputStyle->writeln($this->getCoreShopLogo());

        $this->ensureDirectoryExistsAndIsWritable($this->kernel->getCacheDir(), $output);

        // decide before the database step creates the tables
        $freshInstall = !$this->installer->isSchemaPresent();

        foreach ($this->commands as $step => $command) {
            $outputStyle->newLine();
            $outputStyle->section(
                sprintf(
                    'Step %d of %d. <info>%s</info>',
                    $step + 1,
                    count($this->commands),
                    $command['message'],
                ),
            );

            try {
                $this->commandExecutor->runCommand('coreshop:install:' . $command['command'], [], $output);
            } catch (\Exception $exception) {
                // the executor has already printed the exit code of a failed command
                if (!$exception instanceof CommandFailedException) {
                    $outputStyle->error(sprintf('%s: %s', $exception::class, $exception->getMessage()));

                    if ($output->isVerbose()) {
                        $outputStyle->writeln($exception->getTraceAsString());
                    }
                }

                // later steps depend on the earlier ones, so do not continue on a broken base
                $outputStyle->error(sprintf(
                    'CoreShop could not be installed, step %d (%s) failed. Migrations have not been marked as executed. Run "coreshop:install" again once the problem is solved.',
                    $step + 1,
                    $command['message'],
                ));

                return Command::FAILURE;
            }
        }

        if ($freshInstall) {
            // the schema and classes have just been created in their latest version
            $this->installer->markAllMigrationsInstalled();
        } else {
            // existing installation: classes are not updated by the resources step, so pending migrations must stay pending
            $outputStyle->note('CoreShop was already installed in this database, its migrations have not been marked as executed. Run "coreshop:migrate" to execute pending migrations.');
        }

        $outputStyle->newLine(2);
        $outputStyle->success('CoreShop has been successfully installed.');
        $outputStyle->writeln(
            'You can now open your store at the following path under the website root: <info>/</info>',
        );

        return Command::SUCCESS;
    }

    private function getCoreShopLogo(): string
    {
        return '<fg=red>                                          
                                       %%%%%%%%%%                                    
                                   %%%%%%%%%%%%%%%%%%%                               
                               %%%%%%%%%%%%%%%%%%%%%%%%%%%                           
                           %%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%                       
                      %%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%                   
                  %%%%%%%%%%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%%              
             %%%%%%%%%%%%%%%%%%%%%%         %        %%%%%%%%%%%%%%%%%%%%%%          
         %%%%%%%%%%%%%%%%%%%%%%         %%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%      
     %%%%%%%%%%%%%%%%%%%%%          %%%%%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%% 
    %%%%%%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%%%%%%%        %%%%%%%%%%%%%%%%%% 
    %%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%         %%%%%%%%%%%%% 
    %%%%%%%%%%%        %%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%         %%%%%%%%% 
    %%%%%%%%%%%      %%%%%%%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%%        %%%%% 
    %%%%%%%%%%%      %%%%%%%%%%%%%%%                 %%%%%%%%%%%%%%%%%%%%%%        % 
    %%%%%%%%%%%      %%%%%%%%%%%                         %%%%%%%%%%%%%%%%%%%%%%      
    %%%%%%%%%%%      %%%%%%%%%%                               %%%%%%%%%%%%%%%%%%%%%  
    %%%%%%%%%%%      %%%%%%%%%%                                   %%%%%%%%%%%%%%%%%% 
    %%%%%%%%%%%        %%%%%%%%                          %%%%         %%%%%%%%%%%%%% 
    %%%%%%%%%%%%%%%         %%%                          %%%%%%%%%        %%%%%%%%%% 
    %%%%%%%%%%%%%%%%%%%                                  %%%%%%%%%%%      %%%%%%%%%% 
      %%%%%%%%%%%%%%%%%%%%%%                             %%%%%%%%%%%      %%%%%%%%%% 
          %%%%%%%%%%%%%%%%%%%%%%                         %%%%%%%%%%%      %%%%%%%%%% 
    %%        %%%%%%%%%%%%%%%%%%%%%%                %%%%%%%%%%%%%%%%      %%%%%%%%%% 
    %%%%%%         %%%%%%%%%%%%%%%%%%%%%        %%%%%%%%%%%%%%%%%%%%      %%%%%%%%%% 
    %%%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%        %%%%%%%%%% 
    %%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%         %%%%%%%%%%%%%% 
    %%%%%%%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%%% 
     %%%%%%%%%%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%          %%%%%%%%%%%%%%%%%%%%%  
          %%%%%%%%%%%%%%%%%%%%%%        %%%%%%%%         %%%%%%%%%%%%%%%%%%%%%%      
              %%%%%%%%%%%%%%%%%%%%%%                 %%%%%%%%%%%%%%%%%%%%%%          
                   %%%%%%%%%%%%%%%%%%%%%         %%%%%%%%%%%%%%%%%%%%%               
                       %%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%                   
                           %%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%                       
                               %%%%%%%%%%%%%%%%%%%%%%%%%%                            
                                    %%%%%%%%%%%%%%%%%                                
                                        %%%%%%%%%                                                                                                                                 
</>';
    }
}
