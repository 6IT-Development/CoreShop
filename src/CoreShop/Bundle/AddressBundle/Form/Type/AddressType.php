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

use CoreShop\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use CoreShop\Component\Address\Model\AddressInterface;
use CoreShop\Component\Address\Model\CountryInterface;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AddressType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('company', TextType::class, [
                'label' => 'coreshop.form.address.company',
                'required' => false,
            ])
        ;

        $this->addSalutationField($builder, null);

        $builder
            ->add('firstname', TextType::class, [
                'label' => 'coreshop.form.address.firstname',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'coreshop.form.address.lastname',
            ])
            ->add('street', TextType::class, [
                'label' => 'coreshop.form.address.street',
            ])
            ->add('number', TextType::class, [
                'label' => 'coreshop.form.address.number',
            ])
            ->add('postcode', TextType::class, [
                'label' => 'coreshop.form.address.postcode',
            ])
            ->add('city', TextType::class, [
                'label' => 'coreshop.form.address.city',
            ])
        ;

        $this->addCountryField($builder, null);
        $this->addStateField($builder, null, null);

        $builder
            ->add('phoneNumber', TextType::class, [
                'label' => 'coreshop.form.address.phone_number',
                'required' => false,
            ])
        ;

        // Offer only the states of the address's country (of the submitted country on submit), and
        // keep the stored salutation, country and state selectable even when they are no longer
        // offered, so editing an address does not silently replace them.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $address = $event->getData();
            $address = $address instanceof AddressInterface ? $address : null;
            $form = $event->getForm();

            $this->addSalutationField($form, $address);
            $this->addCountryField($form, $address);
            $this->addStateField($form, $address, $address?->getCountry());
        });
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            $form = $event->getForm();
            $address = $form->getData();

            $this->addStateField(
                $form,
                $address instanceof AddressInterface ? $address : null,
                $this->getSubmittedCountry($form, is_array($data) ? ($data['country'] ?? null) : null),
            );
        });

        if ($options['show_address_identifier_choice'] === true) {
            $builder->add('addressIdentifier', AddressIdentifierChoiceType::class, [
                'label' => false,
                'required' => false,
            ]);
        }
    }

    private function addSalutationField(FormBuilderInterface|FormInterface $form, ?AddressInterface $address): void
    {
        $form->add('salutation', SalutationChoiceType::class, [
            'label' => 'coreshop.form.address.salutation',
            'extra_choices' => array_filter([$address?->getSalutation()]),
        ]);
    }

    private function addCountryField(FormBuilderInterface|FormInterface $form, ?AddressInterface $address): void
    {
        $form->add('country', CountryChoiceType::class, [
            'active' => true,
            'extra_choices' => array_filter([$address?->getCountry()]),
            'label' => 'coreshop.form.address.country',
        ]);
    }

    private function addStateField(FormBuilderInterface|FormInterface $form, ?AddressInterface $address, ?CountryInterface $country): void
    {
        $form->add('state', StateChoiceType::class, [
            'active' => true,
            'country' => $country,
            'extra_choices' => array_filter([$address?->getState()]),
            'label' => 'coreshop.form.address.state',
            // The state is optional (Pimcore field not mandatory, no constraint). A required
            // single choice gets no empty option, so the browser would pre-select the first
            // state and save it on every address, including countries without states.
            'required' => false,
            'placeholder' => '',
        ]);
    }

    private function getSubmittedCountry(FormInterface $form, mixed $countryValue): ?CountryInterface
    {
        if (!is_scalar($countryValue) || '' === (string) $countryValue || !$form->has('country')) {
            return null;
        }

        $choiceList = $form->get('country')->getConfig()->getAttribute('choice_list');

        if (!$choiceList instanceof ChoiceListInterface) {
            return null;
        }

        $countries = $choiceList->getChoicesForValues([(string) $countryValue]);
        $country = reset($countries);

        return $country instanceof CountryInterface ? $country : null;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefault('show_address_identifier_choice', false);
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_address';
    }
}
