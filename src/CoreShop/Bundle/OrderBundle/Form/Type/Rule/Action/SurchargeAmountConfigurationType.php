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

namespace CoreShop\Bundle\OrderBundle\Form\Type\Rule\Action;

use CoreShop\Bundle\CurrencyBundle\Form\Type\CurrencyIdChoiceType;
use CoreShop\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

final class SurchargeAmountConfigurationType extends AbstractType
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
            ->add('amount', MoneyType::class, [
                'constraints' => [
                    new NotBlank(groups: $this->validationGroups),
                    new Type(type: 'numeric', groups: $this->validationGroups),
                    new GreaterThan(value: 0, groups: $this->validationGroups),
                ],
            ])
            ->add('gross', CheckboxType::class, [
            ])
            ->add('currency', CurrencyIdChoiceType::class, [
                'constraints' => [
                    new NotBlank(groups: $this->validationGroups),
                ],
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_cart_price_rule_action_surcharge_amount';
    }
}
