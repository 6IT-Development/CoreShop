<?php

declare(strict_types=1);

namespace CoreShop\Component\OrderReturn\Model;

use CoreShop\Component\Resource\Model\ResourceInterface;
use CoreShop\Component\Order\Model\OrderInterface;

interface OrderReturnInterface extends ResourceInterface
{
    public function getFirstName(): ?string;

    public function getCreatedAt(): ?string;

    public function setFirstName(?string $firstName);

    public function getLastName(): ?string;

    public function setLastName(?string $lastName);

    public function getOrderNumber(): ?string;

    public function setOrderNumber(?string $orderNumber);

    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order);

    public function getEmail(): ?string;

    public function setEmail(?string $email);

    public function getComment(): ?string;

    public function setComment(?string $comment);
}