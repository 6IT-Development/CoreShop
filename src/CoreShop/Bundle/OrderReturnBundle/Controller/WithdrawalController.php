<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Controller;

use CoreShop\Component\OrderReturn\Model\OrderReturn;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use CoreShop\Component\Resource\Factory\FactoryInterface;
use CoreShop\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;

class WithdrawalController extends AbstractController
{
    public function __construct(
        private RepositoryInterface $orderReturnRepository,
        private FactoryInterface $orderReturnFactory,
        private OrderRepositoryInterface $orderRepository
    ) {
    }

    public function formAction(Request $request): Response
    {
        $orderReturn = $this->orderReturnFactory->createNew();

        $form = $this->createFormBuilder($orderReturn)
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
            /** @var OrderReturn $orderReturn */
            $orderReturn = $form->getData();
            
            // Try to link to an actual order if possible
            $order = $this->orderRepository->findOneBy(['orderNumber' => $orderReturn->getOrderNumber()]);
            if ($order) {
                $orderReturn->setOrder($order);
            }

            $this->orderReturnRepository->add($orderReturn);

            return $this->render('@CoreShopOrderReturn/Withdrawal/success.html.twig', [
                'orderReturn' => $orderReturn,
            ]);
        }

        return $this->render('@CoreShopOrderReturn/Withdrawal/form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
