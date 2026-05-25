<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:account:create', description: 'Create an account with an initial balance (in minor units)')]
class CreateAccountCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('accountNumber', InputArgument::REQUIRED)
            ->addArgument('currency', InputArgument::REQUIRED)
            ->addArgument('initialBalanceMinor', InputArgument::OPTIONAL, '', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $account = new Account(
            $input->getArgument('accountNumber'),
            $input->getArgument('currency'),
            (string) $input->getArgument('initialBalanceMinor'),
        );

        $this->em->persist($account);
        $this->em->flush();

        $io->success(sprintf(
            'Account %s created with balance %s %s',
            $account->getAccountNumber(),
            $account->getBalanceMinor(),
            $account->getCurrency(),
        ));

        return Command::SUCCESS;
    }
}
