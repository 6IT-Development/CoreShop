<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Controller;

use CoreShop\Bundle\FrontendBundle\Controller\FrontendController;
use CoreShop\Bundle\OrderReturnBundle\Form\Type\OrderReturnType;
use CoreShop\Bundle\OrderBundle\Renderer\Pdf\PdfRendererInterface;
use CoreShop\Component\OrderReturn\Model\OrderReturnInterface;
use CoreShop\Component\Pimcore\DataObject\ObjectServiceInterface;
use CoreShop\Component\Resource\Factory\FactoryInterface;
use Pimcore\Model\Element\DuplicateFullPathException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderReturnController extends FrontendController
{
    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws DuplicateFullPathException
     */
    public function returnFormAction(Request $request): Response
    {
        $locale = \Pimcore\Tool::getDefaultLanguage();
        if($locale){
            $request->setLocale($locale);
            $request->attributes->set('_locale', $locale);
            $this->container->get('translator')->setLocale($locale);
        }

        /** @var FactoryInterface $factory */
        $factory = $this->container->get('coreshop.factory.order_return');
        /** @var OrderReturnInterface $orderReturn */
        $orderReturn = $factory->createNew();

        $form = $this->container->get('form.factory')->createNamed(
            'coreshop', OrderReturnType::class, $orderReturn
        );

        if (in_array($request->getMethod(), ['POST'], true)) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                /** @var ObjectServiceInterface $objectService */
                $objectService = $this->container->get(ObjectServiceInterface::class);

                $orderReturn->setPublished(true);
                $orderReturn->setKey(uniqid('order-return-'));
                $orderReturn->setParent($objectService->createFolderByPath(
                    (string) $this->getParameter('coreshop.folder.order_return')
                ));

                $orderReturn->save();

                // PDF Generation
                $html = $this->renderView('@CoreShopOrderReturn/OrderReturn/pdf.html.twig', [
                    'orderReturn' => $orderReturn,
                ]);

                /** @var PdfRendererInterface $pdfRenderer */
                $pdfRenderer = $this->container->get(PdfRendererInterface::class);
                $pdfContent = $pdfRenderer->fromString($html);

                $folderPath = '/coreshop_order_return/' . uniqid('pdf-');
                $folder = \Pimcore\Model\Asset\Service::createFolderByPath($folderPath);

                $filename = sprintf('%s-%s-order-return-%s.pdf',
                    $orderReturn->getFirstName(),
                    $orderReturn->getLastName(),
                    $orderReturn->getOrderNumber()
                );
                $filename = \Pimcore\File::getValidFilename($filename);

                $asset = new \Pimcore\Model\Asset();
                $asset->setFilename($filename);
                $asset->setParent($folder);
                $asset->setData($pdfContent);
                $asset->save();

                $orderReturn->setPdfAttachment($asset);
                
                $orderReturn->save();

                return $this->render(
                    '@CoreShopOrderReturn/OrderReturn/return-form.html.twig',
                    [
                        'success' => true,
                        'pdfUrl' => $asset->getFullPath(),
                        'form' => $form->createView(),
                    ]
                );
            }
        }

        $params = [
            'form' => $form->createView(),
        ];

        return $this->render(
            '@CoreShopOrderReturn/OrderReturn/return-form.html.twig', $params
        );
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'coreshop.factory.order_return' => FactoryInterface::class,
            ObjectServiceInterface::class => ObjectServiceInterface::class,
            PdfRendererInterface::class => PdfRendererInterface::class,
        ]);
    }
}