<?php

declare(strict_types=1);

namespace CoreShop\Component\OrderReturn\Model;

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

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getCreatedAt(): mixed
    {
        return $this->getCreationDate() ? date('Y-m-d H:i:s', (int)$this->getCreationDate()) : null;
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
}