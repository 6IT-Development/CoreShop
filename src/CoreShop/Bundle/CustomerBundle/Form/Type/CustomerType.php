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

namespace CoreShop\Bundle\CustomerBundle\Form\Type;

use CoreShop\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CustomerType extends AbstractResourceType
{
    /**
     * @param array $guestValidationGroups deprecated and unused: guest checkout validates through
     *                                     CoreBundle's GuestRegistrationType and its own parameter
     */
    public function __construct(
        string $dataClass,
        array $validationGroups = [],
        protected array $guestValidationGroups = [],
    ) {
        if ([] !== $guestValidationGroups) {
            trigger_deprecation(
                'coreshop/customer-bundle',
                '4.1',
                'Passing guest validation groups to "%s" is deprecated and has no effect; they were never applied. Configure "coreshop.form.type.guest_registration_type.validation_groups" for guest checkout instead.',
                self::class,
            );
        }

        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('gender', ChoiceType::class, [
                'label' => 'coreshop.form.customer.gender',
                'choices' => [
                    'coreshop.form.customer.gender.male' => 'male',
                    'coreshop.form.customer.gender.female' => 'female',
                ],
            ])
            ->add('firstname', TextType::class, [
                'label' => 'coreshop.form.customer.firstname',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'coreshop.form.customer.lastname',
            ])
        ;

        if ($options['allow_email']) {
            $builder->add('email', EmailType::class, [
                'label' => 'coreshop.form.customer.email',
            ]);
        }

        $builder
            ->add('newsletterActive', CheckboxType::class, [
                'label' => 'coreshop.form.customer.newsletter.subscribe',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefault('customer', false);
        $resolver->setDefault('allow_email', false);
        $resolver->setDefault('csrf_protection', true);
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_customer';
    }
}
