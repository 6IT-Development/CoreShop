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

use CoreShop\Component\Core\Context\ShopperContextInterface;
use CoreShop\Component\Pimcore\Slug\SluggableInterface;
use Pimcore\Bundle\StaticRoutesBundle\Model\Staticroute;
use Pimcore\Model\DataObject\Data\UrlSlug;
use Pimcore\Model\Document;
use Pimcore\Model\Site;
use Pimcore\Tool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class LocaleSwitcherExtension extends AbstractExtension
{
    public function __construct(
        private Document\Service $documentService,
        private ShopperContextInterface $shopperContext,
        private RequestStack $requestStack,
        private RouterInterface $router,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('coreshop_locale_switcher', [$this, 'getLocalizedLinks']),
        ];
    }

    public function getLocalizedLinks(Document $document): array
    {
        $translations = $this->documentService->getTranslations($document);
        $links = [];
        $basePath = '/';

        $store = $this->shopperContext->getStore();

        if ($store->getSiteId()) {
            try {
                $site = Site::getById($store->getSiteId());

                $rootDocument = $site instanceof Site ? $site->getRootDocument() : null;

                if ($rootDocument instanceof Document) {
                    $basePath = $rootDocument->getRealFullPath() . '/';
                }
            } catch (\Exception) {
                $basePath = '/';
            }
        }

        $object = $this->getMainRequest()->attributes->get('object');

        foreach (Tool::getValidLanguages() as $language) {
            $target = $basePath . $language;

            if ($object instanceof SluggableInterface) {
                $urlSlug = $this->getSlugForSite($object->getSlug($language) ?? [], (int) $store->getSiteId());

                if ($urlSlug instanceof UrlSlug) {
                    $links[] = [
                        'language' => $language,
                        'target' => $urlSlug->getSlug(),
                        'displayLanguage' => \Locale::getDisplayLanguage($language, $language),
                    ];
                }

                continue;
            }

            $link = '';
            if ($this->getMainRequest()->attributes->get('pimcore_request_source') === 'staticroute') {
                $route = $this->getMainRequest()->attributes->get('_route');
                $staticRoute = is_string($route) && '' !== $route ? Staticroute::getByName($route) : null;

                if ($staticRoute) {
                    $params = [];
                    if (str_contains($staticRoute->getVariables(), '_locale')) {
                        $params = ['_locale' => $language];
                    }
                    $link = $this->router->generate($route, $params);
                }
            } else {
                if (isset($translations[$language])) {
                    $localizedDocument = Document::getById($translations[$language]);
                } else {
                    $localizedDocument = Document::getByPath($target);
                }

                if ($localizedDocument instanceof Document && $localizedDocument->getPublished()) {
                    $link = $localizedDocument->getFullPath();
                }
            }

            if (!empty($link)) {
                $links[] = [
                    'language' => $language,
                    'target' => $link,
                    'displayLanguage' => \Locale::getDisplayLanguage($language, $language),
                ];
            }
        }

        return $links;
    }

    /**
     * The slug of the store's site; a slug without a site (site id 0) is
     * the fallback.
     *
     * @param array<mixed> $slugs
     */
    private function getSlugForSite(array $slugs, int $siteId): ?UrlSlug
    {
        $fallback = null;

        foreach ($slugs as $slug) {
            if (!$slug instanceof UrlSlug) {
                continue;
            }

            $slugSiteId = (int) $slug->getSiteId();

            if ($slugSiteId === $siteId) {
                return $slug;
            }

            if (0 === $slugSiteId && null === $fallback) {
                $fallback = $slug;
            }
        }

        return $fallback;
    }

    private function getMainRequest(): Request
    {
        $mainRequest = $this->requestStack->getMainRequest();

        if (null === $mainRequest) {
            throw new \UnexpectedValueException('There are not any requests on request stack');
        }

        return $mainRequest;
    }
}
