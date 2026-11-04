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

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CustomerLoginType extends AbstractType
{
    public function __construct(
        protected string $loginIdentifier,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $loginIdentifierLabel = sprintf('coreshop.form.login.%s', $this->loginIdentifier);

        $builder
            ->add('_username', TextType::class, [
                'label' => $loginIdentifierLabel,
            ])
            ->add('_password', PasswordType::class, [
                'label' => 'coreshop.form.login.password',
            ])
            ->add('_remember_me', CheckboxType::class, [
                'label' => 'coreshop.form.login.remember_me',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        /**
         * The form is posted to the firewall's form_login check path, so the token has to use the field
         * name and token id the login authenticator validates (enable_csrf: true).
         */
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id' => 'authenticate',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_customer_login';
    }
}
