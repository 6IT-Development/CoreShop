<?php

declare(strict_types=1);

namespace CoreShop\Component\OrderReturn\Model;

use CoreShop\Component\Core\Model\OrderInterface;
use CoreShop\Component\Resource\Pimcore\Model\AbstractPimcoreModel;
use CoreShop\Component\Resource\Model\SetValuesTrait;

class OrderReturn extends AbstractPimcoreModel implements OrderReturnInterface
{
    use SetValuesTrait;

    protected ?int $id = null;
    protected ?string $firstName = null;
    protected ?string $lastName = null;
    protected ?string $orderNumber = null;
    protected ?OrderInterface $order = null;
    protected ?string $email = null;
    protected ?string $comment = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(?string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }
}
