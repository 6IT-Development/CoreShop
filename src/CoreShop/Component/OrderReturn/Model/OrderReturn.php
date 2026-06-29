<?php

declare(strict_types=1);

namespace CoreShop\Component\OrderReturn\Model;

use Carbon\Carbon;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Resource\Pimcore\Model\AbstractPimcoreModel;
use Pimcore\Model\DataObject\PreGetValueHookInterface;

abstract class OrderReturn extends AbstractPimcoreModel implements OrderReturnInterface, PreGetValueHookInterface
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
    protected $returnedAt = null;

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    protected function calculateReturnedAt(): ?Carbon
    {
        if ($this->returnedAt instanceof \DateTimeInterface) {
            return Carbon::instance($this->returnedAt);
        }

        $creationDate = $this->getCreationDate();

        if ($creationDate instanceof \DateTimeInterface) {
            return Carbon::instance($creationDate);
        }

        if (is_numeric($creationDate) && $creationDate > 0) {
            return Carbon::createFromTimestamp((int)$creationDate);
        }

        return null;
    }

    public function preGetValue(string $name): mixed
    {
        if ($name === 'returnedAt') {
            return $this->calculateReturnedAt();
        }

        return null;
    }

    public function preSave($isUpdate)
    {
        $this->returnedAt = $this->calculateReturnedAt() ?? Carbon::now();

        parent::preSave($isUpdate);
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