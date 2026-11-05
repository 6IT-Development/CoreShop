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
use Symfony\Component\Yaml\Yaml;

class TemplateConfiguratorInstaller implements FrontendInstallerInterface
{
    /**
     * @param string|null $twigDefaultPath Twig's default_path (null: <project>/templates)
     */
    public function __construct(
        private ?string $twigDefaultPath = null,
    ) {
    }

    /**
     * Adds core_shop_frontend.view_prefix to config/packages/coreshop_frontend.yaml
     * when it is not set yet. An existing file is only changed by inserting
     * that one line (or appending the section), so its comments, tags and
     * formatting are kept.
     */
    public function installFrontend(string $frontendBundlePath, string $rootPath, string $templatePath): void
    {
        $configFile = $rootPath . '/config/packages/coreshop_frontend.yaml';

        $viewPrefix = TemplatesInstaller::resolveViewPrefix(
            $templatePath,
            TemplatesInstaller::getDefaultTwigPath($rootPath, $this->twigDefaultPath),
        );
        $prefixLine = 'view_prefix: ' . Yaml::dump($viewPrefix);

        $fs = new Filesystem();
        if (!file_exists($configFile)) {
            $fs->dumpFile($configFile, "core_shop_frontend:\n    " . $prefixLine . "\n");

            return;
        }

        $configContent = (string) file_get_contents($configFile);

        // Read only: custom tags (!php/const, ...) must not fail the check.
        $content = Yaml::parse($configContent, Yaml::PARSE_CUSTOM_TAGS);
        $section = is_array($content) ? ($content['core_shop_frontend'] ?? null) : null;

        if (is_array($section) && array_key_exists('view_prefix', $section)) {
            return;
        }

        $fs->dumpFile($configFile, $this->addViewPrefix($configContent, $prefixLine, $configFile));
    }

    private function addViewPrefix(string $configContent, string $prefixLine, string $configFile): string
    {
        $lines = preg_split('/\R/', $configContent);

        foreach ($lines as $index => $line) {
            if (!preg_match('/^core_shop_frontend:\s*(?<value>[^#]*?)\s*(#.*)?$/', $line, $match)) {
                continue;
            }

            if (!in_array($match['value'], ['', '~', 'null', '{}'], true)) {
                throw new \RuntimeException(sprintf(
                    'Could not add "core_shop_frontend.%s" to "%s": the section is not a block mapping. Please add it manually.',
                    $prefixLine,
                    $configFile,
                ));
            }

            $indent = '    ';
            for ($next = $index + 1, $count = count($lines); $next < $count; ++$next) {
                if (preg_match('/^(\s+)\S/', $lines[$next], $indentMatch) && !str_starts_with(ltrim($lines[$next]), '#')) {
                    $indent = $indentMatch[1];

                    break;
                }

                if ('' !== trim($lines[$next]) && !str_starts_with(ltrim($lines[$next]), '#')) {
                    break;
                }
            }

            $lines[$index] = 'core_shop_frontend:' . (isset($match[2]) ? ' ' . $match[2] : '');
            array_splice($lines, $index + 1, 0, [$indent . $prefixLine]);

            return implode("\n", $lines);
        }

        return rtrim($configContent) . ('' === trim($configContent) ? '' : "\n\n") . "core_shop_frontend:\n    " . $prefixLine . "\n";
    }
}
