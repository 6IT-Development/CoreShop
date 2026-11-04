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

use CoreShop\Component\Resource\Factory\FactoryInterface;
use CoreShop\Component\Resource\Metadata\MetadataInterface;
use CoreShop\Component\Resource\Repository\PimcoreRepositoryInterface;
use Pimcore\Model\User;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PimcoreController extends AdminController
{
    public function __construct(
        protected MetadataInterface $metadata,
        protected PimcoreRepositoryInterface $repository,
        protected FactoryInterface $factory,
        ContainerInterface $container,
        ViewHandlerInterface $viewHandler,
        ParameterBagInterface $parameterBag,
    ) {
        parent::__construct($container, $viewHandler, $parameterBag);
    }

    /**
     * @throws AccessDeniedHttpException
     */
    protected function isGrantedOr403(): void
    {
        if ($this->getPermission()) {
            /**
             * @var User $user
             *
             * @psalm-var User $user
             *
             * @psalm-suppress InternalMethod
             */
            $user = $this->getAdminUser();

            if ($user instanceof User && $user->isAllowed($this->getPermission())) {
                return;
            }

            throw $this->createAccessDeniedHttpException();
        }
    }

    protected function getPermission(): string
    {
        return '';
    }
}
