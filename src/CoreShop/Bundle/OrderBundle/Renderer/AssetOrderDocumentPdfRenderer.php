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

namespace CoreShop\Bundle\OrderBundle\Renderer;

use CoreShop\Component\Order\Model\OrderDocumentInterface;
use CoreShop\Component\Order\Renderer\OrderDocumentRendererInterface;
use Pimcore\Model\Asset;
use Symfony\Component\Lock\LockFactory;

class AssetOrderDocumentPdfRenderer implements OrderDocumentRendererInterface
{
    /**
     * Modification date of the order document the asset was rendered for.
     */
    private const RENDERED_FOR_PROPERTY = 'coreshop_rendered_document_modification_date';

    public function __construct(
        private OrderDocumentRendererInterface $decoratedService,
        private string $environment,
        private ?LockFactory $lockFactory = null,
    ) {
    }

    public function renderDocumentPdf(OrderDocumentInterface $orderDocument): string
    {
        // if in dev mode, do not store document
        if ($this->environment === 'dev') {
            return $this->decoratedService->renderDocumentPdf($orderDocument);
        }

        $renderedAsset = $orderDocument->getRenderedAsset();

        if ($renderedAsset instanceof Asset && $this->isUpToDate($renderedAsset, $orderDocument)) {
            return $renderedAsset->getData();
        }

        $lock = $orderDocument->getId() && $this->lockFactory !== null
            ? $this->lockFactory->createLock(sprintf('coreshop_order_document_pdf_%s', $orderDocument->getId()))
            : null;

        $lock?->acquire(true);

        try {
            $assetPath = $orderDocument->getFullPath();
            $assetName = sprintf('%s.pdf', $orderDocument::getDocumentType());

            $asset = Asset\Document::getByPath($assetPath . '/' . $assetName, ['force' => true]);

            // Another request may have rendered it while this one was waiting for the lock.
            if ($asset instanceof Asset\Document && $this->isUpToDate($asset, $orderDocument)) {
                return $asset->getData();
            }

            if (!$asset instanceof Asset\Document) {
                $asset = new Asset\Document();
                $asset->setFilename($assetName);
                $asset->setParent(Asset\Service::createFolderByPath($assetPath));
            }

            $asset->setData($this->decoratedService->renderDocumentPdf($orderDocument));
            $asset->setProperty(self::RENDERED_FOR_PROPERTY, 'text', (string) $orderDocument->getModificationDate());
            $asset->save();

            // Saving the document changes its modification date, so save it only when the link changes,
            // and stamp the asset with the new date afterwards.
            if (!$renderedAsset instanceof Asset || $renderedAsset->getId() !== $asset->getId()) {
                $orderDocument->setRenderedAsset($asset);
                $orderDocument->save();

                $asset->setProperty(self::RENDERED_FOR_PROPERTY, 'text', (string) $orderDocument->getModificationDate());
                $asset->save();
            }

            return $asset->getData();
        } finally {
            $lock?->release();
        }
    }

    private function isUpToDate(Asset $asset, OrderDocumentInterface $orderDocument): bool
    {
        $renderedFor = $asset->getProperty(self::RENDERED_FOR_PROPERTY);

        if (is_numeric($renderedFor)) {
            return (int) $renderedFor >= (int) $orderDocument->getModificationDate();
        }

        // Assets rendered before the property was introduced.
        return $asset->getCreationDate() >= $orderDocument->getModificationDate();
    }
}
