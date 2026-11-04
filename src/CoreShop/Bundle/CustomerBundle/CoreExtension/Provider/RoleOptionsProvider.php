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

namespace CoreShop\Bundle\CustomerBundle\CoreExtension\Provider;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\DynamicOptionsProvider\MultiSelectOptionsProviderInterface;

class RoleOptionsProvider implements MultiSelectOptionsProviderInterface
{
    protected array $originalRoles;

    /**
     * Roles that must never be assigned to a storefront customer group.
     */
    protected array $invalidRoles = [
        'ROLE_PIMCORE_ADMIN',
        'ROLE_SUPER_ADMIN',
        'ROLE_ALLOWED_TO_SWITCH',
        'ROLE_PREVIOUS_ADMIN',
    ];

    /**
     * Role prefixes that must never be assigned to a storefront customer group.
     */
    protected array $invalidRolePrefixes = [
        'ROLE_PIMCORE_',
    ];

    public function __construct(
        ) {
        $this->originalRoles = \Pimcore::getKernel()->getContainer()->getParameter('security.role_hierarchy.roles');
    }

    /**
     * @param array $context
     * @param Data  $fieldDefinition
     */
    public function getOptions($context, $fieldDefinition): array
    {
        /**
         * Get all unique roles.
         */
        $roles = [];

        foreach ($this->originalRoles as $originalRole => $inheritedRoles) {
            $roles[] = $originalRole;

            foreach ((array) $inheritedRoles as $inheritedRole) {
                $roles[] = $inheritedRole;
            }
        }

        $result = [];

        foreach (array_unique($roles) as $role) {
            if (!$this->isAssignable($role)) {
                continue;
            }

            $result[] = ['key' => $role, 'value' => $role];
        }

        return $result;
    }

    /**
     * @param array $context
     * @param Data  $fieldDefinition
     */
    public function hasStaticOptions($context, $fieldDefinition): bool
    {
        return false;
    }

    /**
     * @param array $context
     * @param Data  $fieldDefinition
     */
    public function getDefaultValue($context, $fieldDefinition): ?string
    {
        return null;
    }

    /**
     * A role is assignable only if neither the role itself nor any role it grants through the
     * hierarchy (transitively) is a privileged one.
     */
    protected function isAssignable(string $role): bool
    {
        $pending = [$role];
        $seen = [];

        while (null !== ($current = array_pop($pending))) {
            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            if ($this->isInvalidRole($current)) {
                return false;
            }

            foreach ((array) ($this->originalRoles[$current] ?? []) as $inheritedRole) {
                $pending[] = $inheritedRole;
            }
        }

        return true;
    }

    protected function isInvalidRole(string $role): bool
    {
        if (\in_array($role, $this->invalidRoles, true)) {
            return true;
        }

        foreach ($this->invalidRolePrefixes as $prefix) {
            if (str_starts_with($role, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
