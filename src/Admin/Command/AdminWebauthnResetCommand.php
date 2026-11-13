<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Admin\Repository\AdminRepository;
use App\Admin\Repository\WebAuthnCredentialRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lost-key recovery: removes every hardware key of an admin, which switches
 * them back to password sign-in. Pair with admin:set-password if the password
 * is not known either. Needs shell access to the server — by design.
 */
#[AsCommand(name: 'admin:webauthn-reset', description: 'Remove all hardware keys (YubiKey) of an admin — re-enables password login')]
final class AdminWebauthnResetCommand extends Command
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly WebAuthnCredentialRepository $keys,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $login = (string)$in->getArgument('login');
        $admin = $this->admins->findByLogin($login);
        if ($admin === null) {
            $out->writeln("<error>admin {$login} not found</error>");
            return self::FAILURE;
        }
        $n = $this->keys->deleteAllForAdmin($admin->id);
        $out->writeln("<info>removed {$n} key(s) for {$login}; password login is enabled again</info>");
        return self::SUCCESS;
    }
}
