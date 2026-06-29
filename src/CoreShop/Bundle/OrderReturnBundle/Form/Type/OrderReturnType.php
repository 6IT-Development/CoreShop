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
        'label' => 'Fogyasztó vezetékneve',
        'constraints' => [new NotBlank()],
    ])
        ->add('firstName', TextType::class, [
            'label' => 'Fogyasztó keresztneve',
            'constraints' => [new NotBlank()],
        ])
        ->add('orderNumber', TextType::class, [
            'label' => 'Megrendelés azonosító',
            'constraints' => [new NotBlank()],
        ])
        ->add('email', EmailType::class, [
            'label' => 'Email cím',
            'constraints' => [new NotBlank(), new Email()],
        ])
        ->add('comment', TextareaType::class, [
            'label' => 'Megjegyzés',
            'required' => false,
        ])
        ->add('submit', SubmitType::class, [
            'label' => 'Elállás megerősítése',
        ]);
    }
}