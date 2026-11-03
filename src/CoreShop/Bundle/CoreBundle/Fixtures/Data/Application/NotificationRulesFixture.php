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

namespace CoreShop\Bundle\CoreBundle\Fixtures\Data\Application;

use CoreShop\Bundle\NotificationBundle\Form\Type\NotificationRuleType;
use CoreShop\Component\Notification\Repository\NotificationRuleRepositoryInterface;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Pimcore\Model\Document;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class NotificationRulesFixture extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private string $installerResources,
        private KernelInterface $kernel,
        private NotificationRuleRepositoryInterface $notificationRuleRepository,
        private FormFactoryInterface $formFactory,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getGroups(): array
    {
        return ['application'];
    }

    public function load(ObjectManager $manager): void
    {
        $installResourcesDirectory = $this->installerResources;
        $jsonFile = $this->kernel->locateResource(
            sprintf('%s/data/%s.json', $installResourcesDirectory, 'notification-rules'),
        );

        $totalExistingRules = count($this->notificationRuleRepository->findAll());

        if (!file_exists($jsonFile)) {
            return;
        }

        $rules = json_decode(file_get_contents($jsonFile), true, 512, \JSON_THROW_ON_ERROR);
        $totalImported = 0;

        foreach ($rules as $rule) {
            $existingRules = $this->notificationRuleRepository->findBy(['name' => $rule['name']]);

            if (count($existingRules) > 0) {
                continue;
            }

            foreach ($rule['actions'] as &$action) {
                $mails = [];

                foreach ($action['configuration']['mails'] ?? [] as $language => $mail) {
                    $path = '/' . ltrim($mail, '/');
                    $document = Document::getByPath($path);

                    if (!$document instanceof Document\Email) {
                        $this->logger->warning(sprintf(
                            'Notification rule "%s": the e-mail document "%s" does not exist, no mail is sent for the language "%s".',
                            $rule['name'],
                            $path,
                            $language,
                        ));

                        continue;
                    }

                    $mails[$language] = $document->getId();
                }

                $action['configuration']['mails'] = $mails;
            }
            unset($action);

            $form = $this->formFactory->createNamed('', NotificationRuleType::class);
            $form->submit($rule);

            if (!$form->isValid()) {
                $this->logger->error(sprintf(
                    'Notification rule "%s" is invalid and was not imported: %s',
                    $rule['name'],
                    (string) $form->getErrors(true),
                ));

                continue;
            }

            $notificationRule = $form->getData();
            $notificationRule->setSort($totalExistingRules + $totalImported + 1);

            $manager->persist($notificationRule);

            ++$totalImported;
        }

        $manager->flush();
    }
}
