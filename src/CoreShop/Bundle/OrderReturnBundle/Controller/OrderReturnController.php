<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Controller;

use CoreShop\Bundle\FrontendBundle\Controller\FrontendController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;

class OrderReturnController extends FrontendController
{
    public function returnFormAction(Request $request): Response
    {
        $form = $this->createFormBuilder()
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
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $orderReturn = $form->getData();
        }

        $params = [
            'form' => $form->createView(),
        ];

        return $this->render(
            '@CoreShopOrderReturn/OrderReturn/return-form.html.twig', $params
        );
    }

}