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

namespace CoreShop\Bundle\CoreBundle\Controller;

use CoreShop\Bundle\ResourceBundle\Controller\AdminController;
use CoreShop\Component\Core\Customer\CustomerTransformHelperInterface;
use CoreShop\Component\Core\Model\CompanyInterface;
use CoreShop\Component\Core\Model\CustomerInterface;
use CoreShop\Component\Customer\Repository\CompanyRepositoryInterface;
use CoreShop\Component\Customer\Repository\CustomerRepositoryInterface;
use Pimcore\Model\Element\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\SubscribedService;

class CustomerTransformerController extends AdminController
{
    /** shorter search terms would match (and hydrate) almost every company */
    private const DUPLICATE_SEARCH_MIN_LENGTH = 3;

    private const DUPLICATE_SEARCH_LIMIT = 50;

    public function checkForNameDuplicatesAction(Request $request): JsonResponse
    {
        $this->checkPermissionsHasOneOf([
            'coreshop_permission_ctc_assign_to_new',
            'coreshop_permission_ctc_assign_to_existing',
        ]);

        $error = false;
        $message = null;
        $objects = [];
        $foundObjects = [];
        $value = trim((string) $request->query->get('value', ''));

        if (mb_strlen($value) >= self::DUPLICATE_SEARCH_MIN_LENGTH) {
            $list = $this->getCompanyRepository()->getList();
            $list->addConditionParam('name LIKE ?', '%' . addcslashes($value, '%_\\') . '%');
            $list->setLimit(self::DUPLICATE_SEARCH_LIMIT);
            $foundObjects = $list->getData();
        }

        /** @var CompanyInterface $maybeDuplicate */
        foreach ($foundObjects as $maybeDuplicate) {
            $objects[] = [
                'id' => $maybeDuplicate->getId(),
                'name' => $maybeDuplicate->getName(),
                'path' => $maybeDuplicate->getFullPath(),
            ];
        }

        return $this->json([
            'success' => !$error,
            'message' => $message,
            'list' => $objects,
        ]);
    }

