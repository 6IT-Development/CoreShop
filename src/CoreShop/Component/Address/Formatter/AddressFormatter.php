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

namespace CoreShop\Component\Address\Formatter;

use CoreShop\Component\Address\Model\AddressInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Error\Error as TwigError;

class AddressFormatter implements AddressFormatterInterface
{
    /**
     * Used for an address without a country, or a country without an address format.
     */
    public const DEFAULT_FORMAT = "{{ company }}\n{{ salutation }} {{ firstname }} {{ lastname }}\n{{ street }} {{ number }}\n{{ postcode }} {{ city }}\n{{ country.name|default }}";

    /**
     * @param Environment $twig a sandboxed environment, see AddressFormatEnvironment::create();
     *                          any other environment is replaced by one
     */
    public function __construct(
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
        // address formats are admin input, never render them with the application's environment
        if (!AddressFormatEnvironment::isSandboxed($this->twig)) {
            @trigger_error(
                sprintf('Passing a Twig environment without an enabled sandbox to %s is deprecated, address formats are rendered in %s::create() instead.', self::class, AddressFormatEnvironment::class),
                \E_USER_DEPRECATED,
            );

            $this->twig = AddressFormatEnvironment::create();
        }
    }

    public function formatAddress(AddressInterface $address, bool $asHtml = true): string
    {
        $variables = $this->getVariables($address);

        $format = $address->getCountry()?->getAddressFormat();

        if (!is_string($format) || '' === trim($format)) {
            $format = self::DEFAULT_FORMAT;
        }

        // the name selects the escaping strategy: html, or none for plain text (see AddressFormatEnvironment)
        $templateName = $asHtml ? AddressFormatEnvironment::HTML_TEMPLATE_NAME : AddressFormatEnvironment::PLAIN_TEXT_TEMPLATE_NAME;

        try {
            $convertedAddress = $this->twig->createTemplate($format, $templateName)->render($variables);
        } catch (TwigError) {
            // a format with a syntax error or one the sandbox does not allow must not break every page with an address
            $convertedAddress = $this->twig->createTemplate(self::DEFAULT_FORMAT, $templateName)->render($variables);
        }

        if ($asHtml) {
            $convertedAddress = \nl2br($convertedAddress);
        }

        return $convertedAddress;
    }

    /**
     * The address data from the interface getters, on top of all stored fields of a
     * Pimcore address (getObjectVars()), so custom class fields stay available.
     */
    private function getVariables(AddressInterface $address): array
    {
        $variables = method_exists($address, 'getObjectVars') ? $address->getObjectVars() : [];

        $variables = array_merge($variables, [
            'id' => $address->getId(),
            'salutation' => $address->getSalutation(),
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'number' => $address->getNumber(),
            'postcode' => $address->getPostcode(),
            'city' => $address->getCity(),
            'country' => $address->getCountry(),
            'state' => $address->getState(),
            'phoneNumber' => $address->getPhoneNumber(),
            'addressIdentifier' => $address->getAddressIdentifier(),
        ]);

        if (!empty($variables['salutation'])) {
            // same key as the salutation form choices
            $variables['salutation'] = $this->translator->trans(SalutationTranslationKey::fromSalutation($variables['salutation']));
        }

        return $variables;
    }
}
