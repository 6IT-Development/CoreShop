<?php

declare(strict_types=1);

namespace CoreShop\Component\OrderReturn\Model;

use Carbon\Carbon;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Resource\Pimcore\Model\AbstractPimcoreModel;

abstract class OrderReturn extends AbstractPimcoreModel implements OrderReturnInterface
{
    protected $firstName = null;
    protected $lastName = null;
    protected $orderNumber = null;
    protected $order = null;
    protected $email = null;
    protected $comment = null;
    protected $notificationSent = false;
    protected $notificationSentAt = null;
    protected $notificationData = null;
    protected $pdfAttachment = null;

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getReturnedAt(): mixed
    {
        return $this->formatDate($this->getCreationDate());
    }

    public function formatDate($date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d H:i:s');
        }

        if (is_numeric($date) && $date > 0) {
            return date('Y-m-d H:i:s', (int)$date);
        }

        return '';
    }

    public function setFirstName(?string $firstName)
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName)
    {
        $this->lastName = $lastName;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(?string $orderNumber)
    {
        $this->orderNumber = $orderNumber;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order)
    {
        $this->order = $order;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email)
    {
        $this->email = $email;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment)
    {
        $this->comment = $comment;
    }

    public function isNotificationSent(): bool
    {
        return (bool)$this->notificationSent;
    }

    public function setNotificationSent(bool $notificationSent): static
    {
        $this->notificationSent = $notificationSent;

        return $this;
    }

    public function getNotificationSentAt(): ?Carbon
    {
        return $this->notificationSentAt;
    }

    public function setNotificationSentAt(?Carbon $notificationSentAt): static
    {
        $this->notificationSentAt = $notificationSentAt;

        return $this;
    }

    public function getNotificationData(): ?string
    {
        return $this->notificationData;
    }

    public function setNotificationData(?string $notificationData): static
    {
        $this->notificationData = $notificationData;

        return $this;
    }

    public function getPdfAttachment(): ?\Pimcore\Model\Element\AbstractElement
    {
        return $this->pdfAttachment;
    }

    public function setPdfAttachment(?\Pimcore\Model\Element\AbstractElement $pdfAttachment): static
    {
        $this->pdfAttachment = $pdfAttachment;

        return $this;
    }
}