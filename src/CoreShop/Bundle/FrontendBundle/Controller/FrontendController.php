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

namespace CoreShop\Bundle\FrontendBundle\Controller;

use CoreShop\Bundle\FrontendBundle\TemplateConfigurator\TemplateConfiguratorInterface;
use CoreShop\Bundle\ResourceBundle\Controller\SafeRedirectTrait;
use CoreShop\Component\Core\Context\ShopperContextInterface;
use CoreShop\Component\Order\Context\CartContextInterface;
use CoreShop\Component\SEO\SEOPresentationInterface;
use Pimcore\Http\RequestHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class FrontendController extends AbstractController
{
    use SafeRedirectTrait;

    public function __construct(
        \Psr\Container\ContainerInterface $container,
    ) {
        $this->container = $container;
    }

    protected function getTemplateConfigurator(): TemplateConfiguratorInterface
    {
        return $this->container->get(TemplateConfiguratorInterface::class);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            TemplateConfiguratorInterface::class => TemplateConfiguratorInterface::class,
            ShopperContextInterface::class => ShopperContextInterface::class,
            CartContextInterface::class => CartContextInterface::class,
            'translator' => TranslatorInterface::class,
            RequestHelper::class => RequestHelper::class,
            SEOPresentationInterface::class => SEOPresentationInterface::class,
        ]);
    }

    /**
     * @return mixed
     *
     * based on Symfony\Component\HttpFoundation\Request::get
     */
    protected function getParameterFromRequest(Request $request, string $key, $default = null)
    {
        if ($request !== $result = $request->attributes->get($key, $request)) {
            return $result;
        }

        if ($request->query->has($key)) {
            return $request->query->all()[$key];
        }

        if ($request->request->has($key)) {
            return $request->request->all()[$key];
        }

        return $default;
    }

    /**
     * Reads a parameter like getParameterFromRequest(), but only as a string.
     * A missing parameter, or one that is not a scalar (for example ?token[]=x),
     * gives $default, so it cannot reach a string-typed repository method.
     */
    protected function getStringParameterFromRequest(Request $request, string $key, string $default = ''): string
    {
        $value = $this->getParameterFromRequest($request, $key);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Reads a positive integer id. Anything else (missing, arrays, "abc",
     * "0", "-1") gives null.
     */
    protected function getIdParameterFromRequest(Request $request, string $key): ?int
    {
        $value = $this->getParameterFromRequest($request, $key);

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Checks the "_token" CSRF token of a state-changing action. The token is
     * read from the query string first (buttons inside another form send it
     * in their formaction URL), then from the body.
     */
    protected function isCsrfTokenValidInRequest(Request $request, string $intention): bool
    {
        $token = $request->query->all()['_token'] ?? $request->request->all()['_token'] ?? null;

        return is_string($token) && $this->isCsrfTokenValid($intention, $token);
    }
}
