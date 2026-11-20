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
 * The notices of one cart, at most one per product and one per cart price rule. They stay until the customer
 * dismisses them or the order is placed, so the merge rules keep what the customer saw last: a second price change
 * keeps the first old price, a price that went back is no change, a removal replaces the price change.
 */
final class CartNotices
{
    /**
     * @param array<string, CartNotice> $notices keyed by CartNotice::subjectKey()
     */
    private function __construct(
        private array $notices,
    ) {
    }

    public static function fromArray(?array $notices): self
    {
        $collection = new self([]);
        foreach ($notices ?? [] as $notice) {
            if (!is_array($notice)) {
                continue;
            }

            $notice = CartNotice::fromArray($notice);
            $collection->notices[$notice->subjectKey()] = $notice;
        }

        return $collection;
    }

    /**
     * @return list<array{type: string, subject_id: int, name: string, old_price: ?int, new_price: ?int, at: int}>|null
     */
    public function toArray(): ?array
    {
        if ($this->notices === []) {
            return null;
        }

        return array_values(array_map(static fn (CartNotice $notice): array => $notice->toArray(), $this->notices));
    }

    /**
     * @return list<CartNotice>
     */
    public function all(): array
    {
        return array_values($this->notices);
    }

    public function isEmpty(): bool
    {
        return $this->notices === [];
    }

    public function recordRemoval(int $productId, string $name, int $price, int $at): void
    {
        $key = 'product:' . $productId;
        $oldPrice = $this->notices[$key]->oldPrice ?? $price;

        $this->notices[$key] = new CartNotice(CartNoticeType::Removed, $productId, $name, $oldPrice, null, $at);
    }

    public function recordPriceChange(int $productId, string $name, int $oldPrice, int $newPrice, int $at): void
    {
        $key = 'product:' . $productId;
        $previous = $this->notices[$key] ?? null;

        if ($previous?->type === CartNoticeType::Removed) {
            return;
        }

        $oldPrice = $previous->oldPrice ?? $oldPrice;
        if ($oldPrice === $newPrice) {
            unset($this->notices[$key]);

            return;
        }

        $this->notices[$key] = new CartNotice(CartNoticeType::PriceChanged, $productId, $name, $oldPrice, $newPrice, $at);
    }

    public function recordRuleRemoval(int $ruleId, string $name, int $at): void
    {
        $this->notices['rule:' . $ruleId] = new CartNotice(CartNoticeType::RuleRemoved, $ruleId, $name, null, null, $at);
    }

    /**
     * The rule applies again (e.g. the product that triggered it is back): the customer lost nothing.
     */
    public function forgetRuleRemoval(int $ruleId): void
    {
        unset($this->notices['rule:' . $ruleId]);
    }
}
