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

namespace CoreShop\Bundle\ProductBundle\Form\Type\Unit;

use CoreShop\Component\Product\Model\ProductUnitDefinitionInterface;
use CoreShop\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductUnitDefinitionSelectionType extends AbstractType
{
    public function __construct(
        protected RepositoryInterface $productUnitDefinitionRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            function (mixed $value) {
                if ($value instanceof ProductUnitDefinitionInterface) {
                    return $value->getId();
                }

                return null;
            },
            function (mixed $value) {
                if ($value === null || $value === '') {
                    return null;
                }

                // Only a positive integer ID is accepted; the parent is a text type, so the value is
                // not parsed with the request locale (where "12.7" could become 127).
                $id = is_int($value) ? $value : filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                if (!is_int($id) || $id < 1) {
                    throw new TransformationFailedException(sprintf('"%s" is not a valid unit definition ID.', get_debug_type($value) === 'string' ? $value : get_debug_type($value)));
                }

                $definition = $this->productUnitDefinitionRepository->find($id);

                if (!$definition instanceof ProductUnitDefinitionInterface) {
                    // A deleted definition or a stale editor: do not save the row without its unit.
                    throw new TransformationFailedException(sprintf('Unit definition %d does not exist.', $id));
                }

                return $definition;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'invalid_message' => 'This value is not a valid unit definition.',
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'coreshop_product_unit_definition_selection';
    }
}
