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

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants every attribute with the given prefix (CORESHOP_CHECKOUT, CORESHOP_ORDER_CREATE, ...).
 *
 * With Symfony's default "affirmative" decision strategy a single grant wins, so a project voter
 * that denies one of these attributes has no effect while this voter is active. To restrict access, either
 * set "core_shop_core.security.access_voter: false" and register a voter that grants the attributes
 * the project allows, or replace this service with a subclass that overrides voteOnAttribute().
 */
class AccessVoter extends Voter
{
    public function __construct(
        protected string $prefix = 'CORESHOP_',
    ) {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return str_starts_with($attribute, $this->prefix);
    }

    public function supportsType(string $subjectType): bool
    {
        return true;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute);
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        return true;
    }
}
