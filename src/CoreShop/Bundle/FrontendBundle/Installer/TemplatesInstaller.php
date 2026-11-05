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
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

class TemplatesInstaller implements FrontendInstallerInterface
{
    /**
     * Directory (below the template path) the templates are copied to. With
     * resolveViewPrefix() it gives the view_prefix TemplateConfiguratorInstaller
     * writes, so the rewritten extends/include/import paths and the
     * controllers agree.
     */
    public const VIEW_PREFIX = 'coreshop';

    /**
     * @param string|null $twigDefaultPath Twig's default_path (null: <project>/templates)
     */
    public function __construct(
        private ?string $twigDefaultPath = null,
    ) {
    }

    /**
     * The view_prefix of templates installed into $templatePath: VIEW_PREFIX,
     * below the path of $templatePath relative to Twig's default path. Twig
     * can only find the templates (and the rewritten extends/include paths)
     * from there, so a template path outside it is refused.
     *
     * @throws \InvalidArgumentException
     */
    public static function resolveViewPrefix(string $templatePath, string $twigDefaultPath): string
    {
        $templatePath = Path::canonicalize($templatePath);
        $twigDefaultPath = Path::canonicalize($twigDefaultPath);

        if (!Path::isBasePath($twigDefaultPath, $templatePath)) {
            throw new \InvalidArgumentException(sprintf(
                'The template path "%s" is not inside the Twig default path "%s", so Twig could not find the installed templates.',
                $templatePath,
                $twigDefaultPath,
            ));
        }

        $relativePath = Path::makeRelative($templatePath, $twigDefaultPath);

        return '' === $relativePath ? self::VIEW_PREFIX : $relativePath . '/' . self::VIEW_PREFIX;
    }

    public static function getDefaultTwigPath(string $rootPath, ?string $twigDefaultPath): string
    {
        return $twigDefaultPath ?? $rootPath . '/templates';
    }

    public function installFrontend(string $frontendBundlePath, string $rootPath, string $templatePath): void
    {
        $viewPrefix = self::resolveViewPrefix(
            $templatePath,
            self::getDefaultTwigPath($rootPath, $this->twigDefaultPath),
        );

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
                [$viewPrefix . '/', 'coreshop/'],
                $twigContent,
            );

            $fs->dumpFile($newFileName, $twigContent);
        }
    }
}
