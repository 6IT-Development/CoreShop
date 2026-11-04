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

use CoreShop\Bundle\ResourceBundle\Form\DataTransformer\CollectionOrArrayToArrayTransformer;
use CoreShop\Component\Address\Model\CountryInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CountryChoiceType extends AbstractType
{
    public function __construct(
        private RepositoryInterface $countryRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['multiple']) {
            // works for Doctrine Collections and plain arrays (e.g. Pimcore multiselect fields)
            $builder->addModelTransformer(new CollectionOrArrayToArrayTransformer());
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'choices' => function (Options $options) {
                    if (null === $options['active']) {
                        /**
                         * @var CountryInterface[] $countries
                         */
                        $countries = $this->countryRepository->findAll();
                    } else {
                        /**
                         * @var CountryInterface[] $countries
                         */
                        $countries = $this->countryRepository->findBy(['active' => $options['active']]);
                    }

                    // keep values that are no longer offered (e.g. a deactivated country of an existing address)
                    foreach ($options['extra_choices'] as $extra) {
                        if ($extra instanceof CountryInterface && !in_array($extra->getId(), array_map(static fn (CountryInterface $c) => $c->getId(), $countries), true)) {
                            $countries[] = $extra;
                        }
                    }

                    usort($countries, function (CountryInterface $a, CountryInterface $b): int {
                        return $a->getName() <=> $b->getName();
                    });

                    return $countries;
                },
                'choice_value' => 'id',
                'choice_label' => 'name',
                'choice_translation_domain' => false,
                'active' => true,
                'extra_choices' => [],
            ])
            ->setAllowedTypes('extra_choices', 'array')
        ;
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_country_choice';
    }
}
