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
use CoreShop\Component\Taxation\Model\TaxRateInterface;
use CoreShop\Component\Taxation\Repository\TaxRateRepositoryInterface;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class TaxRateFixture extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private TaxRateRepositoryInterface $taxRateRepository,
        private FactoryInterface $taxRateFactory,
    ) {
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!count($this->taxRateRepository->findAll())) {
            /**
             * @var TaxRateInterface $taxRate
             */
            $taxRate = $this->taxRateFactory->createNew();
            $taxRate->setName('20AT', 'de');
            $taxRate->setName('20AT', 'en');
            $taxRate->setActive(true);
            $taxRate->setRate(20);

            $taxRate10 = $this->taxRateFactory->createNew();
            $taxRate10->setName('10AT', 'de');
            $taxRate10->setName('10AT', 'en');
            $taxRate10->setActive(true);
            $taxRate10->setRate(10);

            $manager->persist($taxRate);
            $manager->persist($taxRate10);
            $manager->flush();

            $this->addReference('taxRate', $taxRate);
            $this->addReference('taxRate10', $taxRate10);
        } else {
            // later fixtures use the references, also when the tax rates already exist
            $taxRates = $this->taxRateRepository->findAll();

            $this->addReference('taxRate', $this->findTaxRate($taxRates, 20) ?? reset($taxRates));
            $this->addReference('taxRate10', $this->findTaxRate($taxRates, 10) ?? reset($taxRates));
        }
    }

    private function findTaxRate(array $taxRates, float $rate): ?TaxRateInterface
    {
        foreach ($taxRates as $taxRate) {
            if ($taxRate instanceof TaxRateInterface && (float) $taxRate->getRate() === $rate) {
                return $taxRate;
            }
        }

        return null;
    }
}
