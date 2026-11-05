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

namespace CoreShop\Component\Order\Modifier;

use CoreShop\Component\Currency\Converter\CurrencyConverterInterface;
use CoreShop\Component\Currency\Model\CurrencyAwareInterface;
use CoreShop\Component\Order\Factory\CartPriceRuleVoucherCodeCustomerFactoryInterface;
use CoreShop\Component\Order\Model\CartPriceRuleVoucherCodeCustomerInterface;
use CoreShop\Component\Order\Model\CartPriceRuleVoucherCodeInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\PriceRuleItemInterface;
use CoreShop\Component\Order\Repository\CartPriceRuleVoucherCodeCustomerRepositoryInterface;
use CoreShop\Component\Order\Repository\CartPriceRuleVoucherRepositoryInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Pimcore\Model\DataObject\Fieldcollection;

class VoucherModifier implements VoucherModifierInterface
{
    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected CartPriceRuleVoucherRepositoryInterface $voucherCodeRepository,
        protected CartPriceRuleVoucherCodeCustomerRepositoryInterface $codePerUserRepository,
        protected CartPriceRuleVoucherCodeCustomerFactoryInterface $voucherCodeCustomerFactory,
        protected ?CurrencyConverterInterface $currencyConverter = null,
    ) {
    }

    public function increment(OrderInterface $order): void
    {
        $this->entityManager->wrapInTransaction(function () use ($order): void {
            $this->doIncrement($order);
        });
    }

    public function decrement(OrderInterface $order): void
    {
        $this->entityManager->wrapInTransaction(function () use ($order): void {
            $this->doDecrement($order);
        });
    }

    protected function doIncrement(OrderInterface $order): void
    {
        $priceRuleItems = $order->getPriceRuleItems();
        if (!$priceRuleItems instanceof Fieldcollection) {
            return;
        }

        foreach ($priceRuleItems->getItems() as $item) {
            if (!$item instanceof PriceRuleItemInterface) {
                continue;
            }

            if (!$item->getVoucherCode()) {
                continue;
            }

            $voucherCode = $this->findAndLockVoucherCode($item->getVoucherCode());

            /*
             * The code was deleted while the cart was open (only used codes are protected), so there is
             * nothing to count, neither on the code nor per customer.
             */
            if (!$voucherCode instanceof CartPriceRuleVoucherCodeInterface) {
                continue;
            }

            $voucherCode->setUsed(true);
            $voucherCode->setUses($voucherCode->getUses() + 1);

            if ($voucherCode->isCreditCode()) {
                $voucherCode->setCreditUsed(
                    (int) $voucherCode->getCreditUsed() + $this->getUsedCredit($order, $item, $voucherCode),
                );
            }

            $this->entityManager->persist($voucherCode);

            $customer = $order->getCustomer();

            if (!$customer) {
                continue;
            }

            foreach ($item->getCartPriceRule()?->getConditions() ?: [] as $conditions) {
                if ($conditions->getType() === 'voucher') {
                    $maxUsagePerCustomer = $conditions->getConfiguration()['maxUsagePerUser'] ?? null;

                    if ($maxUsagePerCustomer !== null) {
                        $perCustomerEntry = $this->codePerUserRepository->findUsesByCustomer($customer, $voucherCode);

                        if ($perCustomerEntry instanceof CartPriceRuleVoucherCodeCustomerInterface) {
                            $perCustomerEntry->incrementUses();

                            $this->entityManager->persist($perCustomerEntry);
                        }

                        if (null === $perCustomerEntry) {
                            $perCustomerEntry = $this->voucherCodeCustomerFactory->createWithInitialData($customer, $voucherCode);

                            $this->entityManager->persist($perCustomerEntry);
                        }
                    }
                }
            }
        }

        $this->entityManager->flush();
    }

    protected function doDecrement(OrderInterface $order): void
    {
        $priceRuleItems = $order->getPriceRuleItems();
        if (!$priceRuleItems instanceof Fieldcollection) {
            return;
        }

        foreach ($priceRuleItems->getItems() as $item) {
            if (!$item instanceof PriceRuleItemInterface) {
                continue;
            }

            if (!$item->getVoucherCode()) {
                continue;
            }

            $voucherCode = $this->findAndLockVoucherCode($item->getVoucherCode());

            if (!$voucherCode instanceof CartPriceRuleVoucherCodeInterface) {
                continue;
            }

            if ($voucherCode->getUses() !== 0) {
                $voucherCode->setUses($voucherCode->getUses() - 1);
                $voucherCode->setUsed($voucherCode->getUses() !== 0);

                if ($voucherCode->isCreditCode()) {
                    $voucherCode->setCreditUsed(
                        max(0, (int) $voucherCode->getCreditUsed() - $this->getUsedCredit($order, $item, $voucherCode)),
                    );
                }

                $this->entityManager->persist($voucherCode);
            }

            $customer = $order->getCustomer();

            if (!$customer) {
                continue;
            }

            foreach ($item->getCartPriceRule()?->getConditions() ?: [] as $conditions) {
                if ($conditions->getType() === 'voucher') {
                    $maxUsagePerCustomer = $conditions->getConfiguration()['maxUsagePerUser'] ?? null;

                    if ($maxUsagePerCustomer !== null) {
                        $perCustomerEntry = $this->codePerUserRepository->findUsesByCustomer($customer, $voucherCode);

                        if ($perCustomerEntry instanceof CartPriceRuleVoucherCodeCustomerInterface) {
                            $perCustomerEntry->decrementUses();

                            if ($perCustomerEntry->getUses() === 0) {
                                $this->entityManager->remove($perCustomerEntry);
                            } else {
                                $this->entityManager->persist($perCustomerEntry);
                            }
                        }
                    }
                }
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Loads the voucher code again with a row lock, so that concurrent orders
     * do not overwrite each other's uses and used credit.
     */
    protected function findAndLockVoucherCode(string $code): ?CartPriceRuleVoucherCodeInterface
    {
        $voucherCode = $this->voucherCodeRepository->findByCode($code);

        if ($voucherCode instanceof CartPriceRuleVoucherCodeInterface) {
            $this->entityManager->refresh($voucherCode, LockMode::PESSIMISTIC_WRITE);
        }

        return $voucherCode;
    }

    /**
     * The discount is stored in the order's base currency, the used credit in
     * the credit currency of the voucher code.
     */
    protected function getUsedCredit(
        OrderInterface $order,
        PriceRuleItemInterface $item,
        CartPriceRuleVoucherCodeInterface $voucherCode,
    ): int {
        $usedCredit = -1 * $item->getDiscount(true);

        $fromCurrency = $order->getBaseCurrency();
        $store = $order->getStore();

        if (null === $fromCurrency && $store instanceof CurrencyAwareInterface) {
            $fromCurrency = $store->getCurrency();
        }

        $toCurrency = $voucherCode->getCreditCurrency();

        if (null === $this->currencyConverter || null === $fromCurrency || null === $toCurrency) {
            return $usedCredit;
        }

        return $this->currencyConverter->convert($usedCredit, $fromCurrency->getIsoCode(), $toCurrency->getIsoCode());
    }
}
