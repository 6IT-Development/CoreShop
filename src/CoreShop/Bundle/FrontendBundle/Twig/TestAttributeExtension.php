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

namespace CoreShop\Bundle\FrontendBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides the data-test-* attribute helpers the storefront templates call.
 * They only emit something in "test*" kernel environments, and are no-ops
 * everywhere else, so production does not depend on CoreShopTestBundle.
 */
final class TestAttributeExtension extends AbstractExtension
{
    public function __construct(
        private string $environment,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'coreshop_test_html_attribute',
                function (string $name, ?string $value = null): string {
                    if (!$this->isTestEnvironment()) {
                        return '';
                    }

                    return sprintf(
                        'data-test-%s="%s"',
                        htmlspecialchars($name, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                    );
                },
                ['is_safe' => ['html']],
            ),
            new TwigFunction(
                'coreshop_test_form_attribute',
                function (string $name, ?string $value = null): array {
                    if (!$this->isTestEnvironment()) {
                        return [];
                    }

                    return ['attr' => ['data-test-' . $name => (string) $value]];
                },
            ),
        ];
    }

    private function isTestEnvironment(): bool
    {
        return str_starts_with($this->environment, 'test');
    }
}
