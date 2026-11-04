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

use CoreShop\Bundle\ProductBundle\Form\Type\ProductSpecificPriceRuleConditionCollectionType;
use CoreShop\Bundle\RuleBundle\Form\Type\Rule\Condition\AbstractNestedConfigurationType;
use CoreShop\Component\Rule\Model\ConditionInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Valid;

final class ProductSpecificPriceNestedConfigurationType extends AbstractNestedConfigurationType
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
        parent::buildForm($builder, $options);

        $builder
            ->add('conditions', ProductSpecificPriceRuleConditionCollectionType::class, [
                'constraints' => [new Valid(groups: $this->validationGroups)],
                'nested' => true,
            ])
        ;

        /*
         * The nested conditions are stored serialized inside this condition's configuration array.
         * Hand clones to the collection: on submit it writes onto its entries, and the stored instances
         * must stay untouched (they are also the original data Doctrine compares the configuration with).
         * Entries that are not conditions cannot be mapped by the entry type and are dropped.
         */
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $data = $event->getData();

            if (!is_array($data)) {
                return;
            }

            $conditions = [];
            foreach (is_iterable($data['conditions'] ?? null) ? $data['conditions'] : [] as $condition) {
                if ($condition instanceof ConditionInterface) {
                    $conditions[] = clone $condition;
                }
            }

            $data['conditions'] = $conditions;
            $event->setData($data);
        });
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_rule_condition_nested';
    }
}
