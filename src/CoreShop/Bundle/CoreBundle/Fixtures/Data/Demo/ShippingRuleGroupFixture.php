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

namespace CoreShop\Bundle\CoreBundle\Fixtures\Data\Demo;

use CoreShop\Component\Resource\Factory\FactoryInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use CoreShop\Component\Shipping\Model\ShippingRuleGroupInterface;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ShippingRuleGroupFixture extends AbstractFixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private RepositoryInterface $shippingRuleGroupRepository,
        private FactoryInterface $shippingRuleGroupFactory,
    ) {
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    /**
     * @return string[]
     */
    public function getDependencies(): array
    {
        return [
            ShippingRuleFixture::class,
            CarrierFixture::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        if (!count($this->shippingRuleGroupRepository->findAll())) {
            $carrier = $this->getReference('carrier');

            for ($index = 0; $index < 3; ++$index) {
                // fewer rules may exist when they were not created by ShippingRuleFixture
                if (!$this->hasReference('shippingRule' . $index)) {
                    continue;
                }

                /**
                 * @var ShippingRuleGroupInterface $shippingRuleGroup
                 */
                $shippingRuleGroup = $this->shippingRuleGroupFactory->createNew();
                $shippingRuleGroup->setShippingRule($this->getReference('shippingRule' . $index));
                $shippingRuleGroup->setPriority(1);
                $shippingRuleGroup->setCarrier($carrier);

                $manager->persist($shippingRuleGroup);
            }

            $manager->flush();
        }
    }
}
