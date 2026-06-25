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

namespace CoreShop\Bundle\OrderReturnBundle;

use CoreShop\Bundle\ResourceBundle\AbstractResourceBundle;
use CoreShop\Bundle\ResourceBundle\CoreShopResourceBundle;
use Pimcore\Bundle\WebToPrintBundle\PimcoreWebToPrintBundle;
use Pimcore\HttpKernel\BundleCollection\BundleCollection;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CoreShopOrderReturnBundle extends AbstractResourceBundle
{
    public function getNiceName(): string
    {
        return 'CoreShop - Order Return';
    }

    public function getDescription(): string
    {
        return 'CoreShop - Order Return Bundle';
    }

    public function getSupportedDrivers(): array
    {
        return [
            CoreShopResourceBundle::DRIVER_DOCTRINE_ORM,
        ];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->registerResources(
            [
                'order_return' => [
                    'classes' => [
                        'model' => \CoreShop\Component\OrderReturn\Model\OrderReturn::class,
                        'interface' => \CoreShop\Component\OrderReturn\Model\OrderReturnInterface::class,
                    ],
                ],
            ],
            $container
        );
    }

    public static function registerDependentBundles(BundleCollection $collection): void
    {
        parent::registerDependentBundles($collection);


        //Examples
        //$collection->addBundle(new \CoreShop\Bundle\TestBundle\CoreShopTestBundle(), 3500);
        //dd($collection);

    }

    protected function getModelNamespace(): string
    {
        return 'CoreShop\Component\OrderReturn\Model';
    }
}
