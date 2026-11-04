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

namespace CoreShop\Bundle\OrderBundle\Form\DataMapper;

use CoreShop\Component\StorageList\StorageListItemQuantityModifierInterface;
use Symfony\Component\Form\DataMapperInterface;

/**
 * @internal
 */
class CartItemQuantityDataMapper implements DataMapperInterface
{
    public function __construct(
        private StorageListItemQuantityModifierInterface $cartItemQuantityModifier,
        private DataMapperInterface $propertyPathDataMapper,
    ) {
    }

    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $this->propertyPathDataMapper->mapDataToForms($viewData, $forms);
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        $formsOtherThanQuantity = [];
        $quantityForm = null;

        // First map all the other fields, then map quantity: the quantity modifier needs the submitted
        // unit definition to compute the default unit quantity.
        foreach ($forms as $form) {
            if ('quantity' === $form->getName()) {
                $quantityForm = $form;

                continue;
            }

            $formsOtherThanQuantity[] = $form;
        }

        if (!empty($formsOtherThanQuantity)) {
            $this->propertyPathDataMapper->mapFormsToData(new \ArrayObject($formsOtherThanQuantity), $viewData);
        }

        if (null === $quantityForm) {
            return;
        }

        $targetQuantity = $quantityForm->getData();

        // Like Symfony's own mapper: skip a field that was not submitted or could not be parsed,
        // instead of turning it into quantity 0.
        if ($quantityForm->isSubmitted() &&
            $quantityForm->isSynchronized() &&
            !$quantityForm->isDisabled() &&
            $quantityForm->getConfig()->getMapped() &&
            is_numeric($targetQuantity)
        ) {
            $this->cartItemQuantityModifier->modify($viewData, (float) $targetQuantity);
        }
    }
}
