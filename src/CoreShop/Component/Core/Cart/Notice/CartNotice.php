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

namespace CoreShop\Component\Core\Cart\Notice;

/**
 * One change of a cart the customer did not make. The name is copied when the notice is written: a removed product
 * is usually unpublished or deleted, so it can not be looked up when the notice is shown.
 */
final readonly class CartNotice
{
    public function __construct(
        public CartNoticeType $type,
        /** the product id, or the cart price rule id for CartNoticeType::RuleRemoved */
        public int $subjectId,
        public string $name,
        /** gross unit price the customer saw before, null for CartNoticeType::RuleRemoved */
        public ?int $oldPrice,
        /** gross unit price after the recalculation, null for removals */
        public ?int $newPrice,
        public int $at,
    ) {
    }

    /**
     * @param array{type: string, subject_id: int, name: string, old_price: ?int, new_price: ?int, at: int} $notice
     */
    public static function fromArray(array $notice): self
    {
        return new self(
            CartNoticeType::from($notice['type']),
            (int) $notice['subject_id'],
            (string) $notice['name'],
            isset($notice['old_price']) ? (int) $notice['old_price'] : null,
            isset($notice['new_price']) ? (int) $notice['new_price'] : null,
            (int) $notice['at'],
        );
    }

    /**
     * Plain scalars only: the field is stored with serialize() and read back with unserialize().
     *
     * @return array{type: string, subject_id: int, name: string, old_price: ?int, new_price: ?int, at: int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'subject_id' => $this->subjectId,
            'name' => $this->name,
            'old_price' => $this->oldPrice,
            'new_price' => $this->newPrice,
            'at' => $this->at,
        ];
    }

    public function subjectKey(): string
    {
        return ($this->type->isAboutProduct() ? 'product:' : 'rule:') . $this->subjectId;
    }
}
