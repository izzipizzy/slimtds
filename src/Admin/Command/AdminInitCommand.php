<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Shared\Auth\PasswordHasher;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:init', description: 'Bootstrap single admin user from ENV (first run only)')]
final class AdminInitCommand extends Command
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordHasher $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $login = $_ENV['ADMIN_LOGIN'] ?? 'admin';

        $existing = $this->pdo->query("SELECT count(*) FROM core.admins")->fetchColumn();
        if ((int)$existing > 0) {
            $out->writeln('<comment>admin(s) already exist; skip</comment>');
            return self::SUCCESS;
        }

        $password  = $_ENV['ADMIN_PASSWORD'] ?? '';
        $generated = false;
        if ($password === '') {
            $password  = bin2hex(random_bytes(10));
            $generated = true;
        }

        $stmt = $this->pdo->prepare('INSERT INTO core.admins (login, password_hash, must_change_password) VALUES (:l, :h, :m)');
        $stmt->execute([
            'l' => $login,
            'h' => $this->hasher->hash($password),
            'm' => $generated ? 'true' : 'false',
        ]);

        $out->writeln("<info>admin created:</info> login={$login}");
        if ($generated) {
            $out->writeln("<info>generated password:</info> {$password}");
            $out->writeln('<comment>save this, will not be shown again; you must change it on first login</comment>');
        }
        return self::SUCCESS;
    }
}
