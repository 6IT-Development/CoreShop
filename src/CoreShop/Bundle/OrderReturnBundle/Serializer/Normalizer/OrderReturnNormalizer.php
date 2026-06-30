<?php

namespace CoreShop\Bundle\OrderReturnBundle\Serializer\Normalizer;

use CoreShop\Component\OrderReturn\Model\OrderReturn;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class OrderReturnNormalizer implements NormalizerInterface
{
    /**
     * @param OrderReturn $object
     *
     * @return array
     */
    public function normalize($object, ?string $format = null, array $context = []): array
    {
        $pdfAttachment = $object->getPdfAttachment();
        $pdfPath = ($pdfAttachment && $pdfAttachment->getFullPath()) ? \Pimcore\Tool::getHostUrl() . $pdfAttachment->getFullPath() : '';

        return [
            'id' => $object->getId(),
            'returnedAt' => $object->getReturnedAt(),
            'customer' => [
                'firstname' => $object->getFirstName(),
                'lastname' => $object->getLastName(),
                'fullName' => $object->getFirstName() . ' ' . $object->getLastName(),
                'email' => $object->getEmail(),
            ],
            'pdfPath' => $pdfPath,
            'notification' => [
                'sent' => $object->getNotificationSent(),
                'sentAt' => $object->getNotificationSentAt(),
                'data' => $object->getNotificationData(),
            ],
            'order' => [
                'id' => $object->getOrder()->getId(),
                'number' => $object->getOrder()->getOrderNumber(),
            ],
        ];
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof OrderReturn;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            OrderReturn::class => true,
        ];
    }
}
