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

namespace CoreShop\Component\Product\Rule\Condition;

use Carbon\Carbon;
use CoreShop\Component\Resource\Model\ResourceInterface;
use CoreShop\Component\Rule\Condition\ConditionCheckerInterface;
use CoreShop\Component\Rule\Model\RuleInterface;
use Psr\Log\LoggerInterface;

/**
 * Valid between "dateFrom" and "dateTo" (Unix timestamps in milliseconds; a missing, empty or 0 value
 * means "no limit" on that side).
 *
 * A time span without any date, or with a value that is not a number, is an invalid configuration:
 * the condition is not met (instead of making the rule apply forever, or throwing a TypeError on
 * every price calculation), and it is logged.
 */
class TimeSpanConditionChecker implements ConditionCheckerInterface
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function isValid(ResourceInterface $subject, RuleInterface $rule, array $configuration, array $params = []): bool
    {
        try {
            $dateFrom = $this->readTimestamp($configuration, 'dateFrom');
            $dateTo = $this->readTimestamp($configuration, 'dateTo');
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($rule, $exception->getMessage());
        }

        if (null === $dateFrom && null === $dateTo) {
            return $this->invalid($rule, 'The time span has neither a start nor an end date.');
        }

        $now = Carbon::now()->getTimestamp();

        if (null !== $dateFrom && $now < intdiv($dateFrom, 1000)) {
            return false;
        }

        if (null !== $dateTo && $now > intdiv($dateTo, 1000)) {
            return false;
        }

        return true;
    }

    /**
     * @return int|null the timestamp in milliseconds, or null when the date is not set
     */
    private function readTimestamp(array $configuration, string $key): ?int
    {
        $value = $configuration[$key] ?? null;

        if (null === $value || '' === $value) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a timestamp: %s.', $key, get_debug_type($value)));
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function invalid(RuleInterface $rule, string $message): bool
    {
        $this->logger?->error('The time span condition of rule "{rule}" is invalid and never matches: {message}', [
            'rule' => $rule->getId(),
            'message' => $message,
        ]);

        return false;
    }
}
