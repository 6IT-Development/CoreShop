<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Twig;

use CoreShop\Component\Locale\Context\LocaleContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class OrderReturnDateExtension extends AbstractExtension
{
    public function __construct(
        private LocaleContextInterface $localeContext
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('coreshop_order_return_date', [$this, 'formatDate']),
        ];
    }

    public function formatDate($date, string $dateType = 'medium', string $timeType = 'none'): string
    {
        if (!$date) {
            return '';
        }

        if (is_numeric($date)) {
            $date = (new \DateTime())->setTimestamp((int)$date);
        }

        if (!$date instanceof \DateTimeInterface) {
            return (string)$date;
        }

        $intlDateType = $this->getIntlType($dateType);
        $intlTimeType = $this->getIntlType($timeType);

        $formatter = new \IntlDateFormatter(
            $this->localeContext->getLocaleCode(),
            $intlDateType,
            $intlTimeType,
            date_default_timezone_get()
        );

        return $formatter->format($date);
    }

    private function getIntlType(string $type): int
    {
        return match (strtolower($type)) {
            'none' => \IntlDateFormatter::NONE,
            'short' => \IntlDateFormatter::SHORT,
            'medium' => \IntlDateFormatter::MEDIUM,
            'long' => \IntlDateFormatter::LONG,
            'full' => \IntlDateFormatter::FULL,
            default => \IntlDateFormatter::MEDIUM,
        };
    }
}
