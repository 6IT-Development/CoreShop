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

namespace CoreShop\Bundle\AddressBundle\Validator\Constraints;

use CoreShop\Component\Address\Model\AddressIdentifierInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * An address identifier is valid when it is a persisted, still existing and active
 * AddressIdentifier entity.
 */
final class ValidAddressIdentifierValidator extends ConstraintValidator
{
    public function __construct(
        private RepositoryInterface $addressIdentifierRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidAddressIdentifier) {
            throw new UnexpectedTypeException($constraint, ValidAddressIdentifier::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (!$value instanceof AddressIdentifierInterface) {
            $this->addViolation($constraint, get_debug_type($value));

            return;
        }

        // a new (unpersisted) identifier has no id, and find() on it would throw
        $id = $value->getId();
        $identifier = null !== $id ? $this->addressIdentifierRepository->find($id) : null;

        if (!$identifier instanceof AddressIdentifierInterface || true !== $identifier->getActive()) {
            $this->addViolation($constraint, (string) ($value->getName() ?? $id ?? ''));
        }
    }

    private function addViolation(ValidAddressIdentifier $constraint, string $name): void
    {
        $this->context->buildViolation($constraint->message)
            ->setParameter('%address_identifier%', $name)
            ->addViolation()
        ;
    }
}
