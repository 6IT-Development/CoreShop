<?php

declare(strict_types=1);

namespace CoreShop\Component\OrderReturn\Model;

use CoreShop\Component\Resource\Pimcore\Model\PimcoreModelInterface;
use CoreShop\Component\Core\Model\OrderInterface;

interface OrderReturnInterface extends PimcoreModelInterface
{
    public function getFirstName(): ?string;

    public function setFirstName(?string $firstName): void;

    public function getLastName(): ?string;

    public function setLastName(?string $lastName): void;

    public function getOrderNumber(): ?string;

    public function setOrderNumber(?string $orderNumber): void;

    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order): void;

    public function getEmail(): ?string;

    public function setEmail(?string $email): void;

    public function getComment(): ?string;

    public function setComment(?string $comment): void;
}
