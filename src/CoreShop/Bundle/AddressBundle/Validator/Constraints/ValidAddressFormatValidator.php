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

use CoreShop\Component\Address\Model\CountryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Twig\Environment;
use Twig\Error\Error as TwigError;

/**
 * Renders the format the way AddressFormatter does, so syntax errors and tags, filters,
 * functions or methods the sandbox does not allow are reported when the country is saved.
 */
final class ValidAddressFormatValidator extends ConstraintValidator
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidAddressFormat) {
            throw new UnexpectedTypeException($constraint, ValidAddressFormat::class);
        }

        // NotBlank reports an empty format
        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        // the country being saved, so method calls on it are checked against the sandbox as well
        $country = $this->context->getObject();

        try {
            $this->twig->createTemplate($value)->render([
                'country' => $country instanceof CountryInterface ? $country : null,
            ]);
        } catch (TwigError $exception) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ error }}', $exception->getRawMessage())
                ->addViolation();
        }
    }
}
