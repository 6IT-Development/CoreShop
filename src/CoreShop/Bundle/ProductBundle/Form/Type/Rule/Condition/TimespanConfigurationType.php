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

namespace CoreShop\Bundle\ProductBundle\Form\Type\Rule\Condition;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class TimespanConfigurationType extends AbstractType
{
    /**
     * @param string[] $validationGroups
     */
    public function __construct(
        protected array $validationGroups,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dateFrom', NumberType::class, [
                'constraints' => [
                    new PositiveOrZero(groups: $this->validationGroups),
                ],
            ])
            ->add('dateTo', NumberType::class, [
                'constraints' => [
                    new PositiveOrZero(groups: $this->validationGroups),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('constraints', [
            new Callback(callback: self::validateTimespan(...), groups: $this->validationGroups),
        ]);
    }

    /**
     * The condition checker treats a date of 0 or null as "no limit" (timestamps in milliseconds),
     * so a time span needs at least one positive date, and a start that is not after the end.
     */
    public static function validateTimespan(mixed $data, ExecutionContextInterface $context): void
    {
        if (!is_array($data)) {
            return;
        }

        $dateFrom = is_numeric($data['dateFrom'] ?? null) ? (float) $data['dateFrom'] : null;
        $dateTo = is_numeric($data['dateTo'] ?? null) ? (float) $data['dateTo'] : null;

        if (($dateFrom ?? 0.0) <= 0 && ($dateTo ?? 0.0) <= 0) {
            $context
                ->buildViolation('Set a start date, an end date or both.')
                ->atPath('[dateFrom]')
                ->addViolation()
            ;

            return;
        }

        if ($dateFrom > 0 && $dateTo > 0 && $dateFrom > $dateTo) {
            $context
                ->buildViolation('The end date must not be before the start date.')
                ->atPath('[dateTo]')
                ->addViolation()
            ;
        }
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_product_price_rule_condition_timespan';
    }
}
