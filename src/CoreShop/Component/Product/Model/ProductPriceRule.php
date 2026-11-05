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

/**
 * @psalm-suppress MissingConstructor
 */
class ProductPriceRule extends AbstractPriceRule implements ProductPriceRuleInterface
{
    /**
     * Only catalog price rules have a description column; specific price rules have none.
     */
    protected ?string $description = null;

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = null === $description ? null : (string) $description;

        return $this;
    }

    protected function createTranslation(): ProductPriceRuleTranslationInterface
    {
        return new ProductPriceRuleTranslation();
    }
}
