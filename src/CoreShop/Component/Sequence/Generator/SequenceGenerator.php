<?php

declare(strict_types=1);

/*
 * CoreShop
 *
 * This source file is available under two different licenses:
 *  - GNU General Public License version 3 (GPLv3)
 *  - CoreShop Commercial License (CCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.com)
 * @license    https://www.coreshop.com/license     GPLv3 and CCL
 *
 */

namespace CoreShop\Component\Sequence\Generator;

use CoreShop\Component\Sequence\Factory\SequenceFactoryInterface;
use CoreShop\Component\Sequence\Model\SequenceInterface;
use CoreShop\Component\Sequence\Repository\SequenceRepositoryInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class SequenceGenerator implements SequenceGeneratorInterface
{
    public function __construct(
        private SequenceRepositoryInterface $sequenceRepository,
        private SequenceFactoryInterface $sequenceFactory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getNextSequenceForType(string $type): int
    {
        $this->entityManager->beginTransaction();

        try {
            $sequence = $this->getSequence($type);
            $sequence->incrementIndex();

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            // Without the rollback a failed flush leaves the sequence row locked
            // and the connection inside the transaction.
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $exception;
        }

        return $sequence->getIndex();
    }

    private function getSequence(string $type): SequenceInterface
    {
        $sequence = $this->sequenceRepository->findForType($type);

        if (null === $sequence) {
            $sequence = $this->sequenceFactory->createWithType($type);
            $this->sequenceRepository->add($sequence);

            return $sequence;
        }

        // A sequence that is already managed is returned from the identity map
        // with its old index, so read the locked row again.
        $this->entityManager->refresh($sequence, LockMode::PESSIMISTIC_WRITE);

        return $sequence;
    }
}
