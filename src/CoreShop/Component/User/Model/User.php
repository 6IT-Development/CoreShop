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

namespace CoreShop\Component\User\Model;

use CoreShop\Component\Resource\Pimcore\Model\AbstractPimcoreModel;

abstract class User extends AbstractPimcoreModel implements UserInterface
{
    protected ?string $salt = null;

    private array $roles = [];

    protected ?string $plainPassword = null;

    public function setPlainPassword(?string $password)
    {
        $this->plainPassword = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setSalt(string $salt)
    {
        $this->salt = $salt;

        return $this;
    }

    public function getSalt(): ?string
    {
        // user has no salt as we use password_hash
        // which handles the salt by itself
        return null;
    }

    /**
     * Intentionally empty: the plain text password is never serialized (see getBlockedVars())
     * and gets cleared after the password has been hashed.
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    protected function getBlockedVars(): array
    {
        return array_merge(parent::getBlockedVars(), ['plainPassword']);
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getUserIdentifier(): string
    {
        return $this->getLoginIdentifier();
    }

    public function getUsername(): ?string
    {
        return $this->getLoginIdentifier();
    }

    /**
     * @inheritdoc
     */
    public function isEqualTo(\Symfony\Component\Security\Core\User\UserInterface $user): bool
    {
        return $user instanceof self && $user->getId() === $this->getId();
    }
}
