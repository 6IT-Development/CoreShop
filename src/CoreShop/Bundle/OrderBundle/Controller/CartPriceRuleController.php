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

namespace CoreShop\Bundle\OrderBundle\Controller;

use CoreShop\Bundle\OrderBundle\Form\Type\VoucherGeneratorType;
use CoreShop\Bundle\OrderBundle\Form\Type\VoucherType;
use CoreShop\Bundle\ResourceBundle\Controller\ResourceController;
use CoreShop\Component\Order\Generator\CartPriceRuleVoucherCodeGenerator;
use CoreShop\Component\Order\Model\CartPriceRuleInterface;
use CoreShop\Component\Order\Model\CartPriceRuleVoucherCodeInterface;
use CoreShop\Component\Order\Repository\CartPriceRuleRepositoryInterface;
use CoreShop\Component\Order\Repository\CartPriceRuleVoucherRepositoryInterface;
use CoreShop\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CartPriceRuleController extends ResourceController
{
    public function listVoucherRulesAction(): JsonResponse
    {
        $this->isGrantedOr403();

        /**
         * @var CartPriceRuleRepositoryInterface $repository
         */
        $repository = $this->repository;

        $data = $repository->findVoucherRules();

        return $this->viewHandler->handle($data, ['group' => 'List']);
    }

    public function getConfigAction(Request $request): JsonResponse
    {
        $this->isGrantedOr403();

        $actions = $this->getConfigActions();
        $conditions = $this->getConfigConditions();

        $itemActions = $this->getCartItemConfigActions();
        $itemConditions = $this->getCartItemConfigConditions();

        return $this->viewHandler->handle([
            'actions' => array_keys($actions),
            'conditions' => array_keys($conditions),
            'itemActions' => array_keys($itemActions),
            'itemConditions' => array_keys($itemConditions),
        ]);
    }

    public function getCartItemConfigAction(Request $request): JsonResponse
    {
        $this->isGrantedOr403();

        $itemActions = $this->getCartItemConfigActions();
        $itemConditions = $this->getCartItemConfigConditions();

        return $this->viewHandler->handle([
            'actions' => array_keys($itemActions),
            'conditions' => array_keys($itemConditions),
        ]);
    }

    public function getVoucherCodesAction(Request $request, CartPriceRuleVoucherRepositoryInterface $cartPriceRuleVoucherCodeRepository): JsonResponse
    {
        $this->isGrantedOr403();

        $id = $this->getParameterFromRequest($request, 'cartPriceRule');
        $cartPriceRule = $this->repository->find($id);

        if (!$cartPriceRule instanceof CartPriceRuleInterface) {
            throw new NotFoundHttpException();
        }

        $data = $cartPriceRuleVoucherCodeRepository->findAllPaginator(
            $cartPriceRule,
            (int) $this->getParameterFromRequest($request, 'start', 0),
            (int) $this->getParameterFromRequest($request, 'limit', 50),
            $this->getVoucherCodeFilters($request),
            $this->getVoucherCodeOrderBy($request),
        );

        return $this->viewHandler->handle(
            [
                'total' => count($data),
                'data' => iterator_to_array($data->getIterator()),
                'success' => true,
            ],
            [
                'group' => 'Detailed',
            ],
        );
    }

    public function createVoucherCodeAction(Request $request, FormFactoryInterface $formFactory, CartPriceRuleVoucherRepositoryInterface $cartPriceRuleVoucherCodeRepository, FactoryInterface $cartPriceRuleVoucherCodeFactory): JsonResponse
    {
        $this->isGrantedOr403();

        $form = $formFactory->createNamed('', VoucherType::class);
        $handledForm = $form->handleRequest($request);
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true) && $handledForm->isValid()) {
            $resource = $form->getData();

            $codeCheck = $cartPriceRuleVoucherCodeRepository->findOneBy(['code' => $resource->getCode()]);

            if ($codeCheck instanceof CartPriceRuleVoucherCodeInterface) {
                return $this->viewHandler->handle(['success' => false, 'message' => 'voucher code already exists']);
            }

            /** @var CartPriceRuleVoucherCodeInterface $codeObject */
            $codeObject = $cartPriceRuleVoucherCodeFactory->createNew();
            $codeObject->setCode($resource->getCode());
            $codeObject->setCreationDate(new \DateTime());
            $codeObject->setUsed(false);
            $codeObject->setUses(0);
            $codeObject->setCartPriceRule($resource->getCartPriceRule());

            $this->manager->persist($codeObject);
            $this->manager->flush();

            return $this->viewHandler->handle(['success' => true]);
        }

        $errors = $this->formErrorSerializer->serializeErrorFromHandledForm($handledForm);

        return $this->viewHandler->handle(['success' => false, 'message' => implode(\PHP_EOL, $errors)]);
    }

    public function generateVoucherCodesAction(Request $request, FormFactoryInterface $formFactory, CartPriceRuleVoucherCodeGenerator $cartPriceRuleVoucherCodeGenerator): JsonResponse
    {
        $this->isGrantedOr403();

        $form = $formFactory->createNamed('', VoucherGeneratorType::class);

        $handledForm = $form->handleRequest($request);

        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true) && $handledForm->isValid()) {
            $resource = $form->getData();

            $codes = $cartPriceRuleVoucherCodeGenerator->generateCodes($resource);

            foreach ($codes as $code) {
                $this->manager->persist($code);
            }
            $this->manager->flush();

            return $this->viewHandler->handle(['success' => true]);
        }

        $errors = $this->formErrorSerializer->serializeErrorFromHandledForm($handledForm);

        return $this->viewHandler->handle(['success' => false, 'message' => implode(\PHP_EOL, $errors)]);
    }

    public function exportVoucherCodesAction(Request $request, CartPriceRuleVoucherRepositoryInterface $cartPriceRuleVoucherCodeRepository): StreamedResponse
    {
        $this->isGrantedOr403();

        $id = $this->getParameterFromRequest($request, 'cartPriceRule');
        $priceRule = $this->repository->find($id);

        if (!$priceRule instanceof CartPriceRuleInterface) {
            throw new NotFoundHttpException();
        }

        $response = new StreamedResponse(function () use ($priceRule, $cartPriceRuleVoucherCodeRepository): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['code', 'creationDate', 'used', 'uses'], ',', '"', '');

            $batchSize = 500;
            $offset = 0;

            do {
                $codes = iterator_to_array($cartPriceRuleVoucherCodeRepository->findAllPaginator($priceRule, $offset, $batchSize)->getIterator());

                /** @var CartPriceRuleVoucherCodeInterface $code */
                foreach ($codes as $code) {
                    fputcsv($handle, [
                        $this->sanitizeCsvCell((string) $code->getCode()),
                        $code->getCreationDate() instanceof \DateTimeInterface ? $code->getCreationDate()->getTimestamp() : '',
                        (int) $code->getUsed(),
                        (int) $code->getUses(),
                    ], ',', '"', '');

                    $this->manager->detach($code);
                }

                flush();
                $offset += $batchSize;
            } while (count($codes) === $batchSize);

            fclose($handle);
        });

        $fileName = str_replace(['/', '\\'], '_', ($priceRule->getName() ?: 'cart_price_rule') . '_vouchercodes.csv');
        $fallbackFileName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $fileName) ?? 'vouchercodes.csv';

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, $fallbackFileName));

        return $response;
    }

    public function deleteVoucherCodeAction(Request $request, CartPriceRuleVoucherRepositoryInterface $cartPriceRuleVoucherCodeRepository): JsonResponse
    {
        $this->isGrantedOr403();

        $cartPriceRuleId = $this->getParameterFromRequest($request, 'cartPriceRule');
        $id = $this->getParameterFromRequest($request, 'id');
        $cartPriceRule = $this->repository->find($cartPriceRuleId);

        if (!$cartPriceRule instanceof CartPriceRuleInterface) {
            throw new NotFoundHttpException();
        }

        $code = is_scalar($id) ? $cartPriceRuleVoucherCodeRepository->find((int) $id) : null;

        if ($code instanceof CartPriceRuleVoucherCodeInterface && $code->getCartPriceRule()?->getId() === $cartPriceRule->getId()) {
            $cartPriceRuleVoucherCodeRepository->remove($code);

            return $this->viewHandler->handle(['success' => true, 'id' => $id]);
        }

        return $this->viewHandler->handle(['success' => false]);
    }

    /**
     * Prevents spreadsheet applications from evaluating a cell as a formula.
     */
    protected function sanitizeCsvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Translates the grid's remote filters (pimcore.gridfilters, encoded as JSON) into repository filters.
     * Unknown properties and operators are ignored.
     *
     * @return list<array{field: 'code'|'used'|'uses', operator: 'like'|'eq'|'lt'|'gt', value: mixed}>
     */
    protected function getVoucherCodeFilters(Request $request): array
    {
        $filterParam = $this->getParameterFromRequest($request, 'filter');
        $filters = is_string($filterParam) ? json_decode($filterParam, true) : null;

        if (!is_array($filters)) {
            return [];
        }

        $result = [];

        foreach ($filters as $filter) {
            if (!is_array($filter) || !array_key_exists('value', $filter)) {
                continue;
            }

            $value = $filter['value'];
            $operator = $filter['operator'] ?? null;

            switch ($filter['property'] ?? null) {
                case 'code':
                    if (is_string($value) && $value !== '') {
                        $result[] = ['field' => 'code', 'operator' => 'like', 'value' => $value];
                    }

                    break;
                case 'used':
                    if (is_bool($value)) {
                        $result[] = ['field' => 'used', 'operator' => 'eq', 'value' => $value];
                    }

                    break;
                case 'uses':
                    if (is_numeric($value) && in_array($operator, ['eq', 'lt', 'gt'], true)) {
                        $result[] = ['field' => 'uses', 'operator' => $operator, 'value' => (int) $value];
                    }

                    break;
            }
        }

        return $result;
    }

    /**
     * @return array<'code'|'used'|'uses'|'creationDate', 'ASC'|'DESC'>
     */
    protected function getVoucherCodeOrderBy(Request $request): array
    {
        $sortParam = $this->getParameterFromRequest($request, 'sort');
        $sorters = is_string($sortParam) ? json_decode($sortParam, true) : null;

        if (!is_array($sorters)) {
            return [];
        }

        $result = [];

        foreach ($sorters as $sorter) {
            $property = is_array($sorter) ? ($sorter['property'] ?? null) : null;

            if (in_array($property, ['code', 'used', 'uses', 'creationDate'], true)) {
                $result[$property] = is_string($sorter['direction'] ?? null) && strtoupper($sorter['direction']) === 'DESC' ? 'DESC' : 'ASC';
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    protected function getConfigActions(): array
    {
        return $this->getParameter('coreshop.cart_price_rule.actions');
    }

    /**
     * @return array<string, string>
     */
    protected function getConfigConditions(): array
    {
        return $this->getParameter('coreshop.cart_price_rule.conditions');
    }

    /**
     * @return array<string, string>
     */
    protected function getCartItemConfigActions(): array
    {
        return $this->getParameter('coreshop.cart_item_price_rule.actions');
    }

    /**
     * @return array<string, string>
     */
    protected function getCartItemConfigConditions(): array
    {
        return $this->getParameter('coreshop.cart_item_price_rule.conditions');
    }
}
