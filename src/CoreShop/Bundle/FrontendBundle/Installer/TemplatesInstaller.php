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

namespace CoreShop\Bundle\FrontendBundle\Installer;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class TemplatesInstaller implements FrontendInstallerInterface
{
    /**
     * Directory (below the template path) the templates are copied to. It is
     * also the view_prefix TemplateConfiguratorInstaller writes, so the
     * rewritten extends/include/import paths and the controllers agree.
     */
    public const VIEW_PREFIX = 'coreshop';

    public function installFrontend(string $frontendBundlePath, string $rootPath, string $templatePath): void
    {
        $finder = new Finder();
        $finder
            ->in($frontendBundlePath . '/Resources/views')
            ->name('*.twig')
        ;

        $twigFiles = $finder->files();

        $fs = new Filesystem();

        $targetDir = $templatePath . '/' . self::VIEW_PREFIX;

        if (!$fs->exists($targetDir)) {
            $fs->mkdir($targetDir);
        }

        foreach ($twigFiles as $twigFile) {
            $newFileName = $targetDir . '/' . $twigFile->getRelativePathname();

            if ($fs->exists($newFileName)) {
                continue;
            }

            $twigContent = file_get_contents($twigFile->getRealPath());
            $twigContent = str_replace(
                ['@CoreShopFrontend/', 'bundles/coreshopfrontend/'],
                [self::VIEW_PREFIX . '/', 'coreshop/'],
                $twigContent,
            );

            $fs->dumpFile($newFileName, $twigContent);
        }
    }
}
