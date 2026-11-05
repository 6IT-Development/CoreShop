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

use CoreShop\Component\Resource\Model\AbstractResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * @psalm-suppress MissingConstructor
 */
class ProductUnitDefinitions extends AbstractResource implements ProductUnitDefinitionsInterface
{
    /**
     * @var int|null
     */
    protected $id;

    /**
     * @var ProductInterface
     */
    protected $product;

    /**
     * @var ProductUnitDefinitionInterface|null
     */
    protected $defaultUnitDefinition;

    /**
     * @var Collection<int, ProductUnitDefinitionInterface>|ProductUnitDefinitionInterface[]
     */
    protected $unitDefinitions;

    public function __construct(
        ) {
        $this->unitDefinitions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id)
    {
        $this->id = $id;
    }

    public function getProduct()
    {
        return $this->product;
    }

    public function setProduct(ProductInterface $product)
    {
        $this->product = $product;
    }

    public function getDefaultUnitDefinition()
    {
        return $this->defaultUnitDefinition;
    }

    /**
     * Makes the definition of the given unit the default (base) unit with conversion rate 1.0.
     *
     * When the product already has a definition for that unit, that definition becomes the
     * default and takes the precision of the argument; the argument itself is not added. When
     * the default changes to a unit that already had a conversion rate, every other rate is
     * converted to the new base (rate / rate of the new default), so the old default keeps its
     * meaning (a box of 12 as new default turns the piece into 1/12).
     *
     * @return ProductUnitDefinitionInterface the definition that is the default now
     */
    public function setDefaultUnitDefinition(ProductUnitDefinitionInterface $defaultUnitDefinition)
    {
        $unit = $defaultUnitDefinition->getUnit();

        if (!$unit instanceof ProductUnitInterface) {
            throw new \InvalidArgumentException('The default unit definition needs a unit.');
        }

        $existing = $this->findUnitDefinitionForUnit($unit);
        $previousDefault = $this->defaultUnitDefinition;

        if (null !== $existing && $existing !== $previousDefault) {
            $rate = $existing->getConversionRate();

            if (null !== $rate && $rate > 0 && 1.0 !== (float) $rate) {
                foreach ($this->unitDefinitions as $unitDefinition) {
                    $otherRate = $unitDefinition->getConversionRate();

                    if ($unitDefinition !== $existing && null !== $otherRate) {
                        $unitDefinition->setConversionRate((float) $otherRate / (float) $rate);
                    }
                }
            }
        }

        if (null === $existing) {
            $defaultUnitDefinition->setProductUnitDefinitions($this);
            $this->unitDefinitions->add($defaultUnitDefinition);
            $existing = $defaultUnitDefinition;
        } elseif ($existing !== $defaultUnitDefinition) {
            $existing->setPrecision($defaultUnitDefinition->getPrecision());
        }

        $existing->setConversionRate(1.0);
        $this->defaultUnitDefinition = $existing;

        return $existing;
    }

    /**
     * Adds the definition, or, when the product already has a definition for the same unit,
     * copies precision and conversion rate onto that one. The conversion rate of the default
     * definition always stays 1.0.
     *
     * @return ProductUnitDefinitionInterface the definition that is in the collection now; use it
     *                                        instead of the argument when they differ
     */
    public function addUnitDefinition(ProductUnitDefinitionInterface $productUnitDefinition)
    {
        $productUnit = $productUnitDefinition->getUnit();
        $existingUnitDefinition = $productUnit instanceof ProductUnitInterface ? $this->findUnitDefinitionForUnit($productUnit) : null;

        if (null === $existingUnitDefinition) {
            if (!$this->unitDefinitions->contains($productUnitDefinition)) {
                $this->unitDefinitions->add($productUnitDefinition);
            }

            $productUnitDefinition->setProductUnitDefinitions($this);

            return $productUnitDefinition;
        }

        if ($existingUnitDefinition !== $productUnitDefinition) {
            $existingUnitDefinition->setPrecision($productUnitDefinition->getPrecision());

            if ($existingUnitDefinition !== $this->defaultUnitDefinition) {
                $existingUnitDefinition->setConversionRate($productUnitDefinition->getConversionRate());
            }
        }

        $existingUnitDefinition->setProductUnitDefinitions($this);

        return $existingUnitDefinition;
    }

