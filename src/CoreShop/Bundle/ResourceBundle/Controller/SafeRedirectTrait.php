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

namespace CoreShop\Bundle\ResourceBundle\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Guards redirect targets taken from user input (for example a "_redirect" query parameter)
 * against open redirects.
 */
trait SafeRedirectTrait
{
    /**
     * Returns $target when it is a local path ("/foo", not "//host") or an absolute http(s) URL
     * on the request's own host, otherwise $fallback.
     */
    protected function getSafeRedirectTarget(Request $request, mixed $target, string $fallback): string
    {
        if (!is_string($target) || '' === $target) {
            return $fallback;
        }

        // Backslashes are treated as slashes by browsers ("/\evil.tld"), control characters
        // and whitespace can be stripped by them; reject both.
        if (str_contains($target, '\\') || 1 === preg_match('/[\x00-\x20\x7F]/', $target)) {
            return $fallback;
        }

        if (str_starts_with($target, '/')) {
            return str_starts_with($target, '//') ? $fallback : $target;
        }

        $parts = parse_url($target);

        if (
            false === $parts ||
            !isset($parts['scheme'], $parts['host']) ||
            !in_array(strtolower($parts['scheme']), ['http', 'https'], true) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            strtolower($parts['host']) !== strtolower($request->getHost())
        ) {
            return $fallback;
        }

        return $target;
    }
}
