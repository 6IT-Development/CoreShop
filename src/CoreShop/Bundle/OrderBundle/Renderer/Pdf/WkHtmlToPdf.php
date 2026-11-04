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

namespace CoreShop\Bundle\OrderBundle\Renderer\Pdf;

use Pimcore\Tool\Console;
use Symfony\Component\Process\Process;

/**
 * @deprecated Deprecated since CoreShop 4.1, to be removed in CoreShop 5.0. No replacement available, use Pimcore's Web2Print Renderer instead.
 *
 * @psalm-suppress DeprecatedInterface
 */
final class WkHtmlToPdf implements PdfRendererInterface
{
    /**
     * wkhtmltopdf options that may be given as an option string (e.g. the store settings
     * system.invoice.wkhtml / system.shipment.wkhtml), with the number of values they take.
     * Options that read or send local files, run scripts or write files are not allowed.
     */
    private const ALLOWED_OPTIONS = [
        '-T' => 1, '--margin-top' => 1,
        '-B' => 1, '--margin-bottom' => 1,
        '-L' => 1, '--margin-left' => 1,
        '-R' => 1, '--margin-right' => 1,
        '-s' => 1, '--page-size' => 1,
        '-O' => 1, '--orientation' => 1,
        '--page-width' => 1, '--page-height' => 1,
        '-d' => 1, '--dpi' => 1,
        '--image-dpi' => 1, '--image-quality' => 1,
        '--zoom' => 1, '--viewport-size' => 1,
        '--encoding' => 1, '--title' => 1,
        '--javascript-delay' => 1, '--minimum-font-size' => 1,
        '--header-spacing' => 1, '--footer-spacing' => 1,
        '--header-font-name' => 1, '--footer-font-name' => 1,
        '--header-font-size' => 1, '--footer-font-size' => 1,
        '--header-left' => 1, '--header-center' => 1, '--header-right' => 1,
        '--footer-left' => 1, '--footer-center' => 1, '--footer-right' => 1,
        '--load-error-handling' => 1, '--load-media-error-handling' => 1,
        '--header-line' => 0, '--no-header-line' => 0,
        '--footer-line' => 0, '--no-footer-line' => 0,
        '-g' => 0, '--grayscale' => 0,
        '-l' => 0, '--lowquality' => 0,
        '-q' => 0, '--quiet' => 0,
        '--print-media-type' => 0, '--no-print-media-type' => 0,
        '--background' => 0, '--no-background' => 0,
        '--images' => 0, '--no-images' => 0,
        '--enable-javascript' => 0, '--disable-javascript' => 0,
        '--enable-smart-shrinking' => 0, '--disable-smart-shrinking' => 0,
        '--enable-external-links' => 0, '--disable-external-links' => 0,
        '--enable-internal-links' => 0, '--disable-internal-links' => 0,
        '--outline' => 0, '--no-outline' => 0,
        '--no-pdf-compression' => 0,
        // needed by wkhtmltopdf >= 0.12.6 to load the temporary HTML files and images of public/
        '--enable-local-file-access' => 0, '--disable-local-file-access' => 0,
    ];

    public function __construct(
        private string $kernelCacheDir,
        private string $kernelRootDir,
    ) {
    }

    public function fromString(string $string, string $header = '', string $footer = '', array $config = []): string
    {
        trigger_deprecation(
            'coreshop/order-bundle',
            '4.1',
            'The "%s" class is deprecated and will be removed in CoreShop 5.0. No replacement available, use Pimcore\'s Web2Print Renderer instead.',
            self::class,
        );

        $bodyHtml = $this->createHtmlFile($string);
        $headerHtml = $this->createHtmlFile($header);
        $footerHtml = $this->createHtmlFile($footer);

        if (!is_array($config['options'])) {
            $config['options'] = [];
        }

        if ($headerHtml) {
            $config['options']['--header-html'] = $headerHtml;
        }

        if ($footerHtml) {
            $config['options']['--footer-html'] = $footerHtml;
        }

        $pdfContent = null;

        try {
            $pdfContent = $this->convert($bodyHtml, $config);
        } catch (\Exception $e) {
            $this->unlinkFile($bodyHtml);
            $this->unlinkFile($headerHtml);
            $this->unlinkFile($footerHtml);

            throw new \Exception('error while converting pdf. message was: ' . $e->getMessage(), 0, $e);
        }

        $this->unlinkFile($bodyHtml);
        $this->unlinkFile($headerHtml);
        $this->unlinkFile($footerHtml);

        return $pdfContent;
    }

    /**
     * Creates an Temporary HTML File.
     *
     * @param string $string
     */
    private function createHtmlFile($string): ?string
    {
        if ($string) {
            $tmpHtmlFile = $this->kernelCacheDir . '/' . uniqid() . '.htm';
            file_put_contents($tmpHtmlFile, $this->replaceUrls($string));

            return $tmpHtmlFile;
        }

        return null;
    }

