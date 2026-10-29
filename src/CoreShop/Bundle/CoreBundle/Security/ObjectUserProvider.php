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

namespace CoreShop\Bundle\CoreBundle\Security;

use CoreShop\Component\User\Repository\UserRepositoryInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class ObjectUserProvider implements UserProviderInterface
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected string $className,
    ) {
    }

    public function loadUserByUsername(string $username): ?UserInterface
    {
        return $this->loadUserByIdentifier($username);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findByLoginIdentifier($identifier);

        if ($user instanceof UserInterface) {
            return $user;
        }

        throw new UserNotFoundException(sprintf('User with email address or username "%s" was not found', $identifier));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof \CoreShop\Component\Core\Model\UserInterface) {
            throw new UnsupportedUserException();
        }

        $refreshedUser = $this->userRepository->find($user->getId());

        /**
         * find() also returns unpublished objects, while the login lookup does not:
         * end the session of a user that was unpublished in the meantime.
         */
        if (!$refreshedUser instanceof UserInterface ||
            ($refreshedUser instanceof \CoreShop\Component\User\Model\UserInterface && !$refreshedUser->isPublished())
        ) {
            throw new UserNotFoundException(sprintf('User with id "%s" was not found', $user->getId()));
        }

        return $refreshedUser;
    }

    public function supportsClass(string $class): bool
    {
        // also support subclasses, e.g. Pimcore class overrides
        return is_a($class, $this->className, true);
    }
}
