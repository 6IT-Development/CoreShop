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

namespace CoreShop\Bundle\CoreBundle\EventListener;

use CoreShop\Component\Order\Exception\CartPersistConflictException;
use Pimcore\Http\RequestHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fallback for a frontend writer that saves a stale copy of the cart without CartLockManagerInterface::lockCart()
 * (project code, a controller not covered yet): instead of an error page, the customer is told that the cart was
 * changed in another window and sent to the cart (an XHR gets a 409 JSON answer). Nothing was saved, the stored cart
 * is unchanged. A project that handles the exception itself registers a listener with a higher priority, or catches
 * it in the writer.
 */
final class CartPersistConflictExceptionListener
{
    private const MESSAGE = 'coreshop.ui.error.cart_changed_meanwhile';

    private const CART_ROUTE = 'coreshop_cart_summary';

    public function __construct(
        private RequestHelper $requestHelper,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $conflict = self::findConflict($event->getThrowable());
        if (null === $conflict) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->requestHelper->isFrontendRequest($request)) {
            return;
        }

        $this->logger?->info('Refused a stale save of a cart: {message}', [
            'message' => $conflict->getMessage(),
            'cart_id' => $conflict->getCartId(),
            'route' => $request->attributes->get('_route'),
        ]);

        $event->setResponse($this->createResponse($request));
    }

    private function createResponse(Request $request): Response
    {
        $message = $this->translator->trans(self::MESSAGE);

        if ($request->isXmlHttpRequest() || 'json' === $request->getPreferredFormat(null)) {
            return new JsonResponse(['success' => false, 'errors' => [$message]], Response::HTTP_CONFLICT);
        }

        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', $message);
            }
        }

        try {
            $target = $this->urlGenerator->generate(self::CART_ROUTE);
        } catch (RoutingException) {
            // a project without the CoreShop frontend routes
            $target = $request->getBaseUrl() . '/';
        }

        return new RedirectResponse($target);
    }

    /**
     * Also when it was wrapped on the way out (e.g. by Twig while a template rendered).
     */
    private static function findConflict(\Throwable $throwable): ?CartPersistConflictException
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof CartPersistConflictException) {
                return $current;
            }
        }

        return null;
    }
}
