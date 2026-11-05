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

namespace CoreShop\Component\Order\Transformer;

use Carbon\Carbon;
use CoreShop\Component\Order\Factory\AdjustmentFactoryInterface;
use CoreShop\Component\Order\Model\OrderDocumentInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\OrderInvoiceInterface;
use CoreShop\Component\Order\Model\OrderInvoiceItemInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Order\NumberGenerator\NumberGeneratorInterface;
use CoreShop\Component\Order\OrderInvoiceStates;
use CoreShop\Component\Order\Repository\OrderInvoiceRepositoryInterface;
use CoreShop\Component\Pimcore\DataObject\VersionHelper;
use CoreShop\Component\Resource\Factory\PimcoreFactoryInterface;
use CoreShop\Component\Resource\Repository\PimcoreRepositoryInterface;
use CoreShop\Component\Resource\Service\FolderCreationServiceInterface;
use Pimcore\Model\DataObject\Service;
use Webmozart\Assert\Assert;

class OrderToInvoiceTransformer implements OrderDocumentTransformerInterface
{
    public function __construct(
        protected OrderDocumentItemTransformerInterface $orderItemToInvoiceItemTransformer,
        protected NumberGeneratorInterface $numberGenerator,
        protected FolderCreationServiceInterface $folderCreationService,
        protected PimcoreRepositoryInterface $orderItemRepository,
        protected PimcoreFactoryInterface $invoiceItemFactory,
        protected OrderInvoiceRepositoryInterface $invoiceRepository,
        protected TransformerEventDispatcherInterface $eventDispatcher,
        protected AdjustmentFactoryInterface $adjustmentFactory,
    ) {
    }

    public function transform(
        OrderInterface $order,
        OrderDocumentInterface $document,
        array $itemsToTransform,
    ): OrderDocumentInterface {
        /**
         * @var OrderInterface $order
         */
        Assert::isInstanceOf($order, OrderInterface::class);
        Assert::isInstanceOf($document, OrderInvoiceInterface::class);

        $orderItems = $this->getOrderItemsToTransform($order, $itemsToTransform);

        $this->eventDispatcher->dispatchPreEvent('invoice', $document, ['order' => $order, 'items' => $itemsToTransform]);

        $documentFolder = $this->folderCreationService->createFolderForResource($document, ['prefix' => $order->getFullPath()]);

        $document->setOrder($order);

        $documentNumber = $this->numberGenerator->generate($document);

        /**
         * @var OrderInvoiceInterface $document
         */
        $document->setKey(Service::getValidKey($documentNumber, 'object'));
        $document->setInvoiceNumber($documentNumber);
        $document->setParent($documentFolder);
        $document->setPublished(true);
        $document->setInvoiceDate(Carbon::now());

        /*
         * We need to save the order twice in order to create the object in the tree for pimcore
         */
        VersionHelper::useVersioning(function () use ($document) {
            $document->save();
        }, false);

        $items = [];

        /**
         * @var array<string, mixed> $item
         */
        foreach ($itemsToTransform as $index => $item) {
            $items[] = $this->orderItemToInvoiceItemTransformer->transform(
                $document,
                $orderItems[$index],
                $this->invoiceItemFactory->createNew(),
                (float) $item['quantity'],
                $item,
            );
        }

        $document->setItems($items);

        VersionHelper::useVersioning(function () use ($document) {
            $document->save();
        }, false);

        $this->calculateInvoice($document);

        $this->eventDispatcher->dispatchPostEvent('invoice', $document, ['order' => $order, 'items' => $itemsToTransform]);

        return $document;
    }

    /**
     * Resolves and validates all items before anything is saved: every item must belong to the order
     * and have a positive quantity.
     *
     * @return array<array-key, OrderItemInterface>
     */
    private function getOrderItemsToTransform(OrderInterface $order, array $itemsToTransform): array
    {
        $orderItems = [];

        foreach ($itemsToTransform as $index => $item) {
            Assert::keyExists($item, 'quantity');
            Assert::keyExists($item, 'orderItemId');

            $orderItem = $this->orderItemRepository->find($item['orderItemId']);

            Assert::isInstanceOf($orderItem, OrderItemInterface::class, sprintf('Order item %s not found', $item['orderItemId']));
            Assert::same($orderItem->getOrder()?->getId(), $order->getId(), sprintf('Order item %s does not belong to order %s', $item['orderItemId'], $order->getId()));
            Assert::greaterThan((float) $item['quantity'], 0, sprintf('Invalid quantity for order item %s', $item['orderItemId']));

            $orderItems[$index] = $orderItem;
        }

        return $orderItems;
    }

    private function calculateInvoice(OrderInvoiceInterface $document): void
    {
        $this->calculateSubtotal($document, true);
        $this->calculateSubtotal($document, false);
        $this->calculateAdjustments($document, true);
        $this->calculateAdjustments($document, false);
        $this->calculateTotal($document, true);
        $this->calculateTotal($document, false);

        VersionHelper::useVersioning(function () use ($document) {
            $document->save();
        }, false);
    }

