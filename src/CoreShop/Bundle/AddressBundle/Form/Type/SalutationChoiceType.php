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

namespace CoreShop\Bundle\AddressBundle\Form\Type;

use CoreShop\Component\Address\Context\CountryContextInterface;
use CoreShop\Component\Address\Formatter\SalutationTranslationKey;
use CoreShop\Component\Address\Model\CountryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SalutationChoiceType extends AbstractType
{
    public function __construct(
        private CountryContextInterface $countryContext,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            // Lazy default: resolved per form build and only when the caller does not pass a
            // country. An eager value would throw without a country context even when "country"
            // is given, and would be cached with the OptionsResolver across requests.
            ->setDefault('country', fn (Options $options): CountryInterface => $this->countryContext->getCountry())
            ->setAllowedTypes('country', CountryInterface::class)
            ->setDefault('extra_choices', [])
            ->setAllowedTypes('extra_choices', 'array')
            ->setDefaults([
                'choices' => function (Options $options) {
                    // simple_array column, null when the country has no salutations
                    $salutations = $options['country']->getSalutations() ?? [];

                    // keep values that are no longer offered (e.g. the salutation of an existing address)
                    foreach ($options['extra_choices'] as $extra) {
                        if (is_string($extra) && '' !== $extra && !in_array($extra, $salutations, true)) {
                            $salutations[] = $extra;
                        }
                    }

                    $choices = [];
                    foreach ($salutations as $salutation) {
                        $choices[SalutationTranslationKey::fromSalutation((string) $salutation)] = $salutation;
                    }

                    return $choices;
                },
            ])
        ;
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_salutation_choice';
    }
}
