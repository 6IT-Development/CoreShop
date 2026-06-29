<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Form\Type;

use CoreShop\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;

final class OrderReturnType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
        ->add('lastName', TextType::class, [
        'label' => 'coreshop.ui.order_return.form.lname',
        'constraints' => [new NotBlank(['groups' => ['coreshop']])],
    ])
        ->add('firstName', TextType::class, [
            'label' => 'coreshop.ui.order_return.form.fname',
            'constraints' => [new NotBlank(['groups' => ['coreshop']])],
        ])
        ->add('orderNumber', TextType::class, [
            'label' => 'coreshop.ui.order_return.form.ordnum',
            'constraints' => [new NotBlank(['groups' => ['coreshop']])],
        ])
        ->add('email', EmailType::class, [
            'label' => 'coreshop.ui.order_return.form.email',
            'constraints' => [
                new NotBlank(['groups' => ['coreshop']]),
                new Email(['groups' => ['coreshop']])
            ],
        ])
        ->add('comment', TextareaType::class, [
            'label' => 'coreshop.ui.order_return.form.comment',
            'required' => false,
        ])
        ->add('submit', SubmitType::class, [
            'label' => 'coreshop.ui.order_return.form.confirm.button',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_order_return';
    }
}