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

use CoreShop\Component\Product\Model\ProductUnitInterface;
use CoreShop\Component\Resource\Factory\FactoryInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class ProductUnitFixture extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private FactoryInterface $productUnitFactory,
        private ?RepositoryInterface $productUnitRepository = null,
    ) {
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        $units = [
            'unit-piece' => [
                'name' => 'Piece',
                'en' => ['Pc', 'Pcs', 'Piece', 'Pieces'],
                'de' => ['Stk', 'Stk', 'Stück', 'Stück'],
            ],
            'unit-carton' => [
                'name' => 'Carton',
                'en' => ['Ctn', 'Ctns', 'Carton', 'Cartons'],
                'de' => ['Ktn', 'Ktne', 'Karton', 'Kartone'],
            ],
            'unit-palette' => [
                'name' => 'Palette',
                'en' => ['Pal', 'Pals', 'Palette', 'Palettes'],
                'de' => ['Pal', 'Paln', 'Palette', 'Paletten'],
            ],
        ];

        foreach ($units as $reference => $unit) {
            // the name is unique, a second run reuses the existing unit
            $productUnit = $this->productUnitRepository?->findOneBy(['name' => $unit['name']]);

            if (!$productUnit instanceof ProductUnitInterface) {
                /**
                 * @var ProductUnitInterface $productUnit
                 */
                $productUnit = $this->productUnitFactory->createNew();
                $productUnit->setName($unit['name']);

                foreach (['en', 'de'] as $language) {
                    [$shortLabel, $shortPluralLabel, $fullLabel, $fullPluralLabel] = $unit[$language];

                    $productUnit->setShortLabel($shortLabel, $language);
                    $productUnit->setShortPluralLabel($shortPluralLabel, $language);
                    $productUnit->setFullLabel($fullLabel, $language);
                    $productUnit->setFullPluralLabel($fullPluralLabel, $language);
                }

                $manager->persist($productUnit);
            }

            $this->setReference($reference, $productUnit);
        }

        $manager->flush();
    }
}
