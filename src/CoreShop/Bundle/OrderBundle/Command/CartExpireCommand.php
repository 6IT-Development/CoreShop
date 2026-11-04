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

namespace CoreShop\Bundle\OrderBundle\Command;

use CoreShop\Component\StorageList\Expiration\StorageListExpirationInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CartExpireCommand extends Command
{
    public function __construct(
        protected StorageListExpirationInterface $cartExpiration,
        protected array $params = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('coreshop:cart:expire')
            ->setDescription('Expire abandoned Carts')
            ->addOption(
                'days',
                null,
                InputOption::VALUE_OPTIONAL,
                'Older than',
            )
            ->addOption(
                'anonymous',
                'a',
                InputOption::VALUE_NONE,
                'Delete anonymous carts (combine with --user for both)',
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_NONE,
                'Delete customer carts (combine with --anonymous for both)',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) ($this->params['cart']['days'] ?? 0);
        $params = (array) ($this->params['cart']['params'] ?? []);

        if ($input->getOption('days')) {
            $days = (int) $input->getOption('days');
        }

        // Either flag replaces the configured groups; both together select both.
        if ($input->getOption('anonymous') || $input->getOption('user')) {
            $params['anonymous'] = (bool) $input->getOption('anonymous');
            $params['customer'] = (bool) $input->getOption('user');
        }

        $output->writeln('Running cart expire job, this could take some time.');

        $this->cartExpiration->expire($days, $params);

        return 0;
    }
}