    public function hasUnitDefinition(ProductUnitDefinitionInterface $unitDefinition)
    {
        return $this->unitDefinitions->contains($unitDefinition);
    }

    /**
     * Removing the default definition also clears the default, so the product never points to
     * a definition that is not in its collection (and is deleted on flush).
     */
    public function removeUnitDefinition(ProductUnitDefinitionInterface $productUnitDefinition)
    {
        if ($this->unitDefinitions->contains($productUnitDefinition)) {
            $this->unitDefinitions->removeElement($productUnitDefinition);
        }

        if ($productUnitDefinition === $this->defaultUnitDefinition) {
            $this->defaultUnitDefinition = null;
        }
    }

    public function getUnitDefinitions()
    {
        return $this->unitDefinitions;
    }

    /**
     * Looks a definition up by unit name (unit names are unique).
     */
    public function getUnitDefinition(string $identifier)
    {
        foreach ($this->unitDefinitions as $unitDefinition) {
            if ($unitDefinition->getUnit()?->getName() === $identifier) {
                return $unitDefinition;
            }
        }

        return null;
    }

    /**
     * Adds a definition for a unit other than the default unit; a definition for the default
     * unit is ignored, so it can never change the default's conversion rate.
     *
     * @return ProductUnitDefinitionInterface|null the definition in the collection, or null when it was ignored
     */
    public function addAdditionalUnitDefinition(ProductUnitDefinitionInterface $unitDefinition)
    {
        if ($this->isDefaultUnit($unitDefinition->getUnit())) {
            return null;
        }

        return $this->addUnitDefinition($unitDefinition);
    }

    /**
     * Removes a definition of a unit other than the default unit; the default is kept.
     */
    public function removeAdditionalUnitDefinition(ProductUnitDefinitionInterface $unitDefinition)
    {
        if ($unitDefinition === $this->defaultUnitDefinition || $this->isDefaultUnit($unitDefinition->getUnit())) {
            return;
        }

        $this->removeUnitDefinition($unitDefinition);
    }

    public function getAdditionalUnitDefinitions()
    {
        $defaultDefinition = $this->getDefaultUnitDefinition();

        if (null === $defaultDefinition || null === $defaultDefinition->getUnit()) {
            return new ArrayCollection();
        }

        $additionalDefinitions = $this->getUnitDefinitions()
            ->filter(fn (ProductUnitDefinitionInterface $definition): bool => $definition !== $defaultDefinition &&
                null !== $definition->getUnit() &&
                !$this->isDefaultUnit($definition->getUnit()))
        ;

        return new ArrayCollection(array_values($additionalDefinitions->toArray()));
    }

    private function findUnitDefinitionForUnit(ProductUnitInterface $unit): ?ProductUnitDefinitionInterface
    {
        foreach ($this->unitDefinitions as $unitDefinition) {
            if (self::isSameUnit($unitDefinition->getUnit(), $unit)) {
                return $unitDefinition;
            }
        }

        return null;
    }

    private function isDefaultUnit(?ProductUnitInterface $unit): bool
    {
        return self::isSameUnit($unit, $this->defaultUnitDefinition?->getUnit());
    }

    /**
     * Same object, or same id; units that are not saved yet (no id) are matched by name.
     */
    private static function isSameUnit(?ProductUnitInterface $a, ?ProductUnitInterface $b): bool
    {
        if (null === $a || null === $b) {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        if (null !== $a->getId() && null !== $b->getId()) {
            return $a->getId() === $b->getId();
        }

        return null !== $a->getName() && $a->getName() === $b->getName();
    }
}
