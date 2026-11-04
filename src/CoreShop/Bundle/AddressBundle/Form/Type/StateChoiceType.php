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

use CoreShop\Component\Address\Model\CountryInterface;
use CoreShop\Component\Address\Model\StateInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class StateChoiceType extends AbstractType
{
    public function __construct(
        private RepositoryInterface $countryRepository,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'choices' => function (Options $options) {
                    $criteria = [];

                    if (null !== $options['active']) {
                        $criteria['active'] = $options['active'];
                    }

                    if (null !== $options['country']) {
                        $criteria['country'] = $options['country'];
                    }

                    /**
                     * @var StateInterface[] $states
                     */
                    $states = [] === $criteria ? $this->countryRepository->findAll() : $this->countryRepository->findBy($criteria);

                    usort($states, function (StateInterface $a, StateInterface $b): int {
                        return $a->getName() <=> $b->getName();
                    });

                    return $states;
                },
                'choice_value' => 'id',
                'choice_label' => 'name',
                'choice_translation_domain' => false,
                'active' => true,
                // only offer the states of this country; null offers the states of all countries
                'country' => null,
            ])
            ->setAllowedTypes('country', ['null', CountryInterface::class])
        ;
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_state_choice';
    }
}
