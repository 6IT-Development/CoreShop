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

use CoreShop\Component\Address\Model\AddressInterface;
use CoreShop\Component\Address\Model\CountryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class StateBelongsToCountryValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof StateBelongsToCountry) {
            throw new UnexpectedTypeException($constraint, StateBelongsToCountry::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof AddressInterface) {
            throw new UnexpectedValueException($value, AddressInterface::class);
        }

        $state = $value->getState();
        $country = $value->getCountry();

        // a missing state is allowed, a missing country is reported by its own NotBlank
        if (null === $state || null === $country) {
            return;
        }

        $stateCountry = $state->getCountry();

        if ($stateCountry instanceof CountryInterface && $this->isSameCountry($stateCountry, $country)) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ state }}', (string) $state->getName())
            ->setParameter('{{ country }}', (string) $country->getName())
            ->atPath('state')
            ->addViolation()
        ;
    }

    private function isSameCountry(CountryInterface $a, CountryInterface $b): bool
    {
        if (null !== $a->getId() && null !== $b->getId()) {
            return $a->getId() === $b->getId();
        }

        return $a === $b;
    }
}