    public function getEntityDetailsAction(string $type, int $objectId): JsonResponse
    {
        $this->checkPermissionsHasOneOf([
            'coreshop_permission_ctc_assign_to_new',
            'coreshop_permission_ctc_assign_to_existing',
        ]);

        $error = false;
        $message = null;
        $data = null;

        if (!in_array($type, ['customer', 'company'], true)) {
            return $this->json([
                'success' => false,
                'message' => sprintf('Invalid object type "%s".', $type),
                'data' => null,
            ]);
        }

        $object = $type === 'customer' ? $this->getCustomerRepository()->find($objectId) : $this->getCompanyRepository()->find($objectId);

        if ($type === 'customer') {
            if (!$object instanceof CustomerInterface) {
                $error = true;
                $message = 'Invalid Customer Object. Please choose a valid customer.';
            } else {
                $data = [
                    'type' => $type,
                    'name' => sprintf('%s %s', $object->getFirstname(), $object->getLastname()),
                    'id' => $object->getId(),
                ];
            }
        } elseif ($type === 'company') {
            if (!$object instanceof CompanyInterface) {
                $error = true;
                $message = 'Invalid Company Object. Please choose a valid company.';
            } else {
                $data = [
                    'type' => $type,
                    'name' => sprintf('%s', $object->getName()),
                    'id' => $object->getId(),
                ];
            }
        }

        return $this->json([
            'success' => !$error,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public function validateAssignmentAction(int $customerId, ?int $companyId = null): JsonResponse
    {
        $this->checkPermissionsHasOneOf([
            'coreshop_permission_ctc_assign_to_new',
            'coreshop_permission_ctc_assign_to_existing',
        ]);

        $error = false;
        $message = null;
        $data = null;

        $customer = $this->getCustomerRepository()->find($customerId);
        $company = $companyId === null ? null : $this->getCompanyRepository()->find($companyId);

        if (!$customer instanceof CustomerInterface) {
            $error = true;
            $message = 'Invalid Customer Object. Please choose a valid customer.';

            return $this->json([
                'success' => !$error,
                'message' => $message,
                'data' => $data,
            ]);
        }

        if ($companyId !== null && !$company instanceof CompanyInterface) {
            $error = true;
            $message = 'Invalid Company Object. Please choose a valid company.';

            return $this->json([
                'success' => !$error,
                'message' => $message,
                'data' => $data,
            ]);
        }

        if ($customer->getCompany() instanceof CompanyInterface) {
            $error = true;
            $message = sprintf('Customer already assigned to company ("%s"). Cannot proceed.', $customer->getCompany()->getName());
        }

        $availableCustomerAddresses = [];

        if ($error === false) {
            foreach ($customer->getAddresses() as $address) {
                $availableCustomerAddresses[] = [
                    'id' => $address->getId(),
                    'path' => $address->getFullPath(),
                ];
            }

            $data = [
                'addresses' => $availableCustomerAddresses,
            ];
        }

        return $this->json([
            'success' => !$error,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public function dispatchExistingAssignmentAction(Request $request, int $customerId, int $companyId): JsonResponse
    {
        $this->checkPermission('coreshop_permission_ctc_assign_to_existing');

        $error = false;
        $formError = false;
        $message = null;

        $addressAssignmentType = $this->getParameterFromRequest($request, 'addressAssignmentType');
        $addressAccessType = $this->getParameterFromRequest($request, 'addressAccessType');

        $customer = $this->getCustomerRepository()->find($customerId);
        $company = $this->getCompanyRepository()->find($companyId);

        if (!$customer instanceof CustomerInterface) {
            return $this->assignmentError('Invalid Customer Object. Please choose a valid customer.', $customerId, $companyId);
        }

        if (!$company instanceof CompanyInterface) {
            return $this->assignmentError('Invalid Company Object. Please choose a valid company.', $customerId, $companyId);
        }

        $options = [
            'addressAssignmentType' => $addressAssignmentType,
            'addressAccessType' => $addressAccessType,
        ];

        try {
            $this->getCustomerTransformerHelper()->moveCustomerToExistingCompany($customer, $company, $options);
        } catch (ValidationException $e) {
            $error = true;
            $formError = true;
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            // also \Error (e.g. TypeError), so the admin always gets a JSON answer
            $error = true;
            $message = $e->getMessage();
        }

        return $this->json([
            'success' => !$error && !$formError,
            'formError' => $formError,
            'message' => $message,
            'customerId' => $customerId,
            'companyId' => $companyId,
        ]);
    }

    public function dispatchNewAssignmentAction(Request $request, int $customerId): JsonResponse
    {
        $this->checkPermission('coreshop_permission_ctc_assign_to_new');

        $error = false;
        $formError = false;
        $message = null;

        $companyId = null;

        $addressAssignmentType = $this->getParameterFromRequest($request, 'addressAssignmentType');
        $addressAccessType = $this->getParameterFromRequest($request, 'addressAccessType');
        $newCompanyName = $this->getParameterFromRequest($request, 'newCompanyName');

        $customer = $this->getCustomerRepository()->find($customerId);

        if (!$customer instanceof CustomerInterface) {
            return $this->assignmentError('Invalid Customer Object. Please choose a valid customer.', $customerId, null);
        }

        $options = [
            'addressAssignmentType' => $addressAssignmentType,
            'addressAccessType' => $addressAccessType,
            'companyData' => [
                'name' => $newCompanyName,
            ],
        ];

        try {
            $this->getCustomerTransformerHelper()->moveCustomerToNewCompany($customer, $options);

            $customerId = $customer->getId();
            $companyId = $customer->getCompany()?->getId();
        } catch (ValidationException $e) {
            $error = true;
            $formError = true;
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            // also \Error (e.g. TypeError), so the admin always gets a JSON answer
            $error = true;
            $message = $e->getMessage();
        }

        return $this->json([
            'success' => !$error && !$formError,
            'formError' => $formError,
            'message' => $message,
            'customerId' => $customerId,
            'companyId' => $companyId,
        ]);
    }

    private function assignmentError(string $message, int $customerId, ?int $companyId): JsonResponse
    {
        return $this->json([
            'success' => false,
            'formError' => false,
            'message' => $message,
            'customerId' => $customerId,
            'companyId' => $companyId,
        ]);
    }

    protected function getCustomerRepository(): CustomerRepositoryInterface
    {
        return $this->container->get('coreshop.repository.customer');
    }

    protected function getCompanyRepository(): CompanyRepositoryInterface
    {
        return $this->container->get('coreshop.repository.company');
    }

    protected function getCustomerTransformerHelper(): CustomerTransformHelperInterface
    {
        return $this->container->get(CustomerTransformHelperInterface::class);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            new SubscribedService('coreshop.repository.customer', CustomerRepositoryInterface::class),
            new SubscribedService('coreshop.repository.company', CompanyRepositoryInterface::class),
            CustomerTransformHelperInterface::class,
        ]);
    }
}
