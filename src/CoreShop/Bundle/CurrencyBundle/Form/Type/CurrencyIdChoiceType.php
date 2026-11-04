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

namespace CoreShop\Bundle\CurrencyBundle\Form\Type;

use CoreShop\Component\Currency\Model\CurrencyInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A currency choice whose model value is the currency id, as rule configurations store it.
 */
final class CurrencyIdChoiceType extends AbstractType
{
    public function __construct(
        private RepositoryInterface $currencyRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            function (mixed $currencyId): ?CurrencyInterface {
                $currency = null === $currencyId ? null : $this->currencyRepository->find($currencyId);

                return $currency instanceof CurrencyInterface ? $currency : null;
            },
            function (mixed $currency): mixed {
                if ($currency instanceof CurrencyInterface) {
                    return $currency->getId();
                }

                return null;
            },
        ));
    }

    public function getParent(): string
    {
        return CurrencyChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_currency_id_choice';
    }
}