    private function calculateSubtotal(OrderInvoiceInterface $document, bool $converted = true): void
    {
        $subtotalWithTax = 0;
        $subtotalWithoutTax = 0;

        /**
         * @var OrderInvoiceItemInterface $item
         */
        foreach ($document->getItems() as $item) {
            if ($converted) {
                $subtotalWithTax += $item->getConvertedTotal();
                $subtotalWithoutTax += $item->getConvertedTotal(false);
            } else {
                $subtotalWithTax += $item->getTotal();
                $subtotalWithoutTax += $item->getTotal(false);
            }
        }

        if ($converted) {
            $document->setConvertedSubtotal($subtotalWithTax);
            $document->setConvertedSubtotal($subtotalWithoutTax, false);
        } else {
            $document->setSubtotal($subtotalWithTax);
            $document->setSubtotal($subtotalWithoutTax, false);
        }
    }

    private function calculateAdjustments(OrderInvoiceInterface $document, bool $converted = true): void
    {
        $order = $document->getOrder();

        $processedTypes = [];

        foreach ($converted ? $order->getConvertedAdjustments() : $order->getAdjustments() as $adjustment) {
            /*
             * Neutral adjustments are not part of the order total (e.g. cart item rule discounts, which are
             * already in the item totals), and every type is processed once with the total of that type
             */
            if ($adjustment->getNeutral() || isset($processedTypes[$adjustment->getTypeIdentifier()])) {
                continue;
            }

            $processedTypes[$adjustment->getTypeIdentifier()] = true;

            $orderAdjustmentsGross = $converted ? $order->getConvertedAdjustmentsTotal($adjustment->getTypeIdentifier()) : $order->getAdjustmentsTotal($adjustment->getTypeIdentifier());
            $orderAdjustmentsNet = $converted ? $order->getConvertedAdjustmentsTotal($adjustment->getTypeIdentifier(), false) : $order->getAdjustmentsTotal($adjustment->getTypeIdentifier(), false);

            $adjustmentValueToProcessGross = $orderAdjustmentsGross - $this->getProcessedAdjustmentValue($order, $adjustment->getTypeIdentifier(), true, $converted);
            $adjustmentValueToProcessNet = $orderAdjustmentsNet - $this->getProcessedAdjustmentValue($order, $adjustment->getTypeIdentifier(), false, $converted);

            if (0 !== $adjustmentValueToProcessGross) {
                $newAdjustment = $this->adjustmentFactory->createWithData($adjustment->getTypeIdentifier(), $adjustment->getLabel(), $adjustmentValueToProcessGross, $adjustmentValueToProcessNet, false);

                $converted ? $document->addConvertedAdjustment($newAdjustment) : $document->addAdjustment($newAdjustment);
            }
        }
    }

    private function calculateTotal(OrderInvoiceInterface $document, bool $converted = true): void
    {
        if ($converted) {
            $subtotalWithTax = $document->getConvertedSubtotal();
            $adjustmentsTotal = $document->getConvertedAdjustmentsTotal();

            $subtotalWithoutTax = $document->getConvertedSubtotal(false);
            $adjustmentsTotalWithoutTax = $document->getConvertedAdjustmentsTotal(null, false);
        } else {
            $subtotalWithTax = $document->getSubtotal();
            $adjustmentsTotal = $document->getAdjustmentsTotal();

            $subtotalWithoutTax = $document->getSubtotal(false);
            $adjustmentsTotalWithoutTax = $document->getAdjustmentsTotal(null, false);
        }

        $total = $subtotalWithTax + $adjustmentsTotal;
        $totalWithoutTax = $subtotalWithoutTax + $adjustmentsTotalWithoutTax;

        if ($converted) {
            $document->setConvertedTotal($total);
            $document->setConvertedTotal($totalWithoutTax, false);
        } else {
            $document->setTotal($total);
            $document->setTotal($totalWithoutTax, false);
        }
    }

    private function getProcessedAdjustmentValue(OrderInterface $order, string $adjustmentIdentifier, bool $withTax, bool $converted): int
    {
        $documents = $this->invoiceRepository->getDocumentsNotInState($order, OrderInvoiceStates::STATE_CANCELLED);
        $processedValue = 0;

        /**
         * @var OrderInvoiceInterface $document
         */
        foreach ($documents as $document) {
            foreach ($converted ? $document->getConvertedAdjustments() : $document->getAdjustments() as $adjustment) {
                if (!$adjustment->getNeutral() && $adjustment->getTypeIdentifier() === $adjustmentIdentifier) {
                    $processedValue += $adjustment->getAmount($withTax);
                }
            }
        }

        return $processedValue;
    }
}
