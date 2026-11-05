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

namespace CoreShop\Component\Product\Model;

use CoreShop\Component\Resource\Model\ResourceInterface;
use Doctrine\Common\Collections\Collection;

interface ProductUnitDefinitionsInterface extends ResourceInterface
{
    public function getId(): ?int;

    /**
     * @return ProductInterface
     */
    public function getProduct();

    public function setProduct(ProductInterface $product);

    /**
     * @return ProductUnitDefinitionInterface|null
     */
    public function getDefaultUnitDefinition();

    /**
     * Makes the definition of that unit the default with conversion rate 1.0; when the default
     * unit changes to a unit that already has a definition, the other rates are converted to the
     * new base.
     *
     * @throws \InvalidArgumentException when the definition has no unit
     *
     * @return ProductUnitDefinitionInterface the definition that is the default now (an existing
     *                                        definition of the same unit, or the argument)
     */
    public function setDefaultUnitDefinition(ProductUnitDefinitionInterface $defaultUnitDefinition);

    /**
     * Definitions are matched by unit (object, id, or name for unsaved units): when one for the
     * same unit exists, it takes precision and conversion rate (not for the default) of the argument.
     *
     * @return ProductUnitDefinitionInterface the definition that is in the collection now
     */
    public function addUnitDefinition(ProductUnitDefinitionInterface $productUnitDefinition);

    /**
     * Removing the default definition also clears the default.
     */

    public function removeUnitDefinition(ProductUnitDefinitionInterface $productUnitDefinition);

    /**
     * @return Collection|ProductUnitDefinitionInterface[]
     */
    public function getUnitDefinitions();

    /**
     * @return bool
     */
    public function hasUnitDefinition(ProductUnitDefinitionInterface $unitDefinition);

    /**
     * @return ProductUnitDefinitionInterface|null
     */
    public function getUnitDefinition(string $identifier);

    /**
     * A definition for the default unit is ignored.
     *
     * @return ProductUnitDefinitionInterface|null the definition in the collection, or null when ignored
     */
    public function addAdditionalUnitDefinition(ProductUnitDefinitionInterface $unitDefinition);

    /**
     * The default definition is never removed this way.
     */
    public function removeAdditionalUnitDefinition(ProductUnitDefinitionInterface $unitDefinition);

    /**
     * @return Collection|ProductUnitDefinitionInterface[]
     */
    public function getAdditionalUnitDefinitions();
}