    /**
     * @param string $string
     */
    private function replaceUrls($string): string
    {
        $hostUrl = $this->kernelRootDir . '/public';
        $replacePrefix = '';

        //matches all links
        preg_match_all(
            "@(href|src)\s*=[\"']([^(http|mailto|javascript|data:|#)].*?(css|jpe?g|gif|png)?)[\"']@is",
            $string,
            $matches,
        );
        if (!empty($matches[0])) {
            foreach ($matches[0] as $key => $value) {
                $path = $matches[2][$key];

                if (str_starts_with($path, '//')) {
                    $absolutePath = 'http:' . $path;
                } elseif (str_starts_with($path, '/')) {
                    $absolutePath = preg_replace('@^' . $replacePrefix . '/@', '/', $path);
                    $absolutePath = $hostUrl . $absolutePath;
                } else {
                    $absolutePath = $hostUrl . "/$path";
                    if ($path[0] == '?') {
                        $absolutePath = $hostUrl . $path;
                    }
                    $netUrl = new \Net_URL2($absolutePath);
                    $absolutePath = $netUrl->getNormalizedURL();
                }

                $path = preg_quote($path);
                $string = preg_replace("!([\"'])$path([\"'])!is", '\\1' . $absolutePath . '\\2', $string);
            }
        }

        preg_match_all("@srcset\s*=[\"'](.*?)[\"']@is", $string, $matches);
        foreach ($matches[1] as $i => $value) {
            $parts = explode(',', $value);
            foreach ($parts as $key => $v) {
                $parts[$key] = $hostUrl . trim($v);
            }
            $s = ' srcset="' . implode(', ', $parts) . '" ';
            if ($matches[0][$i]) {
                $string = str_replace($matches[0][$i], $s, $string);
            }
        }

        return $string;
    }

    /**
     * Converts URL to pdf.
     *
     * @param string $httpSource
     * @param array $config
     *
     * @return string PDF-Content
     *
     * @throws \Exception
     */
    private function convert($httpSource, $config = []): string
    {
        $tmpPdfFile = $this->kernelCacheDir . '/' . uniqid() . '.pdf';
        $options = [];

        if (is_array($config['options'])) {
            foreach ($config['options'] as $argument => $value) {
                if (is_numeric($argument)) {
                    // an option string, e.g. from the store settings: only allow-listed options
                    array_push($options, ...$this->parseOptions((string) $value));
                } else {
                    // an option set in code, e.g. --header-html <tmp file>
                    $options[] = (string) $argument;
                    $options[] = (string) $value;
                }
            }
        }

        if (isset($config['bin'])) {
            $wkHtmlTopPfBinary = $config['bin'];
        } else {
            $wkHtmlTopPfBinary = $this->getWkHtmlToPdfBinary();
        }

        if (!$wkHtmlTopPfBinary) {
            throw new \Exception('wkhtmltopdf binary not found. please check your server configuration');
        }

        // use xvfb if possible
        if ($xvfb = self::getXvfbBinary()) {
            $command = [$xvfb, '--auto-servernum', '--server-args=-screen 0, 1280x1024x24', $wkHtmlTopPfBinary, '--use-xserver'];
        } else {
            $command = [$wkHtmlTopPfBinary];
        }

        $process = new Process([...$command, ...$options, $httpSource, $tmpPdfFile]);
        $process->run();

        if (!file_exists($tmpPdfFile)) {
            throw new \Exception(
                sprintf(
                    'wkhtmltopdf pdf conversion failed. This could be a command error. Executed command was: "%s"',
                    $process->getCommandLine(),
                ),
            );
        }

        $pdfContent = file_get_contents($tmpPdfFile);

        // remove temp pdf file
        $this->unlinkFile($tmpPdfFile);

        return $pdfContent;
    }

    /**
     * Splits an option string into arguments (honouring quotes) and rejects every option
     * that is not allow-listed.
     *
     * @return list<string>
     */
    private function parseOptions(string $options): array
    {
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $options, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        $tokens = array_map(
            static fn (array $match): string => $match[3] ?? $match[2] ?? $match[1] ?? '',
            $matches,
        );

        $arguments = [];

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $option = $tokens[$i];

            if (!array_key_exists($option, self::ALLOWED_OPTIONS)) {
                throw new \InvalidArgumentException(sprintf('wkhtmltopdf option "%s" is not allowed', $option));
            }

            $arguments[] = $option;

            for ($j = 0; $j < self::ALLOWED_OPTIONS[$option]; ++$j) {
                if (!isset($tokens[$i + 1])) {
                    throw new \InvalidArgumentException(sprintf('wkhtmltopdf option "%s" requires a value', $option));
                }

                $arguments[] = $tokens[++$i];
            }
        }

        return $arguments;
    }

    private function unlinkFile(?string $file): void
    {
        if ($file === null) {
            return;
        }

        if (!file_exists($file)) {
            return;
        }

        unlink($file);
    }

    private function getWkHtmlToPdfBinary(): string
    {
        return (string) Console::getExecutable('wkhtmltopdf', true);
    }

    private function getXvfbBinary(): string
    {
        return (string) Console::getExecutable('xvfb-run');
    }
}
