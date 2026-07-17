<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Shared\Auth\PasswordHasher;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:set-password', description: 'Reset admin password (CLI recovery)')]
final class AdminSetPasswordCommand extends Command
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordHasher $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED);
        $this->addArgument('password', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $login = (string)$in->getArgument('login');
        $pass  = (string)$in->getArgument('password');

        $stmt = $this->pdo->prepare('UPDATE core.admins SET password_hash = :h, must_change_password = false, updated_at = now() WHERE login = :l');
        $stmt->execute(['h' => $this->hasher->hash($pass), 'l' => $login]);

        if ($stmt->rowCount() === 0) {
            $out->writeln("<error>admin {$login} not found</error>");
            return self::FAILURE;
        }
        $out->writeln("<info>password updated for {$login}</info>");
        return self::SUCCESS;
    }
}
