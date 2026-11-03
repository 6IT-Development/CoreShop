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

namespace CoreShop\Bundle\CoreBundle\Form\Extension;

use CoreShop\Bundle\CurrencyBundle\Form\Type\CurrencyChoiceType;
use CoreShop\Bundle\MoneyBundle\Form\Type\MoneyType;
use CoreShop\Bundle\ProductBundle\Form\Type\Unit\ProductUnitDefinitionSelectionType;
use CoreShop\Bundle\ProductQuantityPriceRulesBundle\Form\Type\ProductQuantityRangeType;
use CoreShop\Component\Product\Model\ProductUnitDefinitionInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

final class ProductQuantityRangeTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private RepositoryInterface $productUnitDefinitionRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', MoneyType::class, [])
            ->add('currency', CurrencyChoiceType::class, [])
            ->add('percentage', NumberType::class, [])
            ->add('pseudoPrice', MoneyType::class, [])
            ->add('unitDefinition', ProductUnitDefinitionSelectionType::class, [])
        ;

        // on the range form, as the submitted unit is not available yet when rangeStartingFrom is submitted
        $builder->addEventListener(FormEvents::PRE_SUBMIT, [$this, 'roundQuantity'], -2048);
    }

    public function roundQuantity(FormEvent $event): void
    {
        $data = $event->getData();

        if (!is_array($data) || !is_scalar($data['rangeStartingFrom'] ?? null)) {
            return;
        }

        $quantity = str_replace(',', '.', (string) $data['rangeStartingFrom']);

        if (!is_numeric($quantity)) {
            return;
        }

        $scale = $this->getScale($data['unitDefinition'] ?? null);

        if ($scale === null) {
            return;
        }

        $quantity = (float) $quantity;
        $formattedQuantity = round($quantity, $scale, \PHP_ROUND_HALF_UP);

        if ($quantity !== $formattedQuantity) {
            $data['rangeStartingFrom'] = (string) $formattedQuantity;
            $event->setData($data);
        }
    }

    private function getScale(mixed $unitDefinitionId): ?int
    {
        if (!is_numeric($unitDefinitionId)) {
            return null;
        }

        $productUnitDefinition = $this->productUnitDefinitionRepository->find($unitDefinitionId);

        if (!$productUnitDefinition instanceof ProductUnitDefinitionInterface) {
            return null;
        }

        $precision = $productUnitDefinition->getPrecision();

        return is_int($precision) ? $precision : null;
    }

    public static function getExtendedTypes(): iterable
    {
        return [ProductQuantityRangeType::class];
    }
}
