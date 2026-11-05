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

namespace CoreShop\Component\Address\Formatter;

use CoreShop\Component\Address\Model\CountryInterface;
use CoreShop\Component\Address\Model\StateInterface;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * The Twig environment country address formats are rendered in.
 *
 * Address formats are edited by admin users with the "country" permission, so they
 * are rendered in an environment of their own (none of the application's functions,
 * filters, tags or globals) and always sandboxed: only output, "if" and a few string
 * filters are allowed, and the only methods that may be called are the name and
 * ISO code of the country and state.
 */
final class AddressFormatEnvironment
{
    public const ALLOWED_TAGS = ['if'];

    public const ALLOWED_FILTERS = [
        'capitalize',
        'default',
        'e',
        'escape',
        'lower',
        'title',
        'trim',
        'upper',
    ];

    public const ALLOWED_METHODS = [
        CountryInterface::class => ['getName', 'getIsoCode', '__toString'],
        StateInterface::class => ['getName', 'getIsoCode', '__toString'],
    ];

    public static function create(): Environment
    {
        $twig = new Environment(new ArrayLoader(), [
            'autoescape' => 'html',
            'strict_variables' => false,
            'cache' => false,
        ]);
        $twig->addExtension(new SandboxExtension(self::createSecurityPolicy(), true));

        return $twig;
    }

    public static function createSecurityPolicy(): SecurityPolicy
    {
        return new SecurityPolicy(self::ALLOWED_TAGS, self::ALLOWED_FILTERS, self::ALLOWED_METHODS, [], []);
    }

    public static function isSandboxed(Environment $twig): bool
    {
        return $twig->hasExtension(SandboxExtension::class)
            && $twig->getExtension(SandboxExtension::class)->isSandboxed();
    }
}
