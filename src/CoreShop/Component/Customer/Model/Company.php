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

namespace CoreShop\Component\Customer\Model;

use CoreShop\Component\Resource\Pimcore\Model\AbstractPimcoreModel;
use Symfony\Component\Security\Core\User\UserInterface;

abstract class Company extends AbstractPimcoreModel implements CompanyInterface
{
    /**
     * A company is not a security user, so CompanyInterface no longer extends EquatableInterface.
     * Kept for BC only: two unsaved companies (no id) are never equal.
     */
    #[\Deprecated(message: 'compare the ids of saved companies instead', since: 'coreshop/customer 4.1')]
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && null !== $this->getId() && $user->getId() === $this->getId();
    }
}
