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

namespace CoreShop\Bundle\ProductBundle\Controller;

use CoreShop\Bundle\ResourceBundle\Controller\ResourceController;
use CoreShop\Bundle\ResourceBundle\Pimcore\Repository\StackRepositoryInterface;
use CoreShop\Component\Pimcore\DataObject\VersionHelper;
use CoreShop\Component\Product\Model\ProductInterface;
use CoreShop\Component\Product\Model\ProductUnitDefinitionInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\ElementInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductUnitDefinitionsController extends ResourceController
{
    public function productUnitDefinitionsListAction(Request $request, StackRepositoryInterface $coreshopProductStackRepository): Response
    {
        $product = $this->findViewableProduct($request, $coreshopProductStackRepository);

        return $this->viewHandler->handle($this->getUnitDefinitionsForProduct($product, 'all'));
    }

    public function productAdditionalUnitDefinitionsListAction(Request $request, StackRepositoryInterface $coreshopProductStackRepository): Response
    {
        $product = $this->findViewableProduct($request, $coreshopProductStackRepository);

        return $this->viewHandler->handle($this->getUnitDefinitionsForProduct($product, 'additional'));
    }

    /**
     * Both endpoints feed panels of the object editor, which edits the latest version
     * of the object. They must read the same state, so both use the latest version.
     *
     * @throws BadRequestHttpException when productId is missing or not a positive integer
     * @throws NotFoundHttpException   when productId is not a product
     */
    protected function findViewableProduct(Request $request, StackRepositoryInterface $coreshopProductStackRepository): ProductInterface
    {
        $productId = $this->getParameterFromRequest($request, 'productId');
        $productId = is_int($productId) || is_string($productId)
            ? filter_var($productId, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if (false === $productId) {
            throw new BadRequestHttpException('The "productId" parameter must be a positive integer.');
        }

        $product = $coreshopProductStackRepository->find($productId);

        if (!$product instanceof ProductInterface) {
            throw $this->createNotFoundException(sprintf('Product with ID %d not found.', $productId));
        }

        $this->denyAccessUnlessProductViewable($product);

        if ($product instanceof Concrete) {
            $latestVersion = VersionHelper::getLatestVersion($product);

            if ($latestVersion instanceof ProductInterface) {
                $product = $latestVersion;
            }
        }

        return $product;
    }

    protected function denyAccessUnlessProductViewable(ProductInterface $product): void
    {
        if ($product instanceof ElementInterface && !$product->isAllowed('view')) {
            throw $this->createAccessDeniedException();
        }
    }

    protected function getUnitDefinitionsForProduct(ProductInterface $product, string $type = 'all'): Collection
    {
        $definitions = new ArrayCollection();

        if ($product->hasUnitDefinitions()) {
            $productUnitDefinitions = $product->getUnitDefinitions();
            $definitions = $type === 'additional'
                ? $productUnitDefinitions->getAdditionalUnitDefinitions()
                : $productUnitDefinitions->getUnitDefinitions();
        } else {
            $parent = $product->getParent();

            if ($parent instanceof ProductInterface && $product instanceof Concrete && $product->getClass()->getAllowInherit()) {
                $definitions = $this->getUnitDefinitionsForProduct($parent, $type);
            }
        }

        return $definitions->filter(function (ProductUnitDefinitionInterface $unitDefinition) {
            return null !== $unitDefinition->getId();
        });
    }

    protected function getLatestVersion(Concrete $object): Concrete
    {
        $modificationDate = $object->getModificationDate();
        $latestVersion = $object->getLatestVersion();
        if ($latestVersion) {
            /**
             * @psalm-suppress InternalMethod
             */
            $latestObj = $latestVersion->loadData();
            if ($latestObj instanceof Concrete) {
                $object = $latestObj;
                $object->setModificationDate($modificationDate); // set de modification-date from published version to compare it in js-frontend
            }
        }

        return $object;
    }
}
