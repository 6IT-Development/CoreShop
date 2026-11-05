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

namespace CoreShop\Bundle\FrontendBundle\Command;

use CoreShop\Bundle\CoreBundle\Command\AbstractInstallCommand;
use CoreShop\Bundle\CoreBundle\Installer\Checker\CommandDirectoryChecker;
use CoreShop\Bundle\FrontendBundle\Installer\FrontendInstallerInterface;
use CoreShop\Bundle\FrontendBundle\Installer\TemplatesInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;

final class InstallFrontendCommand extends AbstractInstallCommand
{
    public function __construct(
        KernelInterface $kernel,
        CommandDirectoryChecker $directoryChecker,
        protected FrontendInstallerInterface $frontendInstaller,
        private ?string $twigDefaultPath = null,
    ) {
        parent::__construct($kernel, $directoryChecker);
    }

    protected function configure(): void
    {
        $this
            ->setName('coreshop:frontend:install')
            ->setDescription('Install CoreShop Demo Frontend.')
            ->setHelp(
                <<<EOT
The <info>%command.name%</info> command install CoreShop Frontend Controllers/Templates/Configs.

<info>--templatePath</info> is relative to the project directory (or absolute) and
must be Twig's default path or a directory inside it. The templates are
copied to <comment><templatePath>/coreshop</comment>, and a missing
<comment>core_shop_frontend.view_prefix</comment> is added to
<comment>config/packages/coreshop_frontend.yaml</comment>. Existing files are kept.
EOT
            )
            ->addOption('templatePath', null, InputOption::VALUE_REQUIRED, 'Path to the template directory (relative to the project directory, inside Twig\'s default path)', 'templates')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $frontendBundlePath = $this->kernel->getBundle('CoreShopFrontendBundle')->getPath();
        $rootPath = $this->kernel->getProjectDir();

        $templatePathOption = $input->getOption('templatePath');

        if (!is_string($templatePathOption) || '' === trim($templatePathOption)) {
            $io->error('The --templatePath option needs a value.');

            return Command::INVALID;
        }

        $templatePath = Path::makeAbsolute($templatePathOption, $rootPath);

        try {
            $viewPrefix = TemplatesInstaller::resolveViewPrefix(
                $templatePath,
                TemplatesInstaller::getDefaultTwigPath($rootPath, $this->twigDefaultPath),
            );

            $this->frontendInstaller->installFrontend(
                $frontendBundlePath,
                $rootPath,
                $templatePath,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->listing([
            sprintf('Templates: %s (existing files kept)', Path::join($templatePath, TemplatesInstaller::VIEW_PREFIX)),
            sprintf('view_prefix: %s (in config/packages/coreshop_frontend.yaml, unless already set)', $viewPrefix),
            'Assets: public/coreshop/static',
        ]);
        $io->success('CoreShop frontend installed.');

        return Command::SUCCESS;
    }
}
