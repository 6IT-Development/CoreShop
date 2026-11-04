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

namespace CoreShop\Component\Registry;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class Autoconfiguration
{
    public static function registerForAutoConfiguration(
        ContainerBuilder $container,
        string $interface,
        string $tag,
        ?string $attribute = null,
        bool $autoconfigureWithAttributes = false,
    ): void {
        if (!$autoconfigureWithAttributes) {
            $container
                ->registerForAutoconfiguration($interface)
                ->addTag($tag)
            ;

            return;
        }

        if (null === $attribute) {
            throw new \Exception('You need to provide an attribute when using autoconfigure_with_attributes');
        }

        /**
         * @var class-string $attribute
         */
        $container->registerAttributeForAutoconfiguration(
            $attribute,
            static function (ChildDefinition $definition, $attribute) use ($tag): void {
                $definition->addTag($tag, self::getProperties($attribute));
            },
        );
    }

    private static function getProperties(mixed $object): array
    {
        $reflection = new \ReflectionClass($object);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $getterMethods = [];

        foreach ($methods as $method) {
            $name = $method->getName();
            if (str_starts_with($name, 'get')) {
                $getterMethods[self::toTagKey(substr($name, 3))] = $method->invoke($object);
            }
        }

        return $getterMethods;
    }

    /**
     * Compiler passes and YAML tags use dashed tag keys (`form-type`, `notification-type`),
     * so the getter `getFormType()` must become `form-type`, not `formType`.
     */
    private static function toTagKey(string $getterSuffix): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $getterSuffix));
    }
}
